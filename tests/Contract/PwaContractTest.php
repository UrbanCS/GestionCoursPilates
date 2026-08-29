<?php

declare(strict_types=1);

namespace MemiPilates\Tests\Contract;

use PHPUnit\Framework\TestCase;

final class PwaContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2) . '/app';
    }

    public function testInstallableApplicationShellIsComplete(): void
    {
        foreach ([
            'index.html', 'offline.html', 'manifest.webmanifest', 'sw.js', '.htaccess',
            'assets/app.css', 'assets/app.js', 'assets/vendor/qrcode.min.js',
            'assets/icons/logo-memi.png', 'assets/icons/icon-192.png',
            'assets/icons/icon-512.png', 'assets/icons/icon-maskable-512.png',
        ] as $file) {
            self::assertFileExists($this->root . '/' . $file, $file);
        }

        $manifest = json_decode((string) file_get_contents($this->root . '/manifest.webmanifest'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('/app/', $manifest['start_url']);
        self::assertSame('/app/', $manifest['scope']);
        self::assertSame('standalone', $manifest['display']);
        self::assertCount(3, $manifest['icons']);
        self::assertSame([192, 192], array_slice(getimagesize($this->root . '/assets/icons/icon-192.png'), 0, 2));
        self::assertSame([512, 512], array_slice(getimagesize($this->root . '/assets/icons/icon-512.png'), 0, 2));
    }

    public function testAuthenticationAndMutationsAreProtected(): void
    {
        $context = (string) file_get_contents($this->root . '/lib/Context.php');
        self::assertStringContainsString("HTTP_X_MEMI_CSRF", $context);
        self::assertStringContainsString('hash_equals', $context);

        foreach (['logout', 'preferences', 'qr', 'subscription', 'test-push'] as $endpoint) {
            $source = (string) file_get_contents($this->root . '/api/' . $endpoint . '.php');
            self::assertStringContainsString('assertCsrf()', $source, $endpoint);
            self::assertStringContainsString('requireUser', $source, $endpoint);
        }
        $admin = (string) file_get_contents($this->root . '/api/announcement.php');
        self::assertStringContainsString('assertCsrf()', $admin);
        self::assertStringContainsString('requireAdministrator', $admin);
    }

    public function testPreferencesAreOptInAndWebPushIsDeduplicated(): void
    {
        $schema = (string) file_get_contents($this->root . '/lib/Schema.php');
        self::assertGreaterThanOrEqual(3, substr_count($schema, 'NOT NULL DEFAULT 0'));
        self::assertStringContainsString('UNIQUE KEY uq_memi_pwa_event_dedupe', $schema);
        self::assertStringContainsString('UNIQUE KEY uq_memi_pwa_delivery_event_subscription', $schema);
        self::assertStringContainsString("'installed_at'", $schema);

        $cron = (string) file_get_contents($this->root . '/lib/CronRunner.php');
        self::assertStringContainsString("hash('sha256', \$sourceType . ':' . \$sourceId)", $cron);
        self::assertStringContainsString("GET_LOCK('memi_pwa_cron'", $cron);
        self::assertStringContainsString('INSERT IGNORE INTO #__memi_pwa_deliveries', $cron);
        self::assertStringContainsString('p.notify_courses = 1', $cron);
        self::assertStringContainsString('p.notify_promotions = 1', $cron);
        self::assertStringContainsString('p.notify_other = 1', $cron);
    }

    public function testServiceWorkerNeverCachesAccountApis(): void
    {
        $worker = (string) file_get_contents($this->root . '/sw.js');
        self::assertStringContainsString("url.pathname.startsWith('/app/api/')", $worker);
        self::assertStringContainsString("request.method !== 'GET'", $worker);
        self::assertStringContainsString("addEventListener('push'", $worker);
        self::assertStringContainsString("addEventListener('notificationclick'", $worker);
    }

    public function testInstallationGuidanceMatchesTheDevice(): void
    {
        $index = (string) file_get_contents($this->root . '/index.html');
        self::assertStringContainsString('iPhone et iPad', $index);
        self::assertStringContainsString('<strong>Safari</strong>', $index);
        self::assertStringContainsString('Sur l’écran d’accueil', $index);
        self::assertSame(2, substr_count($index, '>Réservez</a>'));
        self::assertStringNotContainsString('>Réserver</a>', $index);
        self::assertStringContainsString('id="client-qr-image"', $index);
        self::assertStringContainsString('/app/assets/vendor/qrcode.min.js', $index);

        $script = (string) file_get_contents($this->root . '/assets/app.js');
        self::assertStringContainsString("iosInstall ? 'Comment installer' : 'Installer'", $script);
        self::assertStringContainsString("navigator.platform === 'MacIntel'", $script);
        self::assertStringContainsString("addEventListener('beforeinstallprompt'", $script);

        $styles = (string) file_get_contents($this->root . '/assets/app.css');
        self::assertStringContainsString('.header-actions .install-button', $styles);
        self::assertStringNotContainsString('.header-actions .button-quiet { display: none;', $styles);
        self::assertStringContainsString('text-transform: uppercase', $styles);
        self::assertStringNotContainsString('--mauve', $styles);
        self::assertStringNotContainsString('var(--mauve)', $styles);
    }

    public function testSensitiveServerDirectoriesAreNotWebAccessible(): void
    {
        $rules = (string) file_get_contents($this->root . '/.htaccess');
        self::assertStringContainsString('(?:lib|cron|vendor|tests)', $rules);
        self::assertStringContainsString('Require all denied', $rules);
        $state = (string) file_get_contents($this->root . '/lib/Repository.php');
        self::assertStringNotContainsString("'vapidPrivateKey'", $state);
        self::assertStringNotContainsString("'vapid_private_key' =>", $state);
    }
}
