<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;

/** Issues opaque, revocable QR tokens. Raw values are returned once only. */
final class QrTokenService
{
    public function __construct(
        private readonly DatabaseDriver $db,
        private readonly DatabaseTools $tools,
        private readonly AuditLogger $audit
    ) {
    }

    /**
     * @return array{token:string, token_id:int, created_at:string}
     */
    public function regenerate(int $userId, ?int $actorId = null): array
    {
        return $this->tools->transaction(function () use ($userId, $actorId): array {
            $profile = $this->tools->lockClientProfile($userId);
            $clientId = (int) $profile['id'];
            $now = gmdate('Y-m-d H:i:s');
            $user = $userId;
            $revoke = $this->db->getQuery(true)
                ->update($this->db->quoteName('#__memi_qr_tokens'))
                ->set($this->db->quoteName('revoked_at') . ' = :revoked_at')
                ->set($this->db->quoteName('revoked_by') . ' = :actor')
                ->where($this->db->quoteName('user_id') . ' = :user_id')
                ->where($this->db->quoteName('revoked_at') . ' IS NULL')
                ->bind(':revoked_at', $now)
                ->bind(':actor', $actorId, ParameterType::INTEGER)
                ->bind(':user_id', $user, ParameterType::INTEGER);
            $this->db->setQuery($revoke)->execute();

            // 32 random bytes encode to a URL-safe 43-character opaque token.
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $hash = hash('sha256', $token);
            $insert = $this->db->getQuery(true)
                ->insert($this->db->quoteName('#__memi_qr_tokens'))
                ->columns(['client_id', 'user_id', 'token_hash', 'issued_at', 'created_at'])
                ->values(':client_id, :user_id, :token_hash, :issued_at, :created_at')
                ->bind(':client_id', $clientId, ParameterType::INTEGER)
                ->bind(':user_id', $user, ParameterType::INTEGER)
                ->bind(':token_hash', $hash)
                ->bind(':issued_at', $now)
                ->bind(':created_at', $now)
                ;
            $this->db->setQuery($insert)->execute();
            $id = (int) $this->db->insertid();
            $this->audit->log($actorId, 'qr.regenerate', 'qr_token', $id, null, ['user_id' => $userId]);

            return ['token' => $token, 'token_id' => $id, 'created_at' => $now];
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
            ->select('*')
            ->from($this->db->quoteName('#__memi_qr_tokens'))
            ->where($this->db->quoteName('token_hash') . ' = :token_hash')
            ->where($this->db->quoteName('revoked_at') . ' IS NULL')
            ->where('(' . $this->db->quoteName('expires_at') . ' IS NULL OR ' . $this->db->quoteName('expires_at') . ' > :now)')
            ->bind(':token_hash', $hash)
            ->bind(':now', $now);
        $this->db->setQuery($query);

        return $this->db->loadAssoc() ?: null;
    }

    public function revoke(int $tokenId, int $actorId, ?string $reason = null): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $id = $tokenId;
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__memi_qr_tokens'))
            ->set($this->db->quoteName('revoked_at') . ' = :revoked_at')
            ->set($this->db->quoteName('revoked_by') . ' = :actor')
            ->set($this->db->quoteName('revocation_reason') . ' = :reason')
            ->where($this->db->quoteName('id') . ' = :id')
            ->where($this->db->quoteName('revoked_at') . ' IS NULL')
            ->bind(':revoked_at', $now)
            ->bind(':actor', $actorId, ParameterType::INTEGER)
            ->bind(':reason', $reason)
            ->bind(':id', $id, ParameterType::INTEGER);
        $this->db->setQuery($query)->execute();
        $this->audit->log($actorId, 'qr.revoke', 'qr_token', $tokenId, null, null, $reason);
    }
}
