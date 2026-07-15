<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Site\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Uri\Uri;
use Memi\Component\Memipilates\Administrator\Service\ComponentServices;

/** Public Square endpoint: signature verification is the authentication layer. */
final class WebhookController extends BaseController
{
    use JsonControllerTrait;

    public function square(): void
    {
        try {
            $input = Factory::getApplication()->input;
            $rawBody = (string) file_get_contents('php://input');
            $signature = $input->server->getString('HTTP_X_SQUARE_HMACSHA256_SIGNATURE', '');
            $configuredUrl = (string) ComponentServices::settings()->get('square_webhook_url', '');
            $url = $configuredUrl !== '' ? $configuredUrl : Uri::root() . 'index.php?option=com_memipilates&task=webhook.square';
            ComponentServices::payments()->handleWebhook($rawBody, $signature, $url);
            $this->respond(['success' => true]);
        } catch (\Throwable $error) {
            $this->respondError($error);
        }
    }
}
