<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Site\View\Waitlistoffer;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Memi\Component\Memipilates\Administrator\Service\DomainException;

/** Authenticated confirmation page for a one-time waitlist offer. */
final class HtmlView extends BaseHtmlView
{
    public int $waitlistId = 0;
    public string $token = '';
    public string $acceptEndpoint = '';

    public function display($tpl = null): void
    {
        $input = Factory::getApplication()->input;
        $this->waitlistId = $input->getInt('id');
        $this->token = $input->getString('token');
        if ($this->waitlistId <= 0 || !preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $this->token)) {
            throw new DomainException('COM_MEMIPILATES_ERROR_WAITLIST_OFFER_INVALID', [], 404);
        }

        $identity = Factory::getApplication()->getIdentity();
        if ((int) ($identity->id ?? 0) <= 0) {
            $return = 'index.php?option=com_memipilates&view=waitlistoffer&id=' . $this->waitlistId . '&token=' . rawurlencode($this->token);
            Factory::getApplication()->redirect(Route::_('index.php?option=com_users&view=login&return=' . base64_encode($return), false));

            return;
        }

        $this->acceptEndpoint = Route::_('index.php?option=com_memipilates&task=booking.acceptWaitlist&format=json', false);
        Factory::getApplication()->getDocument()->getWebAssetManager()
            ->useStyle('com_memipilates.site')
            ->useScript('com_memipilates.booking');
        parent::display($tpl);
    }
}
