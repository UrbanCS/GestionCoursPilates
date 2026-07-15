<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Site\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\BaseController;
use Memi\Component\Memipilates\Administrator\Service\ComponentServices;

final class BookingController extends BaseController
{
    use JsonControllerTrait;

    public function reserve(): void
    {
        try {
            $this->requirePostToken();
            $userId = $this->userId();
            $sessionId = Factory::getApplication()->input->post->getInt('session_id');
            $result = ComponentServices::bookings()->reserve($userId, $sessionId, [
                'use_credit' => Factory::getApplication()->input->post->getInt('use_credit', 1) === 1,
                'source' => 'web',
            ]);
            $this->respond(['success' => true, 'message' => \Joomla\CMS\Language\Text::_('COM_MEMIPILATES_BOOKING_CONFIRMED'), 'data' => $result] + $result);
        } catch (\Throwable $error) {
            $this->respondError($error);
        }
    }

    public function cancel(): void
    {
        try {
            $this->requirePostToken();
            $userId = $this->userId();
            $result = ComponentServices::bookings()->cancel(
                $userId,
                Factory::getApplication()->input->post->getInt('booking_id'),
                $userId,
                Factory::getApplication()->input->post->getString('reason', '')
            );
            $this->respond(['success' => true, 'message' => \Joomla\CMS\Language\Text::_('COM_MEMIPILATES_BOOKING_CANCELLED'), 'data' => $result] + $result);
        } catch (\Throwable $error) {
            $this->respondError($error);
        }
    }

    public function joinWaitlist(): void
    {
        try {
            $this->requirePostToken();
            $userId = $this->userId();
            $result = ComponentServices::waitlist()->join($userId, Factory::getApplication()->input->post->getInt('session_id'));
            $this->respond(['success' => true, 'message' => \Joomla\CMS\Language\Text::_('COM_MEMIPILATES_BOOKING_WAITLIST_JOINED'), 'data' => $result] + $result);
        } catch (\Throwable $error) {
            $this->respondError($error);
        }
    }

    public function leaveWaitlist(): void
    {
        try {
            $this->requirePostToken();
            $userId = $this->userId();
            ComponentServices::waitlist()->leave($userId, Factory::getApplication()->input->post->getInt('waitlist_id'));
            $this->respond(['success' => true, 'message' => \Joomla\CMS\Language\Text::_('COM_MEMIPILATES_WAITLIST_LEFT')]);
        } catch (\Throwable $error) {
            $this->respondError($error);
        }
    }

    public function acceptWaitlist(): void
    {
        try {
            $this->requirePostToken();
            $userId = $this->userId();
            $result = ComponentServices::waitlist()->acceptOffer(
                $userId,
                Factory::getApplication()->input->post->getInt('waitlist_id'),
                Factory::getApplication()->input->post->getString('token')
            );
            $this->respond(['success' => true, 'message' => \Joomla\CMS\Language\Text::_('COM_MEMIPILATES_BOOKING_CONFIRMED'), 'data' => $result] + $result);
        } catch (\Throwable $error) {
            $this->respondError($error);
        }
    }
}
