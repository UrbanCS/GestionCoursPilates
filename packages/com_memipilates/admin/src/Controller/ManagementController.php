<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Administrator\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Memi\Component\Memipilates\Administrator\Service\ClientManagementService;
use Memi\Component\Memipilates\Administrator\Service\ComponentServices;
use Memi\Component\Memipilates\Administrator\Service\DomainException;

/**
 * Protected administrator-only actions for client creation and manual
 * bookings. It intentionally has its own controller to keep catalogue and
 * display actions independent.
 */
final class ManagementController extends BaseController
{
    public function createClient(): void
    {
        $this->processPost('customers', ['clients.manage'], function (int $actorId): void {
            $input = Factory::getApplication()->input;
            $service = new ClientManagementService(
                ComponentServices::database(),
                ComponentServices::databaseTools(),
                ComponentServices::audit()
            );
            $service->createClient($input->post, $actorId);
        });
    }

    public function regenerateQr(): void
    {
        $this->processPost('customers', ['qr.manage'], function (int $actorId): void {
            $input = Factory::getApplication()->input;
            $userId = $input->post->getInt('user_id');
            $idempotencyKey = $input->post->getString('idempotency_key', '');
            $this->assertActiveClient($userId);
            ComponentServices::qrTokens()->regenerate($userId, $idempotencyKey, $actorId);
        });
    }

    public function revokeQr(): void
    {
        $this->processPost('customers', ['qr.manage'], function (int $actorId): void {
            $input = Factory::getApplication()->input;
            $userId = $input->post->getInt('user_id');
            $reason = trim(mb_substr($input->post->getString('reason', ''), 0, 255));
            if ($userId <= 0) {
                throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
            }
            ComponentServices::qrTokens()->revokeForUser(
                $userId,
                $actorId,
                $reason !== '' ? $reason : 'administrative_revocation'
            );
        });
    }

    public function reserveClient(): void
    {
        $this->processPost('bookings', ['bookings.manage', 'bookings.manual'], function (int $actorId): void {
            $input = Factory::getApplication()->input;
            $userId = $input->post->getInt('user_id');
            $sessionId = $input->post->getInt('session_id');
            $mode = $input->post->getCmd('booking_mode', 'credit');
            $note = trim(mb_substr($input->post->getString('note', ''), 0, 500));

            if ($userId <= 0 || $sessionId <= 0 || !in_array($mode, ['credit', 'complimentary'], true)) {
                throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
            }

            $identity = Factory::getApplication()->getIdentity();
            if (!(bool) $identity->authorise('core.admin', 'com_memipilates')
                && !(bool) $identity->authorise('bookings.manage', 'com_memipilates')) {
                ComponentServices::staffScope()->assertAssignedSession($actorId, $sessionId);
            }

            $clients = new ClientManagementService(
                ComponentServices::database(),
                ComponentServices::databaseTools(),
                ComponentServices::audit()
            );
            $clients->assertActiveClient($userId);

            ComponentServices::bookings()->reserve($userId, $sessionId, [
                'use_credit' => $mode === 'credit',
                'allow_comp' => $mode === 'complimentary',
                'actor_user_id' => $actorId,
                'source' => 'admin',
                'note' => $note !== '' ? $note : null,
            ]);
        });
    }

    /** @param list<string> $permissions */
    private function processPost(string $view, array $permissions, callable $operation): void
    {
        if (!Session::checkToken('post')) {
            $this->setMessage(Text::_('JINVALID_TOKEN'), 'error');
            $this->redirectTo($view);

            return;
        }

        try {
            $operation($this->requireAnyPermission($permissions));
            $this->setMessage(Text::_('JLIB_APPLICATION_SAVE_SUCCESS'), 'message');
        } catch (DomainException $error) {
            $message = Text::_($error->getMessage());
            $this->setMessage($message === $error->getMessage() ? Text::_('JERROR_AN_ERROR_HAS_OCCURRED') : $message, 'error');
        } catch (\Throwable) {
            $this->setMessage(Text::_('JERROR_AN_ERROR_HAS_OCCURRED'), 'error');
        }

        $this->redirectTo($view);
    }

    /** @param list<string> $permissions */
    private function requireAnyPermission(array $permissions): int
    {
        $identity = Factory::getApplication()->getIdentity();
        foreach (array_unique(array_merge(['core.admin'], $permissions)) as $permission) {
            if ((bool) $identity->authorise($permission, 'com_memipilates')) {
                return (int) ($identity->id ?? 0);
            }
        }

        throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
    }

    private function assertActiveClient(int $userId): void
    {
        $clients = new ClientManagementService(
            ComponentServices::database(),
            ComponentServices::databaseTools(),
            ComponentServices::audit()
        );
        $clients->assertActiveClient($userId);
    }

    private function redirectTo(string $view): void
    {
        $this->setRedirect(Route::_('index.php?option=com_memipilates&view=' . $view, false));
    }
}
