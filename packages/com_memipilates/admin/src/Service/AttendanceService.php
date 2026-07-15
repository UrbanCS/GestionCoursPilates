<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;

/** Server-side attendance processing for HID, camera and manual kiosk actions. */
final class AttendanceService
{
    public function __construct(
        private readonly DatabaseDriver $db,
        private readonly DatabaseTools $tools,
        private readonly SettingsService $settings,
        private readonly QrTokenService $qrTokens,
        private readonly PointLedgerService $points,
        private readonly AuditLogger $audit
    ) {
    }

    /**
     * @param 'hid'|'camera'|'manual'|'admin'|'import' $method
     * @return array<string, mixed>
     */
    public function scan(int $actorId, int $sessionId, string $token, string $method, string $idempotencyKey, bool $allowOverride = false, ?string $overrideReason = null): array
    {
        if (!in_array($method, ['hid', 'camera', 'manual', 'admin', 'import'], true)
            || !preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $token)
            || strlen($idempotencyKey) < 16 || strlen($idempotencyKey) > 128) {
            $this->recordAttempt($actorId, $sessionId, $token, $method, 'invalid_format');
            throw new DomainException('COM_MEMIPILATES_ERROR_QR_INVALID');
        }

        if (!$this->allowAttempt($actorId, $sessionId)) {
            $this->recordAttempt($actorId, $sessionId, $token, $method, 'rate_limited');
            throw new DomainException('COM_MEMIPILATES_ERROR_SCAN_RATE_LIMITED', [], 429);
        }

        $qr = $this->qrTokens->resolve($token);
        if (!$qr) {
            $this->recordAttempt($actorId, $sessionId, $token, $method, 'invalid_qr');
            throw new DomainException('COM_MEMIPILATES_ERROR_QR_INVALID');
        }

        $userId = (int) $qr['user_id'];
        try {
            $result = $this->tools->transaction(function () use ($actorId, $sessionId, $method, $idempotencyKey, $allowOverride, $overrideReason, $userId): array {
                $profile = $this->tools->lockClientProfile($userId);
                $clientId = (int) $profile['id'];
                $session = $this->tools->lockById('#__memi_sessions', $sessionId);
                if (!$session) {
                    throw new DomainException('COM_MEMIPILATES_ERROR_SESSION_NOT_FOUND', [], 404);
                }
                if (!$this->isSessionActive($session) && !$allowOverride) {
                    throw new DomainException('COM_MEMIPILATES_ERROR_ATTENDANCE_TIME_WINDOW');
                }

                $booking = $this->findBookingForUpdate($userId, $sessionId);
                if (!$booking) {
                    $waitlist = $this->findWaitlist($userId, $sessionId);
                    if ($waitlist) {
                        throw new DomainException('COM_MEMIPILATES_ERROR_ATTENDANCE_WAITLIST');
                    }
                    if (!$allowOverride) {
                        throw new DomainException('COM_MEMIPILATES_ERROR_ATTENDANCE_NO_BOOKING');
                    }
                    $booking = $this->createOverrideBooking($userId, $clientId, $sessionId, $actorId, $overrideReason);
                }

                if (!in_array((string) $booking['status'], ['confirmed', 'attended'], true) && !$allowOverride) {
                    throw new DomainException('COM_MEMIPILATES_ERROR_ATTENDANCE_BOOKING_INVALID');
                }
                $existingAttendance = $this->findAttendance($booking['id'] ?? 0, $idempotencyKey);
                if ($existingAttendance) {
                    return $this->resultForExistingAttendance($existingAttendance, $userId, $sessionId);
                }

                $now = gmdate('Y-m-d H:i:s');
                $bookingId = (int) $booking['id'];
                $bookingIdentifier = $bookingId;
                $actor = $actorId;
                $attendanceStatus = 'confirmed';
                $overrideValue = $allowOverride ? $overrideReason : null;
                $activeAttendanceKey = hash('sha256', $sessionId . ':' . $clientId);
                $insert = $this->db->getQuery(true)
                    ->insert($this->db->quoteName('#__memi_attendance'))
                    ->columns([
                        'client_id', 'user_id', 'session_id', 'booking_id', 'employee_user_id', 'scanned_by_user_id', 'method', 'status',
                        'idempotency_key', 'active_attendance_key', 'override_reason', 'checked_in_at', 'updated_at',
                    ])
                    ->values(':client_id, :user_id, :session_id, :booking_id, :employee_user_id, :scanned_by_user_id, :method, :status, :idempotency_key, :active_attendance_key, :override_reason, :checked_in_at, :updated_at')
                    ->bind(':client_id', $clientId, ParameterType::INTEGER)
                    ->bind(':user_id', $userId, ParameterType::INTEGER)
                    ->bind(':session_id', $sessionId, ParameterType::INTEGER)
                    ->bind(':booking_id', $bookingIdentifier, ParameterType::INTEGER)
                    ->bind(':employee_user_id', $actor, ParameterType::INTEGER)
                    ->bind(':scanned_by_user_id', $actor, ParameterType::INTEGER)
                    ->bind(':method', $method)
                    ->bind(':status', $attendanceStatus)
                    ->bind(':idempotency_key', $idempotencyKey)
                    ->bind(':active_attendance_key', $activeAttendanceKey)
                    ->bind(':override_reason', $overrideValue)
                    ->bind(':checked_in_at', $now)
                    ->bind(':updated_at', $now);
                $this->db->setQuery($insert)->execute();
                $attendanceId = (int) $this->db->insertid();

                $attendedStatus = 'attended';
                $bookingUpdate = $this->db->getQuery(true)
                    ->update($this->db->quoteName('#__memi_bookings'))
                    ->set($this->db->quoteName('status') . ' = :status')
                    ->set($this->db->quoteName('updated_at') . ' = :updated_at')
                    ->where($this->db->quoteName('id') . ' = :id')
                    ->bind(':status', $attendedStatus)
                    ->bind(':updated_at', $now)
                    ->bind(':id', $bookingIdentifier, ParameterType::INTEGER);
                $this->db->setQuery($bookingUpdate)->execute();

                $pointsAdded = 0;
                if ($this->settings->getBool('attendance_auto_points', true)) {
                    $pointsAdded = max(0, $this->settings->getInt('points_per_attendance', 0));
                    $this->points->award(
                        $userId,
                        $pointsAdded,
                        'attendance',
                        'attendance:' . $attendanceId,
                        $attendanceId,
                        null,
                        $actorId,
                        'Présence confirmée'
                    );
                }

                $this->audit->log($actorId, 'attendance.confirm', 'attendance', $attendanceId, null, [
                    'user_id' => $userId,
                    'session_id' => $sessionId,
                    'booking_id' => $bookingId,
                    'method' => $method,
                    'override' => $allowOverride,
                ], $allowOverride ? $overrideReason : null);

                return $this->successResult($userId, $sessionId, $attendanceId, $pointsAdded, false);
            });
            $this->recordAttempt($actorId, $sessionId, $token, $method, 'confirmed');

            return $result;
        } catch (DomainException $error) {
            $this->recordAttempt($actorId, $sessionId, $token, $method, $error->getMessage());
            throw $error;
        }
    }

    /**
     * Explicit manual fallback for an authorised employee. It follows the same
     * transaction/idempotency rules as QR scanning without attempting to
     * reconstruct or expose a client's QR token.
     *
     * @return array<string, mixed>
     */
    public function manualCheckIn(int $actorId, int $sessionId, int $userId, string $idempotencyKey, bool $allowOverride = false, ?string $overrideReason = null): array
    {
        if ($userId <= 0 || strlen($idempotencyKey) < 16 || strlen($idempotencyKey) > 128) {
            throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
        }

        return $this->tools->transaction(function () use ($actorId, $sessionId, $userId, $idempotencyKey, $allowOverride, $overrideReason): array {
            $profile = $this->tools->lockClientProfile($userId);
            $clientId = (int) $profile['id'];
            $session = $this->tools->lockById('#__memi_sessions', $sessionId);
            if (!$session) {
                throw new DomainException('COM_MEMIPILATES_ERROR_SESSION_NOT_FOUND', [], 404);
            }
            if (!$this->isSessionActive($session) && !$allowOverride) {
                throw new DomainException('COM_MEMIPILATES_ERROR_ATTENDANCE_TIME_WINDOW');
            }
            $booking = $this->findBookingForUpdate($userId, $sessionId);
            if (!$booking) {
                if (!$allowOverride) {
                    throw new DomainException('COM_MEMIPILATES_ERROR_ATTENDANCE_NO_BOOKING');
                }
                $booking = $this->createOverrideBooking($userId, $clientId, $sessionId, $actorId, $overrideReason);
            }
            if (!in_array((string) $booking['status'], ['confirmed', 'attended'], true) && !$allowOverride) {
                throw new DomainException('COM_MEMIPILATES_ERROR_ATTENDANCE_BOOKING_INVALID');
            }
            $existing = $this->findAttendance((int) $booking['id'], $idempotencyKey);
            if ($existing) {
                return $this->resultForExistingAttendance($existing, $userId, $sessionId);
            }
            $now = gmdate('Y-m-d H:i:s');
            $bookingId = (int) $booking['id'];
            $attendanceStatus = 'confirmed';
            $manualMethod = 'manual';
            $overrideValue = $allowOverride ? $overrideReason : null;
            $activeAttendanceKey = hash('sha256', $sessionId . ':' . $clientId);
            $query = $this->db->getQuery(true)
                ->insert($this->db->quoteName('#__memi_attendance'))
                ->columns(['client_id', 'user_id', 'session_id', 'booking_id', 'employee_user_id', 'scanned_by_user_id', 'method', 'status', 'idempotency_key', 'active_attendance_key', 'override_reason', 'checked_in_at', 'updated_at'])
                ->values(':client_id, :user_id, :session_id, :booking_id, :employee_user_id, :scanned_by_user_id, :method, :status, :idempotency_key, :active_attendance_key, :override_reason, :checked_in_at, :updated_at')
                ->bind(':client_id', $clientId, ParameterType::INTEGER)
                ->bind(':user_id', $userId, ParameterType::INTEGER)
                ->bind(':session_id', $sessionId, ParameterType::INTEGER)
                ->bind(':booking_id', $bookingId, ParameterType::INTEGER)
                ->bind(':employee_user_id', $actorId, ParameterType::INTEGER)
                ->bind(':scanned_by_user_id', $actorId, ParameterType::INTEGER)
                ->bind(':method', $manualMethod)
                ->bind(':status', $attendanceStatus)
                ->bind(':idempotency_key', $idempotencyKey)
                ->bind(':active_attendance_key', $activeAttendanceKey)
                ->bind(':override_reason', $overrideValue)
                ->bind(':checked_in_at', $now)
                ->bind(':updated_at', $now);
            $this->db->setQuery($query)->execute();
            $attendanceId = (int) $this->db->insertid();
            $attendedStatus = 'attended';
            $update = $this->db->getQuery(true)
                ->update($this->db->quoteName('#__memi_bookings'))
                ->set($this->db->quoteName('status') . ' = :status')
                ->set($this->db->quoteName('updated_at') . ' = :updated_at')
                ->where($this->db->quoteName('id') . ' = :id')
                ->bind(':status', $attendedStatus)
                ->bind(':updated_at', $now)
                ->bind(':id', $bookingId, ParameterType::INTEGER);
            $this->db->setQuery($update)->execute();
            $pointsAdded = 0;
            if ($this->settings->getBool('attendance_auto_points', true)) {
                $pointsAdded = max(0, $this->settings->getInt('points_per_attendance', 0));
                $this->points->award($userId, $pointsAdded, 'attendance', 'attendance:' . $attendanceId, $attendanceId, null, $actorId, 'Présence confirmée manuellement');
            }
            $this->audit->log($actorId, 'attendance.manual', 'attendance', $attendanceId, null, [
                'user_id' => $userId,
                'session_id' => $sessionId,
                'booking_id' => $bookingId,
                'override' => $allowOverride,
            ], $overrideReason);

            return $this->successResult($userId, $sessionId, $attendanceId, $pointsAdded, false);
        });
    }

    /**
     * Test mode is intentionally pure: it returns diagnostic metadata only and
     * neither resolves a QR token nor writes a scan attempt.
     *
     * @return array{valid_format:bool,length:int,duration_ms:int,enter_detected:bool,focus:boolean}
     */
    public function testInput(string $candidate, int $durationMs, bool $enterDetected, bool $hasFocus): array
    {
        return [
            'valid_format' => (bool) preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $candidate),
            'length' => strlen($candidate),
            'duration_ms' => max(0, min($durationMs, 60000)),
            'enter_detected' => $enterDetected,
            'focus' => $hasFocus,
        ];
    }

    /** Remove a mistaken attendance and reverse its idempotent point entry. */
    public function undo(int $actorId, int $attendanceId, ?string $reason = null): void
    {
        $this->tools->transaction(function () use ($actorId, $attendanceId, $reason): void {
            $attendance = $this->tools->lockById('#__memi_attendance', $attendanceId);
            if (!$attendance || (string) $attendance['status'] !== 'confirmed') {
                throw new DomainException('COM_MEMIPILATES_ERROR_ATTENDANCE_NOT_FOUND', [], 404);
            }
            $now = gmdate('Y-m-d H:i:s');
            $id = $attendanceId;
            $voidStatus = 'void';
            $activeAttendanceKey = null;
            $update = $this->db->getQuery(true)
                ->update($this->db->quoteName('#__memi_attendance'))
                ->set($this->db->quoteName('status') . ' = :status')
                ->set($this->db->quoteName('active_attendance_key') . ' = :active_attendance_key')
                ->set($this->db->quoteName('voided_at') . ' = :voided_at')
                ->set($this->db->quoteName('voided_by') . ' = :voided_by')
                ->set($this->db->quoteName('note') . ' = :reason')
                ->set($this->db->quoteName('updated_by') . ' = :updated_by')
                ->set($this->db->quoteName('updated_at') . ' = :updated_at')
                ->where($this->db->quoteName('id') . ' = :id')
                ->bind(':status', $voidStatus)
                ->bind(':active_attendance_key', $activeAttendanceKey)
                ->bind(':voided_at', $now)
                ->bind(':voided_by', $actorId, ParameterType::INTEGER)
                ->bind(':reason', $reason)
                ->bind(':updated_by', $actorId, ParameterType::INTEGER)
                ->bind(':updated_at', $now)
                ->bind(':id', $id, ParameterType::INTEGER);
            $this->db->setQuery($update)->execute();

            $bookingId = $attendance['booking_id'] === null ? null : (int) $attendance['booking_id'];
            if ($bookingId !== null) {
                $confirmedStatus = 'confirmed';
                $bookingUpdate = $this->db->getQuery(true)
                    ->update($this->db->quoteName('#__memi_bookings'))
                    ->set($this->db->quoteName('status') . ' = :status')
                    ->set($this->db->quoteName('updated_at') . ' = :updated_at')
                    ->where($this->db->quoteName('id') . ' = :id')
                    ->bind(':status', $confirmedStatus)
                    ->bind(':updated_at', $now)
                    ->bind(':id', $bookingId, ParameterType::INTEGER);
                $this->db->setQuery($bookingUpdate)->execute();
            }

            $pointKey = 'attendance:' . $attendanceId;
            $this->reversePointsIfAwarded((int) $attendance['user_id'], $attendanceId, $pointKey, $actorId, $reason);
            $this->audit->log($actorId, 'attendance.undo', 'attendance', $attendanceId, $attendance, ['status' => 'void'], $reason);
        });
    }

    /** @param array<string,mixed> $session */
    private function isSessionActive(array $session): bool
    {
        if (!in_array((string) $session['status'], ['published', 'open'], true)) {
            return false;
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $before = max(0, $this->settings->getInt('attendance_before_minutes', 30));
        $after = max(0, $this->settings->getInt('attendance_after_minutes', 30));
        $start = new \DateTimeImmutable((string) $session['starts_at'], new \DateTimeZone('UTC'));
        $end = !empty($session['ends_at']) ? new \DateTimeImmutable((string) $session['ends_at'], new \DateTimeZone('UTC')) : $start;

        return $now >= $start->modify('-' . $before . ' minutes') && $now <= $end->modify('+' . $after . ' minutes');
    }

    /** @return array<string,mixed>|null */
    private function findBookingForUpdate(int $userId, int $sessionId): ?array
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

    /** @return array<string,mixed>|null */
    private function findWaitlist(int $userId, int $sessionId): ?array
    {
        $user = $userId;
        $session = $sessionId;
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__memi_waitlist'))
            ->where($this->db->quoteName('user_id') . ' = :user_id')
            ->where($this->db->quoteName('session_id') . ' = :session_id')
            ->where($this->db->quoteName('status') . ' IN (' . $this->db->quote('waiting') . ', ' . $this->db->quote('offered') . ')')
            ->bind(':user_id', $user, ParameterType::INTEGER)
            ->bind(':session_id', $session, ParameterType::INTEGER);
        $this->db->setQuery($query, 0, 1);

        return $this->db->loadAssoc() ?: null;
    }

    /** @return array<string,mixed> */
    private function createOverrideBooking(int $userId, int $clientId, int $sessionId, int $actorId, ?string $reason): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $user = $userId;
        $session = $sessionId;
        $key = bin2hex(random_bytes(16));
        $confirmedStatus = 'confirmed';
        $activeBookingKey = hash('sha256', $sessionId . ':' . $userId);
        $overrideSource = 'attendance_override';
        $insert = $this->db->getQuery(true)
            ->insert($this->db->quoteName('#__memi_bookings'))
            ->columns(['client_id', 'user_id', 'session_id', 'status', 'booking_key', 'active_booking_key', 'source', 'booked_at', 'confirmed_at', 'notes', 'created_at', 'updated_at'])
            ->values(':client_id, :user_id, :session_id, :status, :booking_key, :active_booking_key, :source, :booked_at, :confirmed_at, :notes, :created_at, :updated_at')
            ->bind(':client_id', $clientId, ParameterType::INTEGER)
            ->bind(':user_id', $user, ParameterType::INTEGER)
            ->bind(':session_id', $session, ParameterType::INTEGER)
            ->bind(':status', $confirmedStatus)
            ->bind(':booking_key', $key)
            ->bind(':active_booking_key', $activeBookingKey)
            ->bind(':source', $overrideSource)
            ->bind(':booked_at', $now)
            ->bind(':confirmed_at', $now)
            ->bind(':notes', $reason)
            ->bind(':created_at', $now)
            ->bind(':updated_at', $now);
        $this->db->setQuery($insert)->execute();
        $id = (int) $this->db->insertid();

        return ['id' => $id, 'user_id' => $userId, 'session_id' => $sessionId, 'status' => 'confirmed'];
    }

    /** @return array<string,mixed>|null */
    private function findAttendance(int $bookingId, string $idempotencyKey): ?array
    {
        $booking = $bookingId;
        $key = $idempotencyKey;
        $confirmedStatus = 'confirmed';
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__memi_attendance'))
            ->where('(' . $this->db->quoteName('booking_id') . ' = :booking_id OR ' . $this->db->quoteName('idempotency_key') . ' = :idempotency_key)')
            ->where($this->db->quoteName('status') . ' = :status')
            ->bind(':booking_id', $booking, ParameterType::INTEGER)
            ->bind(':idempotency_key', $key)
            ->bind(':status', $confirmedStatus);
        $this->db->setQuery($query, 0, 1);

        return $this->db->loadAssoc() ?: null;
    }

    /** @return array<string,mixed> */
    private function successResult(int $userId, int $sessionId, int $attendanceId, int $pointsAdded, bool $alreadyRegistered): array
    {
        $user = $userId;
        $session = $sessionId;
        $query = $this->db->getQuery(true)
            ->select(['u.name', 'c.title AS course_title'])
            ->from($this->db->quoteName('#__users', 'u'))
            ->join('INNER', $this->db->quoteName('#__memi_sessions', 's') . ' ON s.id = :session_id')
            ->join('INNER', $this->db->quoteName('#__memi_courses', 'c') . ' ON c.id = s.course_id')
            ->where('u.id = :user_id')
            ->bind(':session_id', $session, ParameterType::INTEGER)
            ->bind(':user_id', $user, ParameterType::INTEGER);
        $this->db->setQuery($query);
        $details = $this->db->loadAssoc() ?: ['name' => '', 'course_title' => ''];

        return [
            'success' => true,
            'status' => $alreadyRegistered ? 'already_registered' : 'confirmed',
            'attendance_id' => $attendanceId,
            'client' => (string) $details['name'],
            'course' => (string) $details['course_title'],
            'points_added' => $pointsAdded,
            'points_balance' => $this->points->balance($userId),
        ];
    }

    /** @param array<string,mixed> $attendance @return array<string,mixed> */
    private function resultForExistingAttendance(array $attendance, int $userId, int $sessionId): array
    {
        return $this->successResult($userId, $sessionId, (int) $attendance['id'], 0, true);
    }

    private function recordAttempt(int $actorId, int $sessionId, string $token, string $method, string $result): void
    {
        try {
            $now = gmdate('Y-m-d H:i:s');
            $actor = $actorId;
            $session = $sessionId;
            $hash = hash('sha256', $token);
            $outcome = mb_substr($result, 0, 32);
            $query = $this->db->getQuery(true)
                ->insert($this->db->quoteName('#__memi_scan_attempts'))
                ->columns(['scanned_by_user_id', 'session_id', 'token_hash', 'method', 'outcome', 'created_at'])
                ->values(':scanned_by_user_id, :session_id, :token_hash, :method, :outcome, :created_at')
                ->bind(':scanned_by_user_id', $actor, ParameterType::INTEGER)
                ->bind(':session_id', $session, ParameterType::INTEGER)
                ->bind(':token_hash', $hash)
                ->bind(':method', $method)
                ->bind(':outcome', $outcome)
                ->bind(':created_at', $now);
            $this->db->setQuery($query)->execute();
        } catch (\Throwable) {
            // Audit telemetry must not turn a valid attendance into a failure.
        }
    }

    private function allowAttempt(int $actorId, int $sessionId): bool
    {
        $windowStart = gmdate('Y-m-d H:i:s', time() - 60);
        $actor = $actorId;
        $session = $sessionId;
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__memi_scan_attempts'))
            ->where($this->db->quoteName('scanned_by_user_id') . ' = :scanned_by_user_id')
            ->where($this->db->quoteName('session_id') . ' = :session_id')
            ->where($this->db->quoteName('created_at') . ' >= :window_start')
            ->bind(':scanned_by_user_id', $actor, ParameterType::INTEGER)
            ->bind(':session_id', $session, ParameterType::INTEGER)
            ->bind(':window_start', $windowStart);
        $this->db->setQuery($query);

        return (int) $this->db->loadResult() < max(5, $this->settings->getInt('scan_max_attempts_per_minute', 60));
    }

    private function reversePointsIfAwarded(int $userId, int $attendanceId, string $sourceKey, int $actorId, ?string $reason): void
    {
        $key = $sourceKey;
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('points_delta'))
            ->from($this->db->quoteName('#__memi_points_ledger'))
            ->where($this->db->quoteName('idempotency_key') . ' = :idempotency_key')
            ->bind(':idempotency_key', $key);
        $this->db->setQuery($query);
        $awarded = (int) $this->db->loadResult();
        if ($awarded > 0) {
            $this->points->award($userId, -$awarded, 'attendance_reversal', 'attendance-reversal:' . $attendanceId, $attendanceId, null, $actorId, $reason ?: 'Correction de présence');
        }
    }
}
