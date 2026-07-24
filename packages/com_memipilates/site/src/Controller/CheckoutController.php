<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Site\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\BaseController;
use Memi\Component\Memipilates\Administrator\Service\ComponentServices;

final class CheckoutController extends BaseController
{
    use JsonControllerTrait;

    public function createOrder(): void
    {
        try {
            $this->requirePostToken();
            $input = Factory::getApplication()->input->post;
            $sessionId = $input->getInt('session_id');
            $result = $sessionId > 0
                ? ComponentServices::payments()->createSessionOrder($this->userId(), $sessionId)
                : ComponentServices::payments()->createPackageOrder(
                    $this->userId(),
                    $input->getInt('package_id'),
                    $input->getString('promotion_code', '') ?: null
                );
            $this->respond(['success' => true, 'data' => $result] + $result);
        } catch (\Throwable $error) {
            $this->respondError($error);
        }
    }

    public function pay(): void
    {
        try {
            $this->requirePostToken();
            $result = ComponentServices::payments()->payOrder(
                $this->userId(),
                Factory::getApplication()->input->post->getInt('order_id'),
                Factory::getApplication()->input->post->getString('source_id'),
                $this->idempotencyKey()
            );
            $this->respond(['success' => true, 'message' => \Joomla\CMS\Language\Text::_('COM_MEMIPILATES_PAYMENT_SUCCESS'), 'data' => $result] + $result);
        } catch (\Throwable $error) {
            $this->respondError($error);
        }
    }
}
