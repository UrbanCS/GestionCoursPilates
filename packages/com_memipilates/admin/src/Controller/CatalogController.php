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

/** Writes the operational studio catalogue from the back-office catalogue view. */
final class CatalogController extends BaseController
{
    /** @var array<string,list<string>> */
    private const PERMISSIONS = [
        'location' => ['rooms.manage'],
        'room' => ['rooms.manage'],
        'instructor' => ['instructors.manage'],
        'course_type' => ['courses.manage'],
        'course' => ['courses.manage'],
        'session' => ['schedules.manage'],
        'session_rule' => ['schedules.manage'],
        'package' => ['packages.manage'],
    ];

    public function save(): void
    {
        $input = Factory::getApplication()->input;
        $entity = $input->post->getCmd('entity');
        $permissions = self::PERMISSIONS[$entity] ?? [];
        $this->processPost($entity, $permissions, function (int $actorId) use ($input, $entity): void {
            $id = $input->post->getInt('id');
            if ($id > 0 && $entity !== 'session') {
                ComponentServices::catalogManagement()->update($entity, $id, $input->post, $actorId);
            } elseif ($id > 0) {
                throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
            } else {
                ComponentServices::catalog()->create($entity, $input->post, $actorId);
            }
            if ($entity === 'session_rule') {
                ComponentServices::scheduler()->generateRecurringSessions(
                    ComponentServices::settings()->getInt('session_generation_lookahead_days', 90),
                    false
                );
            }
        });
    }

    public function archive(): void
    {
        $input = Factory::getApplication()->input;
        $entity = $input->post->getCmd('entity');
        $permissions = self::PERMISSIONS[$entity] ?? [];
        $this->processPost($entity, $permissions, function (int $actorId) use ($input, $entity): void {
            if ($entity === 'session') {
                throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
            }
            $id = $input->post->getInt('id');
            ComponentServices::catalogManagement()->archive($entity, $id, $actorId);
        });
    }

    /** @param list<string> $permissions */
    private function processPost(string $entity, array $permissions, callable $operation): void
    {
        if (!Session::checkToken('post')) {
            $this->setMessage(Text::_('JINVALID_TOKEN'), 'error');
            $this->redirectTo($entity);

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

        $this->redirectTo($entity);
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

    private function redirectTo(string $entity): void
    {
        $query = 'index.php?option=com_memipilates&view=catalog';
        if (isset(self::PERMISSIONS[$entity])) {
            $query .= '&entity=' . rawurlencode($entity);
        }
        $this->setRedirect(Route::_($query, false));
    }
}
