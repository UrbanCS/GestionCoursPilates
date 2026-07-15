<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;

/**
 * Redeems loyalty rewards without ever mutating the point balance in place.
 *
 * A redemption and its negative point-ledger entry are created in one
 * transaction. Replaying the same idempotency key returns the original
 * redemption and cannot spend points twice.
 */
final class LoyaltyService
{
    public function __construct(
        private readonly DatabaseDriver $db,
        private readonly DatabaseTools $tools,
        private readonly PointLedgerService $points,
        private readonly CreditLedgerService $credits,
        private readonly AuditLogger $audit
    ) {
    }

    /**
     * Redeem a configured reward for a client.
     *
     * Discount and custom/manual rewards remain pending for the workflow that
     * consumes or fulfils them. Credit/package rewards are fulfilled
     * immediately by creating a customer-package allocation and an immutable
     * credit-ledger grant.
     *
     * @return array<string, int|string|bool|null>
     */
    public function redeem(int $userId, int $rewardId, string $idempotencyKey, ?int $actorId = null): array
    {
        $key = trim($idempotencyKey);

        if ($userId <= 0 || $rewardId <= 0 || $key === '' || strlen($key) > 128) {
            throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
        }

        return $this->tools->transaction(function () use ($userId, $rewardId, $key, $actorId): array {
            $existing = $this->findRedemptionByKeyForUpdate($key);
            if ($existing !== null) {
                if ((int) $existing['user_id'] !== $userId || (int) $existing['reward_id'] !== $rewardId) {
                    throw new DomainException('COM_MEMIPILATES_ERROR_LOYALTY_IDEMPOTENCY_CONFLICT', [], 409);
                }

                return $this->existingResult($existing, $userId);
            }

            $reward = $this->tools->lockById('#__memi_rewards', $rewardId);
            if ($reward === null) {
                throw new DomainException('COM_MEMIPILATES_ERROR_REWARD_NOT_FOUND', [], 404);
            }

            $now = gmdate('Y-m-d H:i:s');
            $this->assertRewardRedeemable($reward, $now);

            $maximum = $reward['maximum_redemptions'] === null ? null : (int) $reward['maximum_redemptions'];
            if ($maximum !== null && $this->redemptionCount($rewardId) >= $maximum) {
                throw new DomainException('COM_MEMIPILATES_ERROR_REWARD_LIMIT_REACHED', [], 409);
            }

            // Every point write locks this row, making the balance check and
            // debit serializable across concurrent redemption requests.
            $profile = $this->tools->lockClientProfile($userId);
            $clientId = (int) $profile['id'];
            $pointsCost = (int) $reward['points_cost'];
            if ($this->points->balance($userId) < $pointsCost) {
                throw new DomainException('COM_MEMIPILATES_ERROR_INSUFFICIENT_POINTS', [], 409);
            }

            $rewardType = strtolower(trim((string) $reward['reward_type']));
            $isPackageReward = in_array($rewardType, ['credit', 'credits', 'package'], true);
            $status = $isPackageReward ? 'fulfilled' : 'pending';
            $fulfilledAt = $isPackageReward ? $now : null;
            $actingUser = $actorId ?? $userId;
            $fulfilledBy = $isPackageReward ? $actingUser : 0;
            $redemptionId = $this->insertRedemption(
                $rewardId,
                $clientId,
                $userId,
                $pointsCost,
                $key,
                $status,
                $now,
                $fulfilledAt,
                $fulfilledBy
            );

            $ledgerEntryId = $this->debitPoints(
                $clientId,
                $userId,
                $redemptionId,
                $pointsCost,
                $key,
                $actingUser,
                $now
            );

            $customerPackageId = null;
            $creditsGranted = 0;
            if ($isPackageReward) {
                [$customerPackageId, $creditsGranted] = $this->fulfilPackageReward(
                    $reward,
                    $rewardType,
                    $redemptionId,
                    $clientId,
                    $userId,
                    $actingUser,
                    $now
                );
            }

            $discountCents = $rewardType === 'discount' ? (int) $reward['discount_cents'] : 0;
            $this->audit->log(
                $actingUser,
                'loyalty.redeem',
                'reward_redemption',
                $redemptionId,
                null,
                [
                    'reward_id' => $rewardId,
                    'reward_type' => $rewardType,
                    'status' => $status,
                    'points_cost' => $pointsCost,
                    'credits_granted' => $creditsGranted,
                    'customer_package_id' => $customerPackageId,
                ]
            );

            return [
                'redemption_id' => $redemptionId,
                'reward_id' => $rewardId,
                'reward_type' => $rewardType,
                'status' => $status,
                'points_spent' => $pointsCost,
                'points_ledger_id' => $ledgerEntryId,
                'points_balance' => $this->points->balance($userId),
                'discount_cents' => $discountCents,
                'credits_granted' => $creditsGranted,
                'customer_package_id' => $customerPackageId,
                'already_redeemed' => false,
            ];
        });
    }

    /** @return array<string, mixed>|null */
    private function findRedemptionByKeyForUpdate(string $idempotencyKey): ?array
    {
        $key = $idempotencyKey;
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__memi_reward_redemptions'))
            ->where($this->db->quoteName('idempotency_key') . ' = :idempotency_key')
            ->bind(':idempotency_key', $key);
        $this->db->setQuery(DatabaseTools::forUpdate($query));

        return $this->db->loadAssoc() ?: null;
    }

    private function assertRewardRedeemable(array $reward, string $now): void
    {
        $published = (int) ($reward['published'] ?? 0);
        $archivedAt = (string) ($reward['archived_at'] ?? '');
        $availableFrom = (string) ($reward['available_from'] ?? '');
        $availableUntil = (string) ($reward['available_until'] ?? '');
        $pointsCost = (int) ($reward['points_cost'] ?? 0);
        $rewardType = strtolower(trim((string) ($reward['reward_type'] ?? '')));
        $discountCents = (int) ($reward['discount_cents'] ?? 0);

        if ($published !== 1 || $archivedAt !== ''
            || ($availableFrom !== '' && $availableFrom > $now)
            || ($availableUntil !== '' && $availableUntil < $now)
        ) {
            throw new DomainException('COM_MEMIPILATES_ERROR_REWARD_UNAVAILABLE', [], 409);
        }

        if ($pointsCost <= 0) {
            throw new DomainException('COM_MEMIPILATES_ERROR_REWARD_CONFIGURATION_INVALID', [], 409);
        }

        if (!in_array($rewardType, ['discount', 'custom', 'credit', 'credits', 'package'], true)) {
            throw new DomainException('COM_MEMIPILATES_ERROR_REWARD_CONFIGURATION_INVALID', [], 409);
        }

        if ($rewardType === 'discount' && $discountCents <= 0) {
            throw new DomainException('COM_MEMIPILATES_ERROR_REWARD_CONFIGURATION_INVALID', [], 409);
        }
    }

    private function redemptionCount(int $rewardId): int
    {
        $reward = $rewardId;
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__memi_reward_redemptions'))
            ->where($this->db->quoteName('reward_id') . ' = :reward_id')
            ->bind(':reward_id', $reward, ParameterType::INTEGER);
        $this->db->setQuery($query);

        return (int) $this->db->loadResult();
    }

    private function insertRedemption(
        int $rewardId,
        int $clientId,
        int $userId,
        int $pointsCost,
        string $idempotencyKey,
        string $status,
        string $now,
        ?string $fulfilledAt,
        int $fulfilledBy
    ): int {
        $reward = $rewardId;
        $client = $clientId;
        $user = $userId;
        $cost = $pointsCost;
        $key = $idempotencyKey;
        $orderId = null;
        $createdAt = $now;
        $updatedAt = $now;
        $query = $this->db->getQuery(true)
            ->insert($this->db->quoteName('#__memi_reward_redemptions'))
            ->columns([
                'reward_id', 'client_id', 'user_id', 'order_id', 'status', 'points_cost', 'idempotency_key',
                'claimed_at', 'fulfilled_at', 'fulfilled_by', 'created_at', 'updated_at',
            ])
            ->values(':reward_id, :client_id, :user_id, :order_id, :status, :points_cost, :idempotency_key, :claimed_at, :fulfilled_at, :fulfilled_by, :created_at, :updated_at')
            ->bind(':reward_id', $reward, ParameterType::INTEGER)
            ->bind(':client_id', $client, ParameterType::INTEGER)
            ->bind(':user_id', $user, ParameterType::INTEGER)
            ->bind(':order_id', $orderId, ParameterType::INTEGER)
            ->bind(':status', $status)
            ->bind(':points_cost', $cost, ParameterType::INTEGER)
            ->bind(':idempotency_key', $key)
            ->bind(':claimed_at', $now)
            ->bind(':fulfilled_at', $fulfilledAt)
            ->bind(':fulfilled_by', $fulfilledBy, ParameterType::INTEGER)
            ->bind(':created_at', $createdAt)
            ->bind(':updated_at', $updatedAt);
        $this->db->setQuery($query)->execute();

        return (int) $this->db->insertid();
    }

    private function debitPoints(
        int $clientId,
        int $userId,
        int $redemptionId,
        int $pointsCost,
        string $redemptionKey,
        int $actorId,
        string $now
    ): int {
        $ledgerKey = hash('sha256', 'loyalty-redemption:' . $redemptionKey);
        $existing = $this->findPointEntryByKey($ledgerKey);
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $client = $clientId;
        $user = $userId;
        $delta = -$pointsCost;
        $entryType = 'reward_redemption';
        $referenceType = 'reward_redemption';
        $reference = $redemptionId;
        $description = 'Échange de récompense #' . $redemptionId;
        $createdBy = $actorId;
        $key = $ledgerKey;
        $createdAt = $now;
        $query = $this->db->getQuery(true)
            ->insert($this->db->quoteName('#__memi_points_ledger'))
            ->columns([
                'client_id', 'user_id', 'points_delta', 'entry_type', 'reference_type', 'reference_id',
                'description', 'created_by', 'idempotency_key', 'created_at',
            ])
            ->values(':client_id, :user_id, :points_delta, :entry_type, :reference_type, :reference_id, :description, :created_by, :idempotency_key, :created_at')
            ->bind(':client_id', $client, ParameterType::INTEGER)
            ->bind(':user_id', $user, ParameterType::INTEGER)
            ->bind(':points_delta', $delta, ParameterType::INTEGER)
            ->bind(':entry_type', $entryType)
            ->bind(':reference_type', $referenceType)
            ->bind(':reference_id', $reference, ParameterType::INTEGER)
            ->bind(':description', $description)
            ->bind(':created_by', $createdBy, ParameterType::INTEGER)
            ->bind(':idempotency_key', $key)
            ->bind(':created_at', $createdAt);
        $this->db->setQuery($query)->execute();

        return (int) $this->db->insertid();
    }

    /** @return array<string, mixed>|null */
    private function findPointEntryByKey(string $idempotencyKey): ?array
    {
        $key = $idempotencyKey;
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__memi_points_ledger'))
            ->where($this->db->quoteName('idempotency_key') . ' = :idempotency_key')
            ->bind(':idempotency_key', $key);
        $this->db->setQuery(DatabaseTools::forUpdate($query));

        return $this->db->loadAssoc() ?: null;
    }

    /**
     * @return array{0:int,1:int} Customer package ID and granted credits.
     */
    private function fulfilPackageReward(
        array $reward,
        string $rewardType,
        int $redemptionId,
        int $clientId,
        int $userId,
        int $actorId,
        string $now
    ): array {
        $packageId = (int) ($reward['package_id'] ?? 0);
        if ($packageId <= 0) {
            throw new DomainException('COM_MEMIPILATES_ERROR_REWARD_CONFIGURATION_INVALID', [], 409);
        }

        $package = $this->tools->lockById('#__memi_packages', $packageId);
        if ($package === null || (int) ($package['published'] ?? 0) !== 1 || !empty($package['archived_at'])) {
            throw new DomainException('COM_MEMIPILATES_ERROR_REWARD_CONFIGURATION_INVALID', [], 409);
        }

        $packageCredits = max(0, (int) ($package['credits'] ?? 0));
        $rewardCredits = max(0, (int) ($reward['credits'] ?? 0));
        $creditsGranted = $rewardType === 'package' ? $packageCredits : $rewardCredits;
        if ($rewardType !== 'package' && $creditsGranted <= 0) {
            throw new DomainException('COM_MEMIPILATES_ERROR_REWARD_CONFIGURATION_INVALID', [], 409);
        }

        $issuedAt = new \DateTimeImmutable($now, new \DateTimeZone('UTC'));
        $expiresAt = $this->packageExpiry($package, $issuedAt);
        $expires = $expiresAt?->format('Y-m-d H:i:s');
        $client = $clientId;
        $user = $userId;
        $packageIdentifier = $packageId;
        $orderId = null;
        $status = 'active';
        $originalCredits = $creditsGranted;
        $remainingCredits = $creditsGranted;
        $grantedCredits = $creditsGranted;
        $purchasedAt = $now;
        $startsAt = $now;
        $createdAt = $now;
        $updatedAt = $now;
        $createdBy = $actorId;
        $updatedBy = $actorId;
        $query = $this->db->getQuery(true)
            ->insert($this->db->quoteName('#__memi_customer_packages'))
            ->columns([
                'client_id', 'user_id', 'package_id', 'order_id', 'status', 'original_credits',
                'remaining_credits', 'credits_granted', 'purchased_at', 'starts_at', 'expires_at',
                'created_at', 'created_by', 'updated_at', 'updated_by',
            ])
            ->values(':client_id, :user_id, :package_id, :order_id, :status, :original_credits, :remaining_credits, :credits_granted, :purchased_at, :starts_at, :expires_at, :created_at, :created_by, :updated_at, :updated_by')
            ->bind(':client_id', $client, ParameterType::INTEGER)
            ->bind(':user_id', $user, ParameterType::INTEGER)
            ->bind(':package_id', $packageIdentifier, ParameterType::INTEGER)
            ->bind(':order_id', $orderId, ParameterType::INTEGER)
            ->bind(':status', $status)
            ->bind(':original_credits', $originalCredits, ParameterType::INTEGER)
            ->bind(':remaining_credits', $remainingCredits, ParameterType::INTEGER)
            ->bind(':credits_granted', $grantedCredits, ParameterType::INTEGER)
            ->bind(':purchased_at', $purchasedAt)
            ->bind(':starts_at', $startsAt)
            ->bind(':expires_at', $expires)
            ->bind(':created_at', $createdAt)
            ->bind(':created_by', $createdBy, ParameterType::INTEGER)
            ->bind(':updated_at', $updatedAt)
            ->bind(':updated_by', $updatedBy, ParameterType::INTEGER);
        $this->db->setQuery($query)->execute();
        $customerPackageId = (int) $this->db->insertid();

        if ($creditsGranted > 0) {
            $creditKey = hash('sha256', 'loyalty-credit:' . $redemptionId);
            $this->credits->grant(
                $userId,
                $creditsGranted,
                'reward_redemption',
                $creditKey,
                $customerPackageId,
                null,
                $expiresAt,
                $actorId,
                'Récompense de fidélité #' . $redemptionId
            );
        }

        return [$customerPackageId, $creditsGranted];
    }

    private function packageExpiry(array $package, \DateTimeImmutable $issuedAt): ?\DateTimeImmutable
    {
        $fixedExpiry = (string) ($package['fixed_expiry_at'] ?? '');
        if ($fixedExpiry !== '') {
            $expiry = new \DateTimeImmutable($fixedExpiry, new \DateTimeZone('UTC'));
            if ($expiry <= $issuedAt) {
                throw new DomainException('COM_MEMIPILATES_ERROR_REWARD_CONFIGURATION_INVALID', [], 409);
            }

            return $expiry;
        }

        $validityDays = max(0, (int) ($package['validity_days'] ?? 0));
        if ($validityDays === 0) {
            return null;
        }

        return $issuedAt->modify('+' . $validityDays . ' days');
    }

    /**
     * @return array<string, int|string|bool|null>
     */
    private function existingResult(array $redemption, int $userId): array
    {
        return [
            'redemption_id' => (int) $redemption['id'],
            'reward_id' => (int) $redemption['reward_id'],
            'reward_type' => null,
            'status' => (string) $redemption['status'],
            'points_spent' => (int) $redemption['points_cost'],
            'points_ledger_id' => null,
            'points_balance' => $this->points->balance($userId),
            'discount_cents' => null,
            'credits_granted' => null,
            'customer_package_id' => null,
            'already_redeemed' => true,
        ];
    }
}
