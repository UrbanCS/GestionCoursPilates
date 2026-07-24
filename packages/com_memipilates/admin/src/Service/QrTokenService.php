<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;

/** Issues opaque, revocable QR tokens without persisting their raw value. */
final class QrTokenService
{
    private const TOKEN_DOMAIN = 'com_memipilates.qr-token.v1';

    public function __construct(
        private readonly DatabaseDriver $db,
        private readonly DatabaseTools $tools,
        private readonly AuditLogger $audit
    ) {
    }

    /**
     * Returns the current token so a client can display the same QR after a
     * reload. The raw value is deterministically signed with Joomla's secret;
     * only its SHA-256 fingerprint is stored in the database.
     *
     * Legacy random tokens cannot be reconstructed. On their first authenticated
     * display, they are revoked and replaced once with a signed token.
     *
     * @return array{token:string, token_id:int, created_at:string}|null
     */
    public function current(int $userId, ?int $actorId = null): ?array
    {
        return $this->tools->transaction(function () use ($userId, $actorId): ?array {
            $profile = $this->tools->lockClientProfile($userId);
            $clientId = (int) $profile['id'];
            $row = $this->activeRow($userId);

            if ($row === null) {
                return null;
            }

            $token = $this->deriveToken($clientId, $userId, (int) $row['version']);
            if (!hash_equals((string) $row['token_hash'], hash('sha256', $token))) {
                $this->revokeActiveRows($userId, $actorId, 'legacy_token_rotation');

                return $this->issueLocked(
                    $clientId,
                    $userId,
                    $this->nextVersion($clientId),
                    'legacy-' . bin2hex(random_bytes(16)),
                    $actorId,
                    'qr.rotate_legacy'
                );
            }

            // Heal old rows and any pre-constraint duplicate active records.
            $this->revokeOtherActiveRows($userId, (int) $row['id'], $actorId);
            $activeKey = $this->activeKey($clientId);
            if (!hash_equals((string) ($row['active_token_key'] ?? ''), $activeKey)) {
                $id = (int) $row['id'];
                $update = $this->db->getQuery(true)
                    ->update($this->db->quoteName('#__memi_qr_tokens'))
                    ->set($this->db->quoteName('active_token_key') . ' = :active_key')
                    ->where($this->db->quoteName('id') . ' = :id')
                    ->where($this->db->quoteName('revoked_at') . ' IS NULL')
                    ->bind(':active_key', $activeKey)
                    ->bind(':id', $id, ParameterType::INTEGER);
                $this->db->setQuery($update)->execute();
            }

            return $this->result($row, $token);
        });
    }

    /**
     * Rotates a QR exactly once for a given idempotency key. Replaying a
     * successful request returns the same token instead of invalidating it.
     *
     * @return array{token:string, token_id:int, created_at:string}
     */
    public function regenerate(int $userId, string $idempotencyKey, ?int $actorId = null): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $idempotencyKey)) {
            throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
        }

        return $this->tools->transaction(function () use ($userId, $idempotencyKey, $actorId): array {
            $profile = $this->tools->lockClientProfile($userId);
            $clientId = (int) $profile['id'];
            $existing = $this->rowByIdempotencyKey($idempotencyKey);

            if ($existing !== null) {
                if (
                    (int) $existing['client_id'] !== $clientId
                    || (int) $existing['user_id'] !== $userId
                    || $existing['revoked_at'] !== null
                    || ($existing['expires_at'] !== null && (string) $existing['expires_at'] <= gmdate('Y-m-d H:i:s'))
                ) {
                    throw new DomainException('COM_MEMIPILATES_ERROR_QR_IDEMPOTENCY_CONFLICT');
                }

                $token = $this->deriveToken($clientId, $userId, (int) $existing['version']);
                if (!hash_equals((string) $existing['token_hash'], hash('sha256', $token))) {
                    throw new DomainException('COM_MEMIPILATES_ERROR_QR_IDEMPOTENCY_CONFLICT');
                }

                return $this->result($existing, $token);
            }

            $this->revokeActiveRows($userId, $actorId, 'regenerated');

            return $this->issueLocked(
                $clientId,
                $userId,
                $this->nextVersion($clientId),
                $idempotencyKey,
                $actorId,
                'qr.regenerate'
            );
        });
    }

    /**
     * Returns the active owner for an opaque raw token. Tokens are never
     * persisted or emitted through logs, error messages, or audit context.
     *
     * @return array<string, mixed>|null
     */
    public function resolve(string $token): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $token)) {
            return null;
        }

        $hash = hash('sha256', $token);
        $now = gmdate('Y-m-d H:i:s');
        $query = $this->db->getQuery(true)
            ->select('q.*')
            ->from($this->db->quoteName('#__memi_qr_tokens', 'q'))
            ->join('INNER', $this->db->quoteName('#__memi_client_profiles', 'cp') . ' ON cp.id = q.client_id AND cp.user_id = q.user_id')
            ->join('INNER', $this->db->quoteName('#__users', 'u') . ' ON u.id = q.user_id')
            ->where('q.' . $this->db->quoteName('token_hash') . ' = :token_hash')
            ->where('q.' . $this->db->quoteName('revoked_at') . ' IS NULL')
            ->where('cp.' . $this->db->quoteName('archived_at') . ' IS NULL')
            ->where('u.' . $this->db->quoteName('block') . ' = 0')
            ->where('(q.' . $this->db->quoteName('expires_at') . ' IS NULL OR q.' . $this->db->quoteName('expires_at') . ' > :now)')
            ->bind(':token_hash', $hash)
            ->bind(':now', $now);
        $this->db->setQuery($query);

        return $this->db->loadAssoc() ?: null;
    }

    public function revokeForUser(int $userId, int $actorId, ?string $reason = null): void
    {
        $safeReason = mb_substr($reason ?? 'administrative_revocation', 0, 255);
        $this->tools->transaction(function () use ($userId, $actorId, $safeReason): void {
            $active = $this->activeRow($userId);
            if ($active === null) {
                throw new DomainException('COM_MEMIPILATES_ERROR_QR_NOT_FOUND', [], 404);
            }

            $this->revokeActiveRows($userId, $actorId, $safeReason);
            $this->audit->log(
                $actorId,
                'qr.revoke',
                'client_profile',
                (int) $active['client_id'],
                null,
                ['user_id' => $userId],
                $safeReason
            );
        });
    }

    public function revoke(int $tokenId, int $actorId, ?string $reason = null): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $id = $tokenId;
        $safeReason = mb_substr($reason ?? 'administrative_revocation', 0, 255);
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__memi_qr_tokens'))
            ->set($this->db->quoteName('revoked_at') . ' = :revoked_at')
            ->set($this->db->quoteName('revoked_by') . ' = :actor')
            ->set($this->db->quoteName('revocation_reason') . ' = :reason')
            ->set($this->db->quoteName('active_token_key') . ' = NULL')
            ->where($this->db->quoteName('id') . ' = :id')
            ->where($this->db->quoteName('revoked_at') . ' IS NULL')
            ->bind(':revoked_at', $now)
            ->bind(':actor', $actorId, ParameterType::INTEGER)
            ->bind(':reason', $safeReason)
            ->bind(':id', $id, ParameterType::INTEGER);
        $this->db->setQuery($query)->execute();

        if ($this->db->getAffectedRows() < 1) {
            throw new DomainException('COM_MEMIPILATES_ERROR_QR_NOT_FOUND', [], 404);
        }

        $this->audit->log($actorId, 'qr.revoke', 'qr_token', $tokenId, null, null, $safeReason);
    }

    /** @return array<string,mixed>|null */
    private function activeRow(int $userId): ?array
    {
        $now = gmdate('Y-m-d H:i:s');
        $user = $userId;
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__memi_qr_tokens'))
            ->where($this->db->quoteName('user_id') . ' = :user_id')
            ->where($this->db->quoteName('revoked_at') . ' IS NULL')
            ->where('(' . $this->db->quoteName('expires_at') . ' IS NULL OR ' . $this->db->quoteName('expires_at') . ' > :now)')
            ->order($this->db->quoteName('id') . ' DESC')
            ->bind(':user_id', $user, ParameterType::INTEGER)
            ->bind(':now', $now);
        $query->setLimit(1);
        $this->db->setQuery(DatabaseTools::forUpdate($query));

        return $this->db->loadAssoc() ?: null;
    }

    /** @return array<string,mixed>|null */
    private function rowByIdempotencyKey(string $idempotencyKey): ?array
    {
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__memi_qr_tokens'))
            ->where($this->db->quoteName('idempotency_key') . ' = :idempotency_key')
            ->bind(':idempotency_key', $idempotencyKey);
        $query->setLimit(1);
        $this->db->setQuery(DatabaseTools::forUpdate($query));

        return $this->db->loadAssoc() ?: null;
    }

    private function nextVersion(int $clientId): int
    {
        $client = $clientId;
        $query = $this->db->getQuery(true)
            ->select('COALESCE(MAX(' . $this->db->quoteName('version') . '), 0)')
            ->from($this->db->quoteName('#__memi_qr_tokens'))
            ->where($this->db->quoteName('client_id') . ' = :client_id')
            ->bind(':client_id', $client, ParameterType::INTEGER);
        $this->db->setQuery($query);
        $version = (int) $this->db->loadResult() + 1;

        if ($version > 65535) {
            throw new DomainException('COM_MEMIPILATES_ERROR_QR_CONFIGURATION');
        }

        return $version;
    }

    /** @return array{token:string, token_id:int, created_at:string} */
    private function issueLocked(
        int $clientId,
        int $userId,
        int $version,
        string $idempotencyKey,
        ?int $actorId,
        string $auditAction
    ): array {
        $now = gmdate('Y-m-d H:i:s');
        $token = $this->deriveToken($clientId, $userId, $version);
        $hash = hash('sha256', $token);
        $hint = substr($token, -8);
        $activeKey = $this->activeKey($clientId);
        $client = $clientId;
        $user = $userId;
        $tokenVersion = $version;
        $insert = $this->db->getQuery(true)
            ->insert($this->db->quoteName('#__memi_qr_tokens'))
            ->columns([
                'client_id', 'user_id', 'token_hash', 'token_hint', 'version',
                'idempotency_key', 'active_token_key', 'issued_at', 'created_at',
            ])
            ->values(':client_id, :user_id, :token_hash, :token_hint, :version, :idempotency_key, :active_key, :issued_at, :created_at')
            ->bind(':client_id', $client, ParameterType::INTEGER)
            ->bind(':user_id', $user, ParameterType::INTEGER)
            ->bind(':token_hash', $hash)
            ->bind(':token_hint', $hint)
            ->bind(':version', $tokenVersion, ParameterType::INTEGER)
            ->bind(':idempotency_key', $idempotencyKey)
            ->bind(':active_key', $activeKey)
            ->bind(':issued_at', $now)
            ->bind(':created_at', $now);
        $this->db->setQuery($insert)->execute();
        $id = (int) $this->db->insertid();
        $this->audit->log($actorId, $auditAction, 'qr_token', $id, null, ['user_id' => $userId]);

        return ['token' => $token, 'token_id' => $id, 'created_at' => $now];
    }

    private function revokeActiveRows(int $userId, ?int $actorId, string $reason): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $actor = $actorId ?? 0;
        $user = $userId;
        $safeReason = mb_substr($reason, 0, 255);
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__memi_qr_tokens'))
            ->set($this->db->quoteName('revoked_at') . ' = :revoked_at')
            ->set($this->db->quoteName('revoked_by') . ' = :actor')
            ->set($this->db->quoteName('revocation_reason') . ' = :reason')
            ->set($this->db->quoteName('active_token_key') . ' = NULL')
            ->where($this->db->quoteName('user_id') . ' = :user_id')
            ->where($this->db->quoteName('revoked_at') . ' IS NULL')
            ->bind(':revoked_at', $now)
            ->bind(':actor', $actor, ParameterType::INTEGER)
            ->bind(':reason', $safeReason)
            ->bind(':user_id', $user, ParameterType::INTEGER);
        $this->db->setQuery($query)->execute();
    }

    private function revokeOtherActiveRows(int $userId, int $keepId, ?int $actorId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $actor = $actorId ?? 0;
        $user = $userId;
        $id = $keepId;
        $reason = 'duplicate_active_token_repair';
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__memi_qr_tokens'))
            ->set($this->db->quoteName('revoked_at') . ' = :revoked_at')
            ->set($this->db->quoteName('revoked_by') . ' = :actor')
            ->set($this->db->quoteName('revocation_reason') . ' = :reason')
            ->set($this->db->quoteName('active_token_key') . ' = NULL')
            ->where($this->db->quoteName('user_id') . ' = :user_id')
            ->where($this->db->quoteName('id') . ' <> :keep_id')
            ->where($this->db->quoteName('revoked_at') . ' IS NULL')
            ->bind(':revoked_at', $now)
            ->bind(':actor', $actor, ParameterType::INTEGER)
            ->bind(':reason', $reason)
            ->bind(':user_id', $user, ParameterType::INTEGER)
            ->bind(':keep_id', $id, ParameterType::INTEGER);
        $this->db->setQuery($query)->execute();
    }

    private function deriveToken(int $clientId, int $userId, int $version): string
    {
        $secret = (string) Factory::getApplication()->get('secret', '');
        if (strlen($secret) < 16) {
            throw new DomainException('COM_MEMIPILATES_ERROR_QR_CONFIGURATION');
        }

        $key = hash_hmac('sha256', self::TOKEN_DOMAIN, $secret, true);
        $payload = self::TOKEN_DOMAIN . ':' . $clientId . ':' . $userId . ':' . $version;

        return rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $key, true)), '+/', '-_'), '=');
    }

    private function activeKey(int $clientId): string
    {
        return hash('sha256', 'client:' . $clientId);
    }

    /**
     * @param array<string,mixed> $row
     * @return array{token:string, token_id:int, created_at:string}
     */
    private function result(array $row, string $token): array
    {
        return [
            'token' => $token,
            'token_id' => (int) $row['id'],
            'created_at' => (string) ($row['issued_at'] ?? $row['created_at'] ?? ''),
        ];
    }
}
