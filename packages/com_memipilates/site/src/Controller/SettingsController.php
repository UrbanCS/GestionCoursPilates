<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Site\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\Database\ParameterType;
use Memi\Component\Memipilates\Administrator\Service\ComponentServices;

/** Saves the whitelisted component settings from the protected site portal. */
final class SettingsController extends BaseController
{
    private const SECRET_KEYS = [
        'square_access_token',
        'square_webhook_signature_key',
    ];

    /** @var array<string, array{type:string,min?:int,max?:int,values?:list<string>}> */
    private const RULES = [
        'timezone' => ['type' => 'timezone'],
        'cancellation_hours' => ['type' => 'int', 'min' => 0, 'max' => 336],
        'direct_payment_hold_minutes' => ['type' => 'int', 'min' => 5, 'max' => 120],
        'session_generation_lookahead_days' => ['type' => 'int', 'min' => 7, 'max' => 730],
        'currency' => ['type' => 'enum', 'values' => ['CAD', 'USD']],
        'waitlist_promotion_mode' => ['type' => 'enum', 'values' => ['automatic', 'manual']],
        'waitlist_offer_minutes' => ['type' => 'int', 'min' => 5, 'max' => 10080],
        'waitlist_auto_promote' => ['type' => 'bool'],
        'reminder_hours' => ['type' => 'reminder_hours'],
        'credit_expiry_notice_days' => ['type' => 'int', 'min' => 0, 'max' => 365],
        'email_from_name' => ['type' => 'string', 'max' => 150],
        'notification_max_attempts' => ['type' => 'int', 'min' => 1, 'max' => 20],
        'notification_retry_base_minutes' => ['type' => 'int', 'min' => 1, 'max' => 1440],
        'loyalty_enabled' => ['type' => 'bool'],
        'points_per_attendance' => ['type' => 'int', 'min' => 0, 'max' => 100000],
        'points_per_dollar' => ['type' => 'int', 'min' => 0, 'max' => 1000],
        'attendance_before_minutes' => ['type' => 'int', 'min' => 0, 'max' => 1440],
        'attendance_after_minutes' => ['type' => 'int', 'min' => 0, 'max' => 1440],
        'kiosk_confirmation_seconds' => ['type' => 'int', 'min' => 1, 'max' => 60],
        'kiosk_sound_enabled' => ['type' => 'bool'],
        'square_environment' => ['type' => 'enum', 'values' => ['sandbox', 'production']],
        'square_application_id' => ['type' => 'string', 'max' => 255],
        'square_location_id' => ['type' => 'string', 'max' => 255],
        'square_access_token' => ['type' => 'secret', 'max' => 4096],
        'square_webhook_signature_key' => ['type' => 'secret', 'max' => 4096],
        'square_webhook_url' => ['type' => 'https_url', 'max' => 2048],
    ];

    public function save(): void
    {
        $application = Factory::getApplication();
        $redirect = Route::_('index.php?option=com_memipilates&view=settings', false);

        if (!Session::checkToken('post')) {
            $this->setRedirect($redirect, Text::_('JINVALID_TOKEN'), 'error');

            return;
        }

        $identity = $application->getIdentity();
        if (!(bool) $identity->authorise('core.admin', 'com_memipilates')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        try {
            $submitted = $application->input->post->get('jform', [], 'array');
            $current = ComponentHelper::getParams('com_memipilates')->toArray();
            $updated = $current;

            foreach (self::RULES as $key => $rule) {
                $raw = $submitted[$key] ?? null;
                if ($rule['type'] === 'secret' && trim((string) $raw) === '') {
                    continue;
                }

                $updated[$key] = $this->normalise($key, $raw, $rule);
            }

            $db = ComponentServices::database();
            $db->transactionStart();

            try {
                $this->saveComponentParams($updated);
                ComponentServices::audit()->log(
                    (int) $identity->id,
                    'settings.update',
                    'component_settings',
                    null,
                    $this->safeAuditValues($current),
                    $this->safeAuditValues($updated)
                );
                $db->transactionCommit();
            } catch (\Throwable $exception) {
                $db->transactionRollback();
                throw $exception;
            }

            $this->setRedirect($redirect, Text::_('COM_MEMIPILATES_PORTAL_SETTINGS_SAVED'));
        } catch (\InvalidArgumentException $exception) {
            $this->setRedirect($redirect, $exception->getMessage(), 'error');
        } catch (\Throwable $exception) {
            Log::add('Memi Pilates portal settings failed: ' . $exception->getMessage(), Log::ERROR, 'com_memipilates');
            $this->setRedirect($redirect, Text::_('COM_MEMIPILATES_PORTAL_SETTINGS_SAVE_FAILED'), 'error');
        }
    }

    /**
     * @param array{type:string,min?:int,max?:int,values?:list<string>} $rule
     */
    private function normalise(string $key, mixed $raw, array $rule): string|int
    {
        $type = $rule['type'];

        if ($type === 'bool') {
            return in_array((string) $raw, ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
        }

        if ($type === 'int') {
            if (filter_var($raw, FILTER_VALIDATE_INT) === false) {
                throw new \InvalidArgumentException(Text::sprintf('COM_MEMIPILATES_PORTAL_INVALID_SETTING', $key));
            }

            $value = (int) $raw;
            if ($value < (int) $rule['min'] || $value > (int) $rule['max']) {
                throw new \InvalidArgumentException(Text::sprintf('COM_MEMIPILATES_PORTAL_INVALID_SETTING', $key));
            }

            return $value;
        }

        $value = trim((string) $raw);
        $maximum = (int) ($rule['max'] ?? 255);
        if (mb_strlen($value) > $maximum) {
            throw new \InvalidArgumentException(Text::sprintf('COM_MEMIPILATES_PORTAL_INVALID_SETTING', $key));
        }

        if ($type === 'enum' && !in_array($value, $rule['values'] ?? [], true)) {
            throw new \InvalidArgumentException(Text::sprintf('COM_MEMIPILATES_PORTAL_INVALID_SETTING', $key));
        }

        if ($type === 'timezone') {
            try {
                new \DateTimeZone($value);
            } catch (\Throwable) {
                throw new \InvalidArgumentException(Text::sprintf('COM_MEMIPILATES_PORTAL_INVALID_SETTING', $key));
            }
        }

        if ($type === 'reminder_hours') {
            $hours = array_values(array_unique(array_filter(
                array_map('trim', explode(',', $value)),
                static fn (string $hour): bool => $hour !== ''
            )));
            if ($hours === []) {
                throw new \InvalidArgumentException(Text::sprintf('COM_MEMIPILATES_PORTAL_INVALID_SETTING', $key));
            }
            foreach ($hours as $hour) {
                if (!ctype_digit($hour) || (int) $hour > 8760) {
                    throw new \InvalidArgumentException(Text::sprintf('COM_MEMIPILATES_PORTAL_INVALID_SETTING', $key));
                }
            }
            $value = implode(',', $hours);
        }

        if ($type === 'https_url' && $value !== '') {
            $parts = parse_url($value);
            if (filter_var($value, FILTER_VALIDATE_URL) === false || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
                throw new \InvalidArgumentException(Text::_('COM_MEMIPILATES_PORTAL_HTTPS_REQUIRED'));
            }
        }

        return $value;
    }

    /** @param array<string, mixed> $params */
    private function saveComponentParams(array $params): void
    {
        $db = ComponentServices::database();
        $type = 'component';
        $element = 'com_memipilates';
        $query = $db->getQuery(true)
            ->select([$db->quoteName('extension_id')])
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = :extension_type')
            ->where($db->quoteName('element') . ' = :extension_element')
            ->bind(':extension_type', $type)
            ->bind(':extension_element', $element);
        $db->setQuery($query);
        $extensionId = (int) $db->loadResult();

        if ($extensionId <= 0) {
            throw new \RuntimeException(Text::_('COM_MEMIPILATES_PORTAL_SETTINGS_NOT_FOUND'));
        }

        $json = json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $update = $db->getQuery(true)
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('params') . ' = :params')
            ->where($db->quoteName('extension_id') . ' = :extension_id')
            ->bind(':params', $json)
            ->bind(':extension_id', $extensionId, ParameterType::INTEGER);
        $db->setQuery($update)->execute();
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function safeAuditValues(array $values): array
    {
        foreach (self::SECRET_KEYS as $secretKey) {
            unset($values[$secretKey]);
        }

        return array_intersect_key($values, self::RULES);
    }
}
