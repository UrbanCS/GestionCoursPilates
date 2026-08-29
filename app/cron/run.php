<?php

declare(strict_types=1);

use MemiPwa\CronRunner;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    /** @var \MemiPwa\Context $context */
    $context = require dirname(__DIR__) . '/lib/bootstrap.php';
    $result = (new CronRunner($context))->run();
    fwrite(STDOUT, '[' . gmdate('c') . '] ' . json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[' . gmdate('c') . '] ' . get_debug_type($error) . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
