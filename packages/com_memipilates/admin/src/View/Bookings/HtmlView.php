<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Administrator\View\Bookings;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Memi\Component\Memipilates\Administrator\View\AbstractAdminView;

/** Customer booking list with a protected cancellation action. */
final class HtmlView extends AbstractAdminView
{
    /** @var list<array<string, mixed>> */
    public array $items = [];
    /** @var list<string> */
    public array $statuses = ['pending', 'confirmed', 'attended', 'waitlisted', 'cancelled_on_time', 'cancelled_late', 'administratively_cancelled', 'no_show', 'refunded'];
    public bool $canCancel = false;

    public function display($tpl = null): void
    {
        $this->initialise(['core.manage', 'bookings.manage', 'waitlist.manage'], ['core.edit', 'bookings.manage']);
        $this->filterStatus = $this->normaliseStatus(Factory::getApplication()->input->getCmd('filter_status', ''), $this->statuses);
        $this->canCancel = $this->can('core.edit') || $this->can('bookings.manage');
        $this->loadItems();
        Factory::getApplication()->getDocument()->setTitle($this->label('COM_MEMIPILATES_SUBMENU_BOOKINGS', 'Bookings'));
        parent::display($tpl);
    }

    private function loadItems(): void
    {
        $count = $this->baseQuery()->select('COUNT(*)');
        $this->applyFilters($count);
        $this->db->setQuery($count);
        $this->setPagination((int) $this->db->loadResult());

        $query = $this->baseQuery()
            ->select([
                'b.id', 'b.status', 'b.source', 'b.booked_at', 'b.confirmed_at', 'b.cancelled_at',
                's.id AS session_id', 's.starts_at', 'c.title AS course_title',
                'u.id AS user_id', 'u.name AS customer_name', 'u.email AS customer_email',
                'cp.phone AS customer_phone',
            ])
            ->order('s.starts_at DESC, b.id DESC');
        $this->applyFilters($query);
        $this->db->setQuery($query, $this->limitStart, $this->limit);
        $this->items = $this->db->loadAssocList() ?: [];
    }

    private function baseQuery(): mixed
    {
        return $this->db->getQuery(true)
            ->from($this->db->quoteName('#__memi_bookings', 'b'))
            ->join('INNER', $this->db->quoteName('#__memi_sessions', 's') . ' ON s.id = b.session_id')
            ->join('INNER', $this->db->quoteName('#__memi_courses', 'c') . ' ON c.id = s.course_id')
            ->join('INNER', $this->db->quoteName('#__users', 'u') . ' ON u.id = b.user_id')
            ->join('LEFT', $this->db->quoteName('#__memi_client_profiles', 'cp') . ' ON cp.id = b.client_id');
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
            $query->where('b.status = :filter_status')->bind(':filter_status', $status);
        }
        if ($this->filterSearch !== '') {
            $term = '%' . $this->filterSearch . '%';
            $query->where('(u.name LIKE :filter_search OR u.email LIKE :filter_search OR c.title LIKE :filter_search)')
                ->bind(':filter_search', $term);
        }
    }
}
