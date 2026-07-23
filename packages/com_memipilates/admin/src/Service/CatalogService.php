<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\CMS\Filter\OutputFilter;
use Joomla\Input\Input;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;

/**
 * Creates the studio catalogue from the protected administrator setup screen.
 * The service deliberately exposes only the fields that are used by the live
 * booking flow; operational metadata stays server-owned.
 */
final class CatalogService
{
    /** Tables that prove the catalogue is no longer only bootstrap/test data. */
    private const RESET_ACTIVITY_TABLES = [
        '#__memi_bookings',
        '#__memi_waitlist',
        '#__memi_attendance',
        '#__memi_customer_packages',
        '#__memi_credit_ledger',
        '#__memi_points_ledger',
        '#__memi_orders',
        '#__memi_payments',
        '#__memi_refunds',
        '#__memi_promotion_redemptions',
        '#__memi_reward_redemptions',
        '#__memi_scan_attempts',
        '#__memi_notifications',
        '#__memi_qr_tokens',
        '#__memi_square_webhooks',
        '#__memi_client_profiles',
    ];

    public function __construct(
        private readonly DatabaseDriver $db,
        private readonly DatabaseTools $tools,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit
    ) {
    }

    public function create(string $entity, Input $input, int $actorId): int
    {
        return match ($entity) {
            'location' => $this->createLocation($input, $actorId),
            'room' => $this->createRoom($input, $actorId),
            'instructor' => $this->createInstructor($input, $actorId),
            'course_type' => $this->createCourseType($input, $actorId),
            'course' => $this->createCourse($input, $actorId),
            'session' => $this->createSession($input, $actorId),
            'session_rule' => $this->createSessionRule($input, $actorId),
            'package' => $this->createPackage($input, $actorId),
            default => throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST'),
        };
    }

    /**
     * Archives a never-used bootstrap catalogue without removing the audit
     * history. This is deliberately refused as soon as any customer activity
     * exists, so it cannot be used as a live-data deletion tool.
     *
     * @return array<string, int>
     */
    public function archiveTestCatalog(Input $input, int $actorId): array
    {
        $confirmation = strtoupper(trim($input->getString('confirmation', '')));
        if ($confirmation !== 'REINITIALISER') {
            throw new DomainException('COM_MEMIPILATES_ERROR_SETUP_RESET_CONFIRMATION');
        }

        return $this->tools->transaction(function () use ($actorId): array {
            // All sources that can create customer activity are locked before
            // the activity check. This also serialises the reset with the
            // scheduler, which locks its recurrence rule before inserting.
            $this->lockResetSources();
            $this->assertNoCatalogActivity();

            $now = $this->now();
            $counts = [
                'sessions' => $this->archiveSessionsForReset($actorId, $now),
                'session_rules' => $this->archiveCatalogRows('#__memi_session_rules', $actorId, $now, true),
                'courses' => $this->archiveCatalogRows('#__memi_courses', $actorId, $now, true),
                'course_types' => $this->archiveCatalogRows('#__memi_course_types', $actorId, $now, true),
                'packages' => $this->archiveCatalogRows('#__memi_packages', $actorId, $now, true),
                'promotions' => $this->archiveCatalogRows('#__memi_promotions', $actorId, $now, true),
                'rewards' => $this->archiveCatalogRows('#__memi_rewards', $actorId, $now, true),
                'instructors' => $this->archiveCatalogRows('#__memi_instructors', $actorId, $now, true),
                'rooms' => $this->archiveCatalogRows('#__memi_rooms', $actorId, $now, true),
                'locations' => $this->archiveCatalogRows('#__memi_locations', $actorId, $now, true),
            ];
            $this->audit->log($actorId, 'catalog.reset', 'catalog', null, null, ['archived' => $counts]);

            return $counts;
        });
    }

    private function createLocation(Input $input, int $actorId): int
    {
        $title = $this->requiredText($input, 'title', 255);
        $now = $this->now();
        $row = [
            'title' => $title,
            'alias' => $this->uniqueAlias('#__memi_locations', $title),
            'address_line1' => $this->text($input, 'address_line1', 255),
            'address_line2' => $this->text($input, 'address_line2', 255),
            'city' => $this->text($input, 'city', 128),
            'province' => $this->text($input, 'province', 128),
            'postal_code' => $this->text($input, 'postal_code', 32),
            'country_code' => 'CA',
            'timezone' => $this->settings->timezone()->getName(),
            'phone' => $this->text($input, 'phone', 64),
            'published' => $this->published($input),
            'ordering' => 0,
            'created_at' => $now,
            'created_by' => $actorId,
            'updated_at' => $now,
            'updated_by' => $actorId,
        ];

        return $this->store('location', '#__memi_locations', $row, $actorId);
    }

    private function createRoom(Input $input, int $actorId): int
    {
        $locationId = $this->requiredId($input, 'location_id');
        $this->requireRecord('#__memi_locations', $locationId);
        $title = $this->requiredText($input, 'title', 255);
        $now = $this->now();
        $row = [
            'location_id' => $locationId,
            'title' => $title,
            'alias' => $this->uniqueAlias('#__memi_rooms', $title, $locationId),
            'capacity' => $this->integer($input, 'capacity', 1, 500, 1),
            'description' => $this->text($input, 'description', 10000),
            'published' => $this->published($input),
            'access' => 1,
            'ordering' => 0,
            'created_at' => $now,
            'created_by' => $actorId,
            'updated_at' => $now,
            'updated_by' => $actorId,
        ];

        return $this->store('room', '#__memi_rooms', $row, $actorId);
    }

    private function createInstructor(Input $input, int $actorId): int
    {
        $email = $this->text($input, 'email', 320);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException('COM_MEMIPILATES_ERROR_SETUP_INVALID_EMAIL');
        }
        $userId = $this->validatedInstructorUserId($input);
        $now = $this->now();
        $row = [
            'user_id' => $userId,
            'display_name' => $this->requiredText($input, 'display_name', 255),
            'bio' => $this->text($input, 'bio', 20000),
            'image' => '',
            'phone' => $this->text($input, 'phone', 64),
            'email' => $email,
            'published' => $this->published($input),
            'ordering' => 0,
            'created_at' => $now,
            'created_by' => $actorId,
            'updated_at' => $now,
            'updated_by' => $actorId,
        ];

        return $this->store('instructor', '#__memi_instructors', $row, $actorId);
    }

    private function validatedInstructorUserId(Input $input): ?int
    {
        $userId = $this->optionalId($input, 'user_id');
        if ($userId === null) {
            return null;
        }

        $identifier = $userId;
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__users'))
            ->where($this->db->quoteName('id') . ' = :user_id')
            ->where($this->db->quoteName('block') . ' = 0')
            ->bind(':user_id', $identifier, ParameterType::INTEGER);
        $this->db->setQuery($query);
        if ((int) $this->db->loadResult() !== 1) {
            throw new DomainException('COM_MEMIPILATES_ERROR_NOT_FOUND', [], 404);
        }

        $linkedUser = $userId;
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__memi_instructors'))
            ->where($this->db->quoteName('user_id') . ' = :linked_user_id')
            ->bind(':linked_user_id', $linkedUser, ParameterType::INTEGER);
        $this->db->setQuery($query);
        if ((int) $this->db->loadResult() > 0) {
            throw new DomainException('COM_MEMIPILATES_ERROR_SETUP_DUPLICATE', [], 409);
        }

        return $userId;
    }

    private function createCourseType(Input $input, int $actorId): int
    {
        $title = $this->requiredText($input, 'title', 255);
        $now = $this->now();
        $row = [
            'title' => $title,
            'alias' => $this->uniqueAlias('#__memi_course_types', $title),
            'description' => $this->text($input, 'description', 20000),
            'level' => $this->text($input, 'level', 64),
            'intensity' => $this->optionalInteger($input, 'intensity', 1, 10),
            'default_duration_minutes' => $this->integer($input, 'duration_minutes', 5, 720, 60),
            'default_capacity' => $this->integer($input, 'capacity', 1, 500, 1),
            'default_price_cents' => $this->moneyCents($input, 'price'),
            'default_credits_required' => $this->integer($input, 'credits_required', 0, 1000, 1),
            'tax_rate_basis_points' => $this->integer($input, 'tax_rate_basis_points', 0, 10000, 0),
            'image' => '',
            'published' => $this->published($input),
            'access' => 1,
            'ordering' => 0,
            'created_at' => $now,
            'created_by' => $actorId,
            'updated_at' => $now,
            'updated_by' => $actorId,
        ];

        return $this->store('course_type', '#__memi_course_types', $row, $actorId);
    }

    private function createCourse(Input $input, int $actorId): int
    {
        $courseTypeId = $this->requiredId($input, 'course_type_id');
        $type = $this->requireRecord('#__memi_course_types', $courseTypeId);
        $instructorId = $this->optionalId($input, 'instructor_id');
        $roomId = $this->optionalId($input, 'room_id');
        if ($instructorId !== null) {
            $this->requireRecord('#__memi_instructors', $instructorId);
        }
        $room = $roomId !== null ? $this->requireRecord('#__memi_rooms', $roomId) : null;
        $duration = $this->integer($input, 'duration_minutes', 5, 720, (int) $type['default_duration_minutes']);
        $capacity = $this->integer($input, 'capacity', 1, 500, (int) $type['default_capacity']);
        if ($room !== null && $capacity > (int) $room['capacity']) {
            throw new DomainException('COM_MEMIPILATES_ERROR_SETUP_ROOM_CAPACITY');
        }
        $title = $this->requiredText($input, 'title', 255);
        $bookingOpens = $this->integer($input, 'booking_opens_days', 0, 365, 7) * 1440;
        $bookingCloses = $this->integer($input, 'booking_closes_minutes', 0, 10080, 0);
        if ($bookingOpens < $bookingCloses) {
            throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
        }
        $now = $this->now();
        $row = [
            'course_type_id' => $courseTypeId,
            'instructor_id' => $instructorId,
            'room_id' => $roomId,
            'title' => $title,
            'alias' => $this->uniqueAlias('#__memi_courses', $title),
            'description' => $this->text($input, 'description', 20000),
            'default_duration_minutes' => $duration,
            'duration_minutes' => $duration,
            'capacity' => $capacity,
            'price_cents' => $this->moneyCents($input, 'price', (int) $type['default_price_cents']),
            'credits_required' => $this->integer($input, 'credits_required', 0, 1000, (int) $type['default_credits_required']),
            'tax_rate_basis_points' => $this->integer($input, 'tax_rate_basis_points', 0, 10000, (int) $type['tax_rate_basis_points']),
            'booking_opens_offset_minutes' => $bookingOpens,
            'booking_closes_offset_minutes' => $bookingCloses,
            'is_private' => 0,
            'status' => 'active',
            'published' => $this->published($input),
            'access' => 1,
            'ordering' => 0,
            'internal_note' => '',
            'created_at' => $now,
            'created_by' => $actorId,
            'updated_at' => $now,
            'updated_by' => $actorId,
        ];

        return $this->store('course', '#__memi_courses', $row, $actorId);
    }

    private function createSession(Input $input, int $actorId): int
    {
        $courseId = $this->requiredId($input, 'course_id');
        $course = $this->requireRecord('#__memi_courses', $courseId);
        $instructorId = $this->optionalId($input, 'instructor_id') ?? $this->nullableInt($course['instructor_id'] ?? null);
        $roomId = $this->optionalId($input, 'room_id') ?? $this->nullableInt($course['room_id'] ?? null);
        if ($instructorId !== null) {
            $this->requireRecord('#__memi_instructors', $instructorId);
        }
        $room = $roomId !== null ? $this->requireRecord('#__memi_rooms', $roomId) : null;
        $duration = $this->integer($input, 'duration_minutes', 5, 720, (int) $course['duration_minutes']);
        $capacity = $this->integer($input, 'capacity', 1, 500, (int) $course['capacity']);
        if ($room !== null && $capacity > (int) $room['capacity']) {
            throw new DomainException('COM_MEMIPILATES_ERROR_SETUP_ROOM_CAPACITY');
        }
        $startsAt = $this->localDateTime($this->requiredText($input, 'starts_at', 32));
        if ($startsAt <= new \DateTimeImmutable('now', $this->settings->timezone())) {
            throw new DomainException('COM_MEMIPILATES_ERROR_SETUP_FUTURE_SESSION');
        }
        $endsAt = $startsAt->modify('+' . $duration . ' minutes');
        $utc = new \DateTimeZone('UTC');
        $opens = max(0, (int) $course['booking_opens_offset_minutes']);
        $closes = max(0, (int) $course['booking_closes_offset_minutes']);
        $now = $this->now();
        $row = [
            'course_id' => $courseId,
            'rule_id' => null,
            'instructor_id' => $instructorId,
            'room_id' => $roomId,
            'starts_at' => $startsAt->setTimezone($utc)->format('Y-m-d H:i:s'),
            'ends_at' => $endsAt->setTimezone($utc)->format('Y-m-d H:i:s'),
            'timezone' => $this->settings->timezone()->getName(),
            'duration_minutes' => $duration,
            'capacity' => $capacity,
            'reserved_count' => 0,
            'waitlist_count' => 0,
            'price_cents' => (int) $course['price_cents'],
            'credits_required' => (int) $course['credits_required'],
            'tax_rate_basis_points' => (int) $course['tax_rate_basis_points'],
            'registration_opens_at' => $startsAt->modify('-' . $opens . ' minutes')->setTimezone($utc)->format('Y-m-d H:i:s'),
            'registration_closes_at' => $startsAt->modify('-' . $closes . ' minutes')->setTimezone($utc)->format('Y-m-d H:i:s'),
            'status' => 'published',
            'is_private' => 0,
            'internal_note' => '',
            'created_at' => $now,
            'created_by' => $actorId,
            'updated_at' => $now,
            'updated_by' => $actorId,
        ];

        return $this->store('session', '#__memi_sessions', $row, $actorId);
    }

    private function createSessionRule(Input $input, int $actorId): int
    {
        $courseId = $this->requiredId($input, 'course_id');
        $course = $this->requireRecord('#__memi_courses', $courseId);
        $instructorId = $this->optionalId($input, 'instructor_id') ?? $this->nullableInt($course['instructor_id'] ?? null);
        $roomId = $this->optionalId($input, 'room_id') ?? $this->nullableInt($course['room_id'] ?? null);
        if ($instructorId !== null) {
            $this->requireRecord('#__memi_instructors', $instructorId);
        }
        $room = $roomId !== null ? $this->requireRecord('#__memi_rooms', $roomId) : null;
        $duration = $this->integer($input, 'duration_minutes', 5, 720, (int) $course['duration_minutes']);
        $capacity = $this->integer($input, 'capacity', 1, 500, (int) $course['capacity']);
        if ($room !== null && $capacity > (int) $room['capacity']) {
            throw new DomainException('COM_MEMIPILATES_ERROR_SETUP_ROOM_CAPACITY');
        }
        $startsOn = $this->date($this->requiredText($input, 'starts_on', 10));
        $endsOn = $this->optionalDate($this->text($input, 'ends_on', 10));
        if ($endsOn !== null && $endsOn < $startsOn) {
            throw new DomainException('COM_MEMIPILATES_ERROR_SETUP_INVALID_DATE');
        }
        $weekday = $this->integer($input, 'weekday', 1, 7, 1);
        $startTime = $this->time($this->requiredText($input, 'start_time', 8));
        $days = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];
        $now = $this->now();
        $row = [
            'course_id' => $courseId,
            'instructor_id' => $instructorId,
            'room_id' => $roomId,
            'rrule' => 'FREQ=WEEKLY;BYDAY=' . $days[$weekday - 1],
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'weekday' => $weekday,
            'start_time' => $startTime,
            'timezone' => $this->settings->timezone()->getName(),
            'duration_minutes' => $duration,
            'capacity_override' => $capacity,
            'price_cents_override' => (int) $course['price_cents'],
            'credits_required_override' => (int) $course['credits_required'],
            'booking_opens_offset_minutes' => (int) $course['booking_opens_offset_minutes'],
            'booking_closes_offset_minutes' => (int) $course['booking_closes_offset_minutes'],
            'published' => $this->published($input),
            'created_at' => $now,
            'created_by' => $actorId,
            'updated_at' => $now,
            'updated_by' => $actorId,
        ];

        return $this->store('session_rule', '#__memi_session_rules', $row, $actorId);
    }

    private function createPackage(Input $input, int $actorId): int
    {
        $title = $this->requiredText($input, 'title', 255);
        $now = $this->now();
        $validity = $this->optionalInteger($input, 'validity_days', 1, 3650);
        $row = [
            'title' => $title,
            'alias' => $this->uniqueAlias('#__memi_packages', $title),
            'description' => $this->text($input, 'description', 20000),
            'price_cents' => $this->moneyCents($input, 'price'),
            'credits' => $this->integer($input, 'credits', 1, 1000, 1),
            'validity_days' => $validity,
            'fixed_expiry_at' => null,
            'maximum_bookings' => null,
            'tax_rate_basis_points' => $this->integer($input, 'tax_rate_basis_points', 0, 10000, 0),
            'bonus_points' => $this->integer($input, 'bonus_points', 0, 1000000, 0),
            'points_bonus' => $this->integer($input, 'bonus_points', 0, 1000000, 0),
            'published' => $this->published($input),
            'access' => 1,
            'ordering' => 0,
            'created_at' => $now,
            'created_by' => $actorId,
            'updated_at' => $now,
            'updated_by' => $actorId,
        ];

        return $this->store('package', '#__memi_packages', $row, $actorId);
    }

    private function lockResetSources(): void
    {
        // Keep this order aligned with the order used by operational flows:
        // the scheduler locks its rule; reward redemption locks reward then
        // package; reservations lock their session.
        foreach ([
            '#__memi_session_rules',
            '#__memi_sessions',
            '#__memi_rewards',
            '#__memi_packages',
            '#__memi_promotions',
        ] as $table) {
            $this->lockUnarchivedRows($table);
        }
    }

    private function lockUnarchivedRows(string $table): void
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName($table))
            ->where($this->db->quoteName('archived_at') . ' IS NULL');
        $this->db->setQuery(DatabaseTools::forUpdate($query));
        $this->db->loadColumn();
    }

    private function assertNoCatalogActivity(): void
    {
        foreach (self::RESET_ACTIVITY_TABLES as $table) {
            $query = $this->db->getQuery(true)
                ->select('COUNT(*)')
                ->from($this->db->quoteName($table));
            $this->db->setQuery($query);

            if ((int) $this->db->loadResult() > 0) {
                throw new DomainException('COM_MEMIPILATES_ERROR_SETUP_RESET_HAS_ACTIVITY', [], 409);
            }
        }
    }

    private function archiveSessionsForReset(int $actorId, string $now): int
    {
        $archivedAt = $now;
        $updatedAt = $now;
        $cancelledAt = $now;
        $updatedBy = $actorId;
        $cancelledBy = $actorId;
        $status = 'cancelled';
        $reason = 'Catalogue reset before launch';
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__memi_sessions'))
            ->set($this->db->quoteName('archived_at') . ' = :archived_at')
            ->set($this->db->quoteName('updated_at') . ' = :updated_at')
            ->set($this->db->quoteName('updated_by') . ' = :updated_by')
            ->set($this->db->quoteName('status') . ' = :status')
            ->set($this->db->quoteName('reserved_count') . ' = 0')
            ->set($this->db->quoteName('waitlist_count') . ' = 0')
            ->set($this->db->quoteName('cancelled_at') . ' = :cancelled_at')
            ->set($this->db->quoteName('cancelled_by') . ' = :cancelled_by')
            ->set($this->db->quoteName('cancellation_reason') . ' = :reason')
            ->where($this->db->quoteName('archived_at') . ' IS NULL')
            ->bind(':archived_at', $archivedAt)
            ->bind(':updated_at', $updatedAt)
            ->bind(':updated_by', $updatedBy, ParameterType::INTEGER)
            ->bind(':status', $status)
            ->bind(':cancelled_at', $cancelledAt)
            ->bind(':cancelled_by', $cancelledBy, ParameterType::INTEGER)
            ->bind(':reason', $reason);
        $this->db->setQuery($query)->execute();

        return $this->db->getAffectedRows();
    }

    private function archiveCatalogRows(string $table, int $actorId, string $now, bool $unpublish): int
    {
        $archivedAt = $now;
        $updatedAt = $now;
        $updatedBy = $actorId;
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName($table))
            ->set($this->db->quoteName('archived_at') . ' = :archived_at')
            ->set($this->db->quoteName('updated_at') . ' = :updated_at')
            ->set($this->db->quoteName('updated_by') . ' = :updated_by')
            ->where($this->db->quoteName('archived_at') . ' IS NULL')
            ->bind(':archived_at', $archivedAt)
            ->bind(':updated_at', $updatedAt)
            ->bind(':updated_by', $updatedBy, ParameterType::INTEGER);

        if ($unpublish) {
            $published = 0;
            $query->set($this->db->quoteName('published') . ' = :published')
                ->bind(':published', $published, ParameterType::INTEGER);
        }

        $this->db->setQuery($query)->execute();

        return $this->db->getAffectedRows();
    }

    /** @param array<string, mixed> $row */
    private function store(string $entity, string $table, array $row, int $actorId): int
    {
        return $this->tools->transaction(function () use ($entity, $table, $row, $actorId): int {
            try {
                $id = $this->insert($table, $row);
            } catch (\Throwable $error) {
                if (str_contains(strtolower($error->getMessage()), 'duplicate')) {
                    throw new DomainException('COM_MEMIPILATES_ERROR_SETUP_DUPLICATE', [], 409, $error);
                }

                throw $error;
            }
            $this->audit->log($actorId, 'catalog.create', $entity, $id, null, $this->auditValues($row));

            return $id;
        });
    }

    /** @param array<string, mixed> $values */
    private function insert(string $table, array $values): int
    {
        $query = $this->db->getQuery(true)->insert($this->db->quoteName($table));
        $columns = [];
        $placeholders = [];
        /** @var array<string, int|string> $boundValues */
        $boundValues = [];
        /** @var array<string, int> $boundTypes */
        $boundTypes = [];
        $index = 0;

        foreach ($values as $column => $value) {
            $columns[] = $this->db->quoteName($column);
            if ($value === null) {
                $placeholders[] = 'NULL';
                continue;
            }
            $placeholder = ':value_' . $index++;
            $placeholders[] = $placeholder;
            // DatabaseQuery::bind() stores a reference. Keep each value in a
            // stable array entry rather than binding the reused foreach value.
            $boundValues[$placeholder] = $value;
            $boundTypes[$placeholder] = is_int($value) ? ParameterType::INTEGER : ParameterType::STRING;
        }

        if ($boundValues !== []) {
            $query->bind(array_keys($boundValues), $boundValues, array_values($boundTypes));
        }

        $query->columns($columns)->values(implode(', ', $placeholders));
        $this->db->setQuery($query)->execute();

        return (int) $this->db->insertid();
    }

    /** @return array<string, mixed> */
    private function requireRecord(string $table, int $id): array
    {
        $identifier = $id;
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName($table))
            ->where($this->db->quoteName('id') . ' = :id')
            ->where($this->db->quoteName('archived_at') . ' IS NULL')
            ->bind(':id', $identifier, ParameterType::INTEGER);
        $this->db->setQuery($query);
        $record = $this->db->loadAssoc();
        if (!$record) {
            throw new DomainException('COM_MEMIPILATES_ERROR_NOT_FOUND', [], 404);
        }

        return $record;
    }

    private function uniqueAlias(string $table, string $title, ?int $locationId = null): string
    {
        $base = OutputFilter::stringURLSafe($title);
        $base = $base !== '' ? mb_substr($base, 0, 370) : 'item';

        for ($suffix = 1; $suffix <= 100; ++$suffix) {
            $candidate = $suffix === 1 ? $base : $base . '-' . $suffix;
            $query = $this->db->getQuery(true)
                ->select('COUNT(*)')
                ->from($this->db->quoteName($table))
                ->where($this->db->quoteName('alias') . ' = :alias')
                ->bind(':alias', $candidate);
            if ($locationId !== null) {
                $location = $locationId;
                $query->where($this->db->quoteName('location_id') . ' = :location_id')
                    ->bind(':location_id', $location, ParameterType::INTEGER);
            }
            $this->db->setQuery($query);
            if ((int) $this->db->loadResult() === 0) {
                return $candidate;
            }
        }

        throw new DomainException('COM_MEMIPILATES_ERROR_SETUP_DUPLICATE', [], 409);
    }

    private function requiredText(Input $input, string $name, int $length): string
    {
        $value = $this->text($input, $name, $length);
        if ($value === '') {
            throw new DomainException('COM_MEMIPILATES_ERROR_SETUP_REQUIRED');
        }

        return $value;
    }

    private function text(Input $input, string $name, int $length): string
    {
        return mb_substr(trim($input->getString($name, '')), 0, $length);
    }

    private function requiredId(Input $input, string $name): int
    {
        $id = $input->getInt($name);
        if ($id <= 0) {
            throw new DomainException('COM_MEMIPILATES_ERROR_SETUP_REQUIRED');
        }

        return $id;
    }

    private function optionalId(Input $input, string $name): ?int
    {
        $id = $input->getInt($name);

        return $id > 0 ? $id : null;
    }

    private function integer(Input $input, string $name, int $min, int $max, int $default): int
    {
        $raw = trim($input->getString($name, ''));
        if ($raw === '') {
            return $default;
        }
        if (!preg_match('/^-?\d+$/D', $raw)) {
            throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
        }
        $value = (int) $raw;
        if ($value < $min || $value > $max) {
            throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
        }

        return $value;
    }

    private function optionalInteger(Input $input, string $name, int $min, int $max): ?int
    {
        $raw = trim($input->getString($name, ''));
        if ($raw === '') {
            return null;
        }

        return $this->integer($input, $name, $min, $max, $min);
    }

    private function moneyCents(Input $input, string $name, int $default = 0): int
    {
        $raw = str_replace(',', '.', trim($input->getString($name, '')));
        if ($raw === '') {
            return $default;
        }
        if (!preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D', $raw)) {
            throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
        }
        [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '0');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function published(Input $input): int
    {
        return $input->getInt('published', 1) === 1 ? 1 : 0;
    }

    private function localDateTime(string $value): \DateTimeImmutable
    {
        $timezone = $this->settings->timezone();
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $value, $timezone);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new DomainException('COM_MEMIPILATES_ERROR_SETUP_INVALID_DATE');
        }

        return $date;
    }

    private function date(string $value): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new DomainException('COM_MEMIPILATES_ERROR_SETUP_INVALID_DATE');
        }

        return $date->format('Y-m-d');
    }

    private function optionalDate(string $value): ?string
    {
        return $value === '' ? null : $this->date($value);
    }

    private function time(string $value): string
    {
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $value)) {
            throw new DomainException('COM_MEMIPILATES_ERROR_SETUP_INVALID_DATE');
        }

        return $value . ':00';
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value !== null && (int) $value > 0 ? (int) $value : null;
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed> */
    private function auditValues(array $row): array
    {
        // The audit row identifies what was created without duplicating
        // contact or location details that authorised staff can read from the
        // source record when necessary.
        unset(
            $row['description'],
            $row['bio'],
            $row['internal_note'],
            $row['email'],
            $row['phone'],
            $row['address_line1'],
            $row['address_line2'],
            $row['city'],
            $row['province'],
            $row['postal_code']
        );

        return $row;
    }
}
