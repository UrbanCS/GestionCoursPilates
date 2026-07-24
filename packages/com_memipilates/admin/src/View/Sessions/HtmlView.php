<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Administrator\View\Sessions;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Memi\Component\Memipilates\Administrator\View\AbstractAdminView;

/** Read and cancellation screen for generated class sessions. */
class HtmlView extends AbstractAdminView
{
    /** @var list<array<string, mixed>> */
    public array $items = [];
    /** @var list<string> */
    public array $statuses = ['scheduled', 'published', 'open', 'cancelled', 'completed'];
    public bool $canCancel = false;
    public bool $canOfferWaitlist = false;

    public function display($tpl = null): void
    {
        $this->initialise(['schedules.manage', 'courses.manage', 'waitlist.manage'], ['schedules.manage', 'waitlist.manage']);
        $this->filterStatus = $this->normaliseStatus(Factory::getApplication()->input->getCmd('filter_status', ''), $this->statuses);
        $this->canCancel = $this->can('schedules.manage');
        $this->canOfferWaitlist = $this->can('waitlist.manage');
        $this->loadItems();
        Factory::getApplication()->getDocument()->setTitle($this->label('COM_MEMIPILATES_SUBMENU_SESSIONS', 'Sessions'));
        parent::display($tpl);
    }

    private function loadItems(): void
    {
        $count = $this->baseQuery()
            ->select('COUNT(*)');
        $this->applyFilters($count);
        $this->db->setQuery($count);
        $this->setPagination((int) $this->db->loadResult());

        $query = $this->baseQuery()
            ->select([
                's.id', 's.starts_at', 's.ends_at', 's.capacity', 's.reserved_count', 's.waitlist_count',
                's.status', 's.credits_required', 'c.title AS course_title',
                'i.display_name AS instructor_name', 'r.title AS room_title',
                'GREATEST(0, s.capacity - s.reserved_count) AS available_places',
            ])
            ->order('s.starts_at ASC');
        $this->applyFilters($query);
        $this->db->setQuery($query, $this->limitStart, $this->limit);
        $this->items = $this->db->loadAssocList() ?: [];
    }

    private function baseQuery(): mixed
    {
        $query = $this->db->getQuery(true)
            ->from($this->db->quoteName('#__memi_sessions', 's'))
            ->join('INNER', $this->db->quoteName('#__memi_courses', 'c') . ' ON c.id = s.course_id')
            ->join('LEFT', $this->db->quoteName('#__memi_instructors', 'i') . ' ON i.id = s.instructor_id')
            ->join('LEFT', $this->db->quoteName('#__memi_rooms', 'r') . ' ON r.id = s.room_id')
            ->where('s.archived_at IS NULL');
        $this->applyInstructorSessionScope($query, 's', ['schedules.manage', 'courses.manage']);

        return $query;
    }

    private function applyFilters(mixed $query): void
    {
        $range = $this->selectedDayRange();
        if ($range !== null) {
            [$start, $end] = $range;
            $query->where('s.starts_at >= :filter_start')->where('s.starts_at < :filter_end')
                ->bind(':filter_start', $start)->bind(':filter_end', $end);
        }
        if ($this->filterStatus !== '') {
            $status = $this->filterStatus;
            $query->where('s.status = :filter_status')->bind(':filter_status', $status);
        }
        if ($this->filterSearch !== '') {
            $term = '%' . $this->filterSearch . '%';
            $query->where('(c.title LIKE :filter_search OR i.display_name LIKE :filter_search OR r.title LIKE :filter_search)')
                ->bind(':filter_search', $term);
        }
    }
}
