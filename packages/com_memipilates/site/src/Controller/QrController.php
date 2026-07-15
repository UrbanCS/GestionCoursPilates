<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Site\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\BaseController;
use Memi\Component\Memipilates\Administrator\Service\ComponentServices;

final class QrController extends BaseController
{
    use JsonControllerTrait;

    public function regenerate(): void
    {
        try {
            $this->requirePostToken();
            $userId = $this->userId();
            $result = ComponentServices::qrTokens()->regenerate($userId, $userId);
            $this->respond([
                'success' => true,
                'message' => \Joomla\CMS\Language\Text::_('COM_MEMIPILATES_QR_REGENERATED'),
                'data' => $result,
            ] + $result);
        } catch (\Throwable $error) {
            $this->respondError($error);
        }
    }
}
