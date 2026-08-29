<?php

declare(strict_types=1);

use MemiPwa\Api;
use MemiPwa\HttpError;
use MemiPwa\PushService;

/** @var \MemiPwa\Context $context */
$context = require dirname(__DIR__) . '/lib/bootstrap.php';

Api::handle(static function () use ($context): array {
    Api::requireMethod('POST');
    $userId = Api::requireUser($context);
    $context->assertCsrf();
    $db = $context->database();
    $query = $db->getQuery(true)
        ->select(['id AS subscription_id', 'endpoint', 'public_key', 'auth_token', 'content_encoding'])
        ->from($db->quoteName('#__memi_pwa_push_subscriptions'))
        ->where($db->quoteName('user_id') . ' = ' . $userId)
        ->where($db->quoteName('enabled') . ' = 1')
        ->order($db->quoteName('updated_at') . ' DESC');
    $db->setQuery($query, 0, 1);
    $subscription = $db->loadAssoc();
    if (!$subscription) {
        throw new HttpError('Activez d’abord les notifications sur cet appareil.', 422, 'subscription_required');
    }
    $subscription += [
        'delivery_id' => 1,
        'event_id' => 0,
        'category' => 'other',
        'title' => 'Notifications Memi activées',
        'body' => 'Parfait — cet appareil peut maintenant recevoir vos nouvelles Memi.',
        'target_url' => '/app/',
    ];
    $result = (new PushService($db))->send([$subscription]);
    if (empty($result[1]['success'])) {
        throw new HttpError('Le service de notification n’a pas confirmé l’envoi. Réessayez.', 502, 'push_test_failed');
    }

    return ['sent' => true];
});
