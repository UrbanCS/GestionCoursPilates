<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Site\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;

/** Standard Joomla view dispatcher with route-level kiosk protection. */
final class DisplayController extends BaseController
{
    public function display($cachable = false, $urlparams = []): BaseController
    {
        $view = Factory::getApplication()->input->getCmd('view', 'schedule');
        if ($view === 'kiosk') {
            $identity = Factory::getApplication()->getIdentity();
            if (!(bool) $identity->authorise('attendance.kiosk', 'com_memipilates')) {
                Factory::getApplication()->enqueueMessage(\Joomla\CMS\Language\Text::_('COM_MEMIPILATES_ERROR_FORBIDDEN'), 'error');
                Factory::getApplication()->redirect(Route::_('index.php?option=com_memipilates&view=schedule', false));
                return $this;
            }
        }

        return parent::display($cachable, $urlparams);
    }
}
