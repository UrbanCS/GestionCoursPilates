<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Site\View\Settings;

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;

/** Super User-only component settings exposed inside the front-end portal. */
final class HtmlView extends BaseHtmlView
{
    /** @var array<string, mixed> */
    public array $values = [];
    public bool $squareAccessTokenConfigured = false;
    public bool $squareWebhookKeyConfigured = false;

    public function display($tpl = null): void
    {
        $application = Factory::getApplication();
        $identity = $application->getIdentity();

        if (!(bool) $identity->authorise('core.admin', 'com_memipilates')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $application->getLanguage()->load('com_memipilates', JPATH_ADMINISTRATOR, null, true);
        $params = ComponentHelper::getParams('com_memipilates');
        $values = array_replace([
            'timezone' => 'America/Toronto',
            'cancellation_hours' => 12,
            'direct_payment_hold_minutes' => 15,
            'session_generation_lookahead_days' => 90,
            'currency' => 'CAD',
            'waitlist_promotion_mode' => 'automatic',
            'waitlist_offer_minutes' => 120,
            'waitlist_auto_promote' => 1,
            'reminder_hours' => '24,2',
            'credit_expiry_notice_days' => 14,
            'email_from_name' => 'Memi Studio',
            'notification_max_attempts' => 5,
            'notification_retry_base_minutes' => 5,
            'loyalty_enabled' => 1,
            'points_per_attendance' => 10,
            'points_per_dollar' => 1,
            'attendance_before_minutes' => 30,
            'attendance_after_minutes' => 30,
            'kiosk_confirmation_seconds' => 4,
            'kiosk_sound_enabled' => 1,
            'square_environment' => 'sandbox',
            'square_application_id' => '',
            'square_location_id' => '',
            'square_access_token' => '',
            'square_webhook_signature_key' => '',
            'square_webhook_url' => '',
        ], $params->toArray());
        $this->squareAccessTokenConfigured = trim((string) ($values['square_access_token'] ?? '')) !== '';
        $this->squareWebhookKeyConfigured = trim((string) ($values['square_webhook_signature_key'] ?? '')) !== '';

        // Secrets are deliberately never sent back to the browser. An empty
        // submitted password means "keep the existing value".
        $values['square_access_token'] = '';
        $values['square_webhook_signature_key'] = '';
        $this->values = $values;

        $application->getDocument()->getWebAssetManager()
            ->useStyle('com_memipilates.site')
            ->useStyle('com_memipilates.portal');

        parent::display($tpl);
    }
}
