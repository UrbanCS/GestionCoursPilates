<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Administrator\View\Attendance;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Memi\Component\Memipilates\Administrator\Service\ComponentServices;
use Memi\Component\Memipilates\Administrator\View\AbstractAdminView;

/** Attendance audit list plus staff-only manual check-in candidates. */
final class HtmlView extends AbstractAdminView
{
    /** @var list<array<string, mixed>> */
    public array $items = [];
    /** @var list<array<string, mixed>> */
    public array $checkInCandidates = [];
    /** @var list<string> */
    public array $statuses = ['confirmed', 'void'];
    public bool $canManualCheckIn = false;
    public bool $canUndo = false;

    public function display($tpl = null): void
    {
        $this->initialise(['core.manage', 'attendance.scan', 'attendance.manual', 'attendance.undo'], ['core.edit', 'attendance.manual', 'attendance.undo']);
        $this->filterStatus = $this->normaliseStatus(Factory::getApplication()->input->getCmd('filter_status', ''), $this->statuses);
        $this->canManualCheckIn = $this->can('core.edit') || $this->can('attendance.manual');
        $this->canUndo = $this->can('core.edit') || $this->can('attendance.undo');
        $this->loadItems();
        $this->checkInCandidates = $this->canManualCheckIn ? $this->loadCheckInCandidates() : [];
        Factory::getApplication()->getDocument()->setTitle($this->label('COM_MEMIPILATES_SUBMENU_ATTENDANCE', 'Attendance'));
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
                'a.id', 'a.status', 'a.method', 'a.checked_in_at', 'a.override_reason', 'a.voided_at',
                's.id AS session_id', 's.starts_at', 'c.title AS course_title',
                'u.id AS user_id', 'u.name AS customer_name', 'u.email AS customer_email',
                'scanner.name AS scanner_name',
            ])
            ->order('a.checked_in_at DESC, a.id DESC');
        $this->applyFilters($query);
        $this->db->setQuery($query, $this->limitStart, $this->limit);
        $this->items = $this->db->loadAssocList() ?: [];
    }

    private function baseQuery(): mixed
    {
        return $this->db->getQuery(true)
            ->from($this->db->quoteName('#__memi_attendance', 'a'))
            ->join('INNER', $this->db->quoteName('#__memi_sessions', 's') . ' ON s.id = a.session_id')
            ->join('INNER', $this->db->quoteName('#__memi_courses', 'c') . ' ON c.id = s.course_id')
            ->join('INNER', $this->db->quoteName('#__users', 'u') . ' ON u.id = a.user_id')
            ->join('LEFT', $this->db->quoteName('#__users', 'scanner') . ' ON scanner.id = a.scanned_by_user_id');
    }

    private function applyFilters(mixed $query): void
    {
        $range = $this->selectedDayRange();
        if ($range !== null) {
            [$start, $end] = $range;
            $query->where('a.checked_in_at >= :filter_start')->where('a.checked_in_at < :filter_end')
                ->bind(':filter_start', $start)->bind(':filter_end', $end);
        }
        if ($this->filterStatus !== '') {
            $status = $this->filterStatus;
            $query->where('a.status = :filter_status')->bind(':filter_status', $status);
        }
        if ($this->filterSearch !== '') {
            $term = '%' . $this->filterSearch . '%';
            $query->where('(u.name LIKE :filter_search OR u.email LIKE :filter_search OR c.title LIKE :filter_search)')
                ->bind(':filter_search', $term);
        }
    }

    /** @return list<array<string, mixed>> */
    private function loadCheckInCandidates(): array
    {
        $settings = ComponentServices::settings();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $before = max(0, $settings->getInt('attendance_before_minutes', 30));
        $after = max(0, $settings->getInt('attendance_after_minutes', 30));
        $bookingStatus = 'confirmed';
        $lower = $now->modify('-' . $before . ' minutes')->format('Y-m-d H:i:s');
        $upper = $now->modify('+' . $after . ' minutes')->format('Y-m-d H:i:s');
        $query = $this->db->getQuery(true)
            ->select([
                'b.id AS booking_id', 's.id AS session_id', 's.starts_at', 'c.title AS course_title',
                'u.name AS customer_name', 'u.email AS customer_email',
            ])
            ->from($this->db->quoteName('#__memi_bookings', 'b'))
            ->join('INNER', $this->db->quoteName('#__memi_sessions', 's') . ' ON s.id = b.session_id')
            ->join('INNER', $this->db->quoteName('#__memi_courses', 'c') . ' ON c.id = s.course_id')
            ->join('INNER', $this->db->quoteName('#__users', 'u') . ' ON u.id = b.user_id')
            ->join('LEFT', $this->db->quoteName('#__memi_attendance', 'a') . ' ON a.booking_id = b.id AND a.status = ' . $this->db->quote('confirmed'))
            ->where('b.status = :booking_status')
            ->where('a.id IS NULL')
            ->where('s.status IN (' . $this->db->quote('published') . ', ' . $this->db->quote('open') . ')')
            ->where('s.starts_at <= :candidate_upper')
            ->where('s.ends_at >= :candidate_lower')
            ->order('s.starts_at ASC, u.name ASC')
            ->bind(':booking_status', $bookingStatus)
            ->bind(':candidate_upper', $upper)
            ->bind(':candidate_lower', $lower);
        $this->db->setQuery($query, 0, 30);

        return $this->db->loadAssocList() ?: [];
    }
}
