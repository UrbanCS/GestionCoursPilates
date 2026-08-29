<?php

declare(strict_types=1);

use MemiPwa\Api;
use MemiPwa\HttpError;
use MemiPwa\Repository;

/** @var \MemiPwa\Context $context */
$context = require dirname(__DIR__) . '/lib/bootstrap.php';

Api::handle(static function () use ($context): array {
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['POST', 'DELETE'], true)) {
        throw new HttpError('Méthode non permise.', 405, 'method_not_allowed');
    }
    $userId = Api::requireUser($context);
    $context->assertCsrf();
    $input = Api::input();
    $repository = new Repository($context);
    if ($method === 'DELETE') {
        $endpoint = trim((string) ($input['endpoint'] ?? ''));
        if ($endpoint !== '') {
            $repository->removeSubscription($userId, $endpoint);
        }

        return ['subscribed' => false];
    }

    $subscription = isset($input['subscription']) && is_array($input['subscription'])
        ? $input['subscription']
        : $input;
    $repository->saveSubscription($userId, $subscription);

    return ['subscribed' => true];
});
