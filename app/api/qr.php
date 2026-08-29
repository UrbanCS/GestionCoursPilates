<?php

declare(strict_types=1);

use MemiPwa\Api;
use Memi\Component\Memipilates\Administrator\Service\ComponentServices;

/** @var \MemiPwa\Context $context */
$context = require dirname(__DIR__) . '/lib/bootstrap.php';

Api::handle(static function () use ($context): array {
    Api::requireMethod('POST');
    $userId = Api::requireUser($context);
    $context->assertCsrf();

    $service = ComponentServices::qrTokens();
    $current = $service->current($userId, $userId);
    if ($current === null) {
        $current = $service->regenerate($userId, bin2hex(random_bytes(16)), $userId);
    }

    return ['token' => (string) $current['token']];
});
