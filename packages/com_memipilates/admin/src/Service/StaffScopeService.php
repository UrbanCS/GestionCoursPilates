<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;

/** Resolves the server-side session scope of a Joomla-linked instructor. */
final class StaffScopeService
{
    public function __construct(private readonly DatabaseDriver $db)
    {
    }

    public function instructorIdForUser(int $userId): ?int
    {
        if ($userId <= 0) {
            return null;
        }

        $identifier = $userId;
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__memi_instructors'))
            ->where($this->db->quoteName('user_id') . ' = :user_id')
            ->where($this->db->quoteName('archived_at') . ' IS NULL')
            ->bind(':user_id', $identifier, ParameterType::INTEGER);
        $this->db->setQuery($query);
        $id = (int) $this->db->loadResult();

        return $id > 0 ? $id : null;
    }

    public function assertAssignedSession(int $userId, int $sessionId): void
    {
        $instructorId = $this->instructorIdForUser($userId);
        if ($instructorId === null || $sessionId <= 0) {
            throw new DomainException('COM_MEMIPILATES_ERROR_NOT_FOUND', [], 404);
        }

        $session = $sessionId;
        $instructor = $instructorId;
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__memi_sessions'))
            ->where($this->db->quoteName('id') . ' = :session_id')
            ->where($this->db->quoteName('instructor_id') . ' = :instructor_id')
            ->where($this->db->quoteName('archived_at') . ' IS NULL')
            ->bind(':session_id', $session, ParameterType::INTEGER)
            ->bind(':instructor_id', $instructor, ParameterType::INTEGER);
        $this->db->setQuery($query);

        if ((int) $this->db->loadResult() !== 1) {
            // Deliberately avoid confirming that an out-of-scope session exists.
            throw new DomainException('COM_MEMIPILATES_ERROR_NOT_FOUND', [], 404);
        }
    }
}
