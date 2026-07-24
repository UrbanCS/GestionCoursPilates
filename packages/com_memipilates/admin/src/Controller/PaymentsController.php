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
use Memi\Component\Memipilates\Administrator\Service\ComponentServices;
use Memi\Component\Memipilates\Administrator\Service\DomainException;

/** Protected administrator actions for Square payments. */
class PaymentsController extends BaseController
{
    public function refund(): void
    {
        if (!Session::checkToken('post')) {
            $this->setMessage(Text::_('JINVALID_TOKEN'), 'error');
            $this->redirectToPayments();

            return;
        }

        try {
            $identity = Factory::getApplication()->getIdentity();
            if (!(bool) $identity->authorise('core.admin', 'com_memipilates')
                && !(bool) $identity->authorise('payments.refund', 'com_memipilates')) {
                throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
            }
            $input = Factory::getApplication()->input->post;
            ComponentServices::payments()->refundPayment(
                (int) ($identity->id ?? 0),
                $input->getInt('payment_id'),
                $input->getString('reason', '')
            );
            $this->setMessage(Text::_('COM_MEMIPILATES_REFUND_REQUEST_SUCCESS'), 'message');
        } catch (DomainException $error) {
            $message = Text::_($error->getMessage());
            $this->setMessage($message === $error->getMessage() ? Text::_('JERROR_AN_ERROR_HAS_OCCURRED') : $message, 'error');
        } catch (\Throwable $error) {
            $this->setMessage($error->getCode() === 403 ? Text::_('JERROR_ALERTNOAUTHOR') : Text::_('JERROR_AN_ERROR_HAS_OCCURRED'), 'error');
        }

        $this->redirectToPayments();
    }

    private function redirectToPayments(): void
    {
        $this->setRedirect(Route::_('index.php?option=com_memipilates&view=payments', false));
    }
}
