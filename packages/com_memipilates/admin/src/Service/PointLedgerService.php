<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;

/** Append-only loyalty point ledger with idempotent awards. */
final class PointLedgerService
{
    public function __construct(
        private readonly DatabaseDriver $db,
        private readonly DatabaseTools $tools,
        private readonly AuditLogger $audit
    )
    {
    }

    public function balance(int $userId): int
    {
        $user = $userId;
        $query = $this->db->getQuery(true)
            ->select('COALESCE(SUM(' . $this->db->quoteName('points_delta') . '), 0)')
            ->from($this->db->quoteName('#__memi_points_ledger'))
            ->where($this->db->quoteName('user_id') . ' = :user_id')
            ->bind(':user_id', $user, ParameterType::INTEGER);
        $this->db->setQuery($query);

        return (int) $this->db->loadResult();
    }

    public function award(
        int $userId,
        int $points,
        string $eventType,
        string $idempotencyKey,
        ?int $attendanceId = null,
        ?int $orderId = null,
        ?int $actorId = null,
        ?string $description = null,
        ?\DateTimeInterface $expiresAt = null
    ): int {
        if ($points === 0) {
            return $this->balance($userId);
        }

        $key = $idempotencyKey;
        $find = $this->db->getQuery(true)
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__memi_points_ledger'))
            ->where($this->db->quoteName('idempotency_key') . ' = :idempotency_key')
            ->bind(':idempotency_key', $key);
        $this->db->setQuery($find);
        if ((int) $this->db->loadResult() > 0) {
            return $this->balance($userId);
        }

        $now = gmdate('Y-m-d H:i:s');
        $profile = $this->tools->lockClientProfile($userId);
        $clientId = (int) $profile['id'];
        $expires = $expiresAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $user = $userId;
        $delta = $points;
        $attendance = $attendanceId;
        $order = $orderId;
        $actor = $actorId;
        $query = $this->db->getQuery(true)
            ->insert($this->db->quoteName('#__memi_points_ledger'))
            ->columns([
                'client_id', 'user_id', 'points_delta', 'entry_type', 'attendance_id', 'order_id',
                'description', 'created_by', 'idempotency_key', 'expires_at', 'created_at',
            ])
            ->values(':client_id, :user_id, :points_delta, :entry_type, :attendance_id, :order_id, :description, :created_by, :idempotency_key, :expires_at, :created_at')
            ->bind(':client_id', $clientId, ParameterType::INTEGER)
            ->bind(':user_id', $user, ParameterType::INTEGER)
            ->bind(':points_delta', $delta, ParameterType::INTEGER)
            ->bind(':entry_type', $eventType)
            ->bind(':attendance_id', $attendance, ParameterType::INTEGER)
            ->bind(':order_id', $order, ParameterType::INTEGER)
            ->bind(':description', $description)
            ->bind(':created_by', $actor, ParameterType::INTEGER)
            ->bind(':idempotency_key', $idempotencyKey)
            ->bind(':expires_at', $expires)
            ->bind(':created_at', $now);
        $this->db->setQuery($query)->execute();
        $entryId = (int) $this->db->insertid();
        $this->audit->log($actorId, 'points.' . $eventType, 'points_ledger', $entryId, null, [
            'user_id' => $userId,
            'points_delta' => $points,
            'attendance_id' => $attendanceId,
            'order_id' => $orderId,
        ]);

        return $this->balance($userId);
    }
}
