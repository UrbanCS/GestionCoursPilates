-- Course prerequisites and explicit staff eligibility overrides for 1.8.16.

ALTER TABLE #__memi_course_types
  ADD COLUMN prerequisite_course_type_id INT UNSIGNED NULL DEFAULT NULL AFTER tax_rate_basis_points,
  ADD COLUMN prerequisite_attendance_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER prerequisite_course_type_id,
  ADD KEY idx_memi_course_types_prerequisite (prerequisite_course_type_id),
  ADD CONSTRAINT fk_memi_course_types_prerequisite FOREIGN KEY (prerequisite_course_type_id)
    REFERENCES #__memi_course_types (id) ON UPDATE CASCADE ON DELETE RESTRICT;

CREATE TABLE IF NOT EXISTS #__memi_course_eligibility_overrides (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  course_type_id INT UNSIGNED NOT NULL,
  reason VARCHAR(500) NOT NULL DEFAULT '',
  granted_at DATETIME NOT NULL,
  granted_by INT UNSIGNED NOT NULL DEFAULT 0,
  revoked_at DATETIME NULL DEFAULT NULL,
  revoked_by INT UNSIGNED NOT NULL DEFAULT 0,
  revocation_reason VARCHAR(500) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_memi_course_eligibility_user_type (user_id, course_type_id),
  KEY idx_memi_course_eligibility_client (client_id, revoked_at),
  KEY idx_memi_course_eligibility_type (course_type_id, revoked_at),
  CONSTRAINT fk_memi_course_eligibility_client FOREIGN KEY (client_id)
    REFERENCES #__memi_client_profiles (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_memi_course_eligibility_type FOREIGN KEY (course_type_id)
    REFERENCES #__memi_course_types (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO #__memi_settings
  (setting_key, setting_value, value_type, is_secret, created_at, updated_at, updated_by)
VALUES
  ('schema_version', '1.8.16', 'string', 0, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 0)
ON DUPLICATE KEY UPDATE
  setting_value = VALUES(setting_value),
  value_type = VALUES(value_type),
  is_secret = VALUES(is_secret),
  updated_at = VALUES(updated_at),
  updated_by = VALUES(updated_by);
