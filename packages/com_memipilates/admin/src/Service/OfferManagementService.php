<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;
use Joomla\Input\Input;

/** Creates, updates and retires promotion codes and loyalty rewards. */
final class OfferManagementService
{
    public function __construct(
        private readonly DatabaseDriver $db,
        private readonly DatabaseTools $tools,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit
    ) {
    }

    public function savePromotion(Input $input, int $actorId): int
    {
        $id = $input->getInt('id');

        return $this->tools->transaction(function () use ($input, $actorId, $id): int {
            $before = $id > 0 ? $this->lockActive('#__memi_promotions', $id) : null;
            $values = $this->promotionValues($input);
            $this->assertPromotionCodeAvailable((string) $values['code'], $id);
            $packageIds = $this->packageIds($input);
            foreach ($packageIds as $packageId) {
                $this->activeRecord('#__memi_packages', $packageId);
            }
            $now = gmdate('Y-m-d H:i:s');
            if ($before === null) {
                $values += ['created_at' => $now, 'created_by' => $actorId, 'updated_at' => $now, 'updated_by' => $actorId];
                $id = $this->insert('#__memi_promotions', $values);
                $action = 'promotion.create';
            } else {
                $values += ['updated_at' => $now, 'updated_by' => $actorId];
                $this->update('#__memi_promotions', $id, $values);
                $action = 'promotion.update';
            }
            $this->syncPromotionPackages($id, $packageIds);
            $this->audit->log($actorId, $action, 'promotion', $id, $this->safeAudit($before), $this->safeAudit($values));

            return $id;
        });
    }

    public function archivePromotion(int $id, int $actorId): void
    {
        $this->archive('#__memi_promotions', 'promotion', $id, $actorId);
    }

    public function saveReward(Input $input, int $actorId): int
    {
        $id = $input->getInt('id');

        return $this->tools->transaction(function () use ($input, $actorId, $id): int {
            $before = $id > 0 ? $this->lockActive('#__memi_rewards', $id) : null;
            $values = $this->rewardValues($input);
            if ($values['package_id'] !== null) {
                $this->activeRecord('#__memi_packages', (int) $values['package_id']);
            }
            $now = gmdate('Y-m-d H:i:s');
            if ($before === null) {
                $values += ['ordering' => 0, 'created_at' => $now, 'created_by' => $actorId, 'updated_at' => $now, 'updated_by' => $actorId];
                $id = $this->insert('#__memi_rewards', $values);
                $action = 'reward.create';
            } else {
                $values += ['updated_at' => $now, 'updated_by' => $actorId];
                $this->update('#__memi_rewards', $id, $values);
                $action = 'reward.update';
            }
            $this->audit->log($actorId, $action, 'reward', $id, $this->safeAudit($before), $this->safeAudit($values));

            return $id;
        });
    }

    public function archiveReward(int $id, int $actorId): void
    {
        $this->archive('#__memi_rewards', 'reward', $id, $actorId);
    }

    /** @return array<string,mixed> */
    private function promotionValues(Input $input): array
    {
        $code = mb_strtoupper(trim($input->getString('code', '')));
        if (!preg_match('/^[A-Z0-9_-]{3,64}$/D', $code)) {
            throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
        }
        $type = $input->getCmd('discount_type', 'fixed');
        if (!in_array($type, ['fixed', 'percentage'], true)) {
            throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
        }
        $fixed = $type === 'fixed' ? $this->moneyCents($input, 'discount_value') : 0;
        $basisPoints = $type === 'percentage' ? $this->basisPoints($input, 'discount_value') : 0;
        $bonusCredits = $this->integer($input, 'bonus_credits', 0, 1000, 0);
        $bonusPoints = $this->integer($input, 'bonus_points', 0, 1000000, 0);
        if ($fixed <= 0 && $basisPoints <= 0 && $bonusCredits <= 0 && $bonusPoints <= 0) {
            throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
        }
        [$startsAt, $endsAt] = $this->availability($input);
        $minimum = $this->moneyCents($input, 'minimum_order_value');

        return [
            'code' => $code,
            'title' => $this->requiredText($input, 'title', 255),
            'description' => $this->text($input, 'description', 20000),
            'discount_type' => $type,
            'discount_cents' => $fixed,
            'discount_basis_points' => $basisPoints,
            'bonus_credits' => $bonusCredits,
            'bonus_points' => $bonusPoints,
            'minimum_order_cents' => $minimum,
            'minimum_amount_cents' => $minimum,
            'maximum_redemptions' => $this->optionalInteger($input, 'maximum_redemptions', 1, 1000000),
            'per_customer_limit' => $this->optionalInteger($input, 'per_customer_limit', 1, 1000000),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'published' => $this->published($input),
        ];
    }

    /** @return array<string,mixed> */
    private function rewardValues(Input $input): array
    {
        $type = $input->getCmd('reward_type', 'discount');
        if (!in_array($type, ['discount', 'credits', 'package', 'custom'], true)) {
            throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
        }
        $discount = $type === 'discount' ? $this->moneyCents($input, 'discount_value') : 0;
        $credits = $type === 'credits' ? $this->integer($input, 'credits', 1, 1000, 1) : 0;
        // Credit rewards need a package as an allocation container too: the
        // credit ledger consumes from customer-package rows, which retain the
        // appropriate expiry policy for the granted credits.
        $packageId = in_array($type, ['credits', 'package'], true) ? $this->requiredId($input, 'package_id') : null;
        if (($type === 'discount' && $discount <= 0) || ($type === 'credits' && $credits <= 0)) {
            throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
        }
        [$availableFrom, $availableUntil] = $this->availability($input);

        return [
            'title' => $this->requiredText($input, 'title', 255),
            'description' => $this->text($input, 'description', 20000),
            'points_cost' => $this->integer($input, 'points_cost', 1, 1000000, 1),
            'reward_type' => $type,
            'discount_cents' => $discount,
            'credits' => $credits,
            'package_id' => $packageId,
            'published' => $this->published($input),
            'available_from' => $availableFrom,
            'available_until' => $availableUntil,
            'maximum_redemptions' => $this->optionalInteger($input, 'maximum_redemptions', 1, 1000000),
        ];
    }

    /** @return array{0:?string,1:?string} */
    private function availability(Input $input): array
    {
        $start = $this->optionalDateTime($input, 'starts_at');
        $end = $this->optionalDateTime($input, 'ends_at');
        if ($start !== null && $end !== null && $end < $start) {
            throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
        }

        return [$start, $end];
    }

    /** @return list<int> */
    private function packageIds(Input $input): array
    {
        $raw = $input->get('package_ids', [], 'array');
        $ids = [];
        foreach (is_array($raw) ? $raw : [] as $candidate) {
            $id = (int) $candidate;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    private function syncPromotionPackages(int $promotionId, array $packageIds): void
    {
        $promotion = $promotionId;
        $delete = $this->db->getQuery(true)
            ->delete($this->db->quoteName('#__memi_promotion_packages'))
            ->where($this->db->quoteName('promotion_id') . ' = :promotion_id')
            ->bind(':promotion_id', $promotion, ParameterType::INTEGER);
        $this->db->setQuery($delete)->execute();
        foreach ($packageIds as $packageId) {
            $package = $packageId;
            $insert = $this->db->getQuery(true)
                ->insert($this->db->quoteName('#__memi_promotion_packages'))
                ->columns(['promotion_id', 'package_id'])
                ->values(':promotion_id, :package_id')
                ->bind(':promotion_id', $promotion, ParameterType::INTEGER)
                ->bind(':package_id', $package, ParameterType::INTEGER);
            $this->db->setQuery($insert)->execute();
        }
    }

    private function archive(string $table, string $type, int $id, int $actorId): void
    {
        if ($id <= 0) {
            throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
        }
        $this->tools->transaction(function () use ($table, $type, $id, $actorId): void {
            $before = $this->lockActive($table, $id);
            $now = gmdate('Y-m-d H:i:s');
            $this->update($table, $id, ['published' => 0, 'archived_at' => $now, 'updated_at' => $now, 'updated_by' => $actorId]);
            $this->audit->log($actorId, $type . '.archive', $type, $id, $this->safeAudit($before), ['archived_at' => $now]);
        });
    }

    private function assertPromotionCodeAvailable(string $code, int $exceptId): void
    {
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__memi_promotions'))
            ->where($this->db->quoteName('code') . ' = :code')
            ->bind(':code', $code);
        if ($exceptId > 0) {
            $identifier = $exceptId;
            $query->where($this->db->quoteName('id') . ' != :id')->bind(':id', $identifier, ParameterType::INTEGER);
        }
        $this->db->setQuery($query);
        if ((int) $this->db->loadResult() > 0) {
            throw new DomainException('COM_MEMIPILATES_ERROR_SETUP_DUPLICATE', [], 409);
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
            ->select('*')->from($this->db->quoteName($table))
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
    private function insert(string $table, array $values): int
    {
        $query = $this->db->getQuery(true)->insert($this->db->quoteName($table));
        $columns = [];
        $holders = [];
        $bound = [];
        $types = [];
        $index = 0;
        foreach ($values as $column => $value) {
            $columns[] = $this->db->quoteName($column);
            if ($value === null) {
                $holders[] = 'NULL';
                continue;
            }
            $placeholder = ':value_' . $index++;
            $holders[] = $placeholder;
            $bound[$placeholder] = $value;
            $types[$placeholder] = is_int($value) ? ParameterType::INTEGER : ParameterType::STRING;
        }
        $query->columns($columns)->values(implode(', ', $holders));
        if ($bound !== []) {
            $query->bind(array_keys($bound), $bound, array_values($types));
        }
        $this->db->setQuery($query)->execute();

        return (int) $this->db->insertid();
    }

    /** @param array<string,mixed> $values */
    private function update(string $table, int $id, array $values): void
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
        $query->where($this->db->quoteName('id') . ' = :id')->bind(':id', $identifier, ParameterType::INTEGER);
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

    private function integer(Input $input, string $name, int $min, int $max, int $default): int
    {
        $raw = trim($input->getString($name, ''));
        if ($raw === '') {
            return $default;
        }
        if (!preg_match('/^\d+$/D', $raw)) {
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

    private function moneyCents(Input $input, string $name): int
    {
        $raw = str_replace(',', '.', trim($input->getString($name, '')));
        if ($raw === '') {
            return 0;
        }
        if (!preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D', $raw)) {
            throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
        }
        [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '0');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function basisPoints(Input $input, string $name): int
    {
        $raw = str_replace(',', '.', trim($input->getString($name, '')));
        if (!preg_match('/^\d{1,3}(?:\.\d{1,2})?$/D', $raw)) {
            throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
        }
        $value = (float) $raw;
        if ($value <= 0 || $value > 100) {
            throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
        }

        return (int) round($value * 100);
    }

    private function optionalDateTime(Input $input, string $name): ?string
    {
        $raw = trim($input->getString($name, ''));
        if ($raw === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $raw, $this->settings->timezone());
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
        }

        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function published(Input $input): int
    {
        return $input->getInt('published', 1) === 1 ? 1 : 0;
    }

    /** @param array<string,mixed>|null $values
     *  @return array<string,mixed>|null */
    private function safeAudit(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }
        unset($values['description']);

        return $values;
    }
}
