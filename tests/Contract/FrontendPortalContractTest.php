<?php

declare(strict_types=1);

namespace MemiPilates\Tests\Contract;

use PHPUnit\Framework\TestCase;

final class FrontendPortalContractTest extends TestCase
{
    public function testEveryManagementViewHasAFrontendViewAndTemplate(): void
    {
        $root = dirname(__DIR__, 2) . '/packages/com_memipilates/site';
        $views = [
            'Manage' => 'manage',
            'Setup' => 'setup',
            'Catalog' => 'catalog',
            'Sessions' => 'sessions',
            'Bookings' => 'bookings',
            'Customers' => 'customers',
            'Packages' => 'packages',
            'Offers' => 'offers',
            'Payments' => 'payments',
            'Attendance' => 'attendance',
            'Settings' => 'settings',
        ];

        foreach ($views as $classDirectory => $templateDirectory) {
            self::assertFileExists($root . '/src/View/' . $classDirectory . '/HtmlView.php', $classDirectory);
            self::assertFileExists($root . '/tmpl/' . $templateDirectory . '/default.php', $templateDirectory);
        }
    }

    public function testPortalDispatcherUsesCentralAclMap(): void
    {
        $root = dirname(__DIR__, 2) . '/packages/com_memipilates/site/src';
        $controller = (string) file_get_contents($root . '/Controller/DisplayController.php');
        $access = (string) file_get_contents($root . '/Service/PortalAccess.php');

        self::assertStringContainsString('PortalAccess::isManagementView', $controller);
        self::assertStringContainsString('PortalAccess::canAccess', $controller);
        self::assertStringContainsString("'settings' => ['core.admin']", $access);
        self::assertStringContainsString("'payments' => ['payments.view']", $access);
    }

    public function testSquareSecretsAreBlankedAndPreserved(): void
    {
        $root = dirname(__DIR__, 2) . '/packages/com_memipilates/site/src';
        $view = (string) file_get_contents($root . '/View/Settings/HtmlView.php');
        $controller = (string) file_get_contents($root . '/Controller/SettingsController.php');

        self::assertStringContainsString("\$values['square_access_token'] = ''", $view);
        self::assertStringContainsString("\$values['square_webhook_signature_key'] = ''", $view);
        self::assertStringContainsString("trim((string) \$raw) === ''", $controller);
        self::assertStringContainsString('Session::checkToken', $controller);
        self::assertStringContainsString("authorise('core.admin'", $controller);
    }

    public function testFrontendSettingsRenderEveryWhitelistedInput(): void
    {
        $root = dirname(__DIR__, 2) . '/packages/com_memipilates/site';
        $template = (string) file_get_contents($root . '/tmpl/settings/default.php');
        $controller = (string) file_get_contents($root . '/src/Controller/SettingsController.php');
        $expected = [
            'timezone', 'cancellation_hours', 'direct_payment_hold_minutes',
            'session_generation_lookahead_days', 'currency', 'waitlist_promotion_mode',
            'waitlist_offer_minutes', 'waitlist_auto_promote', 'reminder_hours',
            'credit_expiry_notice_days', 'email_from_name', 'notification_max_attempts',
            'notification_retry_base_minutes', 'loyalty_enabled', 'points_per_attendance',
            'points_per_dollar', 'attendance_before_minutes', 'attendance_after_minutes',
            'kiosk_confirmation_seconds', 'kiosk_sound_enabled', 'square_environment',
            'square_application_id', 'square_location_id', 'square_access_token',
            'square_webhook_signature_key', 'square_webhook_url',
        ];

        foreach ($expected as $key) {
            self::assertStringContainsString('name="jform[' . $key . ']"', $template, $key);
            self::assertStringContainsString("'" . $key . "' =>", $controller, $key);
        }
    }
}
