<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Administrator\View;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Pagination\Pagination;
use Joomla\Database\DatabaseDriver;
use Memi\Component\Memipilates\Administrator\Service\ComponentServices;

/**
 * Shared, deliberately small base for the back-office read views.
 *
 * Every concrete view explicitly declares the permissions required to see it;
 * write actions are still enforced a second time by DisplayController.
 */
abstract class AbstractAdminView extends BaseHtmlView
{
    public int $total = 0;
    public ?Pagination $pagination = null;
    public string $filterSearch = '';
    public string $filterStatus = '';
    public string $filterDate = '';
    public string $currency = 'CAD';
    public bool $canEdit = false;

    protected DatabaseDriver $db;
    protected \DateTimeZone $timezone;
    protected int $limit = 20;
    protected int $limitStart = 0;

    /** @var object|null */
    private ?object $identity = null;

    /**
     * @param list<string> $viewActions
     * @param list<string> $editActions
     */
    protected function initialise(array $viewActions, array $editActions = []): void
    {
        $application = Factory::getApplication();
        $this->identity = $application->getIdentity();

        if (!$this->hasAnyPermission($viewActions)) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        if ($application->isClient('site')) {
            $application->getLanguage()->load('com_memipilates', JPATH_ADMINISTRATOR, null, true);
            $application->getDocument()->getWebAssetManager()
                ->useStyle('com_memipilates.site')
                ->useStyle('com_memipilates.portal');
        }

        $input = $application->input;
        $this->limit = min(100, max(5, $input->getUint('limit', 20)));
        $this->limitStart = max(0, $input->getUint('limitstart', 0));
        $this->filterSearch = trim(substr($input->getString('filter_search', ''), 0, 100));
        $this->filterDate = $this->normaliseDate($input->getString('filter_date', ''));
        $this->db = ComponentServices::database();
        $settings = ComponentServices::settings();
        $configuredCurrency = (string) $settings->get('currency', 'CAD');
        $this->currency = preg_match('/^[A-Z]{3}$/D', $configuredCurrency) ? $configuredCurrency : 'CAD';
        $this->timezone = $settings->timezone();
        $this->canEdit = $editActions !== [] && $this->hasAnyPermission($editActions);
        $this->addComponentToolbar();
    }

    /** @param list<string> $actions */
    protected function hasAnyPermission(array $actions): bool
    {
        if ($this->identity === null) {
            $this->identity = Factory::getApplication()->getIdentity();
        }

        foreach (array_unique(array_merge(['core.admin'], $actions)) as $action) {
            if ((bool) $this->identity->authorise($action, 'com_memipilates')) {
                return true;
            }
        }

        return false;
    }

    public function can(string $action): bool
    {
        return $this->hasAnyPermission([$action]);
    }

    /**
     * Limits a query to sessions assigned to the connected instructor unless
     * the user holds one of the explicitly broad management actions.
     *
     * @param list<string> $unrestrictedActions
     */
    protected function applyInstructorSessionScope(mixed $query, string $sessionAlias, array $unrestrictedActions): void
    {
        if ($this->hasAnyPermission($unrestrictedActions)) {
            return;
        }

        $userId = (int) ($this->identity?->id ?? 0);
        $instructorId = ComponentServices::staffScope()->instructorIdForUser($userId);
        if ($instructorId === null) {
            $query->where('1 = 0');

            return;
        }

        $identifier = $instructorId;
        $query->where($this->db->quoteName($sessionAlias . '.instructor_id') . ' = :scope_instructor_id')
            ->bind(':scope_instructor_id', $identifier, \Joomla\Database\ParameterType::INTEGER);
    }

    /** Adds the native Joomla component-settings button for Super Users only. */
    private function addComponentToolbar(): void
    {
        $application = Factory::getApplication();
        if (!$application->isClient('administrator')) {
            return;
        }

        if (!$this->identity?->authorise('core.admin', 'com_memipilates')) {
            return;
        }

        $application->getDocument()->getToolbar()->preferences('com_memipilates');
    }

    public function label(string $key, string $fallback): string
    {
        $translation = Text::_($key);

        return $translation === $key ? $fallback : $translation;
    }

    public function statusLabel(string $status): string
    {
        $keys = [
            'scheduled' => 'COM_MEMIPILATES_STATUS_SCHEDULED',
            'published' => 'COM_MEMIPILATES_STATUS_PUBLISHED',
            'open' => 'COM_MEMIPILATES_STATUS_OPEN',
            'unpublished' => 'COM_MEMIPILATES_STATUS_UNPUBLISHED',
            'cancelled' => 'COM_MEMIPILATES_STATUS_CANCELLED',
            'completed' => 'COM_MEMIPILATES_STATUS_COMPLETED',
            'pending' => 'COM_MEMIPILATES_STATUS_PENDING',
            'payment_pending' => 'COM_MEMIPILATES_STATUS_PAYMENT_PENDING',
            'payment_processing' => 'COM_MEMIPILATES_STATUS_PAYMENT_PROCESSING',
            'payment_failed' => 'COM_MEMIPILATES_STATUS_PAYMENT_FAILED',
            'payment_expired' => 'COM_MEMIPILATES_STATUS_PAYMENT_EXPIRED',
            'expired' => 'COM_MEMIPILATES_STATUS_EXPIRED',
            'paid' => 'COM_MEMIPILATES_STATUS_PAID',
            'confirmed' => 'COM_MEMIPILATES_STATUS_CONFIRMED',
            'waitlisted' => 'COM_MEMIPILATES_STATUS_WAITLISTED',
            'cancelled_on_time' => 'COM_MEMIPILATES_STATUS_CANCELLED_ON_TIME',
            'cancelled_late' => 'COM_MEMIPILATES_STATUS_CANCELLED_LATE',
            'attended' => 'COM_MEMIPILATES_STATUS_ATTENDED',
            'no_show' => 'COM_MEMIPILATES_STATUS_NO_SHOW',
            'refunded' => 'COM_MEMIPILATES_STATUS_REFUNDED',
            'void' => 'COM_MEMIPILATES_STATUS_VOID',
            'administratively_cancelled' => 'COM_MEMIPILATES_STATUS_ADMINISTRATIVELY_CANCELLED',
        ];
        $key = $keys[$status] ?? '';

        if ($key !== '') {
            $translation = Text::_($key);
            if ($translation !== $key) {
                return $translation;
            }
        }

        return ucfirst(str_replace('_', ' ', $status));
    }

    public function formatDate(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));

            return $date->setTimezone($this->timezone)->format(Text::_('DATE_FORMAT_LC5'));
        } catch (\Throwable) {
            return $value;
        }
    }

    public function formatMoney(int $cents, ?string $currency = null): string
    {
        $candidate = $currency ?? $this->currency;
        $safeCurrency = preg_match('/^[A-Z]{3}$/D', $candidate) ? $candidate : 'CAD';
        $absoluteCents = abs($cents);
        $whole = intdiv($absoluteCents, 100);
        $fraction = str_pad((string) ($absoluteCents % 100), 2, '0', STR_PAD_LEFT);
        $sign = $cents < 0 ? '-' : '';

        return $sign . number_format($whole, 0, '.', ' ') . '.' . $fraction . ' ' . $safeCurrency;
    }

    public function paginationLinks(): string
    {
        return $this->pagination?->getPagesLinks() ?? '';
    }

    protected function setPagination(int $total): void
    {
        $this->total = max(0, $total);
        $this->pagination = new Pagination($this->total, $this->limitStart, $this->limit);
    }

    /** @return array{0:string,1:string}|null */
    protected function selectedDayRange(): ?array
    {
        if ($this->filterDate === '') {
            return null;
        }

        $start = new \DateTimeImmutable($this->filterDate . ' 00:00:00', $this->timezone);
        $end = $start->modify('+1 day');
        $utc = new \DateTimeZone('UTC');

        return [
            $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            $end->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    protected function normaliseStatus(string $candidate, array $allowed): string
    {
        return in_array($candidate, $allowed, true) ? $candidate : '';
    }

    private function normaliseDate(string $candidate): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $candidate)) {
            return '';
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $candidate);
        $errors = \DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return '';
        }

        return $date->format('Y-m-d');
    }
}
