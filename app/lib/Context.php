<?php

declare(strict_types=1);

namespace MemiPwa;

use Joomla\CMS\Application\SiteApplication;
use Joomla\Database\DatabaseInterface;

final class Context
{
    public function __construct(
        private readonly SiteApplication $application,
        private readonly DatabaseInterface $database
    ) {
    }

    public function application(): SiteApplication
    {
        return $this->application;
    }

    public function database(): DatabaseInterface
    {
        return $this->database;
    }

    public function identity(): object
    {
        return $this->application->getIdentity();
    }

    public function userId(): int
    {
        return max(0, (int) ($this->identity()->id ?? 0));
    }

    public function isAuthenticated(): bool
    {
        return $this->userId() > 0 && !(bool) ($this->identity()->guest ?? true);
    }

    public function isAdministrator(): bool
    {
        $identity = $this->identity();

        return $this->isAuthenticated()
            && ($identity->authorise('core.admin') || $identity->authorise('core.manage', 'com_memipilates'));
    }

    public function csrfToken(): string
    {
        $session = $this->application->getSession();
        $token = (string) $session->get('memi.pwa.csrf', '');
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            $token = bin2hex(random_bytes(32));
            $session->set('memi.pwa.csrf', $token);
        }

        return $token;
    }

    public function assertCsrf(): void
    {
        $provided = trim((string) ($_SERVER['HTTP_X_MEMI_CSRF'] ?? ''));
        if ($provided === '' || !hash_equals($this->csrfToken(), $provided)) {
            throw new HttpError('La session a expiré. Actualisez la page et réessayez.', 403, 'csrf_failed');
        }
    }
}
