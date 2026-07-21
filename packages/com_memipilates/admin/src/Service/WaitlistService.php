<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;

/** Ordered, expiring wait-list offers with no credit deducted until acceptance. */
final class WaitlistService
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

    /** @return array{waitlist_id:int,position:int,status:string} */
    public function join(int $userId, int $sessionId, ?int $actorId = null): array
    {
        $actorId ??= $userId;

        $result = $this->tools->transaction(function () use ($userId, $sessionId, $actorId): array {
            $profile = $this->tools->lockClientProfile($userId);
            $clientId = (int) $profile['id'];
            $session = $this->tools->lockById('#__memi_sessions', $sessionId);
            if (!$session || !in_array((string) $session['status'], ['published', 'open'], true)) {
                throw new DomainException('COM_MEMIPILATES_ERROR_SESSION_UNAVAILABLE');
            }
            $this->assertSessionRegistrationOpen($session);
            if ((int) $session['reserved_count'] < (int) $session['capacity']) {
                throw new DomainException('COM_MEMIPILATES_ERROR_SESSION_HAS_SPACE');
            }

            $user = $userId;
            $sessionIdentifier = $sessionId;
            $find = $this->db->getQuery(true)
                ->select('*')
                ->from($this->db->quoteName('#__memi_waitlist'))
                ->where($this->db->quoteName('user_id') . ' = :user_id')
                ->where($this->db->quoteName('session_id') . ' = :session_id')
                ->bind(':user_id', $user, ParameterType::INTEGER)
                ->bind(':session_id', $sessionIdentifier, ParameterType::INTEGER);
            $this->db->setQuery(DatabaseTools::forUpdate($find));
            $existing = $this->db->loadAssoc();
            if ($existing && in_array((string) $existing['status'], ['waiting', 'offered'], true)) {
                throw new DomainException('COM_MEMIPILATES_ERROR_ALREADY_WAITLISTED');
            }

            $position = $this->nextPosition($sessionId);
            $now = gmdate('Y-m-d H:i:s');
            $waiting = $this->waitingStatus();
            if ($existing) {
                $id = (int) $existing['id'];
                $update = $this->db->getQuery(true)
                    ->update($this->db->quoteName('#__memi_waitlist'))
                    ->set($this->db->quoteName('status') . ' = :status')
                    ->set($this->db->quoteName('position') . ' = :position')
                    ->set($this->db->quoteName('withdrawn_at') . ' = NULL')
                    ->set($this->db->quoteName('updated_at') . ' = :updated_at')
                    ->where($this->db->quoteName('id') . ' = :id')
                    ->bind(':status', $waiting)
                    ->bind(':position', $position, ParameterType::INTEGER)
                    ->bind(':updated_at', $now)
                    ->bind(':id', $id, ParameterType::INTEGER);
                $this->db->setQuery($update)->execute();
                $waitlistId = $id;
            } else {
                $idempotencyKey = 'waitlist:' . $sessionId . ':' . $userId . ':' . bin2hex(random_bytes(8));
                $insert = $this->db->getQuery(true)
                    ->insert($this->db->quoteName('#__memi_waitlist'))
                    ->columns(['client_id', 'user_id', 'session_id', 'status', 'position', 'idempotency_key', 'joined_at', 'created_at', 'updated_at'])
                    ->values(':client_id, :user_id, :session_id, :status, :position, :idempotency_key, :joined_at, :created_at, :updated_at')
                    ->bind(':client_id', $clientId, ParameterType::INTEGER)
                    ->bind(':user_id', $user, ParameterType::INTEGER)
                    ->bind(':session_id', $sessionIdentifier, ParameterType::INTEGER)
                    ->bind(':status', $waiting)
                    ->bind(':position', $position, ParameterType::INTEGER)
                    ->bind(':idempotency_key', $idempotencyKey)
                    ->bind(':joined_at', $now)
                    ->bind(':created_at', $now)
                    ->bind(':updated_at', $now);
                $this->db->setQuery($insert)->execute();
                $waitlistId = (int) $this->db->insertid();
            }
            $this->syncWaitlistCount($sessionId, $now);

            $this->audit->log($actorId, 'waitlist.join', 'waitlist', $waitlistId, null, [
                'user_id' => $userId,
                'session_id' => $sessionId,
                'position' => $position,
            ]);

            return ['waitlist_id' => $waitlistId, 'position' => $position, 'status' => $this->waitingStatus()];
        });
        $this->notifications->queue($userId, 'waitlist.joined', ['position' => $result['position']]);

        return $result;
    }

    public function leave(int $userId, int $waitlistId, ?int $actorId = null): void
    {
        $actorId ??= $userId;
        $result = $this->tools->transaction(function () use ($userId, $waitlistId, $actorId): array {
            $entry = $this->tools->lockById('#__memi_waitlist', $waitlistId);
            if (!$entry || (int) $entry['user_id'] !== $userId) {
                throw new DomainException('COM_MEMIPILATES_ERROR_WAITLIST_NOT_FOUND', [], 404);
            }
            if (!in_array((string) $entry['status'], ['waiting', 'offered'], true)) {
                throw new DomainException('COM_MEMIPILATES_ERROR_WAITLIST_NOT_ACTIVE');
            }
            $now = gmdate('Y-m-d H:i:s');
            $hadOffer = (string) $entry['status'] === 'offered';
            $id = $waitlistId;
            $left = 'left';
            $update = $this->db->getQuery(true)
                ->update($this->db->quoteName('#__memi_waitlist'))
                ->set($this->db->quoteName('status') . ' = :status')
                ->set($this->db->quoteName('withdrawn_at') . ' = :withdrawn_at')
                ->set($this->db->quoteName('offer_token_hash') . ' = NULL')
                ->set($this->db->quoteName('offer_expires_at') . ' = NULL')
                ->set($this->db->quoteName('updated_at') . ' = :updated_at')
                ->where($this->db->quoteName('id') . ' = :id')
                ->bind(':status', $left)
                ->bind(':withdrawn_at', $now)
                ->bind(':updated_at', $now)
                ->bind(':id', $id, ParameterType::INTEGER);
            $this->db->setQuery($update)->execute();
            if ($hadOffer) {
                $this->releaseCapacity((int) $entry['session_id']);
            }
            $this->syncWaitlistCount((int) $entry['session_id'], $now);
            $this->audit->log($actorId, 'waitlist.leave', 'waitlist', $waitlistId, $entry, ['status' => 'left']);

            return ['session_id' => (int) $entry['session_id'], 'released_offer' => $hadOffer];
        });
        if ($result['released_offer']) {
            $this->offerNext($result['session_id']);
        }
    }

    /**
     * Offer the next person exactly once. The raw acceptance token is returned
     * only to be embedded in a secure email link, never persisted or logged.
     *
     * @return array{waitlist_id:int,user_id:int,token:string,expires_at:string}|null
     */
    public function offerNext(int $sessionId, ?int $actorId = null, bool $manual = false): ?array
    {
        if (!$manual) {
            $mode = strtolower(trim((string) $this->settings->get('waitlist_promotion_mode', 'automatic')));
            if ($mode !== 'automatic' || !$this->settings->getBool('waitlist_auto_promote', true)) {
                return null;
            }
        }

        $offer = $this->tools->transaction(function () use ($sessionId, $actorId): ?array {
            $session = $this->tools->lockById('#__memi_sessions', $sessionId);
            if (!$session || (int) $session['reserved_count'] >= (int) $session['capacity'] || !in_array((string) $session['status'], ['published', 'open'], true) || !$this->isSessionRegistrationOpen($session)) {
                return null;
            }

            $sessionIdentifier = $sessionId;
            $waiting = $this->waitingStatus();
            $query = $this->db->getQuery(true)
                ->select('*')
                ->from($this->db->quoteName('#__memi_waitlist'))
                ->where($this->db->quoteName('session_id') . ' = :session_id')
                ->where($this->db->quoteName('status') . ' = :status')
                ->order($this->db->quoteName('position') . ' ASC, ' . $this->db->quoteName('created_at') . ' ASC')
                ->bind(':session_id', $sessionIdentifier, ParameterType::INTEGER)
                ->bind(':status', $waiting);
            $query->setLimit(1);
            $this->db->setQuery(DatabaseTools::forUpdate($query));
            $entry = $this->db->loadAssoc();
            if (!$entry) {
                return null;
            }
            if (!$this->claimCapacity($sessionId)) {
                return null;
            }

            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $hash = hash('sha256', $token);
            $expires = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->modify('+' . max(5, $this->settings->getInt('waitlist_offer_minutes', 60)) . ' minutes');
            $expiresAt = $expires->format('Y-m-d H:i:s');
            $now = gmdate('Y-m-d H:i:s');
            $id = (int) $entry['id'];
            $offered = 'offered';
            $update = $this->db->getQuery(true)
                ->update($this->db->quoteName('#__memi_waitlist'))
                ->set($this->db->quoteName('status') . ' = :status')
                ->set($this->db->quoteName('offer_token_hash') . ' = :offer_token_hash')
                ->set($this->db->quoteName('offered_at') . ' = :offered_at')
                ->set($this->db->quoteName('offer_expires_at') . ' = :offer_expires_at')
                ->set($this->db->quoteName('updated_at') . ' = :updated_at')
                ->where($this->db->quoteName('id') . ' = :id')
                ->bind(':status', $offered)
                ->bind(':offer_token_hash', $hash)
                ->bind(':offered_at', $now)
                ->bind(':offer_expires_at', $expiresAt)
                ->bind(':updated_at', $now)
                ->bind(':id', $id, ParameterType::INTEGER);
            $this->db->setQuery($update)->execute();
            $this->audit->log($actorId, 'waitlist.offer', 'waitlist', $id, $entry, [
                'user_id' => (int) $entry['user_id'],
                'session_id' => $sessionId,
                'expires_at' => $expiresAt,
            ]);

            return ['waitlist_id' => $id, 'user_id' => (int) $entry['user_id'], 'token' => $token, 'expires_at' => $expiresAt];
        });

        if ($offer !== null) {
            $this->notifications->queue($offer['user_id'], 'waitlist.offer', [
                'waitlist_id' => $offer['waitlist_id'],
                'acceptance_token' => $offer['token'],
                'expires_at' => $offer['expires_at'],
            ]);
        }

        return $offer;
    }

    /** @return array{booking_id:int,status:string} */
    public function acceptOffer(int $userId, int $waitlistId, string $token, ?int $actorId = null): array
    {
        $actorId ??= $userId;
        if (!preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $token)) {
            throw new DomainException('COM_MEMIPILATES_ERROR_WAITLIST_OFFER_INVALID');
        }

        return $this->tools->transaction(function () use ($userId, $waitlistId, $token, $actorId): array {
            $profile = $this->tools->lockClientProfile($userId);
            $clientId = (int) $profile['id'];
            $entry = $this->tools->lockById('#__memi_waitlist', $waitlistId);
            if (!$entry || (int) $entry['user_id'] !== $userId || (string) $entry['status'] !== 'offered') {
                throw new DomainException('COM_MEMIPILATES_ERROR_WAITLIST_OFFER_INVALID');
            }
            if (empty($entry['offer_expires_at']) || new \DateTimeImmutable((string) $entry['offer_expires_at'], new \DateTimeZone('UTC')) < new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
                throw new DomainException('COM_MEMIPILATES_ERROR_WAITLIST_OFFER_EXPIRED');
            }
            if (!hash_equals((string) $entry['offer_token_hash'], hash('sha256', $token))) {
                throw new DomainException('COM_MEMIPILATES_ERROR_WAITLIST_OFFER_INVALID');
            }

            $sessionId = (int) $entry['session_id'];
            $session = $this->tools->lockById('#__memi_sessions', $sessionId);
            if (!$session || !in_array((string) $session['status'], ['published', 'open'], true)) {
                throw new DomainException('COM_MEMIPILATES_ERROR_SESSION_UNAVAILABLE');
            }
            $this->assertSessionRegistrationOpen($session);

            $now = gmdate('Y-m-d H:i:s');
            $bookingKey = bin2hex(random_bytes(16));
            $confirmed = 'confirmed';
            $activeBookingKey = hash('sha256', $sessionId . ':' . $userId);
            $waitlistSource = 'waitlist';
            $existing = $this->findBookingForUpdate($userId, $sessionId);
            if ($existing && in_array((string) $existing['status'], ['confirmed', 'pending', 'attended'], true)) {
                throw new DomainException('COM_MEMIPILATES_ERROR_ALREADY_BOOKED');
            }

            if ($existing) {
                $bookingId = (int) $existing['id'];
                $id = $bookingId;
                $update = $this->db->getQuery(true)
                    ->update($this->db->quoteName('#__memi_bookings'))
                    ->set($this->db->quoteName('status') . ' = :status')
                    ->set($this->db->quoteName('booking_key') . ' = :booking_key')
                    ->set($this->db->quoteName('active_booking_key') . ' = :active_booking_key')
                    ->set($this->db->quoteName('source') . ' = :source')
                    ->set($this->db->quoteName('updated_at') . ' = :updated_at')
                    ->where($this->db->quoteName('id') . ' = :id')
                    ->bind(':status', $confirmed)
                    ->bind(':booking_key', $bookingKey)
                    ->bind(':active_booking_key', $activeBookingKey)
                    ->bind(':source', $waitlistSource)
                    ->bind(':updated_at', $now)
                    ->bind(':id', $id, ParameterType::INTEGER);
                $this->db->setQuery($update)->execute();
            } else {
                $user = $userId;
                $sessionIdentifier = $sessionId;
                $insert = $this->db->getQuery(true)
                    ->insert($this->db->quoteName('#__memi_bookings'))
                    ->columns(['client_id', 'user_id', 'session_id', 'status', 'booking_key', 'active_booking_key', 'source', 'booked_at', 'confirmed_at', 'created_at', 'updated_at'])
                    ->values(':client_id, :user_id, :session_id, :status, :booking_key, :active_booking_key, :source, :booked_at, :confirmed_at, :created_at, :updated_at')
                    ->bind(':client_id', $clientId, ParameterType::INTEGER)
                    ->bind(':user_id', $user, ParameterType::INTEGER)
                    ->bind(':session_id', $sessionIdentifier, ParameterType::INTEGER)
                    ->bind(':status', $confirmed)
                    ->bind(':booking_key', $bookingKey)
                    ->bind(':active_booking_key', $activeBookingKey)
                    ->bind(':source', $waitlistSource)
                    ->bind(':booked_at', $now)
                    ->bind(':confirmed_at', $now)
                    ->bind(':created_at', $now)
                    ->bind(':updated_at', $now);
                $this->db->setQuery($insert)->execute();
                $bookingId = (int) $this->db->insertid();
            }

            $required = max(0, (int) ($session['credits_required'] ?? 1));
            for ($unit = 0; $unit < $required; ++$unit) {
                $this->credits->consumeForBooking($userId, $bookingId, $sessionId, 'waitlist:' . $waitlistId . ':credit:' . $unit, $actorId);
            }

            $id = $waitlistId;
            $accepted = 'accepted';
            $updateWaitlist = $this->db->getQuery(true)
                ->update($this->db->quoteName('#__memi_waitlist'))
                ->set($this->db->quoteName('status') . ' = :status')
                ->set($this->db->quoteName('accepted_at') . ' = :accepted_at')
                ->set($this->db->quoteName('offer_token_hash') . ' = NULL')
                ->set($this->db->quoteName('offer_expires_at') . ' = NULL')
                ->set($this->db->quoteName('promoted_booking_id') . ' = :promoted_booking_id')
                ->set($this->db->quoteName('updated_at') . ' = :updated_at')
                ->where($this->db->quoteName('id') . ' = :id')
                ->bind(':status', $accepted)
                ->bind(':accepted_at', $now)
                ->bind(':promoted_booking_id', $bookingId, ParameterType::INTEGER)
                ->bind(':updated_at', $now)
                ->bind(':id', $id, ParameterType::INTEGER);
            $this->db->setQuery($updateWaitlist)->execute();
            $this->syncWaitlistCount($sessionId, $now);
            $this->audit->log($actorId, 'waitlist.accept', 'waitlist', $waitlistId, $entry, ['booking_id' => $bookingId]);

            return ['booking_id' => $bookingId, 'status' => 'confirmed'];
        });
    }

    /** @return int number of expired offers */
    public function expireOffers(): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $offered = 'offered';
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__memi_waitlist'))
            ->where($this->db->quoteName('status') . ' = :status')
            ->where($this->db->quoteName('offer_expires_at') . ' <= :now')
            ->bind(':status', $offered)
            ->bind(':now', $now);
        $this->db->setQuery($query);
        $ids = array_map('intval', $this->db->loadColumn() ?: []);
        $sessions = [];

        foreach ($ids as $id) {
            $sessionId = $this->tools->transaction(function () use ($id, $now): ?int {
                $entry = $this->tools->lockById('#__memi_waitlist', $id);
                if (!$entry || (string) $entry['status'] !== 'offered' || (string) $entry['offer_expires_at'] > $now) {
                    return null;
                }
                $identifier = $id;
                $expired = 'expired';
                $update = $this->db->getQuery(true)
                    ->update($this->db->quoteName('#__memi_waitlist'))
                    ->set($this->db->quoteName('status') . ' = :status')
                    ->set($this->db->quoteName('offer_token_hash') . ' = NULL')
                    ->set($this->db->quoteName('updated_at') . ' = :updated_at')
                    ->where($this->db->quoteName('id') . ' = :id')
                    ->bind(':status', $expired)
                    ->bind(':updated_at', $now)
                    ->bind(':id', $identifier, ParameterType::INTEGER);
                $this->db->setQuery($update)->execute();
                $this->releaseCapacity((int) $entry['session_id']);
                $this->syncWaitlistCount((int) $entry['session_id'], $now);

                return (int) $entry['session_id'];
            });
            if ($sessionId !== null) {
                $sessions[$sessionId] = true;
            }
        }

        foreach (array_keys($sessions) as $sessionId) {
            $this->offerNext((int) $sessionId);
        }

        return count($ids);
    }

    private function nextPosition(int $sessionId): int
    {
        $session = $sessionId;
        $query = $this->db->getQuery(true)
            ->select('COALESCE(MAX(' . $this->db->quoteName('position') . '), 0) + 1')
            ->from($this->db->quoteName('#__memi_waitlist'))
            ->where($this->db->quoteName('session_id') . ' = :session_id')
            ->bind(':session_id', $session, ParameterType::INTEGER);
        $this->db->setQuery($query);

        return (int) $this->db->loadResult();
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

    private function claimCapacity(int $sessionId): bool
    {
        $session = $sessionId;
        $now = gmdate('Y-m-d H:i:s');
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__memi_sessions'))
            ->set($this->db->quoteName('reserved_count') . ' = ' . $this->db->quoteName('reserved_count') . ' + 1')
            ->set($this->db->quoteName('updated_at') . ' = :updated_at')
            ->where($this->db->quoteName('id') . ' = :session_id')
            ->where($this->db->quoteName('reserved_count') . ' < ' . $this->db->quoteName('capacity'))
            ->bind(':updated_at', $now)
            ->bind(':session_id', $session, ParameterType::INTEGER);
        $this->db->setQuery($query)->execute();

        return $this->db->getAffectedRows() === 1;
    }

    /** Releases the temporary capacity hold attached to an expired/withdrawn offer. */
    private function releaseCapacity(int $sessionId): void
    {
        $session = $sessionId;
        $now = gmdate('Y-m-d H:i:s');
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__memi_sessions'))
            ->set($this->db->quoteName('reserved_count') . ' = GREATEST(0, ' . $this->db->quoteName('reserved_count') . ' - 1)')
            ->set($this->db->quoteName('updated_at') . ' = :updated_at')
            ->where($this->db->quoteName('id') . ' = :session_id')
            ->bind(':updated_at', $now)
            ->bind(':session_id', $session, ParameterType::INTEGER);
        $this->db->setQuery($query)->execute();
    }

    private function syncWaitlistCount(int $sessionId, string $now): void
    {
        $id = $sessionId;
        $waiting = $this->db->quote('waiting');
        $offered = $this->db->quote('offered');
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__memi_sessions'))
            ->set(
                $this->db->quoteName('waitlist_count')
                . ' = (SELECT COUNT(*) FROM ' . $this->db->quoteName('#__memi_waitlist')
                . ' WHERE ' . $this->db->quoteName('session_id') . ' = :session_id'
                . ' AND ' . $this->db->quoteName('status') . ' IN (' . $waiting . ', ' . $offered . '))'
            )
            ->set($this->db->quoteName('updated_at') . ' = :updated_at')
            ->where($this->db->quoteName('id') . ' = :session_id')
            ->bind(':session_id', $id, ParameterType::INTEGER)
            ->bind(':updated_at', $now);
        $this->db->setQuery($query)->execute();
    }

    private function waitingStatus(): string
    {
        return 'waiting';
    }

    /** @param array<string,mixed> $session */
    private function isSessionRegistrationOpen(array $session): bool
    {
        try {
            $this->assertSessionRegistrationOpen($session);

            return true;
        } catch (DomainException) {
            return false;
        }
    }

    /** @param array<string,mixed> $session */
    private function assertSessionRegistrationOpen(array $session): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
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
}
