-- Baseline migration for the first Memi Pilates release.
-- Joomla component parameters hold the editable defaults. Runtime rows in
-- #__memi_settings intentionally override those parameters only when an
-- administrator creates them through an operational settings interface.
-- This insert is idempotent because Joomla can run it after install SQL.

INSERT IGNORE INTO #__memi_settings
  (setting_key, setting_value, value_type, is_secret, created_at, updated_at, updated_by)
VALUES
  ('schema_version', '1.0.0', 'string', 0, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 0);
