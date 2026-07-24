<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Site\View\Dashboard;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Memi\Component\Memipilates\Administrator\Service\ComponentServices;
use Memi\Component\Memipilates\Site\Service\PortalAccess;

final class HtmlView extends BaseHtmlView
{
    public int $userId = 0;
    public int $creditBalance = 0;
    public int $pointBalance = 0;
    /** @var list<array<string,mixed>> */
    public array $upcoming = [];
    /** @var list<array<string,mixed>> */
    public array $history = [];
    /** @var list<array<string,mixed>> */
    public array $waitlist = [];
    /** @var list<array<string,mixed>> */
    public array $payments = [];
    /** @var list<array<string,mixed>> */
    public array $activePackages = [];
    /** @var list<array<string,mixed>> */
    public array $pointHistory = [];
    /** @var list<array<string,mixed>> */
    public array $rewards = [];
    public string $qrEndpoint = '';
    public ?string $qrToken = null;
    public string $loyaltyEndpoint = '';
    public string $cancelEndpoint = '';
    public string $leaveWaitlistEndpoint = '';
    public bool $canManageStudio = false;
    public string $managementLandingView = 'manage';

    public function display($tpl = null): void
    {
        $identity = Factory::getApplication()->getIdentity();
        $this->userId = (int) ($identity->id ?? 0);
        if ($this->userId <= 0) {
            Factory::getApplication()->redirect(Route::_('index.php?option=com_users&view=login&return=' . base64_encode('index.php?option=com_memipilates&view=dashboard'), false));
            return;
        }
        $managementLandingView = PortalAccess::landingView($identity);
        $this->canManageStudio = $managementLandingView !== null;
        $this->managementLandingView = $managementLandingView ?? 'manage';
        $this->creditBalance = ComponentServices::credits()->balance($this->userId);
        $this->pointBalance = ComponentServices::points()->balance($this->userId);
        $this->upcoming = $this->loadBookings(true);
        $this->history = $this->loadBookings(false);
        $this->waitlist = $this->loadWaitlist();
        $this->payments = $this->loadPayments();
        $this->activePackages = $this->loadActivePackages();
        $this->pointHistory = $this->loadPointHistory();
        $this->rewards = ComponentServices::settings()->getBool('loyalty_enabled', true) ? $this->loadRewards() : [];
        $currentQr = ComponentServices::qrTokens()->current($this->userId, $this->userId);
        $this->qrToken = isset($currentQr['token']) ? (string) $currentQr['token'] : null;
        $this->qrEndpoint = Route::_('index.php?option=com_memipilates&task=qr.regenerate&format=json', false);
        $this->loyaltyEndpoint = Route::_('index.php?option=com_memipilates&task=loyalty.redeem&format=json', false);
        $this->cancelEndpoint = Route::_('index.php?option=com_memipilates&task=booking.cancel&format=json', false);
        $this->leaveWaitlistEndpoint = Route::_('index.php?option=com_memipilates&task=booking.leaveWaitlist&format=json', false);
        $document = Factory::getApplication()->getDocument();
        $document->getWebAssetManager()->useStyle('com_memipilates.site')->useScript('com_memipilates.dashboard');
        parent::display($tpl);
    }

    public function formatDate(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));

            return $date->setTimezone(ComponentServices::settings()->timezone())->format(Text::_('DATE_FORMAT_LC5'));
        } catch (\Throwable) {
            return $value;
        }
    }

    public function formatMoney(int $cents, string $currency): string
    {
        $candidate = strtoupper(trim($currency));
        $safeCurrency = preg_match('/^[A-Z]{3}$/D', $candidate) ? $candidate : 'CAD';
        $absoluteCents = abs($cents);
        $whole = intdiv($absoluteCents, 100);
        $fraction = str_pad((string) ($absoluteCents % 100), 2, '0', STR_PAD_LEFT);
        $sign = $cents < 0 ? '-' : '';

        return $sign . number_format($whole, 0, '.', ' ') . '.' . $fraction . ' ' . $safeCurrency;
    }

    public function statusLabel(string $status): string
    {
        $key = 'COM_MEMIPILATES_STATUS_' . strtoupper($status);
        $translation = Text::_($key);

        return $translation === $key ? ucfirst(str_replace('_', ' ', $status)) : $translation;
    }

    /** @return list<array<string,mixed>> */
    private function loadBookings(bool $upcoming): array
    {
        $db = ComponentServices::database();
        $user = $this->userId;
        $now = gmdate('Y-m-d H:i:s');
        $operator = $upcoming ? '>=' : '<';
        $query = $db->getQuery(true)
            ->select(['b.*', 's.starts_at', 's.ends_at', 'c.title AS course_title', 'i.display_name AS instructor_name'])
            ->from($db->quoteName('#__memi_bookings', 'b'))
            ->join('INNER', $db->quoteName('#__memi_sessions', 's') . ' ON s.id = b.session_id')
            ->join('INNER', $db->quoteName('#__memi_courses', 'c') . ' ON c.id = s.course_id')
            ->join('LEFT', $db->quoteName('#__memi_instructors', 'i') . ' ON i.id = s.instructor_id')
            ->where('b.user_id = :user_id')
            ->where('s.starts_at ' . $operator . ' :now')
            ->order('s.starts_at ' . ($upcoming ? 'ASC' : 'DESC'))
            ->bind(':user_id', $user)
            ->bind(':now', $now);
        $db->setQuery($query, 0, $upcoming ? 10 : 20);

        return $db->loadAssocList() ?: [];
    }

    /** @return list<array<string,mixed>> */
    private function loadWaitlist(): array
    {
        $db = ComponentServices::database();
        $user = $this->userId;
        $query = $db->getQuery(true)
            ->select(['w.*', 's.starts_at', 'c.title AS course_title'])
            ->from($db->quoteName('#__memi_waitlist', 'w'))
            ->join('INNER', $db->quoteName('#__memi_sessions', 's') . ' ON s.id = w.session_id')
            ->join('INNER', $db->quoteName('#__memi_courses', 'c') . ' ON c.id = s.course_id')
            ->where('w.user_id = :user_id')
            ->where('w.status IN (' . $db->quote('waiting') . ', ' . $db->quote('offered') . ')')
            ->order('s.starts_at ASC')
            ->bind(':user_id', $user);
        $db->setQuery($query);

        return $db->loadAssocList() ?: [];
    }

    /** @return list<array<string,mixed>> */
    private function loadPayments(): array
    {
        $db = ComponentServices::database();
        $user = $this->userId;
        $query = $db->getQuery(true)
            ->select(['p.*', 'o.order_number', 'o.total_cents'])
            ->from($db->quoteName('#__memi_payments', 'p'))
            ->join('INNER', $db->quoteName('#__memi_orders', 'o') . ' ON o.id = p.order_id')
            ->where('o.user_id = :user_id')
            ->order('p.created_at DESC')
            ->bind(':user_id', $user);
        $db->setQuery($query, 0, 20);

        return $db->loadAssocList() ?: [];
    }

    /** @return list<array<string,mixed>> */
    private function loadActivePackages(): array
    {
        $db = ComponentServices::database();
        $user = $this->userId;
        $active = 'active';
        $restored = 'restored';
        $now = gmdate('Y-m-d H:i:s');
        $query = $db->getQuery(true)
            ->select(['cp.*', 'p.title AS package_title'])
            ->from($db->quoteName('#__memi_customer_packages', 'cp'))
            ->join('INNER', $db->quoteName('#__memi_packages', 'p') . ' ON p.id = cp.package_id')
            ->where('cp.user_id = :user_id')
            ->where('((cp.status = :active_status AND (cp.expires_at IS NULL OR cp.expires_at > :now)) OR cp.status = :restored_status)')
            ->where('cp.remaining_credits > 0')
            ->order('CASE WHEN cp.status = ' . $db->quote($restored) . ' THEN 0 ELSE 1 END, cp.expires_at ASC, cp.id ASC')
            ->bind(':user_id', $user)
            ->bind(':active_status', $active)
            ->bind(':restored_status', $restored)
            ->bind(':now', $now);
        $db->setQuery($query);

        return $db->loadAssocList() ?: [];
    }

    /** @return list<array<string,mixed>> */
    private function loadPointHistory(): array
    {
        $db = ComponentServices::database();
        $user = $this->userId;
        $query = $db->getQuery(true)
            ->select(['points_delta', 'entry_type', 'description', 'created_at'])
            ->from($db->quoteName('#__memi_points_ledger'))
            ->where('user_id = :user_id')
            ->order('created_at DESC')
            ->bind(':user_id', $user);
        $db->setQuery($query, 0, 20);

        return $db->loadAssocList() ?: [];
    }

    /** @return list<array<string,mixed>> */
    private function loadRewards(): array
    {
        $db = ComponentServices::database();
        $now = gmdate('Y-m-d H:i:s');
        $query = $db->getQuery(true)
            ->select(['r.*', 'p.title AS package_title'])
            ->from($db->quoteName('#__memi_rewards', 'r'))
            ->join('LEFT', $db->quoteName('#__memi_packages', 'p') . ' ON p.id = r.package_id')
            ->where('r.published = 1')
            ->where('r.archived_at IS NULL')
            ->where('(r.available_from IS NULL OR r.available_from <= :now)')
            ->where('(r.available_until IS NULL OR r.available_until >= :now)')
            ->order('r.ordering ASC, r.id ASC')
            ->bind(':now', $now);
        $db->setQuery($query);

        return $db->loadAssocList() ?: [];
    }
}
