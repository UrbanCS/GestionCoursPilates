<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Administrator\View\Dashboard;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Memi\Component\Memipilates\Administrator\View\AbstractAdminView;

/** Operational overview based entirely on the installed component tables. */
class HtmlView extends AbstractAdminView
{
    /** @var array<string, int> */
    public array $metrics = [];
    /** @var list<array<string, mixed>> */
    public array $upcomingSessions = [];
    /** @var list<array<string, mixed>> */
    public array $recentBookings = [];
    /** @var list<array<string, mixed>> */
    public array $waitlist = [];

    public function display($tpl = null): void
    {
        $this->initialise(['reports.view']);
        [$todayStart, $todayEnd, $now, $monthAgo] = $this->timeBoundaries();
        $this->metrics = $this->loadMetrics($todayStart, $todayEnd, $now, $monthAgo);
        $this->upcomingSessions = $this->loadUpcomingSessions($now);
        $this->recentBookings = $this->loadRecentBookings();
        $this->waitlist = $this->loadWaitlist($now);

        Factory::getApplication()->getDocument()->setTitle($this->label('COM_MEMIPILATES_SUBMENU_DASHBOARD', 'Dashboard'));
        parent::display($tpl);
    }

    /** @return array{0:string,1:string,2:string,3:string} */
    private function timeBoundaries(): array
    {
        $utc = new \DateTimeZone('UTC');
        $today = new \DateTimeImmutable('today', $this->timezone);
        $now = new \DateTimeImmutable('now', $utc);

        return [
            $today->setTimezone($utc)->format('Y-m-d H:i:s'),
            $today->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'),
            $now->format('Y-m-d H:i:s'),
            $now->modify('-30 days')->format('Y-m-d H:i:s'),
        ];
    }

    /** @return array<string, int> */
    private function loadMetrics(string $todayStart, string $todayEnd, string $now, string $monthAgo): array
    {
        $db = $this->db;
        $paidStatus = 'paid';
        $failedStatus = 'payment_failed';

        $todaySessions = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__memi_sessions', 's'))
            ->where('s.starts_at >= :today_start')
            ->where('s.starts_at < :today_end')
            ->where('s.archived_at IS NULL')
            ->bind(':today_start', $todayStart)
            ->bind(':today_end', $todayEnd);
        $participants = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__memi_bookings', 'b'))
            ->join('INNER', $db->quoteName('#__memi_sessions', 's') . ' ON s.id = b.session_id')
            ->where('s.starts_at >= :today_start')
            ->where('s.starts_at < :today_end')
            ->where('b.status IN (' . $db->quote('confirmed') . ', ' . $db->quote('attended') . ')')
            ->bind(':today_start', $todayStart)
            ->bind(':today_end', $todayEnd);
        $remainingSeats = $db->getQuery(true)
            ->select('COALESCE(SUM(GREATEST(s.capacity - s.reserved_count, 0)), 0)')
            ->from($db->quoteName('#__memi_sessions', 's'))
            ->where('s.starts_at >= :today_start')
            ->where('s.starts_at < :today_end')
            ->where('s.archived_at IS NULL')
            ->where('s.status <> ' . $db->quote('cancelled'))
            ->bind(':today_start', $todayStart)
            ->bind(':today_end', $todayEnd);
        $waiting = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__memi_waitlist', 'w'))
            ->join('INNER', $db->quoteName('#__memi_sessions', 's') . ' ON s.id = w.session_id')
            ->where('s.starts_at >= :now')
            ->where('w.status IN (' . $db->quote('waiting') . ', ' . $db->quote('offered') . ')')
            ->bind(':now', $now);
        $newCustomers = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__memi_client_profiles', 'cp'))
            ->where('cp.created_at >= :month_ago')
            ->where('cp.archived_at IS NULL')
            ->bind(':month_ago', $monthAgo);
        $revenue = $db->getQuery(true)
            ->select('COALESCE(SUM(o.total_cents), 0)')
            ->from($db->quoteName('#__memi_orders', 'o'))
            ->where('o.status = :paid_status')
            ->where('o.paid_at >= :month_ago')
            ->bind(':paid_status', $paidStatus)
            ->bind(':month_ago', $monthAgo);
        $failedPayments = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__memi_orders', 'o'))
            ->where('o.status = :failed_status')
            ->where('o.created_at >= :month_ago')
            ->bind(':failed_status', $failedStatus)
            ->bind(':month_ago', $monthAgo);

        return [
            'today_sessions' => $this->scalar($todaySessions),
            'today_participants' => $this->scalar($participants),
            'remaining_seats' => $this->scalar($remainingSeats),
            'waitlist' => $this->scalar($waiting),
            'new_customers' => $this->scalar($newCustomers),
            'revenue_cents' => $this->scalar($revenue),
            'failed_payments' => $this->scalar($failedPayments),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function loadUpcomingSessions(string $now): array
    {
        $cancelledStatus = 'cancelled';
        $query = $this->db->getQuery(true)
            ->select([
                's.id', 's.starts_at', 's.capacity', 's.reserved_count', 's.waitlist_count', 's.status',
                'c.title AS course_title', 'i.display_name AS instructor_name', 'r.title AS room_title',
            ])
            ->from($this->db->quoteName('#__memi_sessions', 's'))
            ->join('INNER', $this->db->quoteName('#__memi_courses', 'c') . ' ON c.id = s.course_id')
            ->join('LEFT', $this->db->quoteName('#__memi_instructors', 'i') . ' ON i.id = s.instructor_id')
            ->join('LEFT', $this->db->quoteName('#__memi_rooms', 'r') . ' ON r.id = s.room_id')
            ->where('s.starts_at >= :now')
            ->where('s.archived_at IS NULL')
            ->where('s.status <> :cancelled_status')
            ->order('s.starts_at ASC')
            ->bind(':now', $now)
            ->bind(':cancelled_status', $cancelledStatus);
        $this->db->setQuery($query, 0, 8);

        return $this->db->loadAssocList() ?: [];
    }

    /** @return list<array<string, mixed>> */
    private function loadRecentBookings(): array
    {
        $query = $this->db->getQuery(true)
            ->select([
                'b.id', 'b.status', 'b.booked_at', 's.starts_at', 'c.title AS course_title',
                'u.name AS customer_name',
            ])
            ->from($this->db->quoteName('#__memi_bookings', 'b'))
            ->join('INNER', $this->db->quoteName('#__memi_sessions', 's') . ' ON s.id = b.session_id')
            ->join('INNER', $this->db->quoteName('#__memi_courses', 'c') . ' ON c.id = s.course_id')
            ->join('INNER', $this->db->quoteName('#__users', 'u') . ' ON u.id = b.user_id')
            ->order('b.booked_at DESC');
        $this->db->setQuery($query, 0, 8);

        return $this->db->loadAssocList() ?: [];
    }

    /** @return list<array<string, mixed>> */
    private function loadWaitlist(string $now): array
    {
        $query = $this->db->getQuery(true)
            ->select([
                'w.id', 'w.position', 'w.status', 'w.joined_at', 's.starts_at',
                'c.title AS course_title', 'u.name AS customer_name',
            ])
            ->from($this->db->quoteName('#__memi_waitlist', 'w'))
            ->join('INNER', $this->db->quoteName('#__memi_sessions', 's') . ' ON s.id = w.session_id')
            ->join('INNER', $this->db->quoteName('#__memi_courses', 'c') . ' ON c.id = s.course_id')
            ->join('INNER', $this->db->quoteName('#__users', 'u') . ' ON u.id = w.user_id')
            ->where('s.starts_at >= :now')
            ->where('w.status IN (' . $this->db->quote('waiting') . ', ' . $this->db->quote('offered') . ')')
            ->order('s.starts_at ASC, w.position ASC')
            ->bind(':now', $now);
        $this->db->setQuery($query, 0, 8);

        return $this->db->loadAssocList() ?: [];
    }

    private function scalar(mixed $query): int
    {
        $this->db->setQuery($query);

        return (int) $this->db->loadResult();
    }
}
