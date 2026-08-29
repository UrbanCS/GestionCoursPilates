<?php

declare(strict_types=1);

namespace MemiPwa;

use Joomla\Database\DatabaseInterface;

final class Schema
{
    private const VERSION = '1';

    private static bool $ready = false;

    public static function ensure(Context $context): void
    {
        if (self::$ready) {
            return;
        }

        $db = $context->database();
        $current = self::readSetting($db, 'schema_version');
        if ($current !== self::VERSION) {
            foreach (self::statements() as $statement) {
                $db->setQuery($db->replacePrefix($statement))->execute();
            }
            self::writeSetting($db, 'schema_version', self::VERSION);
        }

        if (self::readSetting($db, 'installed_at') === '') {
            self::writeSetting($db, 'installed_at', gmdate('Y-m-d H:i:s'));
        }
        if (self::readSetting($db, 'vapid_subject') === '') {
            self::writeSetting($db, 'vapid_subject', 'mailto:info@memistudio.ca');
        }
        if (self::readSetting($db, 'vapid_public_key') === '' || self::readSetting($db, 'vapid_private_key') === '') {
            try {
                $keys = self::createVapidKeys();
                self::writeSetting($db, 'vapid_public_key', $keys['publicKey']);
                self::writeSetting($db, 'vapid_private_key', $keys['privateKey']);
            } catch (\Throwable $error) {
                error_log('Memi PWA VAPID key generation failed: ' . get_debug_type($error));
            }
        }
        if (self::readSetting($db, 'app_version') !== '1.0.0') {
            self::writeSetting($db, 'app_version', '1.0.0');
        }
        self::$ready = true;
    }

    public static function setting(DatabaseInterface $db, string $key, string $default = ''): string
    {
        $value = self::readSetting($db, $key);

        return $value === '' ? $default : $value;
    }

    public static function setSetting(DatabaseInterface $db, string $key, string $value): void
    {
        self::writeSetting($db, $key, $value);
    }

    /** @return list<string> */
    private static function statements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS #__memi_pwa_settings (
  setting_key VARCHAR(100) NOT NULL,
  setting_value MEDIUMTEXT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS #__memi_pwa_preferences (
  user_id INT UNSIGNED NOT NULL,
  notify_courses TINYINT(1) NOT NULL DEFAULT 0,
  notify_promotions TINYINT(1) NOT NULL DEFAULT 0,
  notify_other TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (user_id),
  KEY idx_memi_pwa_preferences_categories (notify_courses, notify_promotions, notify_other)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS #__memi_pwa_push_subscriptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  endpoint_hash CHAR(64) NOT NULL,
  endpoint MEDIUMTEXT NOT NULL,
  public_key VARCHAR(255) NOT NULL,
  auth_token VARCHAR(255) NOT NULL,
  content_encoding VARCHAR(32) NOT NULL DEFAULT 'aes128gcm',
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  failure_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_success_at DATETIME NULL DEFAULT NULL,
  last_failure_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_memi_pwa_subscription_endpoint (endpoint_hash),
  KEY idx_memi_pwa_subscription_user (user_id, enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS #__memi_pwa_announcements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(180) NOT NULL,
  body TEXT NOT NULL,
  target_url VARCHAR(1000) NULL DEFAULT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'published',
  published_at DATETIME NOT NULL,
  expires_at DATETIME NULL DEFAULT NULL,
  created_by INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_memi_pwa_announcement_status (status, published_at, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS #__memi_pwa_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  category VARCHAR(24) NOT NULL,
  source_type VARCHAR(40) NOT NULL,
  source_id BIGINT UNSIGNED NULL DEFAULT NULL,
  dedupe_key CHAR(64) NOT NULL,
  title VARCHAR(180) NOT NULL,
  body TEXT NOT NULL,
  target_url VARCHAR(1000) NULL DEFAULT NULL,
  available_at DATETIME NOT NULL,
  expires_at DATETIME NULL DEFAULT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'queued',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_memi_pwa_event_dedupe (dedupe_key),
  KEY idx_memi_pwa_event_delivery (status, available_at),
  KEY idx_memi_pwa_event_category (category, available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS #__memi_pwa_deliveries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id BIGINT UNSIGNED NOT NULL,
  subscription_id BIGINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'queued',
  attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at DATETIME NOT NULL,
  last_attempt_at DATETIME NULL DEFAULT NULL,
  delivered_at DATETIME NULL DEFAULT NULL,
  error_code VARCHAR(80) NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_memi_pwa_delivery_event_subscription (event_id, subscription_id),
  KEY idx_memi_pwa_delivery_due (status, next_attempt_at),
  KEY idx_memi_pwa_delivery_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ];
    }

    private static function readSetting(DatabaseInterface $db, string $key): string
    {
        try {
            $query = $db->getQuery(true)
                ->select($db->quoteName('setting_value'))
                ->from($db->quoteName('#__memi_pwa_settings'))
                ->where($db->quoteName('setting_key') . ' = ' . $db->quote($key));
            $db->setQuery($query, 0, 1);

            return trim((string) $db->loadResult());
        } catch (\Throwable) {
            return '';
        }
    }

    private static function writeSetting(DatabaseInterface $db, string $key, string $value): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $sql = 'INSERT INTO ' . $db->quoteName('#__memi_pwa_settings')
            . ' (' . $db->quoteName('setting_key') . ', ' . $db->quoteName('setting_value') . ', ' . $db->quoteName('updated_at') . ')'
            . ' VALUES (' . $db->quote($key) . ', ' . $db->quote($value) . ', ' . $db->quote($now) . ')'
            . ' ON DUPLICATE KEY UPDATE ' . $db->quoteName('setting_value') . ' = VALUES(' . $db->quoteName('setting_value') . '), '
            . $db->quoteName('updated_at') . ' = VALUES(' . $db->quoteName('updated_at') . ')';
        $db->setQuery($sql)->execute();
    }

    /** @return array{publicKey:string,privateKey:string} */
    private static function createVapidKeys(): array
    {
        if (class_exists(\Minishlink\WebPush\VAPID::class)) {
            /** @var array{publicKey:string,privateKey:string} $keys */
            $keys = \Minishlink\WebPush\VAPID::createVapidKeys();

            return $keys;
        }
        if (!function_exists('openssl_pkey_new')) {
            throw new \RuntimeException('OpenSSL is unavailable.');
        }
        $resource = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        if ($resource === false) {
            throw new \RuntimeException('Unable to create an EC key.');
        }
        $details = openssl_pkey_get_details($resource);
        $ec = is_array($details) && isset($details['ec']) && is_array($details['ec']) ? $details['ec'] : [];
        $x = $ec['x'] ?? null;
        $y = $ec['y'] ?? null;
        $d = $ec['d'] ?? null;
        if (!is_string($x) || !is_string($y) || !is_string($d)) {
            throw new \RuntimeException('OpenSSL did not expose the EC key details.');
        }

        return [
            'publicKey' => self::base64Url("\x04" . $x . $y),
            'privateKey' => self::base64Url(str_pad($d, 32, "\0", STR_PAD_LEFT)),
        ];
    }

    private static function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
