<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;

/**
 * Transactional, append-only credit ledger. `customer_packages.remaining_credits`
 * is a cached allocation only; the ledger remains the source of truth.
 */
final class CreditLedgerService
{
    public function __construct(
        private readonly DatabaseDriver $db,
        private readonly DatabaseTools $tools,
        private readonly AuditLogger $audit
    ) {
    }

    public function balance(int $userId): int
    {
        $user = $userId;
        $query = $this->db->getQuery(true)
            ->select('COALESCE(SUM(' . $this->db->quoteName('credits_delta') . '), 0)')
            ->from($this->db->quoteName('#__memi_credit_ledger'))
            ->where($this->db->quoteName('user_id') . ' = :user_id')
            ->bind(':user_id', $user, ParameterType::INTEGER);
        $this->db->setQuery($query);

        return (int) $this->db->loadResult();
    }

    /**
     * Grants credits following a successful package purchase or manager action.
     * The supplied idempotency key has a unique index in the schema.
     */
    public function grant(
        int $userId,
        int $credits,
        string $eventType,
        string $idempotencyKey,
        ?int $customerPackageId = null,
        ?int $orderId = null,
        ?\DateTimeInterface $expiresAt = null,
        ?int $actorId = null,
        ?string $description = null
    ): int {
        if ($credits <= 0) {
            throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_CREDIT_AMOUNT');
        }

        return $this->insertEntry(
            $userId,
            $credits,
            $eventType,
            $idempotencyKey,
            $customerPackageId,
            $orderId,
            null,
            $expiresAt,
            $actorId,
            $description
        );
    }

    /**
     * Consumes exactly one available credit. Call this only inside the booking
     * transaction; it locks the client allocation and protects concurrent tabs.
     */
    public function consumeForBooking(int $userId, int $bookingId, int $sessionId, string $idempotencyKey, ?int $actorId = null): int
    {
        $profile = $this->tools->lockClientProfile($userId);
        $clientId = (int) $profile['id'];
        $existing = $this->findByIdempotency($idempotencyKey);

        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $now = gmdate('Y-m-d H:i:s');
        $client = $clientId;
        $activeStatus = $this->activeStatus();
        $query = $this->db->getQuery(true)
            ->select(['cp.*', 'COALESCE(SUM(cl.credits_delta), 0) AS remaining_credits'])
            ->from($this->db->quoteName('#__memi_customer_packages', 'cp'))
            ->join('LEFT', $this->db->quoteName('#__memi_credit_ledger', 'cl') . ' ON cl.customer_package_id = cp.id')
            ->where('cp.client_id = :client_id')
            ->where('cp.status = :status')
            ->where('(cp.expires_at IS NULL OR cp.expires_at > :now)')
            ->group('cp.id')
            ->having('COALESCE(SUM(cl.credits_delta), 0) > 0')
            ->order('cp.expires_at ASC, cp.id ASC')
            ->bind(':client_id', $client, ParameterType::INTEGER)
            ->bind(':status', $activeStatus)
            ->bind(':now', $now);
        $query->setLimit(1);
        $this->db->setQuery(DatabaseTools::forUpdate($query));
        $allocation = $this->db->loadAssoc();

        if (!$allocation) {
            throw new DomainException('COM_MEMIPILATES_ERROR_INSUFFICIENT_CREDITS');
        }

        $allocationId = (int) $allocation['id'];

        return $this->insertEntry(
            $userId,
            -1,
            'booking_use',
            $idempotencyKey,
            $allocationId,
            null,
            $bookingId,
            null,
            $actorId,
            'Crédit utilisé pour la séance #' . $sessionId
        );
    }

    /**
     * Restores every credit still consumed by the current booking occurrence,
     * while preserving the allocation each credit originated from.
     *
     * A session can require more than one credit. Looking up only the newest
     * negative ledger row would silently restore just one of them. Instead we
     * reconcile booking-use entries with all existing positive restoration
     * entries for that booking, so cancellation remains correct after a client
     * cancels, books again, and cancels again.
     */
    public function restoreForBooking(int $userId, int $bookingId, string $eventType, string $idempotencyKey, ?int $actorId = null): int
    {
        $booking = $bookingId;
        $query = $this->db->getQuery(true)
            ->select(['customer_package_id', 'credits_delta', 'entry_type'])
            ->from($this->db->quoteName('#__memi_credit_ledger'))
            ->where($this->db->quoteName('booking_id') . ' = :booking_id')
            ->bind(':booking_id', $booking, ParameterType::INTEGER);
        $this->db->setQuery($query);
        $entries = $this->db->loadAssocList() ?: [];
        $consumed = [];
        $restored = [];

        foreach ($entries as $entry) {
            $allocationKey = $entry['customer_package_id'] === null ? 'none' : (string) (int) $entry['customer_package_id'];
            $delta = (int) $entry['credits_delta'];
            if ((string) $entry['entry_type'] === 'booking_use' && $delta < 0) {
                $consumed[$allocationKey] = ($consumed[$allocationKey] ?? 0) - $delta;
            } elseif ($delta > 0) {
                $restored[$allocationKey] = ($restored[$allocationKey] ?? 0) + $delta;
            }
        }

        $totalRestored = 0;
        foreach ($consumed as $allocationKey => $usedCredits) {
            $outstanding = $usedCredits - ($restored[$allocationKey] ?? 0);
            if ($outstanding <= 0) {
                continue;
            }

            $allocationId = $allocationKey === 'none' ? null : (int) $allocationKey;
            $entryKey = hash('sha256', $idempotencyKey . ':allocation:' . $allocationKey);
            $this->insertEntry(
                $userId,
                $outstanding,
                $eventType,
                $entryKey,
                $allocationId,
                null,
                $bookingId,
                null,
                $actorId,
                'Crédit restauré'
            );
            $totalRestored += $outstanding;
        }

        return $totalRestored;
    }

    public function expireAllocation(array $allocation): int
    {
        $remaining = $this->remainingForAllocation((int) $allocation['id']);

        if ($remaining <= 0) {
            return 0;
        }

        $key = 'expire-package:' . (int) $allocation['id'];
        if ($this->findByIdempotency($key) !== null) {
            return 0;
        }

        return $this->insertEntry(
            (int) $allocation['user_id'],
            -$remaining,
            'expiration',
            $key,
            (int) $allocation['id'],
            null,
            null,
            null,
            null,
            'Crédits expirés'
        );
    }

    /** @return array<string, mixed>|null */
    private function findByIdempotency(string $idempotencyKey): ?array
    {
        $key = $idempotencyKey;
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__memi_credit_ledger'))
            ->where($this->db->quoteName('idempotency_key') . ' = :idempotency_key')
            ->bind(':idempotency_key', $key);
        $this->db->setQuery($query);

        return $this->db->loadAssoc() ?: null;
    }

    private function insertEntry(
        int $userId,
        int $delta,
        string $eventType,
        string $idempotencyKey,
        ?int $customerPackageId,
        ?int $orderId,
        ?int $bookingId,
        ?\DateTimeInterface $expiresAt,
        ?int $actorId,
        ?string $description
    ): int {
        $existing = $this->findByIdempotency($idempotencyKey);
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $profile = $this->tools->lockClientProfile($userId);
        $clientId = (int) $profile['id'];
        $now = gmdate('Y-m-d H:i:s');
        $expires = $expiresAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $user = $userId;
        $amount = $delta;
        $package = $customerPackageId;
        $order = $orderId;
        $booking = $bookingId;
        $actor = $actorId;
        $query = $this->db->getQuery(true)
            ->insert($this->db->quoteName('#__memi_credit_ledger'))
            ->columns([
                'client_id', 'user_id', 'credits_delta', 'entry_type', 'idempotency_key', 'customer_package_id',
                'order_id', 'booking_id', 'expires_at', 'created_by', 'description', 'created_at',
            ])
            ->values(':client_id, :user_id, :credits_delta, :entry_type, :idempotency_key, :customer_package_id, :order_id, :booking_id, :expires_at, :created_by, :description, :created_at')
            ->bind(':client_id', $clientId, ParameterType::INTEGER)
            ->bind(':user_id', $user, ParameterType::INTEGER)
            ->bind(':credits_delta', $amount, ParameterType::INTEGER)
            ->bind(':entry_type', $eventType)
            ->bind(':idempotency_key', $idempotencyKey)
            ->bind(':customer_package_id', $package, ParameterType::INTEGER)
            ->bind(':order_id', $order, ParameterType::INTEGER)
            ->bind(':booking_id', $booking, ParameterType::INTEGER)
            ->bind(':expires_at', $expires)
            ->bind(':created_by', $actor, ParameterType::INTEGER)
            ->bind(':description', $description)
            ->bind(':created_at', $now);
        $this->db->setQuery($query)->execute();
        $id = (int) $this->db->insertid();
        if ($customerPackageId !== null) {
            $this->syncAllocationBalance($customerPackageId, $now);
        }

        $this->audit->log($actorId, 'credit.' . $eventType, 'credit_ledger', $id, null, [
            'user_id' => $userId,
            'credits_delta' => $delta,
            'booking_id' => $bookingId,
            'customer_package_id' => $customerPackageId,
        ]);

        return $id;
    }

    private function activeStatus(): string
    {
        return 'active';
    }

    private function remainingForAllocation(int $customerPackageId): int
    {
        $id = $customerPackageId;
        $query = $this->db->getQuery(true)
            ->select('COALESCE(SUM(' . $this->db->quoteName('credits_delta') . '), 0)')
            ->from($this->db->quoteName('#__memi_credit_ledger'))
            ->where($this->db->quoteName('customer_package_id') . ' = :customer_package_id')
            ->bind(':customer_package_id', $id, ParameterType::INTEGER);
        $this->db->setQuery($query);

        return (int) $this->db->loadResult();
    }

    /**
     * Keeps the allocation cache aligned with the immutable ledger. All
     * availability decisions still use the ledger, so a stale cache can never
     * create an extra booking.
     */
    private function syncAllocationBalance(int $customerPackageId, string $now): void
    {
        $id = $customerPackageId;
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__memi_customer_packages'))
            ->set(
                $this->db->quoteName('remaining_credits')
                . ' = (SELECT COALESCE(SUM(' . $this->db->quoteName('credits_delta') . '), 0)'
                . ' FROM ' . $this->db->quoteName('#__memi_credit_ledger')
                . ' WHERE ' . $this->db->quoteName('customer_package_id') . ' = :allocation_id)'
            )
            ->set($this->db->quoteName('updated_at') . ' = :updated_at')
            ->where($this->db->quoteName('id') . ' = :allocation_id')
            ->bind(':allocation_id', $id, ParameterType::INTEGER)
            ->bind(':updated_at', $now);
        $this->db->setQuery($query)->execute();
    }
}
