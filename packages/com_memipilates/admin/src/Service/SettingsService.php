<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;

/** Centralized configuration with safe defaults for a new installation. */
final class SettingsService
{
    /** @var array<string, mixed>|null */
    private ?array $settings = null;

    public function __construct(private readonly DatabaseDriver $db)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $settings = $this->all();

        if (array_key_exists($key, $settings)) {
            return $settings[$key];
        }

        $params = ComponentHelper::getParams('com_memipilates');

        return $params->get($key, $default);
    }

    public function getInt(string $key, int $default): int
    {
        return (int) $this->get($key, $default);
    }

    public function getBool(string $key, bool $default): bool
    {
        return filter_var($this->get($key, $default), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * Returns the studio-wide tax rate in thousandths of one percent.
     *
     * Quebec's combined GST/QST rate is 14.975%, represented as the integer
     * 14975. Keeping the value as an integer avoids floating-point money
     * calculations while retaining the precision required by the QST rate.
     */
    public function taxRateThousandthsPercent(): int
    {
        $configured = str_replace(',', '.', trim((string) $this->get('tax_rate_percent', '14.975')));

        if (!preg_match('/^(?:100(?:\.0{1,3})?|[0-9]{1,2}(?:\.[0-9]{1,3})?)$/D', $configured)) {
            return 14975;
        }

        [$whole, $fraction] = array_pad(explode('.', $configured, 2), 2, '');

        return ((int) $whole * 1000) + (int) str_pad($fraction, 3, '0');
    }

    /** Returns the canonical percentage displayed in settings forms. */
    public function taxRatePercent(): string
    {
        $rate = $this->taxRateThousandthsPercent();
        $fraction = str_pad((string) ($rate % 1000), 3, '0', STR_PAD_LEFT);

        return rtrim(rtrim(intdiv($rate, 1000) . '.' . $fraction, '0'), '.');
    }

    /**
     * Calculates the tax on an integer-cent taxable amount, rounded half up
     * to the nearest cent.
     */
    public function calculateTaxCents(int $taxableCents): int
    {
        $taxableCents = max(0, $taxableCents);

        return intdiv(
            ($taxableCents * $this->taxRateThousandthsPercent()) + 50000,
            100000
        );
    }

    public function timezone(): \DateTimeZone
    {
        $configured = (string) $this->get('timezone', Factory::getApplication()->get('offset', 'America/Toronto'));

        try {
            return new \DateTimeZone($configured ?: 'America/Toronto');
        } catch (\Exception) {
            return new \DateTimeZone('America/Toronto');
        }
    }

    /** @return array<string, mixed> */
    private function all(): array
    {
        if ($this->settings !== null) {
            return $this->settings;
        }

        $query = $this->db->getQuery(true)
            ->select([$this->db->quoteName('setting_key'), $this->db->quoteName('setting_value')])
            ->from($this->db->quoteName('#__memi_settings'));
        $this->db->setQuery($query);
        $rows = $this->db->loadAssocList() ?: [];
        $settings = [];

        foreach ($rows as $row) {
            $value = $row['setting_value'];
            $decoded = json_decode((string) $value, true);
            $settings[(string) $row['setting_key']] = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
        }

        return $this->settings = $settings;
    }
}
