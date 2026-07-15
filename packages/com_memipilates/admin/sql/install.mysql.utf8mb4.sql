-- Memi Pilates component schema.
-- Timestamps are UTC unless a timezone column is present. Money is integer cents.
-- QR token values are never persisted: only SHA-256 hashes and a short hint are kept.

CREATE TABLE IF NOT EXISTS #__memi_course_types (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(255) NOT NULL,
  alias VARCHAR(400) NOT NULL,
  description MEDIUMTEXT NULL,
  level VARCHAR(64) NOT NULL DEFAULT '',
  intensity TINYINT UNSIGNED NULL DEFAULT NULL,
  default_duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  default_capacity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  default_price_cents INT UNSIGNED NOT NULL DEFAULT 0,
  default_credits_required SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  tax_rate_basis_points SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  image VARCHAR(1024) NOT NULL DEFAULT '',
  published TINYINT(1) NOT NULL DEFAULT 1,
  access INT UNSIGNED NOT NULL DEFAULT 1,
  ordering INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  created_by INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NULL DEFAULT NULL,
  updated_by INT UNSIGNED NOT NULL DEFAULT 0,
  archived_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_memi_course_types_alias (alias),
  KEY idx_memi_course_types_published_order (published, ordering),
  KEY idx_memi_course_types_access (access)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS #__memi_instructors (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NULL DEFAULT NULL,
  display_name VARCHAR(255) NOT NULL,
  bio MEDIUMTEXT NULL,
  image VARCHAR(1024) NOT NULL DEFAULT '',
  phone VARCHAR(64) NOT NULL DEFAULT '',
  email VARCHAR(320) NOT NULL DEFAULT '',
  published TINYINT(1) NOT NULL DEFAULT 1,
  ordering INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  created_by INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NULL DEFAULT NULL,
  updated_by INT UNSIGNED NOT NULL DEFAULT 0,
  archived_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_memi_instructors_user (user_id),
  KEY idx_memi_instructors_published_order (published, ordering)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS #__memi_locations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(255) NOT NULL,
  alias VARCHAR(400) NOT NULL,
  address_line1 VARCHAR(255) NOT NULL DEFAULT '',
  address_line2 VARCHAR(255) NOT NULL DEFAULT '',
  city VARCHAR(128) NOT NULL DEFAULT '',
  province VARCHAR(128) NOT NULL DEFAULT '',
  postal_code VARCHAR(32) NOT NULL DEFAULT '',
  country_code CHAR(2) NOT NULL DEFAULT 'CA',
  timezone VARCHAR(64) NOT NULL DEFAULT 'America/Toronto',
  phone VARCHAR(64) NOT NULL DEFAULT '',
  published TINYINT(1) NOT NULL DEFAULT 1,
  ordering INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  created_by INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NULL DEFAULT NULL,
  updated_by INT UNSIGNED NOT NULL DEFAULT 0,
  archived_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_memi_locations_alias (alias),
  KEY idx_memi_locations_published_order (published, ordering)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS #__memi_rooms (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  location_id INT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  alias VARCHAR(400) NOT NULL,
  capacity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  description TEXT NULL,
  published TINYINT(1) NOT NULL DEFAULT 1,
  access INT UNSIGNED NOT NULL DEFAULT 1,
  ordering INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  created_by INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NULL DEFAULT NULL,
  updated_by INT UNSIGNED NOT NULL DEFAULT 0,
  archived_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_memi_rooms_location_alias (location_id, alias),
  KEY idx_memi_rooms_location_published (location_id, published, ordering),
  CONSTRAINT fk_memi_rooms_location FOREIGN KEY (location_id)
    REFERENCES #__memi_locations (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS #__memi_courses (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_type_id INT UNSIGNED NOT NULL,
  instructor_id INT UNSIGNED NULL DEFAULT NULL,
  room_id INT UNSIGNED NULL DEFAULT NULL,
  title VARCHAR(255) NOT NULL,
  alias VARCHAR(400) NOT NULL,
  description MEDIUMTEXT NULL,
  default_duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  capacity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  price_cents INT UNSIGNED NOT NULL DEFAULT 0,
  credits_required SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  tax_rate_basis_points SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  booking_opens_offset_minutes INT UNSIGNED NOT NULL DEFAULT 10080,
  booking_closes_offset_minutes INT UNSIGNED NOT NULL DEFAULT 0,
  is_private TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  published TINYINT(1) NOT NULL DEFAULT 1,
  access INT UNSIGNED NOT NULL DEFAULT 1,
  ordering INT NOT NULL DEFAULT 0,
  internal_note TEXT NULL,
  created_at DATETIME NOT NULL,
  created_by INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NULL DEFAULT NULL,
  updated_by INT UNSIGNED NOT NULL DEFAULT 0,
  archived_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_memi_courses_alias (alias),
  KEY idx_memi_courses_type_published (course_type_id, published),
  KEY idx_memi_courses_instructor (instructor_id),
  KEY idx_memi_courses_room (room_id),
  KEY idx_memi_courses_status (status),
  CONSTRAINT fk_memi_courses_course_type FOREIGN KEY (course_type_id)
    REFERENCES #__memi_course_types (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_memi_courses_instructor FOREIGN KEY (instructor_id)
    REFERENCES #__memi_instructors (id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_memi_courses_room FOREIGN KEY (room_id)
    REFERENCES #__memi_rooms (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS #__memi_session_rules (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id INT UNSIGNED NOT NULL,
  instructor_id INT UNSIGNED NULL DEFAULT NULL,
  room_id INT UNSIGNED NULL DEFAULT NULL,
  rrule VARCHAR(512) NOT NULL,
  starts_on DATE NOT NULL,
  ends_on DATE NULL DEFAULT NULL,
  weekday TINYINT UNSIGNED NOT NULL,
  start_time TIME NOT NULL,
  timezone VARCHAR(64) NOT NULL DEFAULT 'America/Toronto',
  duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  capacity_override SMALLINT UNSIGNED NULL DEFAULT NULL,
  price_cents_override INT UNSIGNED NULL DEFAULT NULL,
  credits_required_override SMALLINT UNSIGNED NULL DEFAULT NULL,
  booking_opens_offset_minutes INT UNSIGNED NULL DEFAULT NULL,
  booking_closes_offset_minutes INT UNSIGNED NULL DEFAULT NULL,
  last_generated_at DATETIME NULL DEFAULT NULL,
  generation_until DATETIME NULL DEFAULT NULL,
  published TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  created_by INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NULL DEFAULT NULL,
  updated_by INT UNSIGNED NOT NULL DEFAULT 0,
  archived_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_memi_session_rules_course_active (course_id, published, weekday, starts_on, ends_on),
  KEY idx_memi_session_rules_instructor (instructor_id),
  KEY idx_memi_session_rules_room (room_id),
  CONSTRAINT fk_memi_session_rules_course FOREIGN KEY (course_id)
    REFERENCES #__memi_courses (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_memi_session_rules_instructor FOREIGN KEY (instructor_id)
    REFERENCES #__memi_instructors (id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_memi_session_rules_room FOREIGN KEY (room_id)
    REFERENCES #__memi_rooms (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS #__memi_sessions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id INT UNSIGNED NOT NULL,
  rule_id INT UNSIGNED NULL DEFAULT NULL,
  instructor_id INT UNSIGNED NULL DEFAULT NULL,
  room_id INT UNSIGNED NULL DEFAULT NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  timezone VARCHAR(64) NOT NULL DEFAULT 'America/Toronto',
  duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  capacity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  reserved_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  waitlist_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  price_cents INT UNSIGNED NOT NULL DEFAULT 0,
  credits_required SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  tax_rate_basis_points SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  registration_opens_at DATETIME NULL DEFAULT NULL,
  registration_closes_at DATETIME NULL DEFAULT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'scheduled',
  is_private TINYINT(1) NOT NULL DEFAULT 0,
  internal_note TEXT NULL,
  cancelled_at DATETIME NULL DEFAULT NULL,
  cancelled_by INT UNSIGNED NOT NULL DEFAULT 0,
  cancellation_reason TEXT NULL,
  created_at DATETIME NOT NULL,
  created_by INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NULL DEFAULT NULL,
  updated_by INT UNSIGNED NOT NULL DEFAULT 0,
  archived_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_memi_sessions_course_start (course_id, starts_at),
  KEY idx_memi_sessions_calendar (starts_at, status),
  KEY idx_memi_sessions_instructor_calendar (instructor_id, starts_at),
  KEY idx_memi_sessions_room_calendar (room_id, starts_at),
  KEY idx_memi_sessions_rule_start (rule_id, starts_at),
  KEY idx_memi_sessions_registration (registration_opens_at, registration_closes_at),
  CONSTRAINT fk_memi_sessions_course FOREIGN KEY (course_id)
    REFERENCES #__memi_courses (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_memi_sessions_rule FOREIGN KEY (rule_id)
    REFERENCES #__memi_session_rules (id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_memi_sessions_instructor FOREIGN KEY (instructor_id)
    REFERENCES #__memi_instructors (id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_memi_sessions_room FOREIGN KEY (room_id)
    REFERENCES #__memi_rooms (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS #__memi_client_profiles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  phone VARCHAR(64) NOT NULL DEFAULT '',
  date_of_birth DATE NULL DEFAULT NULL,
  emergency_contact_name VARCHAR(255) NOT NULL DEFAULT '',
  emergency_contact_phone VARCHAR(64) NOT NULL DEFAULT '',
  preferred_locale VARCHAR(16) NOT NULL DEFAULT 'fr-FR',
  marketing_email_opt_in TINYINT(1) NOT NULL DEFAULT 0,
  marketing_sms_opt_in TINYINT(1) NOT NULL DEFAULT 0,
  terms_accepted_at DATETIME NULL DEFAULT NULL,
  privacy_accepted_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL DEFAULT NULL,
  archived_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_memi_client_profiles_user (user_id),
  KEY idx_memi_client_profiles_archived (archived_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS #__memi_packages (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(255) NOT NULL,
  alias VARCHAR(400) NOT NULL,
  description MEDIUMTEXT NULL,
  price_cents INT UNSIGNED NOT NULL DEFAULT 0,
  credits SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  validity_days SMALLINT UNSIGNED NULL DEFAULT NULL,
  fixed_expiry_at DATETIME NULL DEFAULT NULL,
  maximum_bookings SMALLINT UNSIGNED NULL DEFAULT NULL,
  tax_rate_basis_points SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  bonus_points INT NOT NULL DEFAULT 0,
  points_bonus INT NOT NULL DEFAULT 0,
  published TINYINT(1) NOT NULL DEFAULT 1,
  access INT UNSIGNED NOT NULL DEFAULT 1,
  ordering INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  created_by INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NULL DEFAULT NULL,
  updated_by INT UNSIGNED NOT NULL DEFAULT 0,
  archived_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_memi_packages_alias (alias),
  KEY idx_memi_packages_published_order (published, ordering)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A missing row means the package is eligible for every course type.
CREATE TABLE IF NOT EXISTS #__memi_package_course_types (
  package_id INT UNSIGNED NOT NULL,
  course_type_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (package_id, course_type_id),
  KEY idx_memi_package_course_types_type (course_type_id),
  CONSTRAINT fk_memi_pkg_course_types_package FOREIGN KEY (package_id)
    REFERENCES #__memi_packages (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_memi_pkg_course_types_type FOREIGN KEY (course_type_id)
    REFERENCES #__memi_course_types (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS #__memi_customer_packages (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  package_id INT UNSIGNED NOT NULL,
  order_id INT UNSIGNED NULL DEFAULT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  original_credits SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  remaining_credits SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  credits_granted SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  purchased_at DATETIME NOT NULL,
  starts_at DATETIME NULL DEFAULT NULL,
  expires_at DATETIME NULL DEFAULT NULL,
  cancelled_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  created_by INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NULL DEFAULT NULL,
  updated_by INT UNSIGNED NOT NULL DEFAULT 0,
  archived_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_memi_customer_packages_client_status (client_id, status, expires_at),
  KEY idx_memi_customer_packages_package (package_id),
  KEY idx_memi_customer_packages_order (order_id),
  CONSTRAINT fk_memi_customer_packages_client FOREIGN KEY (client_id)
    REFERENCES #__memi_client_profiles (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_memi_customer_packages_package FOREIGN KEY (package_id)
    REFERENCES #__memi_packages (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS #__memi_promotions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(64) NOT NULL,
  title VARCHAR(255) NOT NULL,
  description MEDIUMTEXT NULL,
  discount_type VARCHAR(32) NOT NULL,
  discount_cents INT UNSIGNED NOT NULL DEFAULT 0,
  discount_basis_points SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  bonus_credits SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  bonus_points INT NOT NULL DEFAULT 0,
  minimum_order_cents INT UNSIGNED NOT NULL DEFAULT 0,
  minimum_amount_cents INT UNSIGNED NOT NULL DEFAULT 0,
  maximum_redemptions INT UNSIGNED NULL DEFAULT NULL,
  per_customer_limit INT UNSIGNED NULL DEFAULT NULL,
  starts_at DATETIME NULL DEFAULT NULL,
  ends_at DATETIME NULL DEFAULT NULL,
  published TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  created_by INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NULL DEFAULT NULL,
  updated_by INT UNSIGNED NOT NULL DEFAULT 0,
  archived_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_memi_promotions_code (code),
  KEY idx_memi_promotions_active (published, starts_at, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A missing row in a promotion restriction table means the promotion has no
-- restriction of that kind.
CREATE TABLE IF NOT EXISTS #__memi_promotion_course_types (
  promotion_id INT UNSIGNED NOT NULL,
  course_type_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (promotion_id, course_type_id),
  KEY idx_memi_promo_course_types_type (course_type_id),
  CONSTRAINT fk_memi_promo_course_types_promotion FOREIGN KEY (promotion_id)
    REFERENCES #__memi_promotions (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_memi_promo_course_types_type FOREIGN KEY (course_type_id)
    REFERENCES #__memi_course_types (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS #__memi_promotion_packages (
  promotion_id INT UNSIGNED NOT NULL,
  package_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (promotion_id, package_id),
  KEY idx_memi_promotion_packages_package (package_id),
  CONSTRAINT fk_memi_promotion_packages_promotion FOREIGN KEY (promotion_id)
    REFERENCES #__memi_promotions (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_memi_promotion_packages_package FOREIGN KEY (package_id)
    REFERENCES #__memi_packages (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS #__memi_orders (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id INT UNSIGNED NULL DEFAULT NULL,
  user_id INT UNSIGNED NOT NULL,
  promotion_id INT UNSIGNED NULL DEFAULT NULL,
  promotion_code VARCHAR(64) NULL DEFAULT NULL,
  order_number VARCHAR(64) NULL DEFAULT NULL,
  order_key VARCHAR(128) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  currency CHAR(3) NOT NULL DEFAULT 'CAD',
  subtotal_cents INT UNSIGNED NOT NULL DEFAULT 0,
  discount_cents INT UNSIGNED NOT NULL DEFAULT 0,
  tax_cents INT UNSIGNED NOT NULL DEFAULT 0,
  total_cents INT UNSIGNED NOT NULL DEFAULT 0,
  square_order_id VARCHAR(128) NULL DEFAULT NULL,
  idempotency_key VARCHAR(128) NULL DEFAULT NULL,
  placed_at DATETIME NULL DEFAULT NULL,
  paid_at DATETIME NULL DEFAULT NULL,
  failed_at DATETIME NULL DEFAULT NULL,
  failure_reason VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  created_by INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NULL DEFAULT NULL,
  updated_by INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_memi_orders_number (order_number),
  UNIQUE KEY uq_memi_orders_idempotency (idempotency_key),
  UNIQUE KEY uq_memi_orders_key (order_key),
  UNIQUE KEY uq_memi_orders_square_order (square_order_id),
  KEY idx_memi_orders_user_created (user_id, created_at),
  KEY idx_memi_orders_client_created (client_id, created_at),
  KEY idx_memi_orders_status_created (status, created_at),
  KEY idx_memi_orders_promotion (promotion_id),
  CONSTRAINT fk_memi_orders_client FOREIGN KEY (client_id)
    REFERENCES #__memi_client_profiles (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_memi_orders_promotion FOREIGN KEY (promotion_id)
    REFERENCES #__memi_promotions (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS #__memi_order_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id INT UNSIGNED NOT NULL,
  item_type VARCHAR(32) NOT NULL,
  item_id INT UNSIGNED NULL DEFAULT NULL,
  package_id INT UNSIGNED NULL DEFAULT NULL,
  title_snapshot VARCHAR(255) NOT NULL,
  quantity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  unit_price_cents INT UNSIGNED NOT NULL DEFAULT 0,
  discount_cents INT UNSIGNED NOT NULL DEFAULT 0,
  tax_cents INT UNSIGNED NOT NULL DEFAULT 0,
  total_cents INT UNSIGNED NOT NULL DEFAULT 0,
  metadata MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_memi_order_items_order (order_id),
  KEY idx_memi_order_items_item (item_type, item_id),
  KEY idx_memi_order_items_package (package_id),
  CONSTRAINT fk_memi_order_items_order FOREIGN KEY (order_id)
    REFERENCES #__memi_orders (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS #__memi_bookings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  customer_package_id INT UNSIGNED NULL DEFAULT NULL,
  order_id INT UNSIGNED NULL DEFAULT NULL,
  booking_key VARCHAR(128) NOT NULL,
  active_booking_key CHAR(64) NULL DEFAULT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  source VARCHAR(32) NOT NULL DEFAULT 'web',
  booked_at DATETIME NOT NULL,
  confirmed_at DATETIME NULL DEFAULT NULL,
  cancelled_at DATETIME NULL DEFAULT NULL,
  cancelled_by INT UNSIGNED NOT NULL DEFAULT 0,
  cancellation_reason TEXT NULL,
  credit_restored_at DATETIME NULL DEFAULT NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_memi_bookings_booking_key (booking_key),
  UNIQUE KEY uq_memi_bookings_active_key (active_booking_key),
  KEY idx_memi_bookings_session_status (session_id, status),
  KEY idx_memi_bookings_user_status (user_id, status),
  KEY idx_memi_bookings_client_status (client_id, status),
  KEY idx_memi_bookings_package (customer_package_id),
  KEY idx_memi_bookings_order (order_id),
  CONSTRAINT fk_memi_bookings_session FOREIGN KEY (session_id)
    REFERENCES #__memi_sessions (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_memi_bookings_client FOREIGN KEY (client_id)
    REFERENCES #__memi_client_profiles (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_memi_bookings_customer_package FOREIGN KEY (customer_package_id)
    REFERENCES #__memi_customer_packages (id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_memi_bookings_order FOREIGN KEY (order_id)
    REFERENCES #__memi_orders (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS #__memi_waitlist (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'waiting',
  position INT UNSIGNED NOT NULL DEFAULT 0,
  offer_token_hash CHAR(64) NULL DEFAULT NULL,
  offered_at DATETIME NULL DEFAULT NULL,
  offer_expires_at DATETIME NULL DEFAULT NULL,
  accepted_at DATETIME NULL DEFAULT NULL,
  withdrawn_at DATETIME NULL DEFAULT NULL,
  promoted_booking_id INT UNSIGNED NULL DEFAULT NULL,
  idempotency_key VARCHAR(128) NOT NULL,
  joined_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_memi_waitlist_session_user (session_id, user_id),
  UNIQUE KEY uq_memi_waitlist_offer_hash (offer_token_hash),
  UNIQUE KEY uq_memi_waitlist_idempotency (idempotency_key),
  KEY idx_memi_waitlist_offer (session_id, status, position, joined_at),
  KEY idx_memi_waitlist_expiry (status, offer_expires_at),
  KEY idx_memi_waitlist_client (client_id),
  KEY idx_memi_waitlist_booking (promoted_booking_id),
  CONSTRAINT fk_memi_waitlist_session FOREIGN KEY (session_id)
    REFERENCES #__memi_sessions (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_memi_waitlist_client FOREIGN KEY (client_id)
    REFERENCES #__memi_client_profiles (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_memi_waitlist_booking FOREIGN KEY (promoted_booking_id)
    REFERENCES #__memi_bookings (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS #__memi_credit_ledger (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  customer_package_id INT UNSIGNED NULL DEFAULT NULL,
  booking_id INT UNSIGNED NULL DEFAULT NULL,
  order_id INT UNSIGNED NULL DEFAULT NULL,
  entry_type VARCHAR(32) NOT NULL,
  credits_delta INT NOT NULL,
  reference_type VARCHAR(32) NOT NULL DEFAULT '',
  reference_id INT UNSIGNED NULL DEFAULT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  expires_at DATETIME NULL DEFAULT NULL,
  idempotency_key VARCHAR(128) NOT NULL,
  created_at DATETIME NOT NULL,
  created_by INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_memi_credit_ledger_idempotency (idempotency_key),
  KEY idx_memi_credit_ledger_client_created (client_id, created_at),
  KEY idx_memi_credit_ledger_package (customer_package_id),
  KEY idx_memi_credit_ledger_booking (booking_id),
  KEY idx_memi_credit_ledger_order (order_id),
  KEY idx_memi_credit_ledger_expiry (expires_at),
  CONSTRAINT fk_memi_credit_ledger_client FOREIGN KEY (client_id)
    REFERENCES #__memi_client_profiles (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_memi_credit_ledger_package FOREIGN KEY (customer_package_id)
    REFERENCES #__memi_customer_packages (id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_memi_credit_ledger_booking FOREIGN KEY (booking_id)
    REFERENCES #__memi_bookings (id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_memi_credit_ledger_order FOREIGN KEY (order_id)
    REFERENCES #__memi_orders (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS #__memi_promotion_redemptions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  promotion_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  order_id INT UNSIGNED NULL DEFAULT NULL,
  booking_id INT UNSIGNED NULL DEFAULT NULL,
  discount_cents INT UNSIGNED NOT NULL DEFAULT 0,
  idempotency_key VARCHAR(128) NOT NULL,
  redeemed_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_memi_promo_redemption_idempotency (idempotency_key),
  KEY idx_memi_promo_redemptions_promotion_client (promotion_id, client_id),
  KEY idx_memi_promo_redemptions_order (order_id),
  KEY idx_memi_promo_redemptions_booking (booking_id),
  CONSTRAINT fk_memi_promo_redemptions_promotion FOREIGN KEY (promotion_id)
    REFERENCES #__memi_promotions (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_memi_promo_redemptions_client FOREIGN KEY (client_id)
    REFERENCES #__memi_client_profiles (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_memi_promo_redemptions_order FOREIGN KEY (order_id)
    REFERENCES #__memi_orders (id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_memi_promo_redemptions_booking FOREIGN KEY (booking_id)
    REFERENCES #__memi_bookings (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS #__memi_payments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id INT UNSIGNED NOT NULL,
  provider VARCHAR(32) NOT NULL DEFAULT 'square',
  provider_payment_id VARCHAR(128) NULL DEFAULT NULL,
  idempotency_key VARCHAR(128) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  amount_cents INT UNSIGNED NOT NULL DEFAULT 0,
  currency CHAR(3) NOT NULL DEFAULT 'CAD',
  source_type VARCHAR(32) NOT NULL DEFAULT 'online',
  receipt_url VARCHAR(2048) NOT NULL DEFAULT '',
  card_brand VARCHAR(64) NULL DEFAULT NULL,
  card_last4 VARCHAR(4) NULL DEFAULT NULL,
  provider_status VARCHAR(64) NOT NULL DEFAULT '',
  response_metadata MEDIUMTEXT NULL,
  authorized_at DATETIME NULL DEFAULT NULL,
  captured_at DATETIME NULL DEFAULT NULL,
  failed_at DATETIME NULL DEFAULT NULL,
  failure_reason VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_memi_payments_provider_payment (provider, provider_payment_id),
  UNIQUE KEY uq_memi_payments_idempotency (idempotency_key),
  KEY idx_memi_payments_order_status (order_id, status),
  KEY idx_memi_payments_created (created_at),
  CONSTRAINT fk_memi_payments_order FOREIGN KEY (order_id)
    REFERENCES #__memi_orders (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS #__memi_refunds (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  payment_id INT UNSIGNED NOT NULL,
  order_id INT UNSIGNED NOT NULL,
  provider_refund_id VARCHAR(128) NULL DEFAULT NULL,
  idempotency_key VARCHAR(128) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  amount_cents INT UNSIGNED NOT NULL DEFAULT 0,
  reason VARCHAR(500) NOT NULL DEFAULT '',
  requested_by INT UNSIGNED NOT NULL DEFAULT 0,
  requested_at DATETIME NOT NULL,
  processed_at DATETIME NULL DEFAULT NULL,
  failure_reason VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_memi_refunds_provider_refund (provider_refund_id),
  UNIQUE KEY uq_memi_refunds_idempotency (idempotency_key),
  KEY idx_memi_refunds_payment (payment_id),
  KEY idx_memi_refunds_order (order_id),
  KEY idx_memi_refunds_status_created (status, created_at),
  CONSTRAINT fk_memi_refunds_payment FOREIGN KEY (payment_id)
    REFERENCES #__memi_payments (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_memi_refunds_order FOREIGN KEY (order_id)
    REFERENCES #__memi_orders (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS #__memi_qr_tokens (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  token_hint VARCHAR(16) NOT NULL DEFAULT '',
  version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  issued_at DATETIME NOT NULL,
  expires_at DATETIME NULL DEFAULT NULL,
  revoked_at DATETIME NULL DEFAULT NULL,
  revoked_by INT UNSIGNED NOT NULL DEFAULT 0,
  revocation_reason VARCHAR(255) NOT NULL DEFAULT '',
  last_used_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_memi_qr_tokens_hash (token_hash),
  KEY idx_memi_qr_tokens_client_active (client_id, revoked_at, expires_at),
  KEY idx_memi_qr_tokens_user (user_id),
  CONSTRAINT fk_memi_qr_tokens_client FOREIGN KEY (client_id)
    REFERENCES #__memi_client_profiles (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS #__memi_attendance (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id INT UNSIGNED NOT NULL,
  booking_id INT UNSIGNED NULL DEFAULT NULL,
  client_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  employee_user_id INT UNSIGNED NOT NULL DEFAULT 0,
  method VARCHAR(32) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'confirmed',
  checked_in_at DATETIME NOT NULL,
  scanned_by_user_id INT UNSIGNED NOT NULL DEFAULT 0,
  idempotency_key VARCHAR(128) NOT NULL,
  active_attendance_key CHAR(64) NULL DEFAULT NULL,
  override_reason VARCHAR(500) NOT NULL DEFAULT '',
  note TEXT NULL,
  voided_at DATETIME NULL DEFAULT NULL,
  voided_by INT UNSIGNED NOT NULL DEFAULT 0,
  corrected_at DATETIME NULL DEFAULT NULL,
  corrected_by_user_id INT UNSIGNED NOT NULL DEFAULT 0,
  correction_reason VARCHAR(500) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL,
  updated_by INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_memi_attendance_active (active_attendance_key),
  UNIQUE KEY uq_memi_attendance_idempotency (idempotency_key),
  KEY idx_memi_attendance_session_status (session_id, status),
  KEY idx_memi_attendance_session_client (session_id, client_id),
  KEY idx_memi_attendance_booking (booking_id),
  KEY idx_memi_attendance_client_created (client_id, checked_in_at),
  CONSTRAINT fk_memi_attendance_session FOREIGN KEY (session_id)
    REFERENCES #__memi_sessions (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_memi_attendance_booking FOREIGN KEY (booking_id)
    REFERENCES #__memi_bookings (id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_memi_attendance_client FOREIGN KEY (client_id)
    REFERENCES #__memi_client_profiles (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS #__memi_points_ledger (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  attendance_id INT UNSIGNED NULL DEFAULT NULL,
  order_id INT UNSIGNED NULL DEFAULT NULL,
  entry_type VARCHAR(32) NOT NULL,
  points_delta INT NOT NULL,
  reference_type VARCHAR(32) NOT NULL DEFAULT '',
  reference_id INT UNSIGNED NULL DEFAULT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  expires_at DATETIME NULL DEFAULT NULL,
  idempotency_key VARCHAR(128) NOT NULL,
  created_at DATETIME NOT NULL,
  created_by INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_memi_points_ledger_idempotency (idempotency_key),
  KEY idx_memi_points_ledger_client_created (client_id, created_at),
  KEY idx_memi_points_ledger_attendance (attendance_id),
  KEY idx_memi_points_ledger_order (order_id),
  KEY idx_memi_points_ledger_expiry (expires_at),
  CONSTRAINT fk_memi_points_ledger_client FOREIGN KEY (client_id)
    REFERENCES #__memi_client_profiles (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_memi_points_ledger_attendance FOREIGN KEY (attendance_id)
    REFERENCES #__memi_attendance (id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_memi_points_ledger_order FOREIGN KEY (order_id)
    REFERENCES #__memi_orders (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS #__memi_rewards (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(255) NOT NULL,
  description MEDIUMTEXT NULL,
  points_cost INT UNSIGNED NOT NULL,
  reward_type VARCHAR(32) NOT NULL,
  discount_cents INT UNSIGNED NOT NULL DEFAULT 0,
  credits SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  package_id INT UNSIGNED NULL DEFAULT NULL,
  published TINYINT(1) NOT NULL DEFAULT 1,
  available_from DATETIME NULL DEFAULT NULL,
  available_until DATETIME NULL DEFAULT NULL,
  maximum_redemptions INT UNSIGNED NULL DEFAULT NULL,
  ordering INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  created_by INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NULL DEFAULT NULL,
  updated_by INT UNSIGNED NOT NULL DEFAULT 0,
  archived_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_memi_rewards_available (published, available_from, available_until, ordering),
  KEY idx_memi_rewards_package (package_id),
  CONSTRAINT fk_memi_rewards_package FOREIGN KEY (package_id)
    REFERENCES #__memi_packages (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS #__memi_reward_redemptions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reward_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  order_id INT UNSIGNED NULL DEFAULT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  points_cost INT UNSIGNED NOT NULL,
  idempotency_key VARCHAR(128) NOT NULL,
  claimed_at DATETIME NOT NULL,
  fulfilled_at DATETIME NULL DEFAULT NULL,
  fulfilled_by INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_memi_reward_redemptions_idempotency (idempotency_key),
  KEY idx_memi_reward_redemptions_client (client_id, created_at),
  KEY idx_memi_reward_redemptions_reward_status (reward_id, status),
  KEY idx_memi_reward_redemptions_order (order_id),
  CONSTRAINT fk_memi_reward_redemptions_reward FOREIGN KEY (reward_id)
    REFERENCES #__memi_rewards (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_memi_reward_redemptions_client FOREIGN KEY (client_id)
    REFERENCES #__memi_client_profiles (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_memi_reward_redemptions_order FOREIGN KEY (order_id)
    REFERENCES #__memi_orders (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS #__memi_email_templates (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  template_key VARCHAR(100) NOT NULL,
  language_tag VARCHAR(16) NOT NULL DEFAULT 'fr-FR',
  subject VARCHAR(255) NOT NULL,
  html_body MEDIUMTEXT NOT NULL,
  text_body MEDIUMTEXT NOT NULL,
  published TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  created_by INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NULL DEFAULT NULL,
  updated_by INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_memi_email_templates_key_language (template_key, language_tag),
  KEY idx_memi_email_templates_published (published)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS #__memi_notifications (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  booking_id INT UNSIGNED NULL DEFAULT NULL,
  waitlist_id INT UNSIGNED NULL DEFAULT NULL,
  template_key VARCHAR(100) NOT NULL DEFAULT '',
  channel VARCHAR(32) NOT NULL DEFAULT 'email',
  subject VARCHAR(255) NOT NULL DEFAULT '',
  payload MEDIUMTEXT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'queued',
  scheduled_at DATETIME NULL DEFAULT NULL,
  sent_at DATETIME NULL DEFAULT NULL,
  failed_at DATETIME NULL DEFAULT NULL,
  error_message VARCHAR(500) NOT NULL DEFAULT '',
  idempotency_key VARCHAR(128) NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_memi_notifications_idempotency (idempotency_key),
  KEY idx_memi_notifications_status_schedule (status, scheduled_at),
  KEY idx_memi_notifications_client_created (client_id, created_at),
  KEY idx_memi_notifications_booking (booking_id),
  KEY idx_memi_notifications_waitlist (waitlist_id),
  CONSTRAINT fk_memi_notifications_client FOREIGN KEY (client_id)
    REFERENCES #__memi_client_profiles (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_memi_notifications_booking FOREIGN KEY (booking_id)
    REFERENCES #__memi_bookings (id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_memi_notifications_waitlist FOREIGN KEY (waitlist_id)
    REFERENCES #__memi_waitlist (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS #__memi_square_webhooks (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id VARCHAR(128) NOT NULL,
  square_event_id VARCHAR(128) NULL DEFAULT NULL,
  event_type VARCHAR(128) NOT NULL,
  signature_valid TINYINT(1) NOT NULL DEFAULT 0,
  payload_hash CHAR(64) NOT NULL,
  payload MEDIUMTEXT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'received',
  received_at DATETIME NOT NULL,
  processed_at DATETIME NULL DEFAULT NULL,
  attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  error_message VARCHAR(500) NOT NULL DEFAULT '',
  created_at DATETIME NULL DEFAULT NULL,
  updated_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_memi_square_webhooks_event (event_id),
  KEY idx_memi_square_webhooks_square_event (square_event_id),
  KEY idx_memi_square_webhooks_status_received (status, received_at),
  KEY idx_memi_square_webhooks_payload_hash (payload_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS #__memi_settings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  setting_key VARCHAR(100) NOT NULL,
  setting_value MEDIUMTEXT NULL,
  value_type VARCHAR(32) NOT NULL DEFAULT 'string',
  is_secret TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL DEFAULT NULL,
  updated_by INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_memi_settings_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS #__memi_audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_user_id INT UNSIGNED NOT NULL DEFAULT 0,
  action VARCHAR(100) NOT NULL,
  entity_type VARCHAR(100) NOT NULL,
  entity_id INT UNSIGNED NULL DEFAULT NULL,
  old_values MEDIUMTEXT NULL,
  new_values MEDIUMTEXT NULL,
  reason VARCHAR(500) NOT NULL DEFAULT '',
  request_id VARCHAR(128) NOT NULL DEFAULT '',
  ip_address VARCHAR(45) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_memi_audit_entity (entity_type, entity_id, created_at),
  KEY idx_memi_audit_actor_created (actor_user_id, created_at),
  KEY idx_memi_audit_action_created (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS #__memi_scan_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id INT UNSIGNED NULL DEFAULT NULL,
  client_id INT UNSIGNED NULL DEFAULT NULL,
  attendance_id INT UNSIGNED NULL DEFAULT NULL,
  employee_user_id INT UNSIGNED NOT NULL DEFAULT 0,
  scanned_by_user_id INT UNSIGNED NOT NULL DEFAULT 0,
  method VARCHAR(32) NOT NULL,
  result VARCHAR(64) NOT NULL DEFAULT '',
  outcome VARCHAR(32) NOT NULL DEFAULT '',
  token_hash CHAR(64) NULL DEFAULT NULL,
  token_hint VARCHAR(16) NOT NULL DEFAULT '',
  duration_ms INT UNSIGNED NULL DEFAULT NULL,
  idempotency_key VARCHAR(128) NULL DEFAULT NULL,
  details VARCHAR(500) NOT NULL DEFAULT '',
  ip_address VARCHAR(45) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_memi_scan_attempts_session_created (session_id, created_at),
  KEY idx_memi_scan_attempts_client_created (client_id, created_at),
  KEY idx_memi_scan_attempts_attendance (attendance_id),
  KEY idx_memi_scan_attempts_outcome_created (outcome, created_at),
  KEY idx_memi_scan_attempts_idempotency (idempotency_key),
  CONSTRAINT fk_memi_scan_attempts_session FOREIGN KEY (session_id)
    REFERENCES #__memi_sessions (id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_memi_scan_attempts_client FOREIGN KEY (client_id)
    REFERENCES #__memi_client_profiles (id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_memi_scan_attempts_attendance FOREIGN KEY (attendance_id)
    REFERENCES #__memi_attendance (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS #__memi_rate_limits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  scope VARCHAR(100) NOT NULL,
  subject_hash CHAR(64) NOT NULL,
  window_started_at DATETIME NOT NULL,
  request_count INT UNSIGNED NOT NULL DEFAULT 0,
  blocked_until DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_memi_rate_limits_window (scope, subject_hash, window_started_at),
  KEY idx_memi_rate_limits_blocked (blocked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
