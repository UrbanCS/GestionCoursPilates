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
