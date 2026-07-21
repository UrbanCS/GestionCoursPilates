<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Site\View\Booking;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\Database\ParameterType;
use Memi\Component\Memipilates\Administrator\Service\ComponentServices;

final class HtmlView extends BaseHtmlView
{
    /** @var array<string,mixed>|null */
    public ?array $session = null;
    public int $userId = 0;
    public int $creditBalance = 0;
    public string $reserveEndpoint = '';
    public string $waitlistEndpoint = '';

    public function display($tpl = null): void
    {
        $this->userId = (int) (Factory::getApplication()->getIdentity()->id ?? 0);
        $id = Factory::getApplication()->input->getInt('session_id');
        $this->session = $this->loadSession($id);
        if (!$this->session) {
            throw new \RuntimeException('COM_MEMIPILATES_ERROR_SESSION_NOT_FOUND', 404);
        }
        $this->creditBalance = $this->userId > 0 ? ComponentServices::credits()->balance($this->userId) : 0;
        $this->reserveEndpoint = Route::_('index.php?option=com_memipilates&task=booking.reserve&format=json', false);
        $this->waitlistEndpoint = Route::_('index.php?option=com_memipilates&task=booking.joinWaitlist&format=json', false);
        Factory::getApplication()->getDocument()->getWebAssetManager()->useStyle('com_memipilates.site')->useScript('com_memipilates.booking');
        parent::display($tpl);
    }

    /** @return array<string,mixed>|null */
    private function loadSession(int $id): ?array
    {
        if ($id <= 0) return null;
        $db = ComponentServices::database();
        $sessionId = $id;
        $query = $db->getQuery(true)
            ->select(['s.*', 'c.title AS course_title', 'c.description', 'i.display_name AS instructor_name', 'r.title AS room_title'])
            ->from($db->quoteName('#__memi_sessions', 's'))
            ->join('INNER', $db->quoteName('#__memi_courses', 'c') . ' ON c.id = s.course_id')
            ->join('LEFT', $db->quoteName('#__memi_instructors', 'i') . ' ON i.id = s.instructor_id')
            ->join('LEFT', $db->quoteName('#__memi_rooms', 'r') . ' ON r.id = s.room_id')
            ->where('s.id = :id')
            ->where('s.archived_at IS NULL')
            ->where('c.archived_at IS NULL')
            ->where('c.published = 1')
            ->bind(':id', $sessionId, ParameterType::INTEGER);
        $db->setQuery($query);
        return $db->loadAssoc() ?: null;
    }
}
