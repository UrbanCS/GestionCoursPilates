<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Site\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Session\Session;
use Memi\Component\Memipilates\Administrator\Service\DomainException;

/** Shared CSRF, identity and JSON response handling for public task routes. */
trait JsonControllerTrait
{
    protected function requirePostToken(): void
    {
        if (!Session::checkToken('post')) {
            throw new DomainException('JINVALID_TOKEN', [], 403);
        }
    }

    protected function userId(): int
    {
        $identity = Factory::getApplication()->getIdentity();
        $id = (int) ($identity->id ?? 0);
        if ($id <= 0) {
            throw new DomainException('COM_MEMIPILATES_ERROR_LOGIN_REQUIRED', [], 401);
        }

        return $id;
    }

    protected function requirePermission(string $action): int
    {
        $identity = Factory::getApplication()->getIdentity();
        if (!(bool) $identity->authorise($action, 'com_memipilates')) {
            throw new DomainException('COM_MEMIPILATES_ERROR_FORBIDDEN', [], 403);
        }

        return (int) $identity->id;
    }

    /** @param array<string, mixed> $payload */
    protected function respond(array $payload, int $status = 200): never
    {
        $app = Factory::getApplication();
        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $app->setHeader('Status', (string) $status, true);
        echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $app->close();
    }

    protected function respondError(\Throwable $error): never
    {
        $status = $error instanceof DomainException ? max(400, min(599, $error->getCode() ?: 400)) : 500;
        $message = $error instanceof DomainException ? $error->getMessage() : 'COM_MEMIPILATES_ERROR_UNEXPECTED';
        $this->respond([
            'success' => false,
            'status' => 'error',
            'message' => \Joomla\CMS\Language\Text::_($message),
        ], $status);
    }

    protected function idempotencyKey(): string
    {
        $key = Factory::getApplication()->input->post->getString('idempotency_key', '');
        if (!preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $key)) {
            throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
        }

        return $key;
    }
}
