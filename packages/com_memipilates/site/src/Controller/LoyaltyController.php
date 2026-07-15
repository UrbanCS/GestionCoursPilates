<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Site\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Memi\Component\Memipilates\Administrator\Service\ComponentServices;

/** Client-facing, CSRF-protected loyalty reward redemption endpoint. */
final class LoyaltyController extends BaseController
{
    use JsonControllerTrait;

    public function redeem(): void
    {
        try {
            $this->requirePostToken();
            $userId = $this->userId();
            $result = ComponentServices::loyalty()->redeem(
                $userId,
                Factory::getApplication()->input->post->getInt('reward_id'),
                $this->idempotencyKey(),
                $userId
            );
            $this->respond([
                'success' => true,
                'message' => Text::_('COM_MEMIPILATES_LOYALTY_REWARD_REDEEMED'),
                'data' => $result,
            ] + $result);
        } catch (\Throwable $error) {
            $this->respondError($error);
        }
    }
}
