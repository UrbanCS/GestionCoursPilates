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
use Joomla\Database\ParameterType;
use Memi\Component\Memipilates\Administrator\Service\ComponentServices;
use Memi\Component\Memipilates\Administrator\Service\DomainException;

/**
 * Dispatches the component's protected administrator views and the few
 * state-changing operations exposed by their list screens.
 */
final class DisplayController extends BaseController
{
    /** @var array<string, list<string>> */
    private const VIEW_PERMISSIONS = [
        'dashboard' => ['core.manage', 'reports.view'],
        'sessions' => ['core.manage', 'schedules.manage', 'courses.manage'],
        'bookings' => ['core.manage', 'bookings.manage', 'waitlist.manage'],
        'customers' => ['core.manage', 'clients.manage'],
        'packages' => ['core.manage', 'packages.manage'],
        'attendance' => ['core.manage', 'attendance.scan', 'attendance.manual', 'attendance.undo'],
    ];

    public function display($cachable = false, $urlparams = []): BaseController
    {
        $input = Factory::getApplication()->input;
        $view = $input->getCmd('view', 'dashboard');

        if (!isset(self::VIEW_PERMISSIONS[$view])) {
            $view = 'dashboard';
            $input->set('view', $view);
        }

        $this->requireAnyPermission(self::VIEW_PERMISSIONS[$view]);

        return parent::display($cachable, $urlparams);
    }

    public function cancelSession(): void
    {
        $this->processPost('sessions', ['core.edit', 'schedules.manage'], function (int $actorId): void {
            $id = Factory::getApplication()->input->post->getInt('id');
            if ($id <= 0) {
                throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
            }

            $reason = trim(substr(Factory::getApplication()->input->post->getString('reason', ''), 0, 500));
            ComponentServices::bookings()->cancelSession($id, $actorId, $reason !== '' ? $reason : null);
        });
    }

    public function cancelBooking(): void
    {
        $this->processPost('bookings', ['core.edit', 'bookings.manage'], function (int $actorId): void {
            $bookingId = Factory::getApplication()->input->post->getInt('id');
            if ($bookingId <= 0) {
                throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
            }

            $db = ComponentServices::database();
            $id = $bookingId;
            $query = $db->getQuery(true)
                ->select($db->quoteName('user_id'))
                ->from($db->quoteName('#__memi_bookings'))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $id, ParameterType::INTEGER);
            $db->setQuery($query);
            $userId = (int) $db->loadResult();

            if ($userId <= 0) {
                throw new DomainException('COM_MEMIPILATES_ERROR_NOT_FOUND', [], 404);
            }

            $reason = trim(substr(Factory::getApplication()->input->post->getString('reason', ''), 0, 500));
            ComponentServices::bookings()->cancel($userId, $bookingId, $actorId, $reason !== '' ? $reason : null);
        });
    }

    public function offerWaitlist(): void
    {
        $this->processPost('sessions', ['core.edit', 'waitlist.manage'], function (int $actorId): void {
            $sessionId = Factory::getApplication()->input->post->getInt('id');
            if ($sessionId <= 0) {
                throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
            }

            $offer = ComponentServices::waitlist()->offerNext($sessionId, $actorId, true);
            if ($offer === null) {
                throw new DomainException('COM_MEMIPILATES_ERROR_WAITLIST_NOT_ACTIVE');
            }
        });
    }

    public function manualCheckIn(): void
    {
        $this->processPost('attendance', ['core.edit', 'attendance.manual'], function (int $actorId): void {
            $bookingId = Factory::getApplication()->input->post->getInt('booking_id');
            if ($bookingId <= 0) {
                throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
            }

            $db = ComponentServices::database();
            $id = $bookingId;
            $confirmedStatus = 'confirmed';
            $query = $db->getQuery(true)
                ->select([$db->quoteName('user_id'), $db->quoteName('session_id')])
                ->from($db->quoteName('#__memi_bookings'))
                ->where($db->quoteName('id') . ' = :id')
                ->where($db->quoteName('status') . ' = :status')
                ->bind(':id', $id, ParameterType::INTEGER)
                ->bind(':status', $confirmedStatus);
            $db->setQuery($query);
            $booking = $db->loadAssoc();

            if (!$booking) {
                throw new DomainException('COM_MEMIPILATES_ERROR_NOT_FOUND', [], 404);
            }

            ComponentServices::attendance()->manualCheckIn(
                $actorId,
                (int) $booking['session_id'],
                (int) $booking['user_id'],
                bin2hex(random_bytes(16))
            );
        });
    }

    public function undoAttendance(): void
    {
        $this->processPost('attendance', ['core.edit', 'attendance.undo'], function (int $actorId): void {
            $id = Factory::getApplication()->input->post->getInt('id');
            if ($id <= 0) {
                throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
            }

            $reason = trim(substr(Factory::getApplication()->input->post->getString('reason', ''), 0, 500));
            ComponentServices::attendance()->undo($actorId, $id, $reason !== '' ? $reason : null);
        });
    }

    /**
     * @param list<string> $permissions
     * @param callable(int):void $operation
     */
    private function processPost(string $view, array $permissions, callable $operation): void
    {
        if (!Session::checkToken('post')) {
            $this->setMessage(Text::_('JINVALID_TOKEN'), 'error');
            $this->redirectTo($view);

            return;
        }

        try {
            $actorId = $this->requireAnyPermission($permissions);
            $operation($actorId);
            $this->setMessage(Text::_('JLIB_APPLICATION_SAVE_SUCCESS'), 'message');
        } catch (DomainException $error) {
            $this->setMessage($this->safeDomainMessage($error), 'error');
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

    private function safeDomainMessage(DomainException $error): string
    {
        $translated = Text::_($error->getMessage());

        return $translated === $error->getMessage()
            ? Text::_('JERROR_AN_ERROR_HAS_OCCURRED')
            : $translated;
    }

    private function redirectTo(string $view): void
    {
        $this->setRedirect(Route::_('index.php?option=com_memipilates&view=' . $view, false));
    }
}
