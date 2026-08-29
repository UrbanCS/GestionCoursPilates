<?php

declare(strict_types=1);

use MemiPwa\Api;
use MemiPwa\Repository;

/** @var \MemiPwa\Context $context */
$context = require dirname(__DIR__) . '/lib/bootstrap.php';

Api::handle(static function () use ($context): array {
    Api::requireMethod('POST');
    $userId = Api::requireUser($context);
    $context->assertCsrf();

    return (new Repository($context))->savePreferences($userId, Api::input());
});
