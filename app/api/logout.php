<?php

declare(strict_types=1);

use MemiPwa\Api;

/** @var \MemiPwa\Context $context */
$context = require dirname(__DIR__) . '/lib/bootstrap.php';

Api::handle(static function () use ($context): array {
    Api::requireMethod('POST');
    $userId = Api::requireUser($context);
    $context->assertCsrf();
    $context->application()->logout($userId, ['clientid' => 0]);

    return ['authenticated' => false];
});
