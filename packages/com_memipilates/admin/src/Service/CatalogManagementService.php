<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;
use Joomla\Input\Input;

/**
 * Updates and retires catalogue records after the initial studio setup.
 *
 * Creation stays in CatalogService because the onboarding page uses it. This
 * companion keeps the operational editing rules separate and, importantly,
 * never deletes records that may be referenced by a customer transaction.
 */
final class CatalogManagementService
{
    /** @var array<string,string> */
    private const TABLES = [
        'location' => '#__memi_locations',
        'room' => '#__memi_rooms',
        'instructor' => '#__memi_instructors',
        'course_type' => '#__memi_course_types',
        'course' => '#__memi_courses',
        'session_rule' => '#__memi_session_rules',
        'package' => '#__memi_packages',
    ];

    public function __construct(
        private readonly DatabaseDriver $db,
        private readonly DatabaseTools $tools,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit
    ) {
    }

    public function update(string $entity, int $id, Input $input, int $actorId): void
    {
        $table = self::TABLES[$entity] ?? null;
        if ($table === null || $id <= 0) {
            throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
        }

        $this->tools->transaction(function () use ($entity, $table, $id, $input, $actorId): void {
            $before = $this->lockActive($table, $id);
            $values = match ($entity) {
                'location' => $this->locationValues($input),
                'room' => $this->roomValues($input),
                'instructor' => $this->instructorValues($input, $id),
                'course_type' => $this->courseTypeValues($input),
                'course' => $this->courseValues($input),
                'session_rule' => $this->sessionRuleValues($input),
                'package' => $this->packageValues($input),
                default => throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST'),
            };
            $values['updated_at'] = $this->now();
            $values['updated_by'] = $actorId;
            $this->updateRow($table, $id, $values);
            $this->audit->log($actorId, 'catalog.update', $entity, $id, $this->safeAudit($before), $this->safeAudit($values));
        });
    }

    /**
     * Retires an unused catalogue item. The method refuses to hide a parent
     * that still has live children; staff must first retire the dependent item
     * in order, which keeps a public schedule internally consistent.
     */
    public function archive(string $entity, int $id, int $actorId): void
    {
        $table = self::TABLES[$entity] ?? null;
        if ($table === null || $id <= 0) {
            throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
        }

        $this->tools->transaction(function () use ($entity, $table, $id, $actorId): void {
            $before = $this->lockActive($table, $id);
            $this->assertArchivable($entity, $id);
            $now = $this->now();
            $values = ['archived_at' => $now, 'updated_at' => $now, 'updated_by' => $actorId];
            if ($entity !== 'course') {
                $values['published'] = 0;
            } else {
                $values['published'] = 0;
                $values['status'] = 'archived';
            }
            $this->updateRow($table, $id, $values);
            $this->audit->log($actorId, 'catalog.archive', $entity, $id, $this->safeAudit($before), ['archived_at' => $now]);
        });
    }

    /** @return array<string,mixed> */
    private function locationValues(Input $input): array
    {
        return [
            'title' => $this->requiredText($input, 'title', 255),
            'address_line1' => $this->text($input, 'address_line1', 255),
            'address_line2' => $this->text($input, 'address_line2', 255),
            'city' => $this->text($input, 'city', 128),
            'province' => $this->text($input, 'province', 128),
            'postal_code' => $this->text($input, 'postal_code', 32),
            'phone' => $this->text($input, 'phone', 64),
            'published' => $this->published($input),
        ];
    }

    /** @return array<string,mixed> */
    private function roomValues(Input $input): array
    {
        $locationId = $this->requiredId($input, 'location_id');
        $this->activeRecord('#__memi_locations', $locationId);

        return [
            'location_id' => $locationId,
            'title' => $this->requiredText($input, 'title', 255),
            'capacity' => $this->integer($input, 'capacity', 1, 500, 1),
            'description' => $this->text($input, 'description', 10000),
            'published' => $this->published($input),
        ];
    }

    /** @return array<string,mixed> */
    private function instructorValues(Input $input, int $instructorId): array
    {
        $email = $this->text($input, 'email', 320);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException('COM_MEMIPILATES_ERROR_SETUP_INVALID_EMAIL');
        }

        return [
            'user_id' => $this->validatedInstructorUserId($input, $instructorId),
            'display_name' => $this->requiredText($input, 'display_name', 255),
            'bio' => $this->text($input, 'bio', 20000),
            'phone' => $this->text($input, 'phone', 64),
            'email' => $email,
            'published' => $this->published($input),
        ];
    }

    private function validatedInstructorUserId(Input $input, int $instructorId): ?int
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
        $currentInstructor = $instructorId;
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__memi_instructors'))
            ->where($this->db->quoteName('user_id') . ' = :linked_user_id')
            ->where($this->db->quoteName('id') . ' <> :instructor_id')
            ->bind(':linked_user_id', $linkedUser, ParameterType::INTEGER)
            ->bind(':instructor_id', $currentInstructor, ParameterType::INTEGER);
        $this->db->setQuery($query);
        if ((int) $this->db->loadResult() > 0) {
            throw new DomainException('COM_MEMIPILATES_ERROR_SETUP_DUPLICATE', [], 409);
        }

        return $userId;
    }

    /** @return array<string,mixed> */
    private function courseTypeValues(Input $input): array
    {
        return [
            'title' => $this->requiredText($input, 'title', 255),
            'description' => $this->text($input, 'description', 20000),
            'level' => $this->text($input, 'level', 64),
            'intensity' => $this->optionalInteger($input, 'intensity', 1, 10),
            'default_duration_minutes' => $this->integer($input, 'duration_minutes', 5, 720, 60),
            'default_capacity' => $this->integer($input, 'capacity', 1, 500, 1),
            'default_price_cents' => $this->moneyCents($input, 'price'),
            'default_credits_required' => $this->integer($input, 'credits_required', 0, 1000, 1),
            'tax_rate_basis_points' => $this->integer($input, 'tax_rate_basis_points', 0, 10000, 0),
            'published' => $this->published($input),
        ];
    }

    /** @return array<string,mixed> */
    private function courseValues(Input $input): array
    {
        $courseTypeId = $this->requiredId($input, 'course_type_id');
        $type = $this->activeRecord('#__memi_course_types', $courseTypeId);
        $instructorId = $this->optionalId($input, 'instructor_id');
        $roomId = $this->optionalId($input, 'room_id');
        if ($instructorId !== null) {
            $this->activeRecord('#__memi_instructors', $instructorId);
        }
        $room = $roomId === null ? null : $this->activeRecord('#__memi_rooms', $roomId);
        $duration = $this->integer($input, 'duration_minutes', 5, 720, (int) $type['default_duration_minutes']);
        $capacity = $this->integer($input, 'capacity', 1, 500, (int) $type['default_capacity']);
        if ($room !== null && $capacity > (int) $room['capacity']) {
            throw new DomainException('COM_MEMIPILATES_ERROR_SETUP_ROOM_CAPACITY');
        }
        $opens = $this->integer($input, 'booking_opens_days', 0, 365, 7) * 1440;
        $closes = $this->integer($input, 'booking_closes_minutes', 0, 10080, 0);
        if ($opens < $closes) {
            throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
        }

        return [
            'course_type_id' => $courseTypeId,
            'instructor_id' => $instructorId,
            'room_id' => $roomId,
            'title' => $this->requiredText($input, 'title', 255),
            'description' => $this->text($input, 'description', 20000),
            'default_duration_minutes' => $duration,
            'duration_minutes' => $duration,
            'capacity' => $capacity,
            'price_cents' => $this->moneyCents($input, 'price', (int) $type['default_price_cents']),
            'credits_required' => $this->integer($input, 'credits_required', 0, 1000, (int) $type['default_credits_required']),
            'tax_rate_basis_points' => $this->integer($input, 'tax_rate_basis_points', 0, 10000, (int) $type['tax_rate_basis_points']),
            'booking_opens_offset_minutes' => $opens,
            'booking_closes_offset_minutes' => $closes,
            'published' => $this->published($input),
            'status' => $this->published($input) === 1 ? 'active' : 'inactive',
        ];
    }

    /** @return array<string,mixed> */
    private function sessionRuleValues(Input $input): array
    {
        $courseId = $this->requiredId($input, 'course_id');
        $course = $this->activeRecord('#__memi_courses', $courseId);
        $instructorId = $this->optionalId($input, 'instructor_id') ?? $this->nullableInt($course['instructor_id'] ?? null);
        $roomId = $this->optionalId($input, 'room_id') ?? $this->nullableInt($course['room_id'] ?? null);
        if ($instructorId !== null) {
            $this->activeRecord('#__memi_instructors', $instructorId);
        }
        $room = $roomId === null ? null : $this->activeRecord('#__memi_rooms', $roomId);
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
        $days = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];

        return [
            'course_id' => $courseId,
            'instructor_id' => $instructorId,
            'room_id' => $roomId,
            'rrule' => 'FREQ=WEEKLY;BYDAY=' . $days[$weekday - 1],
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'weekday' => $weekday,
            'start_time' => $this->time($this->requiredText($input, 'start_time', 8)),
            'timezone' => $this->settings->timezone()->getName(),
            'duration_minutes' => $duration,
            'capacity_override' => $capacity,
            'price_cents_override' => (int) $course['price_cents'],
            'credits_required_override' => (int) $course['credits_required'],
            'booking_opens_offset_minutes' => (int) $course['booking_opens_offset_minutes'],
            'booking_closes_offset_minutes' => (int) $course['booking_closes_offset_minutes'],
            'published' => $this->published($input),
        ];
    }

    /** @return array<string,mixed> */
    private function packageValues(Input $input): array
    {
        return [
            'title' => $this->requiredText($input, 'title', 255),
            'description' => $this->text($input, 'description', 20000),
            'price_cents' => $this->moneyCents($input, 'price'),
            'credits' => $this->integer($input, 'credits', 1, 1000, 1),
            'validity_days' => $this->optionalInteger($input, 'validity_days', 1, 3650),
            'tax_rate_basis_points' => $this->integer($input, 'tax_rate_basis_points', 0, 10000, 0),
            'bonus_points' => $this->integer($input, 'bonus_points', 0, 1000000, 0),
            'points_bonus' => $this->integer($input, 'bonus_points', 0, 1000000, 0),
            'published' => $this->published($input),
        ];
    }

    private function assertArchivable(string $entity, int $id): void
    {
        $dependencies = match ($entity) {
            'location' => [['#__memi_rooms', 'location_id']],
            'room' => [['#__memi_courses', 'room_id'], ['#__memi_session_rules', 'room_id'], ['#__memi_sessions', 'room_id']],
            'instructor' => [['#__memi_courses', 'instructor_id'], ['#__memi_session_rules', 'instructor_id'], ['#__memi_sessions', 'instructor_id']],
            'course_type' => [['#__memi_courses', 'course_type_id']],
            'course' => [['#__memi_session_rules', 'course_id'], ['#__memi_sessions', 'course_id']],
            'session_rule' => [['#__memi_sessions', 'rule_id']],
            'package' => [['#__memi_customer_packages', 'package_id'], ['#__memi_order_items', 'package_id'], ['#__memi_rewards', 'package_id']],
            default => [],
        };
        foreach ($dependencies as [$table, $column]) {
            $identifier = $id;
            $query = $this->db->getQuery(true)
                ->select('COUNT(*)')
                ->from($this->db->quoteName($table))
                ->where($this->db->quoteName($column) . ' = :id')
                ->bind(':id', $identifier, ParameterType::INTEGER);
            if ($table !== '#__memi_order_items' && $table !== '#__memi_customer_packages') {
                $query->where($this->db->quoteName('archived_at') . ' IS NULL');
            }
            $this->db->setQuery($query);
            if ((int) $this->db->loadResult() > 0) {
                throw new DomainException('COM_MEMIPILATES_ERROR_CATALOG_IN_USE', [], 409);
            }
        }
    }

    /** @return array<string,mixed> */
    private function lockActive(string $table, int $id): array
    {
        $record = $this->tools->lockById($table, $id);
        if ($record === null || !empty($record['archived_at'])) {
            throw new DomainException('COM_MEMIPILATES_ERROR_NOT_FOUND', [], 404);
        }

        return $record;
    }

    /** @return array<string,mixed> */
    private function activeRecord(string $table, int $id): array
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

    /** @param array<string,mixed> $values */
    private function updateRow(string $table, int $id, array $values): void
    {
        $query = $this->db->getQuery(true)->update($this->db->quoteName($table));
        $bound = [];
        $types = [];
        $index = 0;
        foreach ($values as $column => $value) {
            if ($value === null) {
                $query->set($this->db->quoteName($column) . ' = NULL');
                continue;
            }
            $placeholder = ':value_' . $index++;
            $query->set($this->db->quoteName($column) . ' = ' . $placeholder);
            $bound[$placeholder] = $value;
            $types[$placeholder] = is_int($value) ? ParameterType::INTEGER : ParameterType::STRING;
        }
        $identifier = $id;
        $query->where($this->db->quoteName('id') . ' = :id')
            ->bind(':id', $identifier, ParameterType::INTEGER);
        if ($bound !== []) {
            $query->bind(array_keys($bound), $bound, array_values($types));
        }
        $this->db->setQuery($query)->execute();
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

        return $raw === '' ? null : $this->integer($input, $name, $min, $max, $min);
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

    /** @param array<string,mixed> $values
     *  @return array<string,mixed> */
    private function safeAudit(array $values): array
    {
        unset($values['description'], $values['bio'], $values['address_line1'], $values['address_line2'], $values['city'], $values['province'], $values['postal_code'], $values['phone'], $values['email']);

        return $values;
    }
}
