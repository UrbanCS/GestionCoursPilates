<?php
/** @var \Memi\Component\Memipilates\Site\View\Settings\HtmlView $this */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$portalView = 'settings';
require dirname(__DIR__) . '/portal/start.php';

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$value = fn (string $key, mixed $default = ''): mixed => $this->values[$key] ?? $default;
$selected = static fn (mixed $actual, mixed $expected): string => (string) $actual === (string) $expected ? ' selected' : '';
$description = static fn (string $key): string => '<small class="memi-portal-field__description">' . $escape(Text::_($key)) . '</small>';
?>
<div class="memi-portal-settings">
    <header class="memi-portal-page-header">
        <div>
            <p class="memi-portal-page-header__eyebrow"><?= Text::_('COM_MEMIPILATES'); ?></p>
            <h2><?= Text::_('COM_MEMIPILATES_PORTAL_SETTINGS'); ?></h2>
            <p><?= Text::_('COM_MEMIPILATES_PORTAL_SETTINGS_INTRO'); ?></p>
        </div>
    </header>

    <form action="<?= Route::_('index.php?option=com_memipilates'); ?>" method="post" class="memi-portal-settings__form">
        <fieldset class="memi-portal-settings__fieldset">
            <legend><?= Text::_('COM_MEMIPILATES_CONFIG_FIELDSET_GENERAL'); ?></legend>
            <div class="memi-portal-field">
                <label for="jform-timezone"><?= Text::_('COM_MEMIPILATES_CONFIG_STUDIO_TIMEZONE_LABEL'); ?></label>
                <div>
                    <input id="jform-timezone" name="jform[timezone]" type="text" list="memi-timezones" value="<?= $escape($value('timezone')); ?>" required autocomplete="off">
                    <datalist id="memi-timezones">
                        <?php foreach (DateTimeZone::listIdentifiers() as $timezone) : ?>
                            <option value="<?= $escape($timezone); ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <?= $description('COM_MEMIPILATES_CONFIG_STUDIO_TIMEZONE_DESC'); ?>
                </div>
            </div>
            <div class="memi-portal-field">
                <label for="jform-cancellation-hours"><?= Text::_('COM_MEMIPILATES_CONFIG_CANCELLATION_WINDOW_LABEL'); ?></label>
                <div><input id="jform-cancellation-hours" name="jform[cancellation_hours]" type="number" min="0" max="336" step="1" value="<?= (int) $value('cancellation_hours', 12); ?>" required><?= $description('COM_MEMIPILATES_CONFIG_CANCELLATION_WINDOW_DESC'); ?></div>
            </div>
            <div class="memi-portal-field">
                <label for="jform-direct-payment-hold"><?= Text::_('COM_MEMIPILATES_CONFIG_DIRECT_PAYMENT_HOLD_LABEL'); ?></label>
                <div><input id="jform-direct-payment-hold" name="jform[direct_payment_hold_minutes]" type="number" min="5" max="120" step="5" value="<?= (int) $value('direct_payment_hold_minutes', 15); ?>" required><?= $description('COM_MEMIPILATES_CONFIG_DIRECT_PAYMENT_HOLD_DESC'); ?></div>
            </div>
            <div class="memi-portal-field">
                <label for="jform-session-lookahead"><?= Text::_('COM_MEMIPILATES_CONFIG_SESSION_LOOKAHEAD_LABEL'); ?></label>
                <div><input id="jform-session-lookahead" name="jform[session_generation_lookahead_days]" type="number" min="7" max="730" step="1" value="<?= (int) $value('session_generation_lookahead_days', 90); ?>" required><?= $description('COM_MEMIPILATES_CONFIG_SESSION_LOOKAHEAD_DESC'); ?></div>
            </div>
            <div class="memi-portal-field">
                <label for="jform-currency"><?= Text::_('COM_MEMIPILATES_CONFIG_CURRENCY_LABEL'); ?></label>
                <div>
                    <select id="jform-currency" name="jform[currency]">
                        <option value="CAD"<?= $selected($value('currency', 'CAD'), 'CAD'); ?>>CAD</option>
                        <option value="USD"<?= $selected($value('currency', 'CAD'), 'USD'); ?>>USD</option>
                    </select>
                    <?= $description('COM_MEMIPILATES_CONFIG_CURRENCY_DESC'); ?>
                </div>
            </div>
        </fieldset>

        <fieldset class="memi-portal-settings__fieldset">
            <legend><?= Text::_('COM_MEMIPILATES_CONFIG_FIELDSET_WAITLIST'); ?></legend>
            <div class="memi-portal-field">
                <label for="jform-waitlist-mode"><?= Text::_('COM_MEMIPILATES_CONFIG_WAITLIST_MODE_LABEL'); ?></label>
                <div>
                    <select id="jform-waitlist-mode" name="jform[waitlist_promotion_mode]">
                        <option value="automatic"<?= $selected($value('waitlist_promotion_mode', 'automatic'), 'automatic'); ?>><?= Text::_('COM_MEMIPILATES_OPTION_AUTOMATIC'); ?></option>
                        <option value="manual"<?= $selected($value('waitlist_promotion_mode', 'automatic'), 'manual'); ?>><?= Text::_('COM_MEMIPILATES_OPTION_MANUAL'); ?></option>
                    </select>
                    <?= $description('COM_MEMIPILATES_CONFIG_WAITLIST_MODE_DESC'); ?>
                </div>
            </div>
            <div class="memi-portal-field">
                <label for="jform-waitlist-offer"><?= Text::_('COM_MEMIPILATES_CONFIG_WAITLIST_OFFER_LABEL'); ?></label>
                <div><input id="jform-waitlist-offer" name="jform[waitlist_offer_minutes]" type="number" min="5" max="10080" step="5" value="<?= (int) $value('waitlist_offer_minutes', 120); ?>" required><?= $description('COM_MEMIPILATES_CONFIG_WAITLIST_OFFER_DESC'); ?></div>
            </div>
            <div class="memi-portal-field">
                <label for="jform-waitlist-auto"><?= Text::_('COM_MEMIPILATES_CONFIG_WAITLIST_AUTO_PROMOTE_LABEL'); ?></label>
                <div>
                    <select id="jform-waitlist-auto" name="jform[waitlist_auto_promote]">
                        <option value="1"<?= $selected($value('waitlist_auto_promote', 1), 1); ?>><?= Text::_('JYES'); ?></option>
                        <option value="0"<?= $selected($value('waitlist_auto_promote', 1), 0); ?>><?= Text::_('JNO'); ?></option>
                    </select>
                    <?= $description('COM_MEMIPILATES_CONFIG_WAITLIST_AUTO_PROMOTE_DESC'); ?>
                </div>
            </div>
        </fieldset>

        <fieldset class="memi-portal-settings__fieldset">
            <legend><?= Text::_('COM_MEMIPILATES_CONFIG_FIELDSET_NOTIFICATIONS'); ?></legend>
            <div class="memi-portal-field">
                <label for="jform-reminder-hours"><?= Text::_('COM_MEMIPILATES_CONFIG_REMINDER_HOURS_LABEL'); ?></label>
                <div><input id="jform-reminder-hours" name="jform[reminder_hours]" type="text" maxlength="100" value="<?= $escape($value('reminder_hours', '24,2')); ?>" required><?= $description('COM_MEMIPILATES_CONFIG_REMINDER_HOURS_DESC'); ?></div>
            </div>
            <div class="memi-portal-field">
                <label for="jform-credit-expiry"><?= Text::_('COM_MEMIPILATES_CONFIG_CREDIT_EXPIRY_NOTICE_LABEL'); ?></label>
                <div><input id="jform-credit-expiry" name="jform[credit_expiry_notice_days]" type="number" min="0" max="365" step="1" value="<?= (int) $value('credit_expiry_notice_days', 14); ?>" required><?= $description('COM_MEMIPILATES_CONFIG_CREDIT_EXPIRY_NOTICE_DESC'); ?></div>
            </div>
            <div class="memi-portal-field">
                <label for="jform-email-name"><?= Text::_('COM_MEMIPILATES_CONFIG_EMAIL_FROM_NAME_LABEL'); ?></label>
                <div><input id="jform-email-name" name="jform[email_from_name]" type="text" maxlength="150" value="<?= $escape($value('email_from_name', 'Memi Studio')); ?>"><?= $description('COM_MEMIPILATES_CONFIG_EMAIL_FROM_NAME_DESC'); ?></div>
            </div>
            <div class="memi-portal-field">
                <label for="jform-notification-attempts"><?= Text::_('COM_MEMIPILATES_CONFIG_NOTIFICATION_MAX_ATTEMPTS_LABEL'); ?></label>
                <div><input id="jform-notification-attempts" name="jform[notification_max_attempts]" type="number" min="1" max="20" step="1" value="<?= (int) $value('notification_max_attempts', 5); ?>" required><?= $description('COM_MEMIPILATES_CONFIG_NOTIFICATION_MAX_ATTEMPTS_DESC'); ?></div>
            </div>
            <div class="memi-portal-field">
                <label for="jform-notification-retry"><?= Text::_('COM_MEMIPILATES_CONFIG_NOTIFICATION_RETRY_BASE_LABEL'); ?></label>
                <div><input id="jform-notification-retry" name="jform[notification_retry_base_minutes]" type="number" min="1" max="1440" step="1" value="<?= (int) $value('notification_retry_base_minutes', 5); ?>" required><?= $description('COM_MEMIPILATES_CONFIG_NOTIFICATION_RETRY_BASE_DESC'); ?></div>
            </div>
        </fieldset>

        <fieldset class="memi-portal-settings__fieldset">
            <legend><?= Text::_('COM_MEMIPILATES_CONFIG_FIELDSET_LOYALTY'); ?></legend>
            <div class="memi-portal-field">
                <label for="jform-loyalty-enabled"><?= Text::_('COM_MEMIPILATES_CONFIG_LOYALTY_ENABLED_LABEL'); ?></label>
                <div>
                    <select id="jform-loyalty-enabled" name="jform[loyalty_enabled]">
                        <option value="1"<?= $selected($value('loyalty_enabled', 1), 1); ?>><?= Text::_('JYES'); ?></option>
                        <option value="0"<?= $selected($value('loyalty_enabled', 1), 0); ?>><?= Text::_('JNO'); ?></option>
                    </select>
                    <?= $description('COM_MEMIPILATES_CONFIG_LOYALTY_ENABLED_DESC'); ?>
                </div>
            </div>
            <div class="memi-portal-field">
                <label for="jform-points-attendance"><?= Text::_('COM_MEMIPILATES_CONFIG_POINTS_ATTENDANCE_LABEL'); ?></label>
                <div><input id="jform-points-attendance" name="jform[points_per_attendance]" type="number" min="0" max="100000" step="1" value="<?= (int) $value('points_per_attendance', 10); ?>" required><?= $description('COM_MEMIPILATES_CONFIG_POINTS_ATTENDANCE_DESC'); ?></div>
            </div>
            <div class="memi-portal-field">
                <label for="jform-points-dollar"><?= Text::_('COM_MEMIPILATES_CONFIG_POINTS_DOLLAR_LABEL'); ?></label>
                <div><input id="jform-points-dollar" name="jform[points_per_dollar]" type="number" min="0" max="1000" step="1" value="<?= (int) $value('points_per_dollar', 1); ?>" required><?= $description('COM_MEMIPILATES_CONFIG_POINTS_DOLLAR_DESC'); ?></div>
            </div>
        </fieldset>

        <fieldset class="memi-portal-settings__fieldset">
            <legend><?= Text::_('COM_MEMIPILATES_CONFIG_FIELDSET_KIOSK'); ?></legend>
            <div class="memi-portal-field">
                <label for="jform-attendance-before"><?= Text::_('COM_MEMIPILATES_CONFIG_KIOSK_BEFORE_LABEL'); ?></label>
                <div><input id="jform-attendance-before" name="jform[attendance_before_minutes]" type="number" min="0" max="1440" step="1" value="<?= (int) $value('attendance_before_minutes', 30); ?>" required><?= $description('COM_MEMIPILATES_CONFIG_KIOSK_BEFORE_DESC'); ?></div>
            </div>
            <div class="memi-portal-field">
                <label for="jform-attendance-after"><?= Text::_('COM_MEMIPILATES_CONFIG_KIOSK_AFTER_LABEL'); ?></label>
                <div><input id="jform-attendance-after" name="jform[attendance_after_minutes]" type="number" min="0" max="1440" step="1" value="<?= (int) $value('attendance_after_minutes', 30); ?>" required><?= $description('COM_MEMIPILATES_CONFIG_KIOSK_AFTER_DESC'); ?></div>
            </div>
            <div class="memi-portal-field">
                <label for="jform-kiosk-confirmation"><?= Text::_('COM_MEMIPILATES_CONFIG_KIOSK_CONFIRMATION_LABEL'); ?></label>
                <div><input id="jform-kiosk-confirmation" name="jform[kiosk_confirmation_seconds]" type="number" min="1" max="60" step="1" value="<?= (int) $value('kiosk_confirmation_seconds', 4); ?>" required><?= $description('COM_MEMIPILATES_CONFIG_KIOSK_CONFIRMATION_DESC'); ?></div>
            </div>
            <div class="memi-portal-field">
                <label for="jform-kiosk-sound"><?= Text::_('COM_MEMIPILATES_CONFIG_KIOSK_SOUND_LABEL'); ?></label>
                <div>
                    <select id="jform-kiosk-sound" name="jform[kiosk_sound_enabled]">
                        <option value="1"<?= $selected($value('kiosk_sound_enabled', 1), 1); ?>><?= Text::_('JYES'); ?></option>
                        <option value="0"<?= $selected($value('kiosk_sound_enabled', 1), 0); ?>><?= Text::_('JNO'); ?></option>
                    </select>
                    <?= $description('COM_MEMIPILATES_CONFIG_KIOSK_SOUND_DESC'); ?>
                </div>
            </div>
        </fieldset>

        <fieldset class="memi-portal-settings__fieldset">
            <legend><?= Text::_('COM_MEMIPILATES_CONFIG_FIELDSET_SQUARE'); ?></legend>
            <div class="alert alert-info" role="status">
                <p>
                    <?= $this->squareAccessTokenConfigured
                        ? Text::_('COM_MEMIPILATES_PORTAL_ACCESS_TOKEN_CONFIGURED')
                        : Text::_('COM_MEMIPILATES_PORTAL_ACCESS_TOKEN_NOT_CONFIGURED'); ?>
                </p>
                <p>
                    <?= $this->squareWebhookKeyConfigured
                        ? Text::_('COM_MEMIPILATES_PORTAL_WEBHOOK_KEY_CONFIGURED')
                        : Text::_('COM_MEMIPILATES_PORTAL_WEBHOOK_KEY_NOT_CONFIGURED'); ?>
                </p>
                <p><?= Text::_('COM_MEMIPILATES_PORTAL_SECRET_PRESERVE'); ?></p>
            </div>
            <div class="memi-portal-field">
                <label for="jform-square-environment"><?= Text::_('COM_MEMIPILATES_CONFIG_SQUARE_ENVIRONMENT_LABEL'); ?></label>
                <div>
                    <select id="jform-square-environment" name="jform[square_environment]">
                        <option value="sandbox"<?= $selected($value('square_environment', 'sandbox'), 'sandbox'); ?>><?= Text::_('COM_MEMIPILATES_OPTION_SANDBOX'); ?></option>
                        <option value="production"<?= $selected($value('square_environment', 'sandbox'), 'production'); ?>><?= Text::_('COM_MEMIPILATES_OPTION_PRODUCTION'); ?></option>
                    </select>
                    <?= $description('COM_MEMIPILATES_CONFIG_SQUARE_ENVIRONMENT_DESC'); ?>
                </div>
            </div>
            <div class="memi-portal-field">
                <label for="jform-square-application"><?= Text::_('COM_MEMIPILATES_CONFIG_SQUARE_APPLICATION_ID_LABEL'); ?></label>
                <div><input id="jform-square-application" name="jform[square_application_id]" type="text" maxlength="255" value="<?= $escape($value('square_application_id')); ?>" autocomplete="off"><?= $description('COM_MEMIPILATES_CONFIG_SQUARE_APPLICATION_ID_DESC'); ?></div>
            </div>
            <div class="memi-portal-field">
                <label for="jform-square-location"><?= Text::_('COM_MEMIPILATES_CONFIG_SQUARE_LOCATION_ID_LABEL'); ?></label>
                <div><input id="jform-square-location" name="jform[square_location_id]" type="text" maxlength="255" value="<?= $escape($value('square_location_id')); ?>" autocomplete="off"><?= $description('COM_MEMIPILATES_CONFIG_SQUARE_LOCATION_ID_DESC'); ?></div>
            </div>
            <div class="memi-portal-field">
                <label for="jform-square-token"><?= Text::_('COM_MEMIPILATES_CONFIG_SQUARE_ACCESS_TOKEN_LABEL'); ?></label>
                <div><input id="jform-square-token" name="jform[square_access_token]" type="password" maxlength="4096" value="" autocomplete="new-password"><?= $description('COM_MEMIPILATES_CONFIG_SQUARE_ACCESS_TOKEN_DESC'); ?></div>
            </div>
            <div class="memi-portal-field">
                <label for="jform-square-webhook-key"><?= Text::_('COM_MEMIPILATES_CONFIG_SQUARE_WEBHOOK_KEY_LABEL'); ?></label>
                <div><input id="jform-square-webhook-key" name="jform[square_webhook_signature_key]" type="password" maxlength="4096" value="" autocomplete="new-password"><?= $description('COM_MEMIPILATES_CONFIG_SQUARE_WEBHOOK_KEY_DESC'); ?></div>
            </div>
            <div class="memi-portal-field">
                <label for="jform-square-webhook-url"><?= Text::_('COM_MEMIPILATES_CONFIG_SQUARE_WEBHOOK_URL_LABEL'); ?></label>
                <div><input id="jform-square-webhook-url" name="jform[square_webhook_url]" type="url" maxlength="2048" value="<?= $escape($value('square_webhook_url')); ?>" placeholder="https://"><?= $description('COM_MEMIPILATES_CONFIG_SQUARE_WEBHOOK_URL_DESC'); ?></div>
            </div>
        </fieldset>

        <input type="hidden" name="option" value="com_memipilates">
        <input type="hidden" name="task" value="settings.save">
        <?= HTMLHelper::_('form.token'); ?>
        <button type="submit" class="btn btn-primary"><?= Text::_('COM_MEMIPILATES_PORTAL_SAVE_SETTINGS'); ?></button>
    </form>
</div>
<?php require dirname(__DIR__) . '/portal/end.php'; ?>
