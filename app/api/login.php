<?php

declare(strict_types=1);

use MemiPwa\Api;
use MemiPwa\HttpError;
use MemiPwa\Repository;

/** @var \MemiPwa\Context $context */
$context = require dirname(__DIR__) . '/lib/bootstrap.php';

Api::handle(static function () use ($context): array {
    Api::requireMethod('POST');
    $context->assertCsrf();
    $input = Api::input();
    $username = Api::text($input['username'] ?? '', 320);
    $password = (string) ($input['password'] ?? '');
    if ($username === '' || $password === '') {
        throw new HttpError('Entrez votre courriel et votre mot de passe.', 422, 'credentials_required');
    }

    $ok = $context->application()->login(
        ['username' => $username, 'password' => $password],
        ['remember' => !empty($input['remember']), 'silent' => true]
    );
    if (!$ok) {
        usleep(350000);
        throw new HttpError('Le courriel ou le mot de passe est incorrect.', 401, 'login_failed');
    }

    return (new Repository($context))->state();
});
