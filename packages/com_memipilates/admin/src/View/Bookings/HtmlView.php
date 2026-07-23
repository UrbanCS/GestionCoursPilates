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
    /** @var list<array<string, mixed>> */
    public array $manualClients = [];
    /** @var list<array<string, mixed>> */
    public array $manualSessions = [];
    /** @var list<string> */
    public array $statuses = ['pending', 'confirmed', 'attended', 'waitlisted', 'cancelled_on_time', 'cancelled_late', 'administratively_cancelled', 'no_show', 'refunded'];
    public bool $canCancel = false;
    public bool $canManualBooking = false;
    public bool $canViewContact = false;

    public function display($tpl = null): void
    {
        $this->initialise(['bookings.manage', 'bookings.manual'], ['bookings.manage', 'bookings.manual']);
        $this->filterStatus = $this->normaliseStatus(Factory::getApplication()->input->getCmd('filter_status', ''), $this->statuses);
        $this->canCancel = $this->can('bookings.manage');
        $this->canManualBooking = $this->can('bookings.manual') || $this->can('bookings.manage');
        $this->canViewContact = $this->can('clients.manage');
        $this->loadItems();
        if ($this->canManualBooking) {
            $this->loadManualBookingData();
        }
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
                'u.id AS user_id', 'u.name AS customer_name',
            ]);
        if ($this->canViewContact) {
            $query->select(['u.email AS customer_email', 'cp.phone AS customer_phone']);
        }
        $query
            ->order('s.starts_at DESC, b.id DESC');
        $this->applyFilters($query);
        $this->db->setQuery($query, $this->limitStart, $this->limit);
        $this->items = $this->db->loadAssocList() ?: [];
    }

    private function baseQuery(): mixed
    {
        $query = $this->db->getQuery(true)
            ->from($this->db->quoteName('#__memi_bookings', 'b'))
            ->join('INNER', $this->db->quoteName('#__memi_sessions', 's') . ' ON s.id = b.session_id')
            ->join('INNER', $this->db->quoteName('#__memi_courses', 'c') . ' ON c.id = s.course_id')
            ->join('INNER', $this->db->quoteName('#__users', 'u') . ' ON u.id = b.user_id')
            ->join('LEFT', $this->db->quoteName('#__memi_client_profiles', 'cp') . ' ON cp.id = b.client_id');
        $this->applyInstructorSessionScope($query, 's', ['bookings.manage']);

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
            $query->where('b.status = :filter_status')->bind(':filter_status', $status);
        }
        if ($this->filterSearch !== '') {
            $term = '%' . $this->filterSearch . '%';
            $columns = $this->canViewContact
                ? 'u.name LIKE :filter_search OR u.email LIKE :filter_search OR c.title LIKE :filter_search'
                : 'u.name LIKE :filter_search OR c.title LIKE :filter_search';
            $query->where('(' . $columns . ')')
                ->bind(':filter_search', $term);
        }
    }

    private function loadManualBookingData(): void
    {
        $clientQuery = $this->db->getQuery(true)
            ->select($this->canViewContact ? ['u.id', 'u.name', 'u.email', 'cp.phone'] : ['u.id', 'u.name'])
            ->from($this->db->quoteName('#__memi_client_profiles', 'cp'))
            ->join('INNER', $this->db->quoteName('#__users', 'u') . ' ON u.id = cp.user_id')
            ->where('cp.archived_at IS NULL')
            ->where('u.block = 0')
            ->order('u.name ASC, u.id ASC');
        $this->db->setQuery($clientQuery, 0, 250);
        $this->manualClients = $this->db->loadAssocList() ?: [];

        $now = gmdate('Y-m-d H:i:s');
        $sessionQuery = $this->db->getQuery(true)
            ->select([
                's.id', 's.starts_at', 's.capacity', 's.reserved_count', 's.credits_required',
                'c.title AS course_title',
                'GREATEST(0, s.capacity - s.reserved_count) AS available_places',
            ])
            ->from($this->db->quoteName('#__memi_sessions', 's'))
            ->join('INNER', $this->db->quoteName('#__memi_courses', 'c') . ' ON c.id = s.course_id')
            ->where('s.archived_at IS NULL')
            ->where('c.archived_at IS NULL')
            ->where('s.status IN (' . $this->db->quote('published') . ', ' . $this->db->quote('open') . ')')
            ->where('s.starts_at > :now')
            ->where('s.reserved_count < s.capacity')
            ->where('(s.registration_opens_at IS NULL OR s.registration_opens_at <= :now)')
            ->where('(s.registration_closes_at IS NULL OR s.registration_closes_at > :now)')
            ->order('s.starts_at ASC')
            ->bind(':now', $now);
        $this->applyInstructorSessionScope($sessionQuery, 's', ['bookings.manage']);
        $this->db->setQuery($sessionQuery, 0, 150);
        $this->manualSessions = $this->db->loadAssocList() ?: [];
    }
}
