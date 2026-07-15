<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Site\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\Database\ParameterType;
use Memi\Component\Memipilates\Administrator\Service\ComponentServices;
use Memi\Component\Memipilates\Administrator\Service\DomainException;

/** Server endpoints for the protected Mac/desktop attendance kiosk. */
final class KioskController extends BaseController
{
    use JsonControllerTrait;

    public function scan(): void
    {
        try {
            $this->requirePostToken();
            $actorId = $this->requirePermission('attendance.kiosk');
            $this->requirePermission('attendance.scan');
            $input = Factory::getApplication()->input;
            $override = $input->post->getInt('override', 0) === 1;
            if ($override) {
                $this->requirePermission('attendance.override');
            }
            $result = ComponentServices::attendance()->scan(
                $actorId,
                $input->post->getInt('session_id'),
                $input->post->getString('token'),
                $input->post->getCmd('method', 'hid'),
                $this->idempotencyKey(),
                $override,
                $input->post->getString('override_reason', '') ?: null
            );
            $message = $result['status'] === 'already_registered'
                ? \Joomla\CMS\Language\Text::_('COM_MEMIPILATES_KIOSK_SCAN_ALREADY_CONFIRMED')
                : \Joomla\CMS\Language\Text::sprintf('COM_MEMIPILATES_KIOSK_SCAN_SUCCESS', $result['client']);
            $this->respond(['success' => true, 'message' => $message, 'data' => $result] + $result);
        } catch (\Throwable $error) {
            $this->respondError($error);
        }
    }

    public function test(): void
    {
        try {
            $this->requirePostToken();
            $this->requirePermission('attendance.kiosk');
            $this->requirePermission('kiosk.test');
            $input = Factory::getApplication()->input;
            $result = ComponentServices::attendance()->testInput(
                $input->post->getString('candidate'),
                $input->post->getInt('duration_ms'),
                $input->post->getInt('enter_detected') === 1,
                $input->post->getInt('has_focus') === 1
            );
            // Do not return, log, or persist the candidate/token in test mode.
            $this->respond(['success' => true, 'data' => $result] + $result);
        } catch (\Throwable $error) {
            $this->respondError($error);
        }
    }

    public function search(): void
    {
        try {
            $this->requirePostToken();
            $this->requirePermission('attendance.kiosk');
            $this->requirePermission('attendance.manual');
            $queryText = trim(Factory::getApplication()->input->post->getString('query'));
            if (mb_strlen($queryText) < 2) {
                throw new DomainException('COM_MEMIPILATES_ERROR_SEARCH_TOO_SHORT');
            }
            $sessionId = Factory::getApplication()->input->post->getInt('session_id');
            if ($sessionId <= 0) {
                throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
            }
            $db = ComponentServices::database();
            $term = '%' . $queryText . '%';
            $session = $sessionId;
            $query = $db->getQuery(true)
                ->select(['u.id', 'u.name'])
                ->from($db->quoteName('#__users', 'u'))
                ->join('INNER', $db->quoteName('#__memi_client_profiles', 'cp') . ' ON cp.user_id = u.id')
                ->join('INNER', $db->quoteName('#__memi_bookings', 'b') . ' ON b.user_id = u.id')
                ->where('(u.name LIKE :term OR u.email LIKE :term)')
                ->where('cp.archived_at IS NULL')
                ->where('u.block = 0')
                ->where('b.session_id = :session_id')
                ->where('b.status IN (' . $db->quote('confirmed') . ', ' . $db->quote('pending') . ')')
                ->order('u.name ASC')
                ->bind(':term', $term)
                ->bind(':session_id', $session, ParameterType::INTEGER);
            $db->setQuery($query, 0, 20);
            $rows = $db->loadAssocList() ?: [];
            $this->respond(['success' => true, 'data' => $rows, 'results' => $rows]);
        } catch (\Throwable $error) {
            $this->respondError($error);
        }
    }

    public function manual(): void
    {
        try {
            $this->requirePostToken();
            $actorId = $this->requirePermission('attendance.kiosk');
            $this->requirePermission('attendance.manual');
            $input = Factory::getApplication()->input;
            $userId = $input->post->getInt('user_id');
            if ($userId <= 0) {
                throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
            }
            $override = $input->post->getInt('override', 0) === 1;
            if ($override) {
                $this->requirePermission('attendance.override');
            }
            $result = ComponentServices::attendance()->manualCheckIn(
                $actorId,
                $input->post->getInt('session_id'),
                $userId,
                $this->idempotencyKey(),
                $override,
                $input->post->getString('override_reason', '') ?: null
            );
            $this->respond(['success' => true, 'message' => \Joomla\CMS\Language\Text::sprintf('COM_MEMIPILATES_KIOSK_SCAN_SUCCESS', $result['client']), 'data' => $result] + $result);
        } catch (\Throwable $error) {
            $this->respondError($error);
        }
    }
}
