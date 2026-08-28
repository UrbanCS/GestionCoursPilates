-- Make existing paid direct-session bookings transferable through the credit
-- ledger. The purchased credit remains reserved while the booking is active
-- and becomes available only after an admissible cancellation.

INSERT INTO #__memi_customer_packages (
  client_id,
  user_id,
  package_id,
  order_id,
  status,
  original_credits,
  remaining_credits,
  credits_granted,
  purchased_at,
  starts_at,
  expires_at,
  created_at,
  updated_at
)
SELECT
  b.client_id,
  b.user_id,
  p.id,
  b.order_id,
  'active',
  1,
  0,
  1,
  COALESCE(o.paid_at, o.updated_at, o.created_at),
  COALESCE(o.paid_at, o.updated_at, o.created_at),
  CASE
    WHEN p.fixed_expiry_at IS NOT NULL THEN p.fixed_expiry_at
    WHEN p.validity_days IS NOT NULL AND p.validity_days > 0
      THEN DATE_ADD(COALESCE(o.paid_at, o.updated_at, o.created_at), INTERVAL p.validity_days DAY)
    ELSE NULL
  END,
  COALESCE(o.paid_at, o.updated_at, o.created_at),
  COALESCE(o.paid_at, o.updated_at, o.created_at)
FROM #__memi_bookings AS b
INNER JOIN #__memi_orders AS o
  ON o.id = b.order_id
  AND o.status = 'paid'
INNER JOIN #__memi_sessions AS s
  ON s.id = b.session_id
INNER JOIN #__memi_order_items AS oi
  ON oi.order_id = o.id
  AND oi.item_type = 'session'
  AND oi.item_id = b.session_id
INNER JOIN #__memi_packages AS p
  ON p.id = COALESCE(
    oi.package_id,
    (
      SELECT MIN(p2.id)
      FROM #__memi_packages AS p2
      WHERE p2.price_cents = oi.unit_price_cents
        AND p2.credits = 1
        AND p2.published = 1
        AND p2.archived_at IS NULL
    )
  )
LEFT JOIN #__memi_customer_packages AS existing
  ON existing.order_id = b.order_id
WHERE b.source = 'square_direct'
  AND b.status IN ('confirmed', 'pending')
  AND existing.id IS NULL;

INSERT IGNORE INTO #__memi_credit_ledger (
  client_id,
  user_id,
  customer_package_id,
  order_id,
  entry_type,
  credits_delta,
  description,
  expires_at,
  idempotency_key,
  created_at,
  created_by
)
SELECT
  cp.client_id,
  cp.user_id,
  cp.id,
  cp.order_id,
  'direct_purchase',
  cp.original_credits,
  'Crédit acheté avec une séance',
  cp.expires_at,
  SHA2(CONCAT('direct-order:', cp.order_id, ':grant'), 256),
  cp.purchased_at,
  cp.user_id
FROM #__memi_customer_packages AS cp
INNER JOIN #__memi_bookings AS b
  ON b.order_id = cp.order_id
  AND b.source = 'square_direct'
INNER JOIN #__memi_orders AS o
  ON o.id = cp.order_id
  AND o.status = 'paid';

INSERT IGNORE INTO #__memi_credit_ledger (
  client_id,
  user_id,
  customer_package_id,
  booking_id,
  entry_type,
  credits_delta,
  description,
  idempotency_key,
  created_at,
  created_by
)
SELECT
  cp.client_id,
  cp.user_id,
  cp.id,
  b.id,
  'booking_use',
  -cp.original_credits,
  CONCAT('Crédit utilisé pour la séance #', b.session_id),
  SHA2(CONCAT('direct-order:', cp.order_id, ':booking:', b.id, ':migration-use'), 256),
  COALESCE(b.confirmed_at, b.booked_at, cp.purchased_at),
  cp.user_id
FROM #__memi_customer_packages AS cp
INNER JOIN #__memi_bookings AS b
  ON b.order_id = cp.order_id
  AND b.source = 'square_direct'
  AND b.status IN ('confirmed', 'pending')
INNER JOIN #__memi_orders AS o
  ON o.id = cp.order_id
  AND o.status = 'paid';

UPDATE #__memi_bookings AS b
INNER JOIN #__memi_customer_packages AS cp
  ON cp.order_id = b.order_id
SET
  b.customer_package_id = cp.id,
  b.updated_at = COALESCE(b.updated_at, UTC_TIMESTAMP())
WHERE b.source = 'square_direct'
  AND b.status IN ('confirmed', 'pending')
  AND b.customer_package_id IS NULL;
