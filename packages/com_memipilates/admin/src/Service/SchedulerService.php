<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;

/** Idempotent scheduled work callable from Joomla Task Scheduler or cPanel cron. */
final class SchedulerService
{
    public function __construct(
        private readonly DatabaseDriver $db,
        private readonly DatabaseTools $tools,
        private readonly SettingsService $settings,
        private readonly CreditLedgerService $credits,
        private readonly WaitlistService $waitlist,
        private readonly NotificationService $notifications,
        private readonly PaymentService $payments,
        private readonly AuditLogger $audit
    ) {
    }

    /**
     * @param array{dry_run?:bool,horizon_days?:int,email_limit?:int,skip_reminders?:bool} $options
     * @return array<string,int|bool>
     */
    public function runDueTasks(array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $horizon = max(1, min((int) ($options['horizon_days'] ?? 90), 365));
        $emailLimit = max(1, min((int) ($options['email_limit'] ?? 100), 500));
        $result = [
            'dry_run' => $dryRun,
            'sessions_generated' => $this->generateRecurringSessions($horizon, $dryRun),
            'credit_expiry_notices_queued' => $dryRun ? 0 : $this->queueCreditExpiryNotices(),
            'credits_expired' => $dryRun ? 0 : $this->expireCredits(),
            'payment_holds_expired' => $dryRun ? 0 : $this->payments->expirePendingSessionOrders(),
            'offers_expired' => $dryRun ? 0 : $this->waitlist->expireOffers(),
            'offers_promoted' => $dryRun ? 0 : $this->waitlist->promoteAvailableSessions(),
            'reminders_queued' => $dryRun || !empty($options['skip_reminders']) ? 0 : $this->queueReminders(),
            'no_shows_marked' => $dryRun ? 0 : $this->markNoShows(),
            'payments_reconciled' => $dryRun ? 0 : $this->payments->reconcileUncertainPayments(),
            'refunds_reconciled' => $dryRun ? 0 : $this->payments->reconcileUncertainRefunds(),
            'notifications_sent' => $dryRun ? 0 : $this->notifications->sendDue($emailLimit),
        ];
        $this->audit->log(null, 'scheduler.run', 'scheduler', null, null, $result);

        return $result;
    }

    /** Generate local-time recurrence rules through the supplied horizon. */
    public function generateRecurringSessions(int $horizonDays, bool $dryRun = false): int
    {
        $query = $this->db->getQuery(true)
            ->select([
                'r.*',
                'c.capacity AS course_capacity', 'c.default_duration_minutes', 'c.duration_minutes AS course_duration_minutes',
                'c.credits_required', 'c.price_cents', 'c.tax_rate_basis_points AS course_tax_rate_basis_points',
                'c.booking_opens_offset_minutes AS course_booking_opens_offset_minutes',
                'c.booking_closes_offset_minutes AS course_booking_closes_offset_minutes',
                'c.instructor_id AS course_instructor_id', 'c.room_id AS course_room_id',
            ])
            ->from($this->db->quoteName('#__memi_session_rules', 'r'))
            ->join('INNER', $this->db->quoteName('#__memi_courses', 'c') . ' ON c.id = r.course_id')
            ->where('r.published = 1')
            ->where('r.archived_at IS NULL')
            ->where('c.published = 1')
            ->where('c.archived_at IS NULL');
        $this->db->setQuery($query);
        $rules = $this->db->loadAssocList() ?: [];
        $created = 0;

        foreach ($rules as $rule) {
            try {
                $ruleTimezone = new \DateTimeZone((string) ($rule['timezone'] ?: $this->settings->timezone()->getName()));
            } catch (\Exception) {
                $ruleTimezone = $this->settings->timezone();
            }
            $today = new \DateTimeImmutable('today', $ruleTimezone);
            $now = new \DateTimeImmutable('now', $ruleTimezone);
            $end = $today->modify('+' . $horizonDays . ' days');
            $startsOn = !empty($rule['starts_on']) ? new \DateTimeImmutable((string) $rule['starts_on'], $ruleTimezone) : $today;
            $endsOn = !empty($rule['ends_on']) ? new \DateTimeImmutable((string) $rule['ends_on'], $ruleTimezone) : $end;
            $from = $startsOn > $today ? $startsOn : $today;
            $to = $endsOn < $end ? $endsOn : $end;
            $weekday = max(1, min(7, (int) $rule['weekday']));

            for ($date = $from; $date <= $to; $date = $date->modify('+1 day')) {
                if ((int) $date->format('N') !== $weekday) {
                    continue;
                }
                $startTime = preg_match('/^\d{2}:\d{2}(:\d{2})?$/D', (string) $rule['start_time']) ? (string) $rule['start_time'] : '09:00:00';
                $startsAt = new \DateTimeImmutable($date->format('Y-m-d') . ' ' . $startTime, $ruleTimezone);
                if ($startsAt <= $now) {
                    continue;
                }
                $duration = max(1, (int) ($rule['duration_minutes'] ?: $rule['default_duration_minutes'] ?: $rule['course_duration_minutes'] ?: 60));
                $endsAt = $startsAt->modify('+' . $duration . ' minutes');
                if ($dryRun) {
                    if (!$this->sessionExists((int) $rule['course_id'], $startsAt)) {
                        ++$created;
                    }
                    continue;
                }
                if ($this->insertSessionIfMissing($rule, $startsAt, $endsAt)) {
                    ++$created;
                }
            }
        }

        return $created;
    }

    /** Expire active allocations exactly once and make their cached balance zero. */
    public function expireCredits(): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $active = 'active';
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__memi_customer_packages'))
            ->where($this->db->quoteName('status') . ' = :status')
            ->where($this->db->quoteName('expires_at') . ' IS NOT NULL')
            ->where($this->db->quoteName('expires_at') . ' <= :now')
            ->bind(':status', $active)
            ->bind(':now', $now);
        $this->db->setQuery($query);
        $ids = array_map('intval', $this->db->loadColumn() ?: []);
        $expired = 0;

        foreach ($ids as $id) {
            $changed = $this->tools->transaction(function () use ($id, $now): bool {
                $allocation = $this->tools->lockById('#__memi_customer_packages', $id);
                if (!$allocation || (string) $allocation['status'] !== 'active' || empty($allocation['expires_at']) || (string) $allocation['expires_at'] > $now) {
                    return false;
                }
                $this->credits->expireAllocation($allocation);
                $identifier = $id;
                $expiredStatus = 'expired';
                $update = $this->db->getQuery(true)
                    ->update($this->db->quoteName('#__memi_customer_packages'))
                    ->set($this->db->quoteName('status') . ' = :status')
                    ->set($this->db->quoteName('remaining_credits') . ' = 0')
                    ->set($this->db->quoteName('updated_at') . ' = :updated_at')
                    ->where($this->db->quoteName('id') . ' = :id')
                    ->bind(':status', $expiredStatus)
                    ->bind(':updated_at', $now)
                    ->bind(':id', $identifier, ParameterType::INTEGER);
                $this->db->setQuery($update)->execute();

                return true;
            });
            $expired += $changed ? 1 : 0;
        }

        return $expired;
    }

    /** Queue one useful, idempotent warning for each expiring allocation. */
    public function queueCreditExpiryNotices(): int
    {
        $noticeDays = max(0, min($this->settings->getInt('credit_expiry_notice_days', 14), 365));
        if ($noticeDays === 0) {
            return 0;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $horizon = $now->modify('+' . $noticeDays . ' days');
        $active = 'active';
        $query = $this->db->getQuery(true)
            ->select([
                'cp.id AS customer_package_id', 'cp.user_id', 'cp.remaining_credits',
                'cp.expires_at', 'p.title AS package_title',
            ])
            ->from($this->db->quoteName('#__memi_customer_packages', 'cp'))
            ->join('INNER', $this->db->quoteName('#__memi_packages', 'p') . ' ON p.id = cp.package_id')
            ->where('cp.status = :status')
            ->where('cp.remaining_credits > 0')
            ->where('cp.expires_at IS NOT NULL')
            ->where('cp.expires_at > :now')
            ->where('cp.expires_at <= :horizon')
            ->where('cp.archived_at IS NULL')
            ->bind(':status', $active)
            ->bind(':now', $now->format('Y-m-d H:i:s'))
            ->bind(':horizon', $horizon->format('Y-m-d H:i:s'));
        $this->db->setQuery($query);
        $allocations = $this->db->loadAssocList() ?: [];
        $queued = 0;

        foreach ($allocations as $allocation) {
            $allocationId = (int) $allocation['customer_package_id'];
            $expiry = (string) $allocation['expires_at'];
            $idempotencyKey = 'credit-expiring:' . $allocationId . ':' . hash('sha256', $expiry . '|' . $noticeDays);
            $notificationId = $this->notifications->queue((int) $allocation['user_id'], 'credit.expiring', [
                'customer_package_id' => $allocationId,
                'package_title' => (string) $allocation['package_title'],
                'remaining_credits' => (int) $allocation['remaining_credits'],
                'expires_at' => $expiry,
            ], null, $idempotencyKey);
            $queued += $notificationId > 0 ? 1 : 0;
        }

        return $queued;
    }

    /** Queue reminder emails deterministically so rerunning cron does not duplicate them. */
    public function queueReminders(): int
    {
        $hours = preg_split('/\s*,\s*/', (string) $this->settings->get('reminder_hours', '24,2')) ?: [];
        $queued = 0;
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        foreach ($hours as $hour) {
            $offset = (int) $hour;
            if ($offset < 0 || $offset > 168) {
                continue;
            }
            $windowStart = $now->modify('+' . $offset . ' hours')->format('Y-m-d H:i:s');
            $windowEnd = $now->modify('+' . ($offset + 1) . ' hours')->format('Y-m-d H:i:s');
            $confirmed = 'confirmed';
            $query = $this->db->getQuery(true)
                ->select([
                    'b.user_id', 'b.id AS booking_id', 'b.booking_key', 's.id AS session_id', 's.starts_at',
                    'c.title AS session_title', 'i.display_name AS instructor_name',
                    'r.title AS room_title', 'l.title AS location_title',
                ])
                ->from($this->db->quoteName('#__memi_bookings', 'b'))
                ->join('INNER', $this->db->quoteName('#__memi_sessions', 's') . ' ON s.id = b.session_id')
                ->join('INNER', $this->db->quoteName('#__memi_courses', 'c') . ' ON c.id = s.course_id')
                ->join('LEFT', $this->db->quoteName('#__memi_instructors', 'i') . ' ON i.id = s.instructor_id')
                ->join('LEFT', $this->db->quoteName('#__memi_rooms', 'r') . ' ON r.id = s.room_id')
                ->join('LEFT', $this->db->quoteName('#__memi_locations', 'l') . ' ON l.id = r.location_id')
                ->where('b.status = :booking_status')
                ->where('s.starts_at >= :window_start')
                ->where('s.starts_at < :window_end')
                ->bind(':booking_status', $confirmed)
                ->bind(':window_start', $windowStart)
                ->bind(':window_end', $windowEnd);
            $this->db->setQuery($query);
            $bookings = $this->db->loadAssocList() ?: [];
            foreach ($bookings as $booking) {
                // A cancelled booking row can be reused for a later booking
                // lifecycle. Include the rotated booking key so that the new
                // lifecycle receives its reminders without duplicating retries.
                $idempotencyKey = hash(
                    'sha256',
                    'reminder:' . (int) $booking['booking_id'] . ':' . (string) $booking['booking_key'] . ':' . $offset
                );
                $notification = $this->notifications->queue((int) $booking['user_id'], 'booking.reminder', [
                    'session_id' => (int) $booking['session_id'],
                    'session_title' => (string) $booking['session_title'],
                    'starts_at' => (string) $booking['starts_at'],
                    'instructor_name' => (string) ($booking['instructor_name'] ?? ''),
                    'room_title' => (string) ($booking['room_title'] ?? ''),
                    'location_title' => (string) ($booking['location_title'] ?? ''),
                ], null, $idempotencyKey);
                $queued += $notification > 0 ? 1 : 0;
            }
        }

        return $queued;
    }

    /** Mark ended confirmed bookings as no-shows, without mutating credit balances. */
    public function markNoShows(): int
    {
        $grace = max(0, $this->settings->getInt('no_show_grace_minutes', 30));
        $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-' . $grace . ' minutes')->format('Y-m-d H:i:s');
        $confirmed = 'confirmed';
        $query = $this->db->getQuery(true)
            ->select('b.id')
            ->from($this->db->quoteName('#__memi_bookings', 'b'))
            ->join('INNER', $this->db->quoteName('#__memi_sessions', 's') . ' ON s.id = b.session_id')
            ->leftJoin($this->db->quoteName('#__memi_attendance', 'a') . ' ON a.booking_id = b.id AND a.status = ' . $this->db->quote('confirmed'))
            ->where('b.status = :booking_status')
            ->where('s.ends_at < :cutoff')
            ->where('a.id IS NULL')
            ->bind(':booking_status', $confirmed)
            ->bind(':cutoff', $cutoff);
        $this->db->setQuery($query);
        $ids = array_map('intval', $this->db->loadColumn() ?: []);
        $count = 0;

        foreach ($ids as $id) {
            $changed = $this->tools->transaction(function () use ($id, $confirmed): bool {
                $booking = $this->tools->lockById('#__memi_bookings', $id);
                if (!$booking || (string) $booking['status'] !== 'confirmed') {
                    return false;
                }
                $now = gmdate('Y-m-d H:i:s');
                $identifier = $id;
                $noShow = 'no_show';
                $update = $this->db->getQuery(true)
                    ->update($this->db->quoteName('#__memi_bookings'))
                    ->set($this->db->quoteName('status') . ' = :status')
                    ->set($this->db->quoteName('updated_at') . ' = :updated_at')
                    ->where($this->db->quoteName('id') . ' = :id')
                    ->where($this->db->quoteName('status') . ' = :confirmed_status')
                    ->bind(':status', $noShow)
                    ->bind(':updated_at', $now)
                    ->bind(':id', $identifier, ParameterType::INTEGER)
                    ->bind(':confirmed_status', $confirmed);
                $this->db->setQuery($update)->execute();

                return $this->db->getAffectedRows() === 1;
            });
            $count += $changed ? 1 : 0;
        }

        return $count;
    }

    private function sessionExists(int $courseId, \DateTimeInterface $startsAt): bool
    {
        $course = $courseId;
        $start = $startsAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $query = $this->db->getQuery(true)
            ->select('1')
            ->from($this->db->quoteName('#__memi_sessions'))
            ->where($this->db->quoteName('course_id') . ' = :course_id')
            ->where($this->db->quoteName('starts_at') . ' = :starts_at')
            ->bind(':course_id', $course, ParameterType::INTEGER)
            ->bind(':starts_at', $start);
        $this->db->setQuery($query, 0, 1);

        return (bool) $this->db->loadResult();
    }

    /** @param array<string,mixed> $rule */
    private function insertSessionIfMissing(array $rule, \DateTimeInterface $startsAt, \DateTimeInterface $endsAt): bool
    {
        return $this->tools->transaction(function () use ($rule, $startsAt, $endsAt): bool {
            // The rule may have been archived after the scheduler took its
            // initial list. Lock and reload it so a catalogue reset cannot
            // leave a newly generated session behind.
            $activeRule = $this->lockActiveRuleForGeneration((int) $rule['id']);
            if ($activeRule === null) {
                return false;
            }

            return $this->insertLockedSessionIfMissing($activeRule, $startsAt, $endsAt);
        });
    }

    /** @return array<string,mixed>|null */
    private function lockActiveRuleForGeneration(int $ruleId): ?array
    {
        $identifier = $ruleId;
        $query = $this->db->getQuery(true)
            ->select([
                'r.*',
                'c.capacity AS course_capacity', 'c.default_duration_minutes', 'c.duration_minutes AS course_duration_minutes',
                'c.credits_required', 'c.price_cents', 'c.tax_rate_basis_points AS course_tax_rate_basis_points',
                'c.booking_opens_offset_minutes AS course_booking_opens_offset_minutes',
                'c.booking_closes_offset_minutes AS course_booking_closes_offset_minutes',
                'c.instructor_id AS course_instructor_id', 'c.room_id AS course_room_id',
            ])
            ->from($this->db->quoteName('#__memi_session_rules', 'r'))
            ->join('INNER', $this->db->quoteName('#__memi_courses', 'c') . ' ON c.id = r.course_id')
            ->where('r.id = :rule_id')
            ->where('r.published = 1')
            ->where('r.archived_at IS NULL')
            ->where('c.published = 1')
            ->where('c.archived_at IS NULL')
            ->bind(':rule_id', $identifier, ParameterType::INTEGER);
        $this->db->setQuery(DatabaseTools::forUpdate($query));

        return $this->db->loadAssoc() ?: null;
    }

    /** @param array<string,mixed> $rule */
    private function insertLockedSessionIfMissing(array $rule, \DateTimeInterface $startsAt, \DateTimeInterface $endsAt): bool
    {
        $courseId = (int) $rule['course_id'];
        if ($this->sessionExists($courseId, $startsAt)) {
            return false;
        }
        $now = gmdate('Y-m-d H:i:s');
        $start = $startsAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $end = $endsAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $capacity = max(1, (int) ($rule['capacity_override'] ?: $rule['course_capacity']));
        $duration = max(1, (int) ($rule['duration_minutes'] ?: $rule['default_duration_minutes'] ?: $rule['course_duration_minutes'] ?: 60));
        $timezone = (string) ($rule['timezone'] ?: $this->settings->timezone()->getName());
        $priceCents = (int) ($rule['price_cents_override'] ?? 0) > 0
            ? max(0, (int) $rule['price_cents_override'])
            : max(0, (int) $rule['price_cents']);
        $creditsRequired = (int) ($rule['credits_required_override'] ?? 0) > 0
            ? max(0, (int) $rule['credits_required_override'])
            : max(0, (int) $rule['credits_required']);
        $taxRateBasisPoints = max(0, (int) ($rule['course_tax_rate_basis_points'] ?? 0));
        $opensOffset = $rule['booking_opens_offset_minutes'] !== null
            ? max(0, (int) $rule['booking_opens_offset_minutes'])
            : max(0, (int) ($rule['course_booking_opens_offset_minutes'] ?? 0));
        $closesOffset = $rule['booking_closes_offset_minutes'] !== null
            ? max(0, (int) $rule['booking_closes_offset_minutes'])
            : max(0, (int) ($rule['course_booking_closes_offset_minutes'] ?? 0));
        $utc = new \DateTimeZone('UTC');
        $registrationOpensAt = $startsAt->modify('-' . $opensOffset . ' minutes')->setTimezone($utc)->format('Y-m-d H:i:s');
        $registrationClosesAt = $startsAt->modify('-' . $closesOffset . ' minutes')->setTimezone($utc)->format('Y-m-d H:i:s');
        $instructor = !empty($rule['instructor_id']) ? (int) $rule['instructor_id'] : (!empty($rule['course_instructor_id']) ? (int) $rule['course_instructor_id'] : null);
        $room = !empty($rule['room_id']) ? (int) $rule['room_id'] : (!empty($rule['course_room_id']) ? (int) $rule['course_room_id'] : null);
        $course = $courseId;
        $ruleId = (int) $rule['id'];
        $published = 'published';
        $query = $this->db->getQuery(true)
            ->insert($this->db->quoteName('#__memi_sessions'))
            ->columns(['course_id', 'rule_id', 'instructor_id', 'room_id', 'starts_at', 'ends_at', 'timezone', 'duration_minutes', 'capacity', 'reserved_count', 'credits_required', 'price_cents', 'tax_rate_basis_points', 'registration_opens_at', 'registration_closes_at', 'status', 'created_at', 'updated_at'])
            ->values(':course_id, :rule_id, :instructor_id, :room_id, :starts_at, :ends_at, :timezone, :duration_minutes, :capacity, 0, :credits_required, :price_cents, :tax_rate_basis_points, :registration_opens_at, :registration_closes_at, :status, :created_at, :updated_at')
            ->bind(':course_id', $course, ParameterType::INTEGER)
            ->bind(':rule_id', $ruleId, ParameterType::INTEGER)
            ->bind(':instructor_id', $instructor, ParameterType::INTEGER)
            ->bind(':room_id', $room, ParameterType::INTEGER)
            ->bind(':starts_at', $start)
            ->bind(':ends_at', $end)
            ->bind(':timezone', $timezone)
            ->bind(':duration_minutes', $duration, ParameterType::INTEGER)
            ->bind(':capacity', $capacity, ParameterType::INTEGER)
            ->bind(':credits_required', $creditsRequired, ParameterType::INTEGER)
            ->bind(':price_cents', $priceCents, ParameterType::INTEGER)
            ->bind(':tax_rate_basis_points', $taxRateBasisPoints, ParameterType::INTEGER)
            ->bind(':registration_opens_at', $registrationOpensAt)
            ->bind(':registration_closes_at', $registrationClosesAt)
            ->bind(':status', $published)
            ->bind(':created_at', $now)
            ->bind(':updated_at', $now);
        try {
            $this->db->setQuery($query)->execute();
            return true;
        } catch (\Throwable $error) {
            // A unique (course_id, starts_at) collision means another cron run
            // won the race; this is the expected idempotent behaviour.
            if (str_contains(strtolower($error->getMessage()), 'duplicate')) {
                return false;
            }
            throw $error;
        }
    }
}
