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

    public function testPortalMenuLinksAuthorizedStaffToTheAttendanceKiosk(): void
    {
        $template = (string) file_get_contents(
            dirname(__DIR__, 2) . '/packages/com_memipilates/site/tmpl/portal/start.php'
        );

        self::assertStringContainsString("'view' => 'kiosk'", $template);
        self::assertStringContainsString("'label' => 'COM_MEMIPILATES_KIOSK_TITLE'", $template);
        self::assertStringContainsString("'permission' => 'attendance.kiosk'", $template);
        self::assertStringContainsString(
            "authorise(\$item['permission'], 'com_memipilates')",
            $template
        );
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
            'session_generation_lookahead_days', 'currency', 'tax_rate_percent', 'waitlist_promotion_mode',
            'waitlist_offer_minutes', 'waitlist_auto_promote', 'reminder_hours',
            'credit_expiry_notice_days', 'email_from_name', 'notification_max_attempts',
            'notification_retry_base_minutes', 'loyalty_enabled',
            'attendance_before_minutes', 'attendance_after_minutes',
            'kiosk_confirmation_seconds', 'kiosk_sound_enabled', 'square_environment',
            'square_application_id', 'square_location_id', 'square_access_token',
            'square_webhook_signature_key', 'square_webhook_url',
        ];

        foreach ($expected as $key) {
            self::assertStringContainsString('name="jform[' . $key . ']"', $template, $key);
            self::assertStringContainsString("'" . $key . "' =>", $controller, $key);
        }

        self::assertStringNotContainsString('name="jform[points_per_attendance]"', $template);
        self::assertStringNotContainsString('name="jform[points_per_dollar]"', $template);
        self::assertStringContainsString('COM_MEMIPILATES_CONFIG_LOYALTY_ATTENDANCE_ONLY', $template);
    }

    public function testPackageCheckoutUsesResponsiveCardsAndLimitsAnExplicitSelection(): void
    {
        $component = dirname(__DIR__, 2) . '/packages/com_memipilates';
        $template = (string) file_get_contents($component . '/site/tmpl/checkout/default.php');
        $view = (string) file_get_contents($component . '/site/src/View/Checkout/HtmlView.php');
        $script = (string) file_get_contents($component . '/media/js/checkout.js');

        self::assertStringContainsString('class="memi-package-picker"', $template);
        self::assertStringContainsString('data-memi-package-choice', $template);
        self::assertStringContainsString('data-memi-package-buy', $template);
        self::assertStringContainsString("\$package['price_cents']", $template);
        self::assertStringContainsString('COM_MEMIPILATES_PAYMENT_TAXES_EXTRA', $template);
        self::assertStringContainsString('data-memi-package-totals', $template);
        self::assertStringNotContainsString("\$package['total_with_tax_cents']", $template);
        self::assertStringNotContainsString('data-memi-package-select', $template);
        self::assertStringContainsString("getInt('package_id', 0)", $view);
        self::assertStringContainsString("\$return .= '&package_id=' . \$this->selectedPackageId", $view);
        self::assertStringContainsString('$selectedPackages = array_values(array_filter(', $view);
        self::assertStringContainsString('$this->packages = $selectedPackages;', $view);
        self::assertStringContainsString("'[data-memi-package-choice]:checked'", $script);
        self::assertStringContainsString("order.tax_cents", $script);
    }

    public function testLoyaltyPointsAreAwardedOnlyForConfirmedAttendance(): void
    {
        $component = dirname(__DIR__, 2) . '/packages/com_memipilates/admin/src/Service';
        $payments = (string) file_get_contents($component . '/PaymentService.php');
        $attendance = (string) file_get_contents($component . '/AttendanceService.php');

        self::assertStringNotContainsString('$this->points->award', $payments);
        self::assertGreaterThanOrEqual(2, substr_count($attendance, '$pointsAdded = 1;'));
    }

    public function testDirectSessionPaymentsCreateRestorableReservedCredits(): void
    {
        $component = dirname(__DIR__, 2) . '/packages/com_memipilates';
        $payments = (string) file_get_contents($component . '/admin/src/Service/PaymentService.php');
        $credits = (string) file_get_contents($component . '/admin/src/Service/CreditLedgerService.php');
        $dashboardView = (string) file_get_contents($component . '/site/src/View/Dashboard/HtmlView.php');
        $dashboardTemplate = (string) file_get_contents($component . '/site/tmpl/dashboard/default.php');
        $migration = (string) file_get_contents($component . '/admin/sql/updates/mysql/1.8.14.sql');

        self::assertStringContainsString('createDirectSessionAllocation', $payments);
        self::assertStringContainsString("'direct_purchase'", $payments);
        self::assertStringContainsString('consumeAllocationForBooking', $payments);
        self::assertStringContainsString('$credits = 1;', $payments);
        self::assertStringContainsString('public function consumeAllocationForBooking', $credits);
        self::assertStringContainsString('$this->reservedCreditBalance = $this->loadReservedCreditBalance();', $dashboardView);
        self::assertStringContainsString('$this->totalCreditBalance = $this->creditBalance + $this->reservedCreditBalance;', $dashboardView);
        self::assertStringContainsString('COM_MEMIPILATES_ACCOUNT_CREDIT_BREAKDOWN', $dashboardTemplate);
        self::assertStringContainsString("b.source = 'square_direct'", $migration);
        self::assertStringContainsString('AND p2.credits = 1', $migration);
        self::assertStringContainsString("'booking_use'", $migration);
    }

    public function testFrontendStudioPortalUsesTheMemiStudioBrand(): void
    {
        $template = (string) file_get_contents(
            dirname(__DIR__, 2) . '/packages/com_memipilates/site/tmpl/portal/start.php'
        );

        self::assertStringContainsString("Text::_('COM_MEMIPILATES_STUDIO_NAME')", $template);
        self::assertStringNotContainsString("Text::_('COM_MEMIPILATES')", $template);
    }

    public function testTaxesUseOnePreciseStudioSettingForOrdersAndFrontendTotals(): void
    {
        $component = dirname(__DIR__, 2) . '/packages/com_memipilates';
        $settings = (string) file_get_contents($component . '/admin/src/Service/SettingsService.php');
        $payments = (string) file_get_contents($component . '/admin/src/Service/PaymentService.php');
        $booking = (string) file_get_contents($component . '/site/src/View/Booking/HtmlView.php');
        $checkout = (string) file_get_contents($component . '/site/src/View/Checkout/HtmlView.php');
        $catalogue = (string) file_get_contents($component . '/admin/src/Service/CatalogManagementService.php');
        $configuration = (string) file_get_contents($component . '/admin/config.xml');

        self::assertStringContainsString('name="tax_rate_percent"', $configuration);
        self::assertStringContainsString('default="14.975"', $configuration);
        self::assertStringContainsString('taxRateThousandthsPercent', $settings);
        self::assertStringContainsString('calculateTaxCents', $settings);
        self::assertStringContainsString('intdiv(', $settings);
        self::assertSame(2, substr_count($payments, '$this->settings->calculateTaxCents('));
        self::assertStringNotContainsString('$taxRateBasisPoints', $payments);
        self::assertStringContainsString('$this->directPaymentSubtotalCents = $priceCents;', $booking);
        self::assertStringNotContainsString('calculateTaxCents($priceCents)', $booking);
        self::assertStringContainsString('calculateTaxCents($this->sessionSubtotalCents)', $checkout);
        self::assertStringContainsString("\$values['tax_rate_basis_points'] = (int) \$before['tax_rate_basis_points']", $catalogue);
    }

    public function testCheckoutAllowsPackagePurchasesWithoutASessionId(): void
    {
        $checkout = (string) file_get_contents(
            dirname(__DIR__, 2) . '/packages/com_memipilates/site/src/View/Checkout/HtmlView.php'
        );

        self::assertStringContainsString("getInt('session_id', 0)", $checkout);
        self::assertStringNotContainsString("getInt('session_id');", $checkout);
        self::assertStringContainsString(
            "Text::_('COM_MEMIPILATES_ERROR_DIRECT_PAYMENT_UNAVAILABLE')",
            $checkout
        );
    }

    public function testKioskReaderTestRendersEverySafeDiagnostic(): void
    {
        $template = (string) file_get_contents(
            dirname(__DIR__, 2) . '/packages/com_memipilates/site/tmpl/kiosk/default.php'
        );

        self::assertStringContainsString('data-memi-kiosk-test-panel', $template);
        foreach (['received', 'chars', 'length', 'enter', 'duration', 'format', 'focus', 'transport'] as $field) {
            self::assertStringContainsString('data-memi-kiosk-test-field="' . $field . '"', $template, $field);
        }
        self::assertStringNotContainsString('data-memi-kiosk-test-field="token"', $template);
    }

    public function testKioskUsesACompactTitleAndProminentVisualScanResult(): void
    {
        $component = dirname(__DIR__, 2) . '/packages/com_memipilates';
        $template = (string) file_get_contents($component . '/site/tmpl/kiosk/default.php');
        $styles = (string) file_get_contents($component . '/media/css/kiosk.css');
        $script = (string) file_get_contents($component . '/media/js/kiosk.js');

        self::assertStringContainsString('data-memi-kiosk-header', $template);
        self::assertStringContainsString('data-memi-kiosk-result aria-live="assertive" hidden', $template);
        self::assertStringContainsString('[data-memi-kiosk-header] h1', $styles);
        self::assertStringContainsString('[data-memi-kiosk-result]:not([hidden])', $styles);
        self::assertStringContainsString('position: fixed;', $styles);
        self::assertStringContainsString("setAttribute('aria-live', 'assertive')", $script);
    }

    public function testKioskAutoSubmitsReaderInputWithoutAnEnterSuffix(): void
    {
        $script = (string) file_get_contents(
            dirname(__DIR__, 2) . '/packages/com_memipilates/media/js/kiosk.js'
        );

        self::assertStringContainsString('autoSubmitDelayMs: 350', $script);
        self::assertStringContainsString('this.state.inputTimer = window.setTimeout', $script);
        self::assertStringContainsString('this.input.value !== capturedValue', $script);
        self::assertStringContainsString('this.clearInputTimer();', $script);
    }

    public function testKioskRequiresASelectableSessionAndExplainsAnEmptyList(): void
    {
        $component = dirname(__DIR__, 2) . '/packages/com_memipilates/site';
        $template = (string) file_get_contents($component . '/tmpl/kiosk/default.php');
        $french = (string) file_get_contents($component . '/language/fr-FR/com_memipilates.ini');

        self::assertStringContainsString('data-require-session="true"', $template);
        self::assertStringContainsString('COM_MEMIPILATES_KIOSK_NO_SESSIONS', $template);
        self::assertStringContainsString('jusqu’à 12 heures avant leur début', $french);
    }

    public function testQueuedEmailsAlwaysRenderInFrench(): void
    {
        $service = (string) file_get_contents(
            dirname(__DIR__, 2) . '/packages/com_memipilates/admin/src/Service/NotificationService.php'
        );
        $translations = (string) file_get_contents(
            dirname(__DIR__, 2) . '/packages/com_memipilates/admin/language/fr-FR/com_memipilates.ini'
        );

        self::assertStringContainsString("private const EMAIL_LANGUAGE_TAG = 'fr-FR';", $service);
        self::assertStringContainsString(
            "load('com_memipilates', JPATH_ADMINISTRATOR, self::EMAIL_LANGUAGE_TAG, true)",
            $service
        );
        self::assertStringContainsString('html lang="\' . self::EMAIL_LANGUAGE_TAG . \'"', $service);
        self::assertStringContainsString("format('d/m/Y à H:i')", $service);
        self::assertStringContainsString(
            'COM_MEMIPILATES_EMAIL_BOOKING_SUBJECT="Votre réservation Memi Pilates"',
            $translations
        );
        self::assertStringContainsString(
            'COM_MEMIPILATES_EMAIL_BOOKING_CONFIRMED_BODY="Votre réservation est confirmée."',
            $translations
        );
    }

    public function testLoginReturnsToTheSelectedBookingAndCheckout(): void
    {
        $component = dirname(__DIR__, 2) . '/packages/com_memipilates/site';
        $booking = (string) file_get_contents($component . '/tmpl/booking/default.php');
        $checkout = (string) file_get_contents($component . '/src/View/Checkout/HtmlView.php');

        self::assertStringContainsString(
            "'index.php?option=com_memipilates&view=booking&session_id='",
            $booking
        );
        self::assertStringContainsString("'index.php?option=com_users&view=login&return='", $booking);
        self::assertStringContainsString('rawurlencode($loginReturn)', $booking);
        self::assertStringContainsString("getInt('session_id', 0)", $checkout);
        self::assertStringContainsString("\$return .= '&session_id=' . \$sessionId", $checkout);
        self::assertStringContainsString("'index.php?option=com_users&view=login&return='", $checkout);
    }

    public function testDashboardProvidesASecureLogoutAction(): void
    {
        $dashboard = (string) file_get_contents(
            dirname(__DIR__, 2) . '/packages/com_memipilates/site/tmpl/dashboard/default.php'
        );

        self::assertStringContainsString(
            "Route::_('index.php?option=com_users&task=user.logout')",
            $dashboard
        );
        self::assertStringContainsString('class="memi-dashboard__logout-form"', $dashboard);
        self::assertStringContainsString('method="post"', $dashboard);
        self::assertStringContainsString('name="return"', $dashboard);
        self::assertStringContainsString("Text::_('JLOGOUT')", $dashboard);
        self::assertSame(2, substr_count($dashboard, "HTMLHelper::_('form.token')"));
    }

    public function testSingleStudioBackendHidesLocationManagement(): void
    {
        $component = dirname(__DIR__, 2) . '/packages/com_memipilates/admin';
        $catalogueView = (string) file_get_contents($component . '/src/View/Catalog/HtmlView.php');
        $catalogueTemplate = (string) file_get_contents($component . '/tmpl/catalog/default.php');
        $setupView = (string) file_get_contents($component . '/src/View/Setup/HtmlView.php');
        $setupTemplate = (string) file_get_contents($component . '/tmpl/setup/default.php');

        self::assertStringNotContainsString("'location' => 'Emplacements'", $catalogueView);
        self::assertStringNotContainsString('Emplacement *', $catalogueTemplate);
        self::assertStringContainsString('type="hidden" name="location_id"', $catalogueTemplate);
        self::assertStringContainsString('<input type="hidden" name="published" value="0">', $catalogueTemplate);
        self::assertStringNotContainsString('location_title', $setupView);
        self::assertStringNotContainsString("\$room['location_title']", $setupTemplate);
        self::assertStringContainsString('type="hidden" name="location_id"', $setupTemplate);
    }
}
