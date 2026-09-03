<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;

/** Enforces configurable course prerequisites and auditable staff overrides. */
final class EligibilityService
{
    public function __construct(
        private readonly DatabaseDriver $db,
        private readonly DatabaseTools $tools,
        private readonly AuditLogger $audit
    ) {
    }

    /**
     * @return array{eligible:bool,required_count:int,completed_count:int,course_type_id:int,course_type_title:string,prerequisite_course_type_id:int,prerequisite_title:string,override:bool}
     */
    public function evaluateForSession(int $userId, int $sessionId): array
    {
        if ($userId <= 0 || $sessionId <= 0) {
            throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
        }

        $session = $sessionId;
        $query = $this->db->getQuery(true)
            ->select([
                'ct.id AS course_type_id',
                'ct.title AS course_type_title',
                'ct.prerequisite_course_type_id',
                'ct.prerequisite_attendance_count',
                'prerequisite.title AS prerequisite_title',
            ])
            ->from($this->db->quoteName('#__memi_sessions', 's'))
            ->join('INNER', $this->db->quoteName('#__memi_courses', 'c') . ' ON c.id = s.course_id')
            ->join('INNER', $this->db->quoteName('#__memi_course_types', 'ct') . ' ON ct.id = c.course_type_id')
            ->join('LEFT', $this->db->quoteName('#__memi_course_types', 'prerequisite') . ' ON prerequisite.id = ct.prerequisite_course_type_id')
            ->where('s.id = :session_id')
            ->where('s.archived_at IS NULL')
            ->where('c.archived_at IS NULL')
            ->where('ct.archived_at IS NULL')
            ->bind(':session_id', $session, ParameterType::INTEGER);
        $this->db->setQuery($query, 0, 1);
        $requirement = $this->db->loadAssoc();
        if (!$requirement) {
            throw new DomainException('COM_MEMIPILATES_ERROR_SESSION_NOT_FOUND', [], 404);
        }

        $courseTypeId = (int) $requirement['course_type_id'];
        $prerequisiteId = (int) ($requirement['prerequisite_course_type_id'] ?? 0);
        $requiredCount = max(0, (int) ($requirement['prerequisite_attendance_count'] ?? 0));
        $result = [
            'eligible' => true,
            'required_count' => $requiredCount,
            'completed_count' => 0,
            'course_type_id' => $courseTypeId,
            'course_type_title' => (string) ($requirement['course_type_title'] ?? ''),
            'prerequisite_course_type_id' => $prerequisiteId,
            'prerequisite_title' => (string) ($requirement['prerequisite_title'] ?? ''),
            'override' => false,
        ];

        if ($prerequisiteId <= 0 || $requiredCount <= 0) {
            return $result;
        }

        if ($this->hasActiveOverride($userId, $courseTypeId)) {
            $result['override'] = true;

            return $result;
        }

        $result['completed_count'] = $this->completedAttendanceCount($userId, $prerequisiteId);
        $result['eligible'] = $result['completed_count'] >= $requiredCount;

        return $result;
    }

    public function assertEligibleForSession(int $userId, int $sessionId): void
    {
        if (!$this->evaluateForSession($userId, $sessionId)['eligible']) {
            throw new DomainException('COM_MEMIPILATES_ERROR_COURSE_PREREQUISITE', [], 403);
        }
    }

    public function grantOverride(int $userId, int $courseTypeId, int $actorId, string $reason): void
    {
        $reason = trim(mb_substr($reason, 0, 500));
        if ($userId <= 0 || $courseTypeId <= 0 || $actorId <= 0 || $reason === '') {
            throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
        }

        $this->tools->transaction(function () use ($userId, $courseTypeId, $actorId, $reason): void {
            $profile = $this->tools->lockClientProfile($userId);
            $courseType = $this->restrictedCourseType($courseTypeId);
            $user = $userId;
            $type = $courseTypeId;
            $query = $this->db->getQuery(true)
                ->select('*')
                ->from($this->db->quoteName('#__memi_course_eligibility_overrides'))
                ->where('user_id = :user_id')
                ->where('course_type_id = :course_type_id')
                ->bind(':user_id', $user, ParameterType::INTEGER)
                ->bind(':course_type_id', $type, ParameterType::INTEGER);
            $this->db->setQuery(DatabaseTools::forUpdate($query), 0, 1);
            $before = $this->db->loadAssoc() ?: null;
            $now = gmdate('Y-m-d H:i:s');

            if ($before) {
                $id = (int) $before['id'];
                $update = $this->db->getQuery(true)
                    ->update($this->db->quoteName('#__memi_course_eligibility_overrides'))
                    ->set('reason = :reason')
                    ->set('granted_at = :granted_at')
                    ->set('granted_by = :granted_by')
                    ->set('revoked_at = NULL')
                    ->set('revoked_by = 0')
                    ->set("revocation_reason = ''")
                    ->set('updated_at = :updated_at')
                    ->where('id = :id')
                    ->bind(':reason', $reason)
                    ->bind(':granted_at', $now)
                    ->bind(':granted_by', $actorId, ParameterType::INTEGER)
                    ->bind(':updated_at', $now)
                    ->bind(':id', $id, ParameterType::INTEGER);
                $this->db->setQuery($update)->execute();
                $overrideId = $id;
            } else {
                $clientId = (int) $profile['id'];
                $insert = $this->db->getQuery(true)
                    ->insert($this->db->quoteName('#__memi_course_eligibility_overrides'))
                    ->columns(['client_id', 'user_id', 'course_type_id', 'reason', 'granted_at', 'granted_by', 'created_at', 'updated_at'])
                    ->values(':client_id, :user_id, :course_type_id, :reason, :granted_at, :granted_by, :created_at, :updated_at')
                    ->bind(':client_id', $clientId, ParameterType::INTEGER)
                    ->bind(':user_id', $user, ParameterType::INTEGER)
                    ->bind(':course_type_id', $type, ParameterType::INTEGER)
                    ->bind(':reason', $reason)
                    ->bind(':granted_at', $now)
                    ->bind(':granted_by', $actorId, ParameterType::INTEGER)
                    ->bind(':created_at', $now)
                    ->bind(':updated_at', $now);
                $this->db->setQuery($insert)->execute();
                $overrideId = (int) $this->db->insertid();
            }

            $this->audit->log($actorId, 'eligibility.override.grant', 'course_eligibility', $overrideId, $before, [
                'user_id' => $userId,
                'course_type_id' => $courseTypeId,
                'course_type_title' => (string) $courseType['title'],
                'reason' => $reason,
            ], $reason);
        });
    }

    public function revokeOverride(int $userId, int $courseTypeId, int $actorId, string $reason): void
    {
        $reason = trim(mb_substr($reason, 0, 500));
        if ($userId <= 0 || $courseTypeId <= 0 || $actorId <= 0) {
            throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
        }

        $this->tools->transaction(function () use ($userId, $courseTypeId, $actorId, $reason): void {
            $user = $userId;
            $type = $courseTypeId;
            $query = $this->db->getQuery(true)
                ->select('*')
                ->from($this->db->quoteName('#__memi_course_eligibility_overrides'))
                ->where('user_id = :user_id')
                ->where('course_type_id = :course_type_id')
                ->where('revoked_at IS NULL')
                ->bind(':user_id', $user, ParameterType::INTEGER)
                ->bind(':course_type_id', $type, ParameterType::INTEGER);
            $this->db->setQuery(DatabaseTools::forUpdate($query), 0, 1);
            $before = $this->db->loadAssoc();
            if (!$before) {
                throw new DomainException('COM_MEMIPILATES_ERROR_NOT_FOUND', [], 404);
            }

            $id = (int) $before['id'];
            $now = gmdate('Y-m-d H:i:s');
            $update = $this->db->getQuery(true)
                ->update($this->db->quoteName('#__memi_course_eligibility_overrides'))
                ->set('revoked_at = :revoked_at')
                ->set('revoked_by = :revoked_by')
                ->set('revocation_reason = :revocation_reason')
                ->set('updated_at = :updated_at')
                ->where('id = :id')
                ->bind(':revoked_at', $now)
                ->bind(':revoked_by', $actorId, ParameterType::INTEGER)
                ->bind(':revocation_reason', $reason)
                ->bind(':updated_at', $now)
                ->bind(':id', $id, ParameterType::INTEGER);
            $this->db->setQuery($update)->execute();
            $this->audit->log($actorId, 'eligibility.override.revoke', 'course_eligibility', $id, $before, [
                'revoked_at' => $now,
                'reason' => $reason,
            ], $reason !== '' ? $reason : null);
        });
    }

    private function hasActiveOverride(int $userId, int $courseTypeId): bool
    {
        $user = $userId;
        $type = $courseTypeId;
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__memi_course_eligibility_overrides'))
            ->where('user_id = :user_id')
            ->where('course_type_id = :course_type_id')
            ->where('revoked_at IS NULL')
            ->bind(':user_id', $user, ParameterType::INTEGER)
            ->bind(':course_type_id', $type, ParameterType::INTEGER);
        $this->db->setQuery($query);

        return (int) $this->db->loadResult() > 0;
    }

    private function completedAttendanceCount(int $userId, int $courseTypeId): int
    {
        $user = $userId;
        $type = $courseTypeId;
        $confirmed = 'confirmed';
        $query = $this->db->getQuery(true)
            ->select('COUNT(DISTINCT a.session_id)')
            ->from($this->db->quoteName('#__memi_attendance', 'a'))
            ->join('INNER', $this->db->quoteName('#__memi_sessions', 's') . ' ON s.id = a.session_id')
            ->join('INNER', $this->db->quoteName('#__memi_courses', 'c') . ' ON c.id = s.course_id')
            ->where('a.user_id = :user_id')
            ->where('c.course_type_id = :course_type_id')
            ->where('a.status = :status')
            ->where('a.voided_at IS NULL')
            ->bind(':user_id', $user, ParameterType::INTEGER)
            ->bind(':course_type_id', $type, ParameterType::INTEGER)
            ->bind(':status', $confirmed);
        $this->db->setQuery($query);

        return max(0, (int) $this->db->loadResult());
    }

    /** @return array<string,mixed> */
    private function restrictedCourseType(int $courseTypeId): array
    {
        $type = $courseTypeId;
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__memi_course_types'))
            ->where('id = :id')
            ->where('archived_at IS NULL')
            ->where('prerequisite_course_type_id IS NOT NULL')
            ->where('prerequisite_attendance_count > 0')
            ->bind(':id', $type, ParameterType::INTEGER);
        $this->db->setQuery($query, 0, 1);
        $record = $this->db->loadAssoc();
        if (!$record) {
            throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
        }

        return $record;
    }
}
