<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Site\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Memi\Component\Memipilates\Administrator\Controller\DisplayController as AdministratorDisplayController;
use Memi\Component\Memipilates\Site\Service\PortalAccess;

/**
 * Site dispatcher for public/client pages and the protected studio portal.
 *
 * The inherited state-changing actions retain the same ACL, CSRF and domain
 * checks as the administrator application.
 */
final class DisplayController extends AdministratorDisplayController
{
    public function display($cachable = false, $urlparams = []): BaseController
    {
        $application = Factory::getApplication();
        $view = $application->input->getCmd('view', 'schedule');

        if (PortalAccess::isManagementView($view)) {
            $identity = $application->getIdentity();
            if ((int) ($identity->id ?? 0) <= 0) {
                $return = base64_encode('index.php?option=com_memipilates&view=' . $view);
                $application->redirect(Route::_('index.php?option=com_users&view=login&return=' . rawurlencode($return), false));

                return $this;
            }

            if (!PortalAccess::canAccess($identity, $view)) {
                throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
            }
        } elseif ($view === 'kiosk') {
            $identity = $application->getIdentity();
            if (!(bool) $identity->authorise('attendance.kiosk', 'com_memipilates')) {
                $application->enqueueMessage(Text::_('COM_MEMIPILATES_ERROR_FORBIDDEN'), 'error');
                $application->redirect(Route::_('index.php?option=com_memipilates&view=schedule', false));

                return $this;
            }
        }

        return $this->renderView($cachable, $urlparams);
    }
}
