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

/** Protected back-office changes to promotion codes and reward catalogue. */
final class OffersController extends BaseController
{
    public function savePromotion(): void
    {
        $this->processPost(['core.manage', 'promotions.manage'], static function (int $actorId): void {
            ComponentServices::offers()->savePromotion(Factory::getApplication()->input->post, $actorId);
        });
    }

    public function archivePromotion(): void
    {
        $this->processPost(['core.manage', 'promotions.manage'], static function (int $actorId): void {
            ComponentServices::offers()->archivePromotion(Factory::getApplication()->input->post->getInt('id'), $actorId);
        });
    }

    public function saveReward(): void
    {
        $this->processPost(['core.manage', 'loyalty.adjust'], static function (int $actorId): void {
            ComponentServices::offers()->saveReward(Factory::getApplication()->input->post, $actorId);
        });
    }

    public function archiveReward(): void
    {
        $this->processPost(['core.manage', 'loyalty.adjust'], static function (int $actorId): void {
            ComponentServices::offers()->archiveReward(Factory::getApplication()->input->post->getInt('id'), $actorId);
        });
    }

    /** @param list<string> $permissions */
    private function processPost(array $permissions, callable $operation): void
    {
        if (!Session::checkToken('post')) {
            $this->setMessage(Text::_('JINVALID_TOKEN'), 'error');
            $this->redirectToOffers();

            return;
        }
        try {
            $operation($this->requireAnyPermission($permissions));
            $this->setMessage(Text::_('JLIB_APPLICATION_SAVE_SUCCESS'), 'message');
        } catch (DomainException $error) {
            $message = Text::_($error->getMessage());
            $this->setMessage($message === $error->getMessage() ? Text::_('JERROR_AN_ERROR_HAS_OCCURRED') : $message, 'error');
        } catch (\Throwable) {
            $this->setMessage(Text::_('JERROR_AN_ERROR_HAS_OCCURRED'), 'error');
        }
        $this->redirectToOffers();
    }

    /** @param list<string> $permissions */
    private function requireAnyPermission(array $permissions): int
    {
        $identity = Factory::getApplication()->getIdentity();
        foreach (array_unique(array_merge(['core.admin'], $permissions)) as $permission) {
            if ((bool) $identity->authorise($permission, 'com_memipilates')) {
                return (int) ($identity->id ?? 0);
            }
        }

        throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
    }

    private function redirectToOffers(): void
    {
        $this->setRedirect(Route::_('index.php?option=com_memipilates&view=offers', false));
    }
}
