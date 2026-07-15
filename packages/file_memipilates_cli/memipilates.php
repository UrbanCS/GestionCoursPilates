#!/usr/bin/env php
<?php
/**
 * Memi Pilates scheduled-work runner.
 *
 * Installed to Joomla's cli/ directory by the package file extension.
 * It deliberately emits only a compact aggregate result; secrets, tokens,
 * card data and raw exception messages stay out of cron output.
 */

declare(strict_types=1);

use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Session\SessionInterface;
use Memi\Component\Memipilates\Administrator\Service\ComponentServices;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only run from the command line.\n");
}

if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    fwrite(STDERR, "Memi Pilates requires PHP 8.1 or newer.\n");
    exit(2);
}

$options = getopt('', [
    'dry-run',
    'horizon-days:',
    'email-limit:',
    'skip-reminders',
    'help',
]);

if (isset($options['help'])) {
    echo "Usage: php cli/memipilates.php [--dry-run] [--horizon-days=60] [--email-limit=100] [--skip-reminders]\n";
    exit(0);
}

/**
 * @param mixed $value
 */
$positiveInteger = static function ($value, string $name, int $minimum, int $maximum): ?int {
    if ($value === false || $value === null) {
        return null;
    }

    if (!is_string($value) && !is_int($value)) {
        throw new InvalidArgumentException($name);
    }

    $filtered = filter_var((string) $value, FILTER_VALIDATE_INT);
    if ($filtered === false || $filtered < $minimum || $filtered > $maximum) {
        throw new InvalidArgumentException($name);
    }

    return (int) $filtered;
};

try {
    $horizon = $positiveInteger($options['horizon-days'] ?? null, 'horizon-days', 1, 365);
    $emailLimit = $positiveInteger($options['email-limit'] ?? null, 'email-limit', 1, 500);
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, "Invalid --{$error->getMessage()} value. See --help.\n");
    exit(2);
}

define('_JEXEC', 1);
define('JPATH_BASE', realpath(__DIR__ . '/..') ?: dirname(__DIR__));

require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';

try {
    $container = Factory::getContainer();
    $container->alias(SessionInterface::class, 'session.web.site');
    $application = $container->get(SiteApplication::class);
    Factory::$application = $application;
    $application->createExtensionNamespaceMap();

    $result = ComponentServices::scheduler()->runDueTasks([
        'dry_run' => isset($options['dry-run']),
        'horizon_days' => $horizon ?? 90,
        'email_limit' => $emailLimit ?? 100,
        'skip_reminders' => isset($options['skip-reminders']),
    ]);

    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    // Keep cron output safe. The Joomla logger gets the technical context.
    Log::add('Memi Pilates CLI scheduled work failed: ' . get_class($error), Log::ERROR, 'memipilates');
    fwrite(STDERR, "Memi Pilates scheduled work failed. Inspect the Joomla logs.\n");
    exit(1);
}
