<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;

/**
 * The authoritative reservation workflow. All capacity, booking and ledger
 * changes execute in a single database transaction and must never be recreated
 * client-side in JavaScript.
 */
final class BookingService
{
    public function __construct(
        private readonly DatabaseDriver $db,
        private readonly DatabaseTools $tools,
        private readonly SettingsService $settings,
        private readonly CreditLedgerService $credits,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications
    ) {
    }

    /**
     * @param array{use_credit?:bool, actor_user_id?:int, source?:string, note?:string, allow_comp?:bool} $options
     * @return array<string, mixed>
     */
    public function reserve(int $userId, int $sessionId, array $options = []): array
    {
        if ($userId <= 0 || $sessionId <= 0) {
            throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
        }

        $actorId = isset($options['actor_user_id']) ? (int) $options['actor_user_id'] : $userId;
        $source = isset($options['source']) ? (string) $options['source'] : 'web';
        $useCredit = !array_key_exists('use_credit', $options) || (bool) $options['use_credit'];

        $result = $this->tools->transaction(function () use ($userId, $sessionId, $actorId, $source, $useCredit, $options): array {
            $profile = $this->tools->lockClientProfile($userId);
            $clientId = (int) $profile['id'];
            $session = $this->lockSession($sessionId);
            $this->assertSessionBookable($session);
            $existing = $this->lockBooking($userId, $sessionId);

            if ($existing && in_array((string) $existing['status'], ['confirmed', 'pending', 'attended'], true)) {
                throw new DomainException('COM_MEMIPILATES_ERROR_ALREADY_BOOKED');
            }

            // Claim capacity atomically. The SQL predicate is the final gate
            // against two requests seeing the final place at the same time.
            $claimed = $this->claimCapacity($sessionId);
            if (!$claimed) {
                throw new DomainException('COM_MEMIPILATES_ERROR_SESSION_FULL');
            }

            $now = gmdate('Y-m-d H:i:s');
            $bookingId = $existing ? (int) $existing['id'] : 0;
            $bookingKey = bin2hex(random_bytes(16));
            $status = 'confirmed';
            $customerPackageId = null;
            $activeBookingKey = hash('sha256', $sessionId . ':' . $userId);
            $note = isset($options['note']) ? (string) $options['note'] : null;

            if ($existing) {
                $id = $bookingId;
                $query = $this->db->getQuery(true)
                    ->update($this->db->quoteName('#__memi_bookings'))
                    ->set($this->db->quoteName('status') . ' = :status')
                    ->set($this->db->quoteName('booking_key') . ' = :booking_key')
                    ->set($this->db->quoteName('active_booking_key') . ' = :active_booking_key')
                    ->set($this->db->quoteName('cancelled_at') . ' = NULL')
                    ->set($this->db->quoteName('cancellation_reason') . ' = NULL')
                    ->set($this->db->quoteName('source') . ' = :source')
                    ->set($this->db->quoteName('confirmed_at') . ' = :confirmed_at')
                    ->set($this->db->quoteName('updated_at') . ' = :updated_at')
                    ->where($this->db->quoteName('id') . ' = :id')
                    ->bind(':status', $status)
                    ->bind(':booking_key', $bookingKey)
                    ->bind(':active_booking_key', $activeBookingKey)
                    ->bind(':source', $source)
                    ->bind(':confirmed_at', $now)
                    ->bind(':updated_at', $now)
                    ->bind(':id', $id, ParameterType::INTEGER);
                $this->db->setQuery($query)->execute();
            } else {
                $user = $userId;
                $sessionIdentifier = $sessionId;
                $query = $this->db->getQuery(true)
                    ->insert($this->db->quoteName('#__memi_bookings'))
                    ->columns([
                        'client_id', 'user_id', 'session_id', 'status', 'booking_key', 'active_booking_key', 'source',
                        'booked_at', 'confirmed_at', 'notes', 'created_at', 'updated_at',
                    ])
                    ->values(':client_id, :user_id, :session_id, :status, :booking_key, :active_booking_key, :source, :booked_at, :confirmed_at, :notes, :created_at, :updated_at')
                    ->bind(':client_id', $clientId, ParameterType::INTEGER)
                    ->bind(':user_id', $user, ParameterType::INTEGER)
                    ->bind(':session_id', $sessionIdentifier, ParameterType::INTEGER)
                    ->bind(':status', $status)
                    ->bind(':booking_key', $bookingKey)
                    ->bind(':active_booking_key', $activeBookingKey)
                    ->bind(':source', $source)
                    ->bind(':booked_at', $now)
                    ->bind(':confirmed_at', $now)
                    ->bind(':notes', $note)
                    ->bind(':created_at', $now)
                    ->bind(':updated_at', $now);
                $this->db->setQuery($query)->execute();
                $bookingId = (int) $this->db->insertid();
            }

            $creditRequired = max(0, (int) ($session['credits_required'] ?? 1));
            if ($creditRequired > 0 && $useCredit) {
                if ($creditRequired !== 1) {
                    // The current ledger represents individual course credits.
                    // More than one is consumed with unique keys for each unit.
                    for ($unit = 0; $unit < $creditRequired; ++$unit) {
                        $this->credits->consumeForBooking(
                            $userId,
                            $bookingId,
                            $sessionId,
                            'booking:' . $bookingId . ':' . $bookingKey . ':credit:' . $unit,
                            $actorId
                        );
                    }
                } else {
                    $this->credits->consumeForBooking($userId, $bookingId, $sessionId, 'booking:' . $bookingId . ':' . $bookingKey . ':credit:0', $actorId);
                }
            } elseif ($creditRequired > 0 && empty($options['allow_comp'])) {
                throw new DomainException('COM_MEMIPILATES_ERROR_CREDIT_REQUIRED');
            }

            $this->audit->log($actorId, 'booking.confirm', 'booking', $bookingId, $existing ?: null, [
                'user_id' => $userId,
                'session_id' => $sessionId,
                'status' => $status,
                'source' => $source,
            ], $options['note'] ?? null);

            return [
                'id' => $bookingId,
                'session_id' => $sessionId,
                'user_id' => $userId,
                'status' => $status,
                'starts_at' => $session['starts_at'],
                'title' => $session['course_title'] ?? '',
                'credits_used' => $creditRequired,
                'customer_package_id' => $customerPackageId,
                'notification_payload' => $this->notificationPayload($session),
            ];
        });

        $notificationPayload = (array) $result['notification_payload'];
        unset($result['notification_payload']);
        $this->notifications->queue($userId, 'booking.confirmed', $notificationPayload);

        return $result;
    }

    /**
     * Applies the configured cancellation window using the site time zone. A
     * credit restoration is deliberately separate from refund processing.
     *
     * @return array{booking_id:int,session_id:int,status:string,credit_restored:bool}
     */
    public function cancel(int $userId, int $bookingId, ?int $actorId = null, ?string $reason = null, bool $forceRestore = false): array
    {
        $actorId ??= $userId;

        $result = $this->tools->transaction(function () use ($userId, $bookingId, $actorId, $reason, $forceRestore): array {
            $booking = $this->tools->lockById('#__memi_bookings', $bookingId);
            if (!$booking || (int) $booking['user_id'] !== $userId) {
                throw new DomainException('COM_MEMIPILATES_ERROR_BOOKING_NOT_FOUND', [], 404);
            }
            if (!in_array((string) $booking['status'], ['confirmed', 'pending'], true)) {
                throw new DomainException('COM_MEMIPILATES_ERROR_BOOKING_NOT_CANCELLABLE');
            }

            $session = $this->lockSession((int) $booking['session_id']);
            $onTime = $forceRestore || $this->isOnTimeCancellation($session);
            $newStatus = $onTime ? 'cancelled_on_time' : 'cancelled_late';
            $now = gmdate('Y-m-d H:i:s');
            $id = $bookingId;
            $query = $this->db->getQuery(true)
                ->update($this->db->quoteName('#__memi_bookings'))
                ->set($this->db->quoteName('status') . ' = :status')
                ->set($this->db->quoteName('cancelled_at') . ' = :cancelled_at')
                ->set($this->db->quoteName('cancelled_by') . ' = :cancelled_by')
                ->set($this->db->quoteName('cancellation_reason') . ' = :reason')
                ->set($this->db->quoteName('active_booking_key') . ' = NULL')
                ->set($this->db->quoteName('credit_restored_at') . ' = NULL')
                ->set($this->db->quoteName('updated_at') . ' = :updated_at')
                ->where($this->db->quoteName('id') . ' = :id')
                ->bind(':status', $newStatus)
                ->bind(':cancelled_at', $now)
                ->bind(':cancelled_by', $actorId, ParameterType::INTEGER)
                ->bind(':reason', $reason)
                ->bind(':updated_at', $now)
                ->bind(':id', $id, ParameterType::INTEGER);
            $this->db->setQuery($query)->execute();
            $this->releaseCapacity((int) $booking['session_id']);

            $restoredCredits = 0;
            if ($onTime) {
                $restoredCredits = $this->credits->restoreForBooking($userId, $bookingId, 'cancellation_restore', 'cancel:' . $bookingId . ':' . (string) $booking['booking_key'], $actorId);
            }
            $creditRestored = $restoredCredits > 0;
            if ($creditRestored) {
                $restoredAt = $this->db->getQuery(true)
                    ->update($this->db->quoteName('#__memi_bookings'))
                    ->set($this->db->quoteName('credit_restored_at') . ' = :credit_restored_at')
                    ->where($this->db->quoteName('id') . ' = :id')
                    ->bind(':credit_restored_at', $now)
                    ->bind(':id', $id, ParameterType::INTEGER);
                $this->db->setQuery($restoredAt)->execute();
            }

            $this->audit->log($actorId, 'booking.cancel', 'booking', $bookingId, $booking, [
                'status' => $newStatus,
                'credit_restored' => $creditRestored,
                'credits_restored' => $restoredCredits,
            ], $reason);

            return [
                'booking_id' => $bookingId,
                'session_id' => (int) $booking['session_id'],
                'status' => $newStatus,
                'credit_restored' => $creditRestored,
                'notification_payload' => $this->notificationPayload($session, [
                    'credit_restored' => $creditRestored ? 1 : 0,
                    'reason' => $reason ?? '',
                ]),
            ];
        });

        $notificationPayload = (array) $result['notification_payload'];
        unset($result['notification_payload']);
        $this->notifications->queue($userId, 'booking.' . $result['status'], $notificationPayload);
        try {
            // This happens only after the cancellation transaction commits.
            // offerNext() itself honors the automatic/manual setting.
            ComponentServices::waitlist()->offerNext($result['session_id']);
        } catch (\Throwable) {
            // A transient notification/offer error must not make an already
            // committed cancellation look failed to the client. The scheduler
            // will retry the eligible waitlist on its next run.
            $this->audit->log($actorId, 'waitlist.offer.deferred', 'session', $result['session_id']);
        }

        return $result;
    }

    /** Cancel a session without refunding money; it restores all used credits. */
    public function cancelSession(int $sessionId, int $actorId, ?string $reason = null): int
    {
        return $this->tools->transaction(function () use ($sessionId, $actorId, $reason): int {
            $session = $this->lockSession($sessionId);
            $now = gmdate('Y-m-d H:i:s');
            $id = $sessionId;
            $cancelledStatus = $this->cancelledStatus();
            $cancel = $this->db->getQuery(true)
                ->update($this->db->quoteName('#__memi_sessions'))
                ->set($this->db->quoteName('status') . ' = :status')
                ->set($this->db->quoteName('reserved_count') . ' = 0')
                ->set($this->db->quoteName('waitlist_count') . ' = 0')
                ->set($this->db->quoteName('cancelled_at') . ' = :cancelled_at')
                ->set($this->db->quoteName('cancelled_by') . ' = :actor')
                ->set($this->db->quoteName('cancellation_reason') . ' = :reason')
                ->set($this->db->quoteName('updated_at') . ' = :updated_at')
                ->where($this->db->quoteName('id') . ' = :id')
                ->bind(':status', $cancelledStatus)
                ->bind(':cancelled_at', $now)
                ->bind(':actor', $actorId, ParameterType::INTEGER)
                ->bind(':reason', $reason)
                ->bind(':updated_at', $now)
                ->bind(':id', $id, ParameterType::INTEGER);
            $this->db->setQuery($cancel)->execute();

            $sessionIdentifier = $sessionId;
            $bookingsQuery = $this->db->getQuery(true)
                ->select('*')
                ->from($this->db->quoteName('#__memi_bookings'))
                ->where($this->db->quoteName('session_id') . ' = :session_id')
                ->where($this->db->quoteName('status') . ' IN (' . $this->db->quote('confirmed') . ', ' . $this->db->quote('pending') . ')')
                ->bind(':session_id', $sessionIdentifier, ParameterType::INTEGER);
            $this->db->setQuery($bookingsQuery);
            $bookings = $this->db->loadAssocList() ?: [];

            foreach ($bookings as $booking) {
                $bookingId = (int) $booking['id'];
                $userId = (int) $booking['user_id'];
                $adminCancelledStatus = $this->adminCancelledStatus();
                $update = $this->db->getQuery(true)
                    ->update($this->db->quoteName('#__memi_bookings'))
                    ->set($this->db->quoteName('status') . ' = :status')
                    ->set($this->db->quoteName('cancelled_at') . ' = :cancelled_at')
                    ->set($this->db->quoteName('cancelled_by') . ' = :actor')
                    ->set($this->db->quoteName('cancellation_reason') . ' = :reason')
                    ->set($this->db->quoteName('active_booking_key') . ' = NULL')
                    ->set($this->db->quoteName('updated_at') . ' = :updated_at')
                    ->where($this->db->quoteName('id') . ' = :id')
                    ->bind(':status', $adminCancelledStatus)
                    ->bind(':cancelled_at', $now)
                    ->bind(':actor', $actorId, ParameterType::INTEGER)
                    ->bind(':reason', $reason)
                    ->bind(':updated_at', $now)
                    ->bind(':id', $bookingId, ParameterType::INTEGER);
                $this->db->setQuery($update)->execute();
                $restored = $this->credits->restoreForBooking($userId, $bookingId, 'studio_cancellation_restore', 'session-cancel:' . $bookingId . ':' . (string) $booking['booking_key'], $actorId);
                $this->notifications->queue($userId, 'session.cancelled', $this->notificationPayload($session, [
                    'reason' => $reason ?? '',
                    'credit_restored' => $restored > 0 ? 1 : 0,
                ]));
            }

            $waiting = 'waiting';
            $offered = 'offered';
            $closed = 'closed';
            $waitlistQuery = $this->db->getQuery(true)
                ->select(['id', 'user_id'])
                ->from($this->db->quoteName('#__memi_waitlist'))
                ->where($this->db->quoteName('session_id') . ' = :session_id')
                ->where($this->db->quoteName('status') . ' IN (:waiting, :offered)')
                ->bind(':session_id', $sessionIdentifier, ParameterType::INTEGER)
                ->bind(':waiting', $waiting)
                ->bind(':offered', $offered);
            $this->db->setQuery($waitlistQuery);
            $waitlistEntries = $this->db->loadAssocList() ?: [];
            if ($waitlistEntries !== []) {
                $waitlistUpdate = $this->db->getQuery(true)
                    ->update($this->db->quoteName('#__memi_waitlist'))
                    ->set($this->db->quoteName('status') . ' = :status')
                    ->set($this->db->quoteName('offer_token_hash') . ' = NULL')
                    ->set($this->db->quoteName('updated_at') . ' = :updated_at')
                    ->where($this->db->quoteName('session_id') . ' = :session_id')
                    ->where($this->db->quoteName('status') . ' IN (:waiting, :offered)')
                    ->bind(':status', $closed)
                    ->bind(':updated_at', $now)
                    ->bind(':session_id', $sessionIdentifier, ParameterType::INTEGER)
                    ->bind(':waiting', $waiting)
                    ->bind(':offered', $offered);
                $this->db->setQuery($waitlistUpdate)->execute();

                foreach ($waitlistEntries as $entry) {
                    $this->notifications->queue((int) $entry['user_id'], 'session.cancelled', $this->notificationPayload($session, [
                        'reason' => $reason ?? '',
                    ]));
                }
            }

            $this->audit->log($actorId, 'session.cancel', 'session', $sessionId, $session, ['status' => $this->cancelledStatus()], $reason);

            return count($bookings);
        });
    }

    /** @return array<string, mixed> */
    private function lockSession(int $sessionId): array
    {
        $session = $this->tools->lockById('#__memi_sessions', $sessionId);
        if (!$session) {
            throw new DomainException('COM_MEMIPILATES_ERROR_SESSION_NOT_FOUND', [], 404);
        }

        $identifier = $sessionId;
        $contextQuery = $this->db->getQuery(true)
            ->select([
                'c.title AS course_title',
                'i.display_name AS instructor_name',
                'r.title AS room_title',
                'l.title AS location_title',
            ])
            ->from($this->db->quoteName('#__memi_sessions', 's'))
            ->join('INNER', $this->db->quoteName('#__memi_courses', 'c') . ' ON c.id = s.course_id')
            ->join('LEFT', $this->db->quoteName('#__memi_instructors', 'i') . ' ON i.id = s.instructor_id')
            ->join('LEFT', $this->db->quoteName('#__memi_rooms', 'r') . ' ON r.id = s.room_id')
            ->join('LEFT', $this->db->quoteName('#__memi_locations', 'l') . ' ON l.id = r.location_id')
            ->where('s.id = :session_id')
            ->bind(':session_id', $identifier, ParameterType::INTEGER);
        $this->db->setQuery($contextQuery, 0, 1);
        $session = array_merge($session, $this->db->loadAssoc() ?: []);

        return $session;
    }

    /**
     * Keep a non-sensitive snapshot in the queued email so a later catalogue
     * edit cannot turn a confirmation or cancellation into an empty message.
     *
     * @param array<string,mixed> $session
     * @param array<string,scalar|null> $extra
     * @return array<string,scalar|null>
     */
    private function notificationPayload(array $session, array $extra = []): array
    {
        return array_merge([
            'session_id' => (int) ($session['id'] ?? 0),
            'session_title' => (string) ($session['course_title'] ?? ''),
            'starts_at' => (string) ($session['starts_at'] ?? ''),
            'instructor_name' => (string) ($session['instructor_name'] ?? ''),
            'room_title' => (string) ($session['room_title'] ?? ''),
            'location_title' => (string) ($session['location_title'] ?? ''),
        ], $extra);
    }

    /** @return array<string, mixed>|null */
    private function lockBooking(int $userId, int $sessionId): ?array
    {
        $user = $userId;
        $session = $sessionId;
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__memi_bookings'))
            ->where($this->db->quoteName('user_id') . ' = :user_id')
            ->where($this->db->quoteName('session_id') . ' = :session_id')
            ->bind(':user_id', $user, ParameterType::INTEGER)
            ->bind(':session_id', $session, ParameterType::INTEGER);
        $this->db->setQuery(DatabaseTools::forUpdate($query));

        return $this->db->loadAssoc() ?: null;
    }

    /** @param array<string,mixed> $session */
    private function assertSessionBookable(array $session): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if (!in_array((string) $session['status'], ['published', 'open'], true)) {
            throw new DomainException('COM_MEMIPILATES_ERROR_SESSION_UNAVAILABLE');
        }
        if (!empty($session['registration_opens_at']) && $now < new \DateTimeImmutable((string) $session['registration_opens_at'], new \DateTimeZone('UTC'))) {
            throw new DomainException('COM_MEMIPILATES_ERROR_REGISTRATION_NOT_OPEN');
        }
        if (!empty($session['registration_closes_at']) && $now >= new \DateTimeImmutable((string) $session['registration_closes_at'], new \DateTimeZone('UTC'))) {
            throw new DomainException('COM_MEMIPILATES_ERROR_REGISTRATION_CLOSED');
        }
        if ($now >= new \DateTimeImmutable((string) $session['starts_at'], new \DateTimeZone('UTC'))) {
            throw new DomainException('COM_MEMIPILATES_ERROR_SESSION_STARTED');
        }
    }

    private function claimCapacity(int $sessionId): bool
    {
        $session = $sessionId;
        $published = 'published';
        $open = 'open';
        $updatedAt = gmdate('Y-m-d H:i:s');
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__memi_sessions'))
            ->set($this->db->quoteName('reserved_count') . ' = ' . $this->db->quoteName('reserved_count') . ' + 1')
            ->set($this->db->quoteName('updated_at') . ' = :updated_at')
            ->where($this->db->quoteName('id') . ' = :session_id')
            ->where($this->db->quoteName('reserved_count') . ' < ' . $this->db->quoteName('capacity'))
            ->where($this->db->quoteName('status') . ' IN (:published, :open)')
            ->bind(':updated_at', $updatedAt)
            ->bind(':session_id', $session, ParameterType::INTEGER)
            ->bind(':published', $published)
            ->bind(':open', $open);
        $this->db->setQuery($query)->execute();

        return $this->db->getAffectedRows() === 1;
    }

    private function releaseCapacity(int $sessionId): void
    {
        $session = $sessionId;
        $updatedAt = gmdate('Y-m-d H:i:s');
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__memi_sessions'))
            ->set($this->db->quoteName('reserved_count') . ' = GREATEST(0, ' . $this->db->quoteName('reserved_count') . ' - 1)')
            ->set($this->db->quoteName('updated_at') . ' = :updated_at')
            ->where($this->db->quoteName('id') . ' = :session_id')
            ->bind(':updated_at', $updatedAt)
            ->bind(':session_id', $session, ParameterType::INTEGER);
        $this->db->setQuery($query)->execute();
    }

    /** @param array<string,mixed> $session */
    private function isOnTimeCancellation(array $session): bool
    {
        $timezone = $this->settings->timezone();
        $start = new \DateTimeImmutable((string) $session['starts_at'], new \DateTimeZone('UTC'));
        $start = $start->setTimezone($timezone);
        $deadline = $start->modify('-' . max(0, $this->settings->getInt('cancellation_hours', 12)) . ' hours');
        $now = new \DateTimeImmutable('now', $timezone);

        return $now <= $deadline;
    }

    private function cancelledStatus(): string
    {
        return 'cancelled';
    }

    private function adminCancelledStatus(): string
    {
        return 'administratively_cancelled';
    }
}
