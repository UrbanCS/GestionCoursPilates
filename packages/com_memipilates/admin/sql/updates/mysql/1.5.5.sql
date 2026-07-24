-- Direct session payment and temporary capacity holds for Memi Pilates 1.5.5.
INSERT INTO #__memi_settings
  (setting_key, setting_value, value_type, is_secret, created_at, updated_at, updated_by)
VALUES
  ('schema_version', '1.5.5', 'string', 0, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 0)
ON DUPLICATE KEY UPDATE
  setting_value = VALUES(setting_value),
  value_type = VALUES(value_type),
  is_secret = VALUES(is_secret),
  updated_at = VALUES(updated_at),
  updated_by = VALUES(updated_by);
