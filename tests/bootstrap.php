<?php

declare(strict_types=1);

$autoloadCandidates = [
    dirname(__DIR__) . '/vendor/autoload.php',
    dirname(__DIR__) . '/packages/com_memipilates/vendor/autoload.php',
];

foreach ($autoloadCandidates as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
        break;
    }
}

spl_autoload_register(
    static function (string $class): void {
        $prefix = 'MemiPilates\\Tests\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
        $path = __DIR__ . DIRECTORY_SEPARATOR . $relative . '.php';

        if (is_file($path)) {
            require_once $path;
        }
    }
);

$timezone = getenv('MEMIPILATES_TEST_TIMEZONE') ?: 'America/Toronto';
date_default_timezone_set($timezone);
