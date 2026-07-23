<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;

/** Releases the capacity hold for an offer that cannot be delivered. */
final class WaitlistOfferFailureService
{
    public function __construct(
        private readonly DatabaseDriver $db,
        private readonly DatabaseTools $tools,
        private readonly AuditLogger $audit
    ) {
    }

    /** Returns the released session ID, or null if this offer is no longer current. */
    public function fail(int $waitlistId, string $offeredAt, ?int $actorId, string $failureCode): ?int
    {
        if ($waitlistId <= 0 || $offeredAt === '') {
            return null;
        }

        $result = $this->tools->transaction(function () use ($waitlistId, $offeredAt): ?array {
            $entry = $this->tools->lockById('#__memi_waitlist', $waitlistId);
            if (!$entry || (string) $entry['status'] !== 'offered' || (string) $entry['offered_at'] !== $offeredAt) {
                return null;
            }

            $now = gmdate('Y-m-d H:i:s');
            $identifier = $waitlistId;
            $failed = 'notification_failed';
            $update = $this->db->getQuery(true)
                ->update($this->db->quoteName('#__memi_waitlist'))
                ->set($this->db->quoteName('status') . ' = :status')
                ->set($this->db->quoteName('offer_token_hash') . ' = NULL')
                ->set($this->db->quoteName('offer_expires_at') . ' = NULL')
                ->set($this->db->quoteName('updated_at') . ' = :updated_at')
                ->where($this->db->quoteName('id') . ' = :id')
                ->bind(':status', $failed)
                ->bind(':updated_at', $now)
                ->bind(':id', $identifier, ParameterType::INTEGER);
            $this->db->setQuery($update)->execute();

            $sessionId = (int) $entry['session_id'];
            $session = $sessionId;
            $release = $this->db->getQuery(true)
                ->update($this->db->quoteName('#__memi_sessions'))
                ->set($this->db->quoteName('reserved_count') . ' = GREATEST(0, ' . $this->db->quoteName('reserved_count') . ' - 1)')
                ->set(
                    $this->db->quoteName('waitlist_count')
                    . ' = (SELECT COUNT(*) FROM ' . $this->db->quoteName('#__memi_waitlist')
                    . ' WHERE ' . $this->db->quoteName('session_id') . ' = :session_id'
                    . ' AND ' . $this->db->quoteName('status') . ' IN (' . $this->db->quote('waiting') . ', ' . $this->db->quote('offered') . '))'
                )
                ->set($this->db->quoteName('updated_at') . ' = :updated_at')
                ->where($this->db->quoteName('id') . ' = :session_id')
                ->bind(':session_id', $session, ParameterType::INTEGER)
                ->bind(':updated_at', $now);
            $this->db->setQuery($release)->execute();
            return ['session_id' => $sessionId, 'entry' => $entry, 'status' => $failed];
        });

        if ($result === null) {
            return null;
        }

        try {
            $this->audit->log($actorId, 'waitlist.offer.notification_failed', 'waitlist', $waitlistId, $result['entry'], [
                'status' => $result['status'],
                'failure_code' => $failureCode,
                'capacity_released' => true,
            ]);
        } catch (\Throwable) {
            // Audit availability must not re-lock a place after its offer failed.
        }

        return (int) $result['session_id'];
    }
}
