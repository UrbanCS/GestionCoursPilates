-- Reliability and QR-token migration for Memi Pilates 1.5.0.
-- Existing operational data is preserved.

ALTER TABLE #__memi_notifications
  ADD COLUMN attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER scheduled_at,
  ADD COLUMN last_attempt_at DATETIME NULL DEFAULT NULL AFTER attempt_count,
  ADD COLUMN next_attempt_at DATETIME NULL DEFAULT NULL AFTER last_attempt_at,
  ADD KEY idx_memi_notifications_retry (status, next_attempt_at, attempt_count);

UPDATE #__memi_notifications
SET next_attempt_at = scheduled_at
WHERE status IN ('queued', 'retry')
  AND next_attempt_at IS NULL;

-- Version 1.4.x stored a one-use wait-list token inside the queued payload.
-- Rebuild every historical payload exclusively from server-side offer state so
-- no bearer token survives the upgrade and pending messages remain sendable.
UPDATE #__memi_notifications AS n
INNER JOIN #__memi_waitlist AS w
  ON w.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(n.payload, '$.waitlist_id')) AS UNSIGNED)
SET n.waitlist_id = w.id
WHERE n.template_key = 'waitlist.offer'
  AND n.waitlist_id IS NULL
  AND JSON_VALID(n.payload);

UPDATE #__memi_notifications AS n
LEFT JOIN #__memi_waitlist AS w ON w.id = n.waitlist_id
SET
  n.payload = JSON_OBJECT(
    'waitlist_id', COALESCE(n.waitlist_id, 0),
    'offered_at', COALESCE(DATE_FORMAT(w.offered_at, '%Y-%m-%d %H:%i:%s'), ''),
    'expires_at', COALESCE(DATE_FORMAT(w.offer_expires_at, '%Y-%m-%d %H:%i:%s'), '')
  ),
  n.updated_at = UTC_TIMESTAMP()
WHERE n.template_key = 'waitlist.offer';

-- Earlier releases could restore a credit to an allocation that had already
-- expired without changing the allocation status. Only allocations with an
-- audited cancellation restoration and a positive ledger balance are revived.
UPDATE #__memi_customer_packages AS cp
INNER JOIN (
  SELECT
    customer_package_id,
    SUM(credits_delta) AS ledger_balance,
    SUM(entry_type IN ('cancellation_restore', 'studio_cancellation_restore')) AS restoration_count
  FROM #__memi_credit_ledger
  WHERE customer_package_id IS NOT NULL
  GROUP BY customer_package_id
) AS balances ON balances.customer_package_id = cp.id
SET
  cp.status = 'restored',
  cp.remaining_credits = GREATEST(0, balances.ledger_balance),
  cp.updated_at = UTC_TIMESTAMP()
WHERE cp.status = 'expired'
  AND cp.archived_at IS NULL
  AND balances.restoration_count > 0
  AND balances.ledger_balance > 0;

ALTER TABLE #__memi_qr_tokens
  ADD COLUMN idempotency_key VARCHAR(128) NULL DEFAULT NULL AFTER version,
  ADD COLUMN active_token_key CHAR(64) NULL DEFAULT NULL AFTER idempotency_key;

UPDATE #__memi_qr_tokens
SET
  revoked_at = UTC_TIMESTAMP(),
  revoked_by = 0,
  revocation_reason = 'expired_token_migration',
  active_token_key = NULL
WHERE revoked_at IS NULL
  AND expires_at IS NOT NULL
  AND expires_at <= UTC_TIMESTAMP();

UPDATE #__memi_qr_tokens AS q
INNER JOIN (
  SELECT client_id, MAX(id) AS keep_id
  FROM (
    SELECT id, client_id, revoked_at
    FROM #__memi_qr_tokens
  ) AS qr_snapshot
  WHERE revoked_at IS NULL
  GROUP BY client_id
) AS latest
  ON latest.client_id = q.client_id
SET
  q.revoked_at = UTC_TIMESTAMP(),
  q.revoked_by = 0,
  q.revocation_reason = 'duplicate_active_token_migration',
  q.active_token_key = NULL
WHERE q.revoked_at IS NULL
  AND q.id <> latest.keep_id;

UPDATE #__memi_qr_tokens
SET active_token_key = SHA2(CONCAT('client:', client_id), 256)
WHERE revoked_at IS NULL;

ALTER TABLE #__memi_qr_tokens
  ADD UNIQUE KEY uq_memi_qr_tokens_idempotency (idempotency_key),
  ADD UNIQUE KEY uq_memi_qr_tokens_active (active_token_key);

INSERT INTO #__memi_settings
  (setting_key, setting_value, value_type, is_secret, created_at, updated_at, updated_by)
VALUES
  ('schema_version', '1.5.0', 'string', 0, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 0)
ON DUPLICATE KEY UPDATE
  setting_value = VALUES(setting_value),
  value_type = VALUES(value_type),
  is_secret = VALUES(is_secret),
  updated_at = VALUES(updated_at),
  updated_by = VALUES(updated_by);
