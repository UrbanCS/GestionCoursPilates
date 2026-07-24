<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;

/**
 * Server-side Square integration. Browser code can receive only the public
 * application/location identifiers; all charges and secrets remain here.
 */
final class PaymentService
{
    public function __construct(
        private readonly DatabaseDriver $db,
        private readonly DatabaseTools $tools,
        private readonly SettingsService $settings,
        private readonly CreditLedgerService $credits,
        private readonly PointLedgerService $points,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications
    ) {
    }

    /**
     * Creates an immutable pending order for a package. Monetary values are
     * always integer cents, never floating point.
     *
     * @return array<string, mixed>
     */
    public function createPackageOrder(int $userId, int $packageId, ?string $promotionCode = null): array
    {
        return $this->tools->transaction(function () use ($userId, $packageId, $promotionCode): array {
            $profile = $this->tools->lockClientProfile($userId);
            $clientId = (int) $profile['id'];
            $package = $this->tools->lockById('#__memi_packages', $packageId);
            if (!$package || (int) $package['published'] !== 1) {
                throw new DomainException('COM_MEMIPILATES_ERROR_PACKAGE_NOT_FOUND', [], 404);
            }
            $now = gmdate('Y-m-d H:i:s');
            $subtotal = max(0, (int) $package['price_cents']);
            $promotion = $this->promotionForOrder($clientId, $packageId, $promotionCode, $subtotal);
            $discount = (int) $promotion['discount_cents'];
            $taxRateBasisPoints = max(0, (int) ($package['tax_rate_basis_points'] ?? 0));
            $taxable = max(0, $subtotal - $discount);
            $tax = (int) round($taxable * $taxRateBasisPoints / 10000);
            $total = $taxable + $tax;
            $orderKey = bin2hex(random_bytes(16));
            $user = $userId;
            $packageIdentifier = $packageId;
            $pendingStatus = 'pending';
            $currency = (string) $this->settings->get('currency', 'CAD');
            $promotionId = $promotion['id'];
            $normalisedPromotionCode = $promotion['code'];
            $query = $this->db->getQuery(true)
                ->insert($this->db->quoteName('#__memi_orders'))
                ->columns([
                    'client_id', 'user_id', 'promotion_id', 'status', 'currency', 'subtotal_cents', 'discount_cents', 'tax_cents',
                    'total_cents', 'promotion_code', 'order_key', 'created_at', 'updated_at',
                ])
                ->values(':client_id, :user_id, :promotion_id, :status, :currency, :subtotal_cents, :discount_cents, :tax_cents, :total_cents, :promotion_code, :order_key, :created_at, :updated_at')
                ->bind(':client_id', $clientId, ParameterType::INTEGER)
                ->bind(':user_id', $user, ParameterType::INTEGER)
                ->bind(':promotion_id', $promotionId, ParameterType::INTEGER)
                ->bind(':status', $pendingStatus)
                ->bind(':currency', $currency)
                ->bind(':subtotal_cents', $subtotal, ParameterType::INTEGER)
                ->bind(':discount_cents', $discount, ParameterType::INTEGER)
                ->bind(':tax_cents', $tax, ParameterType::INTEGER)
                ->bind(':total_cents', $total, ParameterType::INTEGER)
                ->bind(':promotion_code', $normalisedPromotionCode)
                ->bind(':order_key', $orderKey)
                ->bind(':created_at', $now)
                ->bind(':updated_at', $now);
            $this->db->setQuery($query)->execute();
            $orderId = (int) $this->db->insertid();
            $order = $orderId;
            $packageItemType = 'package';
            $titleSnapshot = (string) $package['title'];
            $quantity = 1;
            $item = $this->db->getQuery(true)
                ->insert($this->db->quoteName('#__memi_order_items'))
                ->columns(['order_id', 'item_type', 'package_id', 'title_snapshot', 'quantity', 'unit_price_cents', 'total_cents', 'created_at'])
                ->values(':order_id, :item_type, :package_id, :title_snapshot, :quantity, :unit_price_cents, :total_cents, :created_at')
                ->bind(':order_id', $order, ParameterType::INTEGER)
                ->bind(':item_type', $packageItemType)
                ->bind(':package_id', $packageIdentifier, ParameterType::INTEGER)
                ->bind(':title_snapshot', $titleSnapshot)
                ->bind(':quantity', $quantity, ParameterType::INTEGER)
                ->bind(':unit_price_cents', $subtotal, ParameterType::INTEGER)
                ->bind(':total_cents', $subtotal, ParameterType::INTEGER)
                ->bind(':created_at', $now);
            $this->db->setQuery($item)->execute();
            $this->audit->log($userId, 'order.create', 'order', $orderId, null, [
                'package_id' => $packageId,
                'total_cents' => $total,
            ]);

            return [
                'id' => $orderId,
                'order_key' => $orderKey,
                'total_cents' => $total,
                'currency' => (string) $this->settings->get('currency', 'CAD'),
                'square_application_id' => $this->publicApplicationId(),
                'square_location_id' => $this->publicLocationId(),
            ];
        });
    }

    /**
     * Creates a pending order and atomically holds one place in a specific
     * session. The hold becomes a confirmed booking only after Square reports
     * COMPLETED; a definitive failure or an expired hold releases capacity.
     *
     * @return array<string,mixed>
     */
    public function createSessionOrder(int $userId, int $sessionId): array
    {
        if ($userId <= 0 || $sessionId <= 0) {
            throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
        }
        if ($this->publicApplicationId() === '' || $this->publicLocationId() === '' || $this->squareAccessToken() === '') {
            throw new DomainException('COM_MEMIPILATES_ERROR_SQUARE_NOT_CONFIGURED', [], 503);
        }

        return $this->tools->transaction(function () use ($userId, $sessionId): array {
            $profile = $this->tools->lockClientProfile($userId);
            $clientId = (int) $profile['id'];
            $session = $this->lockSessionForPurchase($sessionId);
            $this->assertSessionPurchasable($session);

            $existing = $this->findBookingForUpdate($userId, $sessionId);
            if ($existing && in_array((string) $existing['status'], ['confirmed', 'pending', 'attended'], true)) {
                throw new DomainException('COM_MEMIPILATES_ERROR_ALREADY_BOOKED');
            }
            if ($existing && (string) $existing['status'] === 'payment_pending' && (int) ($existing['order_id'] ?? 0) > 0) {
                $existingOrder = $this->tools->lockById('#__memi_orders', (int) $existing['order_id']);
                if ($existingOrder && in_array((string) $existingOrder['status'], ['pending', 'payment_processing'], true)) {
                    return $this->sessionCheckoutPayload($existingOrder, $session, (int) $existing['id']);
                }
            }

            $subtotal = max(0, (int) $session['price_cents']);
            if ($subtotal <= 0) {
                throw new DomainException('COM_MEMIPILATES_ERROR_DIRECT_PAYMENT_UNAVAILABLE');
            }
            $taxRateBasisPoints = max(0, (int) ($session['tax_rate_basis_points'] ?? 0));
            $tax = (int) round($subtotal * $taxRateBasisPoints / 10000);
            $total = $subtotal + $tax;
            $currency = strtoupper((string) $this->settings->get('currency', 'CAD'));
            if (!preg_match('/^[A-Z]{3}$/D', $currency)) {
                $currency = 'CAD';
            }
            $now = gmdate('Y-m-d H:i:s');
            $orderKey = bin2hex(random_bytes(16));
            $pending = 'pending';
            $orderUser = $userId;
            $insertOrder = $this->db->getQuery(true)
                ->insert($this->db->quoteName('#__memi_orders'))
                ->columns([
                    'client_id', 'user_id', 'status', 'currency', 'subtotal_cents', 'discount_cents',
                    'tax_cents', 'total_cents', 'order_key', 'created_at', 'updated_at',
                ])
                ->values(':client_id, :user_id, :status, :currency, :subtotal_cents, 0, :tax_cents, :total_cents, :order_key, :created_at, :updated_at')
                ->bind(':client_id', $clientId, ParameterType::INTEGER)
                ->bind(':user_id', $orderUser, ParameterType::INTEGER)
                ->bind(':status', $pending)
                ->bind(':currency', $currency)
                ->bind(':subtotal_cents', $subtotal, ParameterType::INTEGER)
                ->bind(':tax_cents', $tax, ParameterType::INTEGER)
                ->bind(':total_cents', $total, ParameterType::INTEGER)
                ->bind(':order_key', $orderKey)
                ->bind(':created_at', $now)
                ->bind(':updated_at', $now);
            $this->db->setQuery($insertOrder)->execute();
            $orderId = (int) $this->db->insertid();

            if (!$this->claimCapacity($sessionId)) {
                throw new DomainException('COM_MEMIPILATES_ERROR_SESSION_FULL');
            }

            $bookingKey = bin2hex(random_bytes(16));
            $activeBookingKey = hash('sha256', $sessionId . ':' . $userId);
            $bookingStatus = 'payment_pending';
            $source = 'square_direct';
            $sessionIdentifier = $sessionId;
            if ($existing) {
                $bookingId = (int) $existing['id'];
                $bookingIdentifier = $bookingId;
                $updateBooking = $this->db->getQuery(true)
                    ->update($this->db->quoteName('#__memi_bookings'))
                    ->set($this->db->quoteName('order_id') . ' = :order_id')
                    ->set($this->db->quoteName('status') . ' = :status')
                    ->set($this->db->quoteName('booking_key') . ' = :booking_key')
                    ->set($this->db->quoteName('active_booking_key') . ' = :active_booking_key')
                    ->set($this->db->quoteName('source') . ' = :source')
                    ->set($this->db->quoteName('booked_at') . ' = :booked_at')
                    ->set($this->db->quoteName('confirmed_at') . ' = NULL')
                    ->set($this->db->quoteName('cancelled_at') . ' = NULL')
                    ->set($this->db->quoteName('cancelled_by') . ' = 0')
                    ->set($this->db->quoteName('cancellation_reason') . ' = NULL')
                    ->set($this->db->quoteName('credit_restored_at') . ' = NULL')
                    ->set($this->db->quoteName('updated_at') . ' = :updated_at')
                    ->where($this->db->quoteName('id') . ' = :id')
                    ->bind(':order_id', $orderId, ParameterType::INTEGER)
                    ->bind(':status', $bookingStatus)
                    ->bind(':booking_key', $bookingKey)
                    ->bind(':active_booking_key', $activeBookingKey)
                    ->bind(':source', $source)
                    ->bind(':booked_at', $now)
                    ->bind(':updated_at', $now)
                    ->bind(':id', $bookingIdentifier, ParameterType::INTEGER);
                $this->db->setQuery($updateBooking)->execute();
            } else {
                $bookingUser = $userId;
                $insertBooking = $this->db->getQuery(true)
                    ->insert($this->db->quoteName('#__memi_bookings'))
                    ->columns([
                        'session_id', 'client_id', 'user_id', 'order_id', 'booking_key', 'active_booking_key',
                        'status', 'source', 'booked_at', 'created_at', 'updated_at',
                    ])
                    ->values(':session_id, :client_id, :user_id, :order_id, :booking_key, :active_booking_key, :status, :source, :booked_at, :created_at, :updated_at')
                    ->bind(':session_id', $sessionIdentifier, ParameterType::INTEGER)
                    ->bind(':client_id', $clientId, ParameterType::INTEGER)
                    ->bind(':user_id', $bookingUser, ParameterType::INTEGER)
                    ->bind(':order_id', $orderId, ParameterType::INTEGER)
                    ->bind(':booking_key', $bookingKey)
                    ->bind(':active_booking_key', $activeBookingKey)
                    ->bind(':status', $bookingStatus)
                    ->bind(':source', $source)
                    ->bind(':booked_at', $now)
                    ->bind(':created_at', $now)
                    ->bind(':updated_at', $now);
                $this->db->setQuery($insertBooking)->execute();
                $bookingId = (int) $this->db->insertid();
            }

            $itemType = 'session';
            $title = (string) $session['course_title'];
            $itemMetadata = json_encode([
                'booking_id' => $bookingId,
                'starts_at' => (string) $session['starts_at'],
            ], JSON_THROW_ON_ERROR);
            $insertItem = $this->db->getQuery(true)
                ->insert($this->db->quoteName('#__memi_order_items'))
                ->columns([
                    'order_id', 'item_type', 'item_id', 'title_snapshot', 'quantity',
                    'unit_price_cents', 'tax_cents', 'total_cents', 'metadata', 'created_at',
                ])
                ->values(':order_id, :item_type, :item_id, :title_snapshot, 1, :unit_price_cents, :tax_cents, :total_cents, :metadata, :created_at')
                ->bind(':order_id', $orderId, ParameterType::INTEGER)
                ->bind(':item_type', $itemType)
                ->bind(':item_id', $sessionIdentifier, ParameterType::INTEGER)
                ->bind(':title_snapshot', $title)
                ->bind(':unit_price_cents', $subtotal, ParameterType::INTEGER)
                ->bind(':tax_cents', $tax, ParameterType::INTEGER)
                ->bind(':total_cents', $subtotal, ParameterType::INTEGER)
                ->bind(':metadata', $itemMetadata)
                ->bind(':created_at', $now);
            $this->db->setQuery($insertItem)->execute();

            $this->audit->log($userId, 'order.session.create', 'order', $orderId, null, [
                'session_id' => $sessionId,
                'booking_id' => $bookingId,
                'total_cents' => $total,
            ]);

            return $this->sessionCheckoutPayload([
                'id' => $orderId,
                'order_key' => $orderKey,
                'total_cents' => $total,
                'currency' => $currency,
            ], $session, $bookingId);
        });
    }

    /**
     * Charges a Square token and fulfills the order only after Square reports
     * COMPLETED. A duplicate request returns the existing successful payment.
     *
     * @return array<string, mixed>
     */
    public function payOrder(int $userId, int $orderId, string $sourceId, string $idempotencyKey): array
    {
        if ($sourceId === '' || strlen($sourceId) > 512 || $idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_PAYMENT_REQUEST');
        }

        $attempt = $this->claimPaymentAttempt($userId, $orderId, $idempotencyKey);
        if ($attempt['already_paid']) {
            return $this->paymentForOrder($orderId) ?? ['order_id' => $orderId, 'status' => 'paid'];
        }
        $order = $attempt['order'];
        $paymentKey = $attempt['payment_key'];

        // Zero-total orders never call Square, yet they follow the same
        // idempotent fulfillment transaction.
        try {
            $squarePayment = (int) $order['total_cents'] === 0
                ? ['id' => 'zero-' . $orderId, 'status' => 'COMPLETED', 'receipt_url' => null, 'card_details' => null]
                : $this->squareCharge($order, $sourceId, $paymentKey);
        } catch (\Throwable $error) {
            $definitiveLocalFailure = $this->isDefinitivePaymentFailure($error);
            if ($definitiveLocalFailure) {
                $this->failOrderAfterPaymentAttempt($orderId, 'provider_rejected');
            } else {
                // A timeout/5xx does not prove that Square rejected the
                // charge. Keep the attempt and promotion claim in processing
                // so a delayed webhook cannot create a double charge or an
                // over-redemption window.
                $this->audit->log($userId, 'payment.transport_unknown', 'order', $orderId, null, []);
            }

            throw $error;
        }

        if (($squarePayment['status'] ?? '') !== 'COMPLETED') {
            $providerStatus = strtoupper((string) ($squarePayment['status'] ?? 'unknown'));
            if (in_array($providerStatus, ['FAILED', 'CANCELED', 'CANCELLED'], true)) {
                $this->failOrderAfterPaymentAttempt($orderId, $providerStatus);
            }
            throw new DomainException('COM_MEMIPILATES_ERROR_PAYMENT_FAILED');
        }

        return $this->tools->transaction(function () use ($userId, $orderId, $squarePayment, $paymentKey): array {
            $order = $this->tools->lockById('#__memi_orders', $orderId);
            if (!$order || (int) $order['user_id'] !== $userId) {
                throw new DomainException('COM_MEMIPILATES_ERROR_ORDER_NOT_FOUND', [], 404);
            }
            if ((string) $order['status'] === 'paid') {
                return $this->paymentForOrder($orderId) ?? ['order_id' => $orderId, 'status' => 'paid'];
            }
            if ((string) $order['status'] !== 'payment_processing') {
                throw new DomainException('COM_MEMIPILATES_ERROR_ORDER_NOT_PAYABLE');
            }

            $paymentId = $this->insertPayment($order, $squarePayment, $paymentKey);
            $now = gmdate('Y-m-d H:i:s');
            $id = $orderId;
            $status = 'paid';
            $update = $this->db->getQuery(true)
                ->update($this->db->quoteName('#__memi_orders'))
                ->set($this->db->quoteName('status') . ' = :status')
                ->set($this->db->quoteName('paid_at') . ' = :paid_at')
                ->set($this->db->quoteName('updated_at') . ' = :updated_at')
                ->where($this->db->quoteName('id') . ' = :id')
                ->bind(':status', $status)
                ->bind(':paid_at', $now)
                ->bind(':updated_at', $now)
                ->bind(':id', $id, ParameterType::INTEGER);
            $this->db->setQuery($update)->execute();

            $fulfillment = $this->fulfillOrderItems($userId, $orderId);
            $this->audit->log($userId, 'payment.complete', 'payment', $paymentId, null, [
                'order_id' => $orderId,
                'amount_cents' => (int) $order['total_cents'],
            ]);

            return array_merge([
                'order_id' => $orderId,
                'payment_id' => $paymentId,
                'status' => 'paid',
                'receipt_url' => $squarePayment['receipt_url'] ?? null,
            ], $fulfillment);
        });
    }

    /**
     * Obtains the single server-side payment attempt for an order before any
     * provider request is issued. This closes the double-click/concurrent-tab
     * window that otherwise could create two card charges.
     *
     * @return array{already_paid:bool,order:array<string,mixed>,payment_key:string}
     */
    private function claimPaymentAttempt(int $userId, int $orderId, string $requestedKey): array
    {
        return $this->tools->transaction(function () use ($userId, $orderId, $requestedKey): array {
            $order = $this->tools->lockById('#__memi_orders', $orderId);
            if (!$order || (int) $order['user_id'] !== $userId) {
                throw new DomainException('COM_MEMIPILATES_ERROR_ORDER_NOT_FOUND', [], 404);
            }
            if ((string) $order['status'] === 'paid') {
                return [
                    'already_paid' => true,
                    'order' => $order,
                    'payment_key' => (string) ($order['idempotency_key'] ?: $requestedKey),
                ];
            }
            if (!in_array((string) $order['status'], ['pending', 'payment_failed'], true)) {
                throw new DomainException('COM_MEMIPILATES_ERROR_ORDER_NOT_PAYABLE');
            }

            $this->prepareSessionOrderForPayment($order);
            $this->assertPromotionClaimAvailable($order);

            // A pending order has never reached Square and payment_failed is a
            // definitive provider/local rejection. Each claim therefore gets
            // a fresh server-generated key. The browser key is only an input
            // nonce; row locking remains the protection against double-clicks.
            $paymentKey = $this->newSquareIdempotencyKey('pay', $orderId, $requestedKey);
            $now = gmdate('Y-m-d H:i:s');
            $id = $orderId;
            $processing = 'payment_processing';
            $update = $this->db->getQuery(true)
                ->update($this->db->quoteName('#__memi_orders'))
                ->set($this->db->quoteName('status') . ' = :status')
                ->set($this->db->quoteName('idempotency_key') . ' = :idempotency_key')
                ->set($this->db->quoteName('updated_at') . ' = :updated_at')
                ->where($this->db->quoteName('id') . ' = :id')
                ->bind(':status', $processing)
                ->bind(':idempotency_key', $paymentKey)
                ->bind(':updated_at', $now)
                ->bind(':id', $id, ParameterType::INTEGER);
            $this->db->setQuery($update)->execute();
            $order['status'] = $processing;
            $order['idempotency_key'] = $paymentKey;

            return ['already_paid' => false, 'order' => $order, 'payment_key' => $paymentKey];
        });
    }

    /** Verify, record and process an inbound Square webhook exactly once. */
    public function handleWebhook(string $rawBody, string $signature, string $notificationUrl): void
    {
        if (!$this->verifyWebhookSignature($rawBody, $signature, $notificationUrl)) {
            $hash = hash('sha256', $rawBody);
            $this->recordWebhookFailure('invalid-' . substr($hash, 0, 64), 'unknown', $hash, false, 'invalid_signature');
            throw new DomainException('COM_MEMIPILATES_ERROR_WEBHOOK_SIGNATURE', [], 401);
        }
        $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        $eventId = (string) ($payload['event_id'] ?? '');
        if ($eventId === '' || strlen($eventId) > 128) {
            throw new DomainException('COM_MEMIPILATES_ERROR_WEBHOOK_INVALID', [], 400);
        }
        $type = mb_substr((string) ($payload['type'] ?? 'unknown'), 0, 128);
        $hash = hash('sha256', $rawBody);

        try {
            $this->tools->transaction(function () use ($payload, $eventId, $type, $hash): void {
                $event = $eventId;
                $find = $this->db->getQuery(true)
                    ->select(['id', 'status'])
                    ->from($this->db->quoteName('#__memi_square_webhooks'))
                    ->where($this->db->quoteName('event_id') . ' = :event_id')
                    ->bind(':event_id', $event);
                $this->db->setQuery(DatabaseTools::forUpdate($find));
                $existing = $this->db->loadAssoc();
                $now = gmdate('Y-m-d H:i:s');
                $signatureValid = 1;
                $processing = 'processing';

                if ($existing) {
                    $identifier = (int) $existing['id'];
                    $attempt = $this->db->getQuery(true)
                        ->update($this->db->quoteName('#__memi_square_webhooks'))
                        ->set($this->db->quoteName('attempt_count') . ' = LEAST(65535, ' . $this->db->quoteName('attempt_count') . ' + 1)')
                        ->set($this->db->quoteName('signature_valid') . ' = :signature_valid')
                        ->set($this->db->quoteName('updated_at') . ' = :updated_at')
                        ->where($this->db->quoteName('id') . ' = :id')
                        ->bind(':signature_valid', $signatureValid, ParameterType::INTEGER)
                        ->bind(':updated_at', $now)
                        ->bind(':id', $identifier, ParameterType::INTEGER);
                    if ((string) $existing['status'] !== 'processed') {
                        $attempt->set($this->db->quoteName('status') . ' = :status')->bind(':status', $processing);
                    }
                    $this->db->setQuery($attempt)->execute();
                    if ((string) $existing['status'] === 'processed') {
                        return;
                    }
                } else {
                    $attemptCount = 1;
                    $insert = $this->db->getQuery(true)
                        ->insert($this->db->quoteName('#__memi_square_webhooks'))
                        ->columns(['event_id', 'square_event_id', 'event_type', 'signature_valid', 'payload_hash', 'status', 'received_at', 'attempt_count', 'created_at', 'updated_at'])
                        ->values(':event_id, :square_event_id, :event_type, :signature_valid, :payload_hash, :status, :received_at, :attempt_count, :created_at, :updated_at')
                        ->bind(':event_id', $event)
                        ->bind(':square_event_id', $event)
                        ->bind(':event_type', $type)
                        ->bind(':signature_valid', $signatureValid, ParameterType::INTEGER)
                        ->bind(':payload_hash', $hash)
                        ->bind(':status', $processing)
                        ->bind(':received_at', $now)
                        ->bind(':attempt_count', $attemptCount, ParameterType::INTEGER)
                        ->bind(':created_at', $now)
                        ->bind(':updated_at', $now);
                    $this->db->setQuery($insert)->execute();
                }

                $payment = $payload['data']['object']['payment'] ?? null;
                if (is_array($payment) && isset($payment['id'])) {
                    $this->syncPaymentStatus((string) $payment['id'], (string) ($payment['status'] ?? ''));
                    $this->reconcileCompletedWebhookPayment($payment);
                }
                $refund = $payload['data']['object']['refund'] ?? null;
                if (is_array($refund) && isset($refund['id'])) {
                    $this->syncRefundWebhook($refund);
                }

                $processed = 'processed';
                $finished = $this->db->getQuery(true)
                    ->update($this->db->quoteName('#__memi_square_webhooks'))
                    ->set($this->db->quoteName('status') . ' = :status')
                    ->set($this->db->quoteName('processed_at') . ' = :processed_at')
                    ->set($this->db->quoteName('error_message') . " = ''")
                    ->set($this->db->quoteName('updated_at') . ' = :updated_at')
                    ->where($this->db->quoteName('event_id') . ' = :event_id')
                    ->bind(':status', $processed)
                    ->bind(':processed_at', $now)
                    ->bind(':updated_at', $now)
                    ->bind(':event_id', $event);
                $this->db->setQuery($finished)->execute();
            });
        } catch (\Throwable $error) {
            $this->recordWebhookFailure($eventId, $type, $hash, true, 'processing_error');

            throw $error;
        }
    }

    /** @return array{application_id:string,location_id:string,environment:string} */
    public function clientConfiguration(): array
    {
        return [
            'application_id' => $this->publicApplicationId(),
            'location_id' => $this->publicLocationId(),
            'environment' => $this->isSandbox() ? 'sandbox' : 'production',
        ];
    }

    /**
     * Releases abandoned direct-payment holds that never reached Square.
     * Attempts already in payment_processing are deliberately excluded and
     * remain protected until provider reconciliation determines their result.
     */
    public function expirePendingSessionOrders(int $limit = 100): int
    {
        $limit = max(1, min($limit, 500));
        $holdMinutes = max(5, min($this->settings->getInt('direct_payment_hold_minutes', 15), 120));
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($holdMinutes * 60));
        $pendingOrder = 'pending';
        $pendingBooking = 'payment_pending';
        $query = $this->db->getQuery(true)
            ->select('o.id')
            ->from($this->db->quoteName('#__memi_orders', 'o'))
            ->join('INNER', $this->db->quoteName('#__memi_bookings', 'b') . ' ON b.order_id = o.id')
            ->where('o.status = :order_status')
            ->where('b.status = :booking_status')
            ->where('o.created_at <= :cutoff')
            ->order('o.created_at ASC, o.id ASC')
            ->bind(':order_status', $pendingOrder)
            ->bind(':booking_status', $pendingBooking)
            ->bind(':cutoff', $cutoff);
        $this->db->setQuery($query, 0, $limit);
        $orderIds = array_map('intval', $this->db->loadColumn() ?: []);
        $expired = 0;

        foreach ($orderIds as $orderId) {
            $changed = $this->tools->transaction(function () use ($orderId, $cutoff): bool {
                $order = $this->tools->lockById('#__memi_orders', $orderId);
                if (!$order || (string) $order['status'] !== 'pending' || (string) $order['created_at'] > $cutoff) {
                    return false;
                }
                $booking = $this->bookingForOrderForUpdate($orderId);
                if (!$booking || (string) $booking['status'] !== 'payment_pending') {
                    return false;
                }

                $now = gmdate('Y-m-d H:i:s');
                $expiredOrder = 'expired';
                $failureReason = 'payment_hold_expired';
                $orderIdentifier = $orderId;
                $orderUpdate = $this->db->getQuery(true)
                    ->update($this->db->quoteName('#__memi_orders'))
                    ->set($this->db->quoteName('status') . ' = :status')
                    ->set($this->db->quoteName('failed_at') . ' = :failed_at')
                    ->set($this->db->quoteName('failure_reason') . ' = :failure_reason')
                    ->set($this->db->quoteName('updated_at') . ' = :updated_at')
                    ->where($this->db->quoteName('id') . ' = :id')
                    ->bind(':status', $expiredOrder)
                    ->bind(':failed_at', $now)
                    ->bind(':failure_reason', $failureReason)
                    ->bind(':updated_at', $now)
                    ->bind(':id', $orderIdentifier, ParameterType::INTEGER);
                $this->db->setQuery($orderUpdate)->execute();

                $bookingIdentifier = (int) $booking['id'];
                $expiredBooking = 'payment_expired';
                $bookingUpdate = $this->db->getQuery(true)
                    ->update($this->db->quoteName('#__memi_bookings'))
                    ->set($this->db->quoteName('status') . ' = :status')
                    ->set($this->db->quoteName('active_booking_key') . ' = NULL')
                    ->set($this->db->quoteName('cancelled_at') . ' = :cancelled_at')
                    ->set($this->db->quoteName('cancellation_reason') . ' = :reason')
                    ->set($this->db->quoteName('updated_at') . ' = :updated_at')
                    ->where($this->db->quoteName('id') . ' = :id')
                    ->bind(':status', $expiredBooking)
                    ->bind(':cancelled_at', $now)
                    ->bind(':reason', $failureReason)
                    ->bind(':updated_at', $now)
                    ->bind(':id', $bookingIdentifier, ParameterType::INTEGER);
                $this->db->setQuery($bookingUpdate)->execute();
                $this->releaseCapacity((int) $booking['session_id']);
                $this->audit->log(null, 'order.session.expire', 'order', $orderId, $order, [
                    'booking_id' => $bookingIdentifier,
                    'session_id' => (int) $booking['session_id'],
                ]);

                return true;
            });
            $expired += $changed ? 1 : 0;
        }

        return $expired;
    }

    /**
     * Refunds the remaining captured amount of a Square payment. The local
     * processing row is claimed under the payment lock before the provider is
     * called, preventing two administrators from refunding the same balance.
     * Credits and package entitlements are deliberately not revoked here: a
     * financial refund and an entitlement adjustment are separate audited
     * business decisions.
     *
     * @return array{id:int,status:string,amount_cents:int,provider_refund_id:?string}
     */
    public function refundPayment(int $actorId, int $paymentId, string $reason): array
    {
        $reason = trim(mb_substr($reason, 0, 500));
        if ($actorId <= 0 || $paymentId <= 0) {
            throw new DomainException('COM_MEMIPILATES_ERROR_REFUND_NOT_AVAILABLE');
        }
        if ($reason === '') {
            throw new DomainException('COM_MEMIPILATES_ERROR_REFUND_REASON_REQUIRED');
        }

        try {
            $claim = $this->tools->transaction(function () use ($actorId, $paymentId, $reason): array {
                $payment = $this->tools->lockById('#__memi_payments', $paymentId);
                if (!$payment || (string) $payment['provider'] !== 'square'
                    || (string) $payment['status'] !== 'completed'
                    || (string) ($payment['provider_payment_id'] ?? '') === '') {
                    throw new DomainException('COM_MEMIPILATES_ERROR_REFUND_NOT_AVAILABLE');
                }

                $identifier = $paymentId;
                $refundRows = $this->db->getQuery(true)
                    ->select(['amount_cents', 'status'])
                    ->from($this->db->quoteName('#__memi_refunds'))
                    ->where($this->db->quoteName('payment_id') . ' = :payment_id')
                    ->bind(':payment_id', $identifier, ParameterType::INTEGER);
                $this->db->setQuery(DatabaseTools::forUpdate($refundRows));
                $reserved = 0;
                $manualRefundRequired = false;
                foreach ($this->db->loadAssocList() ?: [] as $refund) {
                    $refundStatus = (string) $refund['status'];
                    if (in_array($refundStatus, ['failed', 'rejected'], true)) {
                        // Square requires an alternate/manual refund after a
                        // provider refund reaches FAILED or REJECTED. The
                        // balance must be resolved manually instead of issuing a
                        // fresh idempotency key and risking an inconsistent state.
                        $manualRefundRequired = true;
                    }
                    if (in_array($refundStatus, ['pending', 'processing', 'completed'], true)) {
                        $reserved += max(0, (int) $refund['amount_cents']);
                    }
                }
                if ($manualRefundRequired) {
                    throw new DomainException('COM_MEMIPILATES_ERROR_REFUND_MANUAL_REQUIRED');
                }
                $remaining = max(0, (int) $payment['amount_cents'] - $reserved);
                if ($remaining <= 0) {
                    throw new DomainException('COM_MEMIPILATES_ERROR_REFUND_NOT_AVAILABLE');
                }

                $now = gmdate('Y-m-d H:i:s');
                $orderId = (int) $payment['order_id'];
                $idempotencyKey = $this->newSquareIdempotencyKey('refund', $paymentId);
                $processing = 'processing';
                $insert = $this->db->getQuery(true)
                    ->insert($this->db->quoteName('#__memi_refunds'))
                    ->columns(['payment_id', 'order_id', 'idempotency_key', 'status', 'amount_cents', 'reason', 'requested_by', 'requested_at', 'created_at', 'updated_at'])
                    ->values(':payment_id, :order_id, :idempotency_key, :status, :amount_cents, :reason, :requested_by, :requested_at, :created_at, :updated_at')
                    ->bind(':payment_id', $identifier, ParameterType::INTEGER)
                    ->bind(':order_id', $orderId, ParameterType::INTEGER)
                    ->bind(':idempotency_key', $idempotencyKey)
                    ->bind(':status', $processing)
                    ->bind(':amount_cents', $remaining, ParameterType::INTEGER)
                    ->bind(':reason', $reason)
                    ->bind(':requested_by', $actorId, ParameterType::INTEGER)
                    ->bind(':requested_at', $now)
                    ->bind(':created_at', $now)
                    ->bind(':updated_at', $now);
                $this->db->setQuery($insert)->execute();

                return [
                    'id' => (int) $this->db->insertid(),
                    'payment_id' => $paymentId,
                    'order_id' => $orderId,
                    'provider_payment_id' => (string) $payment['provider_payment_id'],
                    'idempotency_key' => $idempotencyKey,
                    'amount_cents' => $remaining,
                    'currency' => strtoupper((string) $payment['currency']),
                    'reason' => $reason,
                ];
            });
        } catch (DomainException $error) {
            if ($error->getMessage() === 'COM_MEMIPILATES_ERROR_REFUND_MANUAL_REQUIRED') {
                $this->audit->log($actorId, 'refund.manual_required', 'payment', $paymentId, null, [
                    'existing_terminal_refund' => true,
                ]);
            }
            throw $error;
        }

        $accessToken = $this->squareAccessToken();
        if ($accessToken === '') {
            // No provider call was made. Keep the immutable refund claim so it
            // can be replayed after configuration is repaired.
            throw new DomainException('COM_MEMIPILATES_ERROR_SQUARE_NOT_CONFIGURED', [], 503);
        }
        try {
            $response = $this->request(
                'POST',
                $this->squareBaseUrl() . '/v2/refunds',
                $this->squareRefundPayload($claim),
                $this->squareHeaders($accessToken)
            );
        } catch (\Throwable $error) {
            // A transport failure is not proof that Square did not receive the
            // request. Preserve the row and original key/body for scheduler
            // reconciliation rather than enabling another refund.
            throw $error;
        }

        if ($response['status'] < 200 || $response['status'] >= 300 || !isset($response['json']['refund'])) {
            $this->audit->log($actorId, 'refund.square_error', 'refund', (int) $claim['id'], null, [
                'http_status' => $response['status'],
                'error_count' => count($response['json']['errors'] ?? []),
            ]);
            // Even 4xx responses can be retryable (notably 429), while an
            // idempotency/authentication error can be operationally ambiguous.
            // Keep processing and let the scheduler replay this exact request.
            throw new DomainException('COM_MEMIPILATES_ERROR_PAYMENT_FAILED');
        }

        /** @var array<string,mixed> $refund */
        $refund = $response['json']['refund'];
        $providerRefundId = (string) ($refund['id'] ?? '');
        $providerPaymentId = (string) ($refund['payment_id'] ?? '');
        $amount = (int) ($refund['amount_money']['amount'] ?? -1);
        $currency = strtoupper((string) ($refund['amount_money']['currency'] ?? ''));
        if ($providerRefundId === '' || $providerPaymentId !== $claim['provider_payment_id']
            || $amount !== (int) $claim['amount_cents'] || $currency !== $claim['currency']) {
            $this->audit->log($actorId, 'refund.provider_mismatch', 'refund', (int) $claim['id'], null, [
                'payment_matches' => $providerPaymentId === $claim['provider_payment_id'],
                'amount_matches' => $amount === (int) $claim['amount_cents'],
                'currency_matches' => $currency === $claim['currency'],
            ]);
            throw new DomainException('COM_MEMIPILATES_ERROR_REFUND_PROVIDER_MISMATCH');
        }

        $status = $this->normaliseRefundStatus((string) ($refund['status'] ?? 'PENDING'));
        $this->updateRefundResult((int) $claim['id'], $providerRefundId, $status);
        $this->audit->log($actorId, 'refund.request', 'refund', (int) $claim['id'], null, [
            'payment_id' => $paymentId,
            'order_id' => (int) $claim['order_id'],
            'amount_cents' => (int) $claim['amount_cents'],
            'status' => $status,
        ]);

        return [
            'id' => (int) $claim['id'],
            'status' => $status,
            'amount_cents' => (int) $claim['amount_cents'],
            'provider_refund_id' => $providerRefundId,
        ];
    }

    /**
     * Reconcile card attempts whose CreatePayment response was lost. Square's
     * immutable reference locates a completed/failed payment. If no payment is
     * visible, CancelPaymentByIdempotencyKey safely cancels the attempt (or is
     * a no-op when Square never received it) before a fresh card is allowed.
     */
    public function reconcileUncertainPayments(int $limit = 25): int
    {
        $accessToken = $this->squareAccessToken();
        $locationId = $this->publicLocationId();
        if ($accessToken === '' || $locationId === '') {
            return 0;
        }

        $limit = max(1, min($limit, 100));
        $processing = 'payment_processing';
        $cutoff = gmdate('Y-m-d H:i:s', time() - 120);
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__memi_orders'))
            ->where($this->db->quoteName('status') . ' = :status')
            ->where($this->db->quoteName('updated_at') . ' <= :cutoff')
            ->order($this->db->quoteName('updated_at') . ' ASC')
            ->bind(':status', $processing)
            ->bind(':cutoff', $cutoff);
        $this->db->setQuery($query, 0, $limit);
        $orderIds = array_map('intval', $this->db->loadColumn() ?: []);
        $reconciled = 0;

        foreach ($orderIds as $orderId) {
            try {
                $order = $this->orderForReconciliation($orderId);
                if ($order === null || (string) $order['status'] !== $processing) {
                    continue;
                }
                $paymentKey = (string) ($order['idempotency_key'] ?? '');
                if ($paymentKey === '') {
                    continue;
                }

                $payment = $this->findSquarePaymentForOrder($order, $accessToken, $locationId);
                if ($payment !== null) {
                    $providerStatus = strtoupper((string) ($payment['status'] ?? ''));
                    if ($providerStatus === 'COMPLETED') {
                        $this->tools->transaction(function () use ($payment): void {
                            $this->reconcileCompletedWebhookPayment($payment);
                        });
                        $after = $this->orderForReconciliation($orderId);
                        if ((string) ($after['status'] ?? '') === 'paid') {
                            ++$reconciled;
                        }
                        continue;
                    }
                    if (in_array($providerStatus, ['FAILED', 'CANCELED', 'CANCELLED'], true)) {
                        if ($this->failReconciledPaymentAttempt($orderId, $paymentKey, $providerStatus)) {
                            ++$reconciled;
                        }
                        continue;
                    }
                }

                // For APPROVED/PENDING or no visible payment, this endpoint is
                // Square's documented recovery path for an unknown response.
                if ($this->cancelSquarePaymentAttempt($paymentKey, $accessToken)
                    && $this->failReconciledPaymentAttempt($orderId, $paymentKey, 'reconciled_cancelled')) {
                    ++$reconciled;
                }
            } catch (\Throwable $error) {
                $this->audit->log(null, 'payment.reconcile_error', 'order', $orderId, null, [
                    'error_code' => (int) $error->getCode(),
                ]);
            }
        }

        return $reconciled;
    }

    /**
     * Replays an uncertain RefundPayment with its original immutable key and
     * fields, or polls a known provider refund. Square returns the same refund
     * for a repeated key, so parallel scheduler runs cannot issue it twice.
     */
    public function reconcileUncertainRefunds(int $limit = 25): int
    {
        $accessToken = $this->squareAccessToken();
        if ($accessToken === '') {
            return 0;
        }

        $limit = max(1, min($limit, 100));
        $processing = 'processing';
        $pending = 'pending';
        $cutoff = gmdate('Y-m-d H:i:s', time() - 300);
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__memi_refunds'))
            ->where($this->db->quoteName('status') . ' IN (:processing_status, :pending_status)')
            ->where($this->db->quoteName('updated_at') . ' <= :cutoff')
            ->order($this->db->quoteName('updated_at') . ' ASC')
            ->bind(':processing_status', $processing)
            ->bind(':pending_status', $pending)
            ->bind(':cutoff', $cutoff);
        $this->db->setQuery($query, 0, $limit);
        $refundIds = array_map('intval', $this->db->loadColumn() ?: []);
        $reconciled = 0;

        foreach ($refundIds as $refundId) {
            $refundKey = '';
            try {
                $refund = $this->refundForReconciliation($refundId);
                if ($refund === null || !in_array((string) $refund['status'], [$processing, $pending], true)) {
                    continue;
                }
                $refundKey = (string) ($refund['idempotency_key'] ?? '');
                if ($refundKey === '' || !$this->refundReconciliationIsDue($refund)) {
                    continue;
                }
                if ($this->refundReconciliationIsProlonged($refund)) {
                    $this->audit->log(null, 'refund.reconcile_prolonged', 'refund', $refundId, null, [
                        'status' => (string) $refund['status'],
                    ]);
                }
                $providerRefundId = (string) ($refund['provider_refund_id'] ?? '');
                if ($providerRefundId !== '') {
                    $response = $this->request(
                        'GET',
                        $this->squareBaseUrl() . '/v2/refunds/' . rawurlencode($providerRefundId),
                        null,
                        $this->squareHeaders($accessToken)
                    );
                } else {
                    $response = $this->request(
                        'POST',
                        $this->squareBaseUrl() . '/v2/refunds',
                        $this->squareRefundPayload($refund),
                        $this->squareHeaders($accessToken)
                    );
                }

                if ($response['status'] < 200 || $response['status'] >= 300 || !isset($response['json']['refund'])) {
                    $this->audit->log(null, 'refund.reconcile_error', 'refund', $refundId, null, [
                        'http_status' => $response['status'],
                    ]);
                    // Preserve the same key/body for 429 and all ambiguous
                    // provider errors. Touching updated_at implements backoff
                    // without introducing a second financial state machine.
                    $this->touchRefundReconciliation($refundId, $refundKey);
                    continue;
                }

                /** @var array<string,mixed> $providerRefund */
                $providerRefund = $response['json']['refund'];
                $beforeStatus = (string) $refund['status'];
                $beforeProviderId = $providerRefundId;
                $this->tools->transaction(function () use ($providerRefund): void {
                    $this->syncRefundWebhook($providerRefund);
                });
                $after = $this->refundForReconciliation($refundId);
                if ($after !== null && (
                    (string) $after['status'] !== $beforeStatus
                    || (string) ($after['provider_refund_id'] ?? '') !== $beforeProviderId
                )) {
                    ++$reconciled;
                }
            } catch (\Throwable $error) {
                if ($refundKey !== '') {
                    $this->touchRefundReconciliation($refundId, $refundKey);
                }
                $this->audit->log(null, 'refund.reconcile_error', 'refund', $refundId, null, [
                    'error_code' => (int) $error->getCode(),
                ]);
            }
        }

        return $reconciled;
    }

    /** @return array<string,mixed> */
    private function sessionCheckoutPayload(array $order, array $session, int $bookingId): array
    {
        return [
            'id' => (int) $order['id'],
            'order_key' => (string) $order['order_key'],
            'status' => (string) ($order['status'] ?? 'pending'),
            'total_cents' => (int) $order['total_cents'],
            'currency' => (string) $order['currency'],
            'square_application_id' => $this->publicApplicationId(),
            'square_location_id' => $this->publicLocationId(),
            'environment' => $this->isSandbox() ? 'sandbox' : 'production',
            'booking_id' => $bookingId,
            'session_id' => (int) $session['id'],
            'session_title' => (string) ($session['course_title'] ?? ''),
            'starts_at' => (string) $session['starts_at'],
        ];
    }

    /** @return array<string,mixed> */
    private function lockSessionForPurchase(int $sessionId): array
    {
        $session = $this->tools->lockById('#__memi_sessions', $sessionId);
        if (!$session) {
            throw new DomainException('COM_MEMIPILATES_ERROR_SESSION_NOT_FOUND', [], 404);
        }

        $identifier = $sessionId;
        $query = $this->db->getQuery(true)
            ->select([
                'c.title AS course_title',
                'i.display_name AS instructor_name',
                'r.title AS room_title',
                'l.title AS location_title',
            ])
            ->from($this->db->quoteName('#__memi_sessions', 's'))
            ->join('INNER', $this->db->quoteName('#__memi_courses', 'c') . ' ON c.id = s.course_id')
            ->join('LEFT', $this->db->quoteName('#__memi_instructors', 'i') . ' ON i.id = s.instructor_id')
            ->join('LEFT', $this->db->quoteName('#__memi_rooms', 'r') . ' ON r.id = s.room_id')
            ->join('LEFT', $this->db->quoteName('#__memi_locations', 'l') . ' ON l.id = r.location_id')
            ->where('s.id = :session_id')
            ->where('s.archived_at IS NULL')
            ->where('c.archived_at IS NULL')
            ->where('c.published = 1')
            ->bind(':session_id', $identifier, ParameterType::INTEGER);
        $this->db->setQuery($query, 0, 1);
        $context = $this->db->loadAssoc();
        if (!$context) {
            throw new DomainException('COM_MEMIPILATES_ERROR_SESSION_NOT_FOUND', [], 404);
        }

        return array_merge($session, $context);
    }

    /** @param array<string,mixed> $session */
    private function assertSessionPurchasable(array $session): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if (!in_array((string) $session['status'], ['published', 'open'], true)) {
            throw new DomainException('COM_MEMIPILATES_ERROR_SESSION_UNAVAILABLE');
        }
        if (!empty($session['registration_opens_at'])
            && $now < new \DateTimeImmutable((string) $session['registration_opens_at'], new \DateTimeZone('UTC'))) {
            throw new DomainException('COM_MEMIPILATES_ERROR_REGISTRATION_NOT_OPEN');
        }
        if (!empty($session['registration_closes_at'])
            && $now >= new \DateTimeImmutable((string) $session['registration_closes_at'], new \DateTimeZone('UTC'))) {
            throw new DomainException('COM_MEMIPILATES_ERROR_REGISTRATION_CLOSED');
        }
        if ($now >= new \DateTimeImmutable((string) $session['starts_at'], new \DateTimeZone('UTC'))) {
            throw new DomainException('COM_MEMIPILATES_ERROR_SESSION_STARTED');
        }
    }

    /** @return array<string,mixed>|null */
    private function findBookingForUpdate(int $userId, int $sessionId): ?array
    {
        $user = $userId;
        $session = $sessionId;
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__memi_bookings'))
            ->where($this->db->quoteName('user_id') . ' = :user_id')
            ->where($this->db->quoteName('session_id') . ' = :session_id')
            ->order($this->db->quoteName('id') . ' DESC')
            ->bind(':user_id', $user, ParameterType::INTEGER)
            ->bind(':session_id', $session, ParameterType::INTEGER);
        $query->setLimit(1);
        $this->db->setQuery(DatabaseTools::forUpdate($query));

        return $this->db->loadAssoc() ?: null;
    }

    /** @return array<string,mixed>|null */
    private function bookingForOrderForUpdate(int $orderId): ?array
    {
        $order = $orderId;
        $source = 'square_direct';
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__memi_bookings'))
            ->where($this->db->quoteName('order_id') . ' = :order_id')
            ->where($this->db->quoteName('source') . ' = :source')
            ->bind(':order_id', $order, ParameterType::INTEGER)
            ->bind(':source', $source);
        $query->setLimit(1);
        $this->db->setQuery(DatabaseTools::forUpdate($query));

        return $this->db->loadAssoc() ?: null;
    }

    private function claimCapacity(int $sessionId): bool
    {
        $identifier = $sessionId;
        $published = 'published';
        $open = 'open';
        $now = gmdate('Y-m-d H:i:s');
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__memi_sessions'))
            ->set($this->db->quoteName('reserved_count') . ' = ' . $this->db->quoteName('reserved_count') . ' + 1')
            ->set($this->db->quoteName('updated_at') . ' = :updated_at')
            ->where($this->db->quoteName('id') . ' = :session_id')
            ->where($this->db->quoteName('reserved_count') . ' < ' . $this->db->quoteName('capacity'))
            ->where($this->db->quoteName('status') . ' IN (:published, :open)')
            ->bind(':updated_at', $now)
            ->bind(':session_id', $identifier, ParameterType::INTEGER)
            ->bind(':published', $published)
            ->bind(':open', $open);
        $this->db->setQuery($query)->execute();

        return $this->db->getAffectedRows() === 1;
    }

    private function releaseCapacity(int $sessionId): void
    {
        $identifier = $sessionId;
        $now = gmdate('Y-m-d H:i:s');
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__memi_sessions'))
            ->set($this->db->quoteName('reserved_count') . ' = GREATEST(0, ' . $this->db->quoteName('reserved_count') . ' - 1)')
            ->set($this->db->quoteName('updated_at') . ' = :updated_at')
            ->where($this->db->quoteName('id') . ' = :session_id')
            ->bind(':updated_at', $now)
            ->bind(':session_id', $identifier, ParameterType::INTEGER);
        $this->db->setQuery($query)->execute();
    }

    /**
     * A failed direct-payment attempt no longer owns capacity. Before retrying
     * that same immutable order, atomically claim a fresh place and reactivate
     * its booking. Package orders have no linked square_direct booking.
     *
     * @param array<string,mixed> $order
     */
    private function prepareSessionOrderForPayment(array $order): void
    {
        $booking = $this->bookingForOrderForUpdate((int) $order['id']);
        if (!$booking) {
            $orderIdentifier = (int) $order['id'];
            $sessionType = 'session';
            $itemQuery = $this->db->getQuery(true)
                ->select('COUNT(*)')
                ->from($this->db->quoteName('#__memi_order_items'))
                ->where($this->db->quoteName('order_id') . ' = :order_id')
                ->where($this->db->quoteName('item_type') . ' = :item_type')
                ->bind(':order_id', $orderIdentifier, ParameterType::INTEGER)
                ->bind(':item_type', $sessionType);
            $this->db->setQuery($itemQuery);
            if ((int) $this->db->loadResult() > 0) {
                throw new DomainException('COM_MEMIPILATES_ERROR_ORDER_NOT_PAYABLE');
            }
            return;
        }
        if ((string) $booking['status'] === 'payment_pending') {
            $session = $this->lockSessionForPurchase((int) $booking['session_id']);
            $this->assertSessionPurchasable($session);
            return;
        }
        if ((string) $order['status'] !== 'payment_failed'
            || !in_array((string) $booking['status'], ['payment_failed', 'payment_expired'], true)) {
            throw new DomainException('COM_MEMIPILATES_ERROR_ORDER_NOT_PAYABLE');
        }

        $session = $this->lockSessionForPurchase((int) $booking['session_id']);
        $this->assertSessionPurchasable($session);
        if (!$this->claimCapacity((int) $booking['session_id'])) {
            throw new DomainException('COM_MEMIPILATES_ERROR_SESSION_FULL');
        }

        $now = gmdate('Y-m-d H:i:s');
        $identifier = (int) $booking['id'];
        $status = 'payment_pending';
        $activeBookingKey = hash('sha256', (int) $booking['session_id'] . ':' . (int) $booking['user_id']);
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__memi_bookings'))
            ->set($this->db->quoteName('status') . ' = :status')
            ->set($this->db->quoteName('active_booking_key') . ' = :active_booking_key')
            ->set($this->db->quoteName('cancelled_at') . ' = NULL')
            ->set($this->db->quoteName('cancelled_by') . ' = 0')
            ->set($this->db->quoteName('cancellation_reason') . ' = NULL')
            ->set($this->db->quoteName('updated_at') . ' = :updated_at')
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':status', $status)
            ->bind(':active_booking_key', $activeBookingKey)
            ->bind(':updated_at', $now)
            ->bind(':id', $identifier, ParameterType::INTEGER);
        $this->db->setQuery($query)->execute();
    }

    private function failOrderAfterPaymentAttempt(int $orderId, string $reason): void
    {
        $this->tools->transaction(function () use ($orderId, $reason): void {
            $order = $this->tools->lockById('#__memi_orders', $orderId);
            if (!$order || (string) $order['status'] === 'paid') {
                return;
            }
            if (!in_array((string) $order['status'], ['pending', 'payment_processing', 'payment_failed'], true)) {
                return;
            }
            $this->markOrderFailed($orderId, $reason);
        });
    }

    /** @return array<string,int> */
    private function fulfillOrderItems(int $userId, int $orderId): array
    {
        $identifier = $orderId;
        $query = $this->db->getQuery(true)
            ->select('DISTINCT ' . $this->db->quoteName('item_type'))
            ->from($this->db->quoteName('#__memi_order_items'))
            ->where($this->db->quoteName('order_id') . ' = :order_id')
            ->bind(':order_id', $identifier, ParameterType::INTEGER);
        $this->db->setQuery($query);
        $types = array_values(array_unique(array_map('strval', $this->db->loadColumn() ?: [])));
        if ($types === ['package']) {
            $this->fulfillPackageItems($userId, $orderId);

            return [];
        }
        if ($types === ['session']) {
            return $this->fulfillSessionItems($userId, $orderId);
        }

        throw new DomainException('COM_MEMIPILATES_ERROR_ORDER_NOT_PAYABLE');
    }

    /** @return array{booking_id:int,session_id:int} */
    private function fulfillSessionItems(int $userId, int $orderId): array
    {
        $booking = $this->bookingForOrderForUpdate($orderId);
        if (!$booking || (int) $booking['user_id'] !== $userId) {
            throw new DomainException('COM_MEMIPILATES_ERROR_ORDER_NOT_PAYABLE');
        }
        if ((string) $booking['status'] === 'confirmed') {
            return ['booking_id' => (int) $booking['id'], 'session_id' => (int) $booking['session_id']];
        }
        if ((string) $booking['status'] !== 'payment_pending') {
            throw new DomainException('COM_MEMIPILATES_ERROR_ORDER_NOT_PAYABLE');
        }

        $session = $this->lockSessionForPurchase((int) $booking['session_id']);
        if (!in_array((string) $session['status'], ['published', 'open'], true)) {
            throw new DomainException('COM_MEMIPILATES_ERROR_SESSION_UNAVAILABLE');
        }

        $now = gmdate('Y-m-d H:i:s');
        $identifier = (int) $booking['id'];
        $confirmed = 'confirmed';
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__memi_bookings'))
            ->set($this->db->quoteName('status') . ' = :status')
            ->set($this->db->quoteName('confirmed_at') . ' = :confirmed_at')
            ->set($this->db->quoteName('cancelled_at') . ' = NULL')
            ->set($this->db->quoteName('cancelled_by') . ' = 0')
            ->set($this->db->quoteName('cancellation_reason') . ' = NULL')
            ->set($this->db->quoteName('updated_at') . ' = :updated_at')
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':status', $confirmed)
            ->bind(':confirmed_at', $now)
            ->bind(':updated_at', $now)
            ->bind(':id', $identifier, ParameterType::INTEGER);
        $this->db->setQuery($query)->execute();

        $order = $this->tools->lockById('#__memi_orders', $orderId);
        if ($order && $this->settings->getBool('loyalty_enabled', true)) {
            $perDollar = max(0, $this->settings->getInt('points_per_dollar', 1));
            $orderPoints = (int) floor((int) $order['total_cents'] / 100) * $perDollar;
            if ($orderPoints > 0) {
                $this->points->award(
                    $userId,
                    $orderPoints,
                    'order_paid',
                    'order:' . $orderId . ':spend-points',
                    null,
                    $orderId,
                    $userId,
                    'Points d’achat'
                );
            }
        }

        $notificationPayload = [
            'session_id' => (int) $session['id'],
            'session_title' => (string) ($session['course_title'] ?? ''),
            'starts_at' => (string) $session['starts_at'],
            'instructor_name' => (string) ($session['instructor_name'] ?? ''),
            'room_title' => (string) ($session['room_title'] ?? ''),
            'location_title' => (string) ($session['location_title'] ?? ''),
        ];
        $this->notifications->queue(
            $userId,
            'booking.confirmed',
            $notificationPayload,
            null,
            'booking-confirmed:' . $identifier . ':' . (string) $booking['booking_key']
        );
        $this->notifications->queue(
            $userId,
            'payment.receipt',
            ['order_id' => $orderId],
            null,
            'payment-receipt:order:' . $orderId
        );
        $this->audit->log($userId, 'booking.confirm.direct_payment', 'booking', $identifier, $booking, [
            'order_id' => $orderId,
            'session_id' => (int) $session['id'],
            'status' => $confirmed,
        ]);

        return ['booking_id' => $identifier, 'session_id' => (int) $session['id']];
    }

    private function publicApplicationId(): string
    {
        return (string) $this->settings->get('square_application_id', '');
    }

    private function publicLocationId(): string
    {
        return (string) $this->settings->get('square_location_id', '');
    }

    private function squareAccessToken(): string
    {
        return getenv('MEMI_SQUARE_ACCESS_TOKEN') ?: (string) $this->settings->get('square_access_token', '');
    }

    /** @return list<string> */
    private function squareHeaders(string $accessToken): array
    {
        return [
            'Authorization: Bearer ' . $accessToken,
            'Square-Version: 2024-08-21',
        ];
    }

    /** Square currently accepts idempotency keys of at most 45 characters. */
    private function newSquareIdempotencyKey(string $scope, int $entityId, string $nonce = ''): string
    {
        $prefix = preg_replace('/[^a-z0-9]/', '', strtolower($scope)) ?: 'op';
        $prefix = substr($prefix, 0, 8);
        $entropy = $entityId . ':' . $nonce . ':' . bin2hex(random_bytes(16));

        return $prefix . '-' . substr(hash('sha256', $entropy), 0, 36);
    }

    private function squarePaymentReference(int $orderId, string $paymentKey): string
    {
        // Square caps reference_id at 40 characters. The order remains
        // readable to the webhook while the hash binds the reference to one
        // specific provider attempt rather than to every retry of the order.
        return 'memi-o-' . $orderId . '-' . substr(hash('sha256', $paymentKey), 0, 12);
    }

    /** @return array{order_id:int,legacy:bool}|null */
    private function parseSquarePaymentReference(string $reference): ?array
    {
        if (preg_match('/^memi-o-(\d+)-[a-f0-9]{12}$/D', $reference, $matches)) {
            return ['order_id' => (int) $matches[1], 'legacy' => false];
        }
        // Compatibility for payments submitted by releases before references
        // were made attempt-specific.
        if (preg_match('/^memi-order-(\d+)$/D', $reference, $matches)) {
            return ['order_id' => (int) $matches[1], 'legacy' => true];
        }

        return null;
    }

    private function isDefinitivePaymentFailure(\Throwable $error): bool
    {
        if (!$error instanceof DomainException) {
            return false;
        }
        if (in_array($error->getMessage(), [
            'COM_MEMIPILATES_ERROR_SQUARE_NOT_CONFIGURED',
            'COM_MEMIPILATES_ERROR_HTTP_CLIENT_UNAVAILABLE',
        ], true)) {
            return true;
        }

        // Card/payment-method declines are definitive and can safely release
        // this attempt. Rate limits, authentication errors and idempotency
        // conflicts remain ambiguous and must be closed by reconciliation
        // with the original key before another card is attempted.
        $categories = strtoupper((string) ($error->getContext()['square_error_categories'] ?? ''));

        return in_array('PAYMENT_METHOD_ERROR', array_filter(explode(',', $categories)), true);
    }

    /** Square limits the provider-visible refund reason to 192 characters. */
    private function squareRefundReason(string $reason): string
    {
        return mb_substr(trim($reason), 0, 192);
    }

    /** @param array<string,mixed> $refund */
    private function squareRefundPayload(array $refund): array
    {
        return [
            'idempotency_key' => (string) $refund['idempotency_key'],
            'amount_money' => [
                'amount' => (int) $refund['amount_cents'],
                'currency' => strtoupper((string) $refund['currency']),
            ],
            'payment_id' => (string) $refund['provider_payment_id'],
            'reason' => $this->squareRefundReason((string) $refund['reason']),
        ];
    }

    /** @return array<string,mixed>|null */
    private function orderForReconciliation(int $orderId): ?array
    {
        $identifier = $orderId;
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__memi_orders'))
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':id', $identifier, ParameterType::INTEGER);
        $this->db->setQuery($query);

        return $this->db->loadAssoc() ?: null;
    }

    /** @return array<string,mixed>|null */
    private function refundForReconciliation(int $refundId): ?array
    {
        $identifier = $refundId;
        $query = $this->db->getQuery(true)
            ->select(['r.*', 'p.provider_payment_id', 'p.currency'])
            ->from($this->db->quoteName('#__memi_refunds', 'r'))
            ->join('INNER', $this->db->quoteName('#__memi_payments', 'p') . ' ON p.id = r.payment_id')
            ->where('r.id = :id')
            ->bind(':id', $identifier, ParameterType::INTEGER);
        $this->db->setQuery($query);

        return $this->db->loadAssoc() ?: null;
    }

    /** @param array<string,mixed> $refund */
    private function refundReconciliationIsDue(array $refund): bool
    {
        $now = time();
        $requestedAt = $this->utcTimestamp((string) ($refund['requested_at'] ?? $refund['created_at'] ?? ''), $now);
        $updatedAt = $this->utcTimestamp((string) ($refund['updated_at'] ?? ''), $requestedAt);
        $age = max(0, $now - $requestedAt);
        $delay = match (true) {
            $age < 3600 => 300,
            $age < 86400 => 1800,
            $age < 604800 => 21600,
            default => 86400,
        };

        return $updatedAt <= ($now - $delay);
    }

    /** @param array<string,mixed> $refund */
    private function refundReconciliationIsProlonged(array $refund): bool
    {
        $now = time();
        $requestedAt = $this->utcTimestamp((string) ($refund['requested_at'] ?? $refund['created_at'] ?? ''), $now);

        return ($now - $requestedAt) >= (14 * 86400);
    }

    private function utcTimestamp(string $value, int $fallback): int
    {
        if ($value === '') {
            return $fallback;
        }
        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->getTimestamp();
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private function touchRefundReconciliation(int $refundId, string $expectedKey): void
    {
        if ($refundId <= 0 || $expectedKey === '') {
            return;
        }
        $identifier = $refundId;
        $processing = 'processing';
        $pending = 'pending';
        $now = gmdate('Y-m-d H:i:s');
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__memi_refunds'))
            ->set($this->db->quoteName('updated_at') . ' = :updated_at')
            ->where($this->db->quoteName('id') . ' = :id')
            ->where($this->db->quoteName('idempotency_key') . ' = :idempotency_key')
            ->where('(' . $this->db->quoteName('status') . ' = :processing_status OR '
                . $this->db->quoteName('status') . ' = :pending_status)')
            ->bind(':updated_at', $now)
            ->bind(':id', $identifier, ParameterType::INTEGER)
            ->bind(':idempotency_key', $expectedKey)
            ->bind(':processing_status', $processing)
            ->bind(':pending_status', $pending);
        $this->db->setQuery($query)->execute();
    }

    /**
     * @param array<string,mixed> $order
     * @return array<string,mixed>|null
     */
    private function findSquarePaymentForOrder(array $order, string $accessToken, string $locationId): ?array
    {
        $timezone = new \DateTimeZone('UTC');
        try {
            $attempt = new \DateTimeImmutable((string) $order['updated_at'], $timezone);
        } catch (\Throwable) {
            $attempt = new \DateTimeImmutable('now', $timezone);
        }
        $now = new \DateTimeImmutable('now', $timezone);
        if ($attempt > $now) {
            $attempt = $now;
        }
        $end = $attempt->modify('+1 day');
        if ($end > $now) {
            $end = $now;
        }

        $baseParameters = [
            'begin_time' => $attempt->modify('-5 minutes')->format('Y-m-d\TH:i:s\Z'),
            'end_time' => $end->format('Y-m-d\TH:i:s\Z'),
            'sort_order' => 'ASC',
            'location_id' => $locationId,
            'total' => (int) $order['total_cents'],
            'limit' => 100,
        ];
        $paymentKey = (string) ($order['idempotency_key'] ?? '');
        $expectedReference = $this->squarePaymentReference((int) $order['id'], $paymentKey);
        $legacyReference = 'memi-order-' . (int) $order['id'];
        $acceptLegacyReference = strncmp($paymentKey, 'pay-', 4) !== 0;
        $expectedCurrency = strtoupper((string) $order['currency']);
        $cursor = '';
        $exactCandidate = null;
        $exactCandidateScore = -1;
        $exactCandidateIds = [];
        $exactCandidateStatuses = [];
        $legacyCandidate = null;
        $legacyCandidateScore = -1;
        $legacyCandidateIds = [];
        $legacyCandidateStatuses = [];
        $pages = 0;

        do {
            $parameters = $baseParameters;
            if ($cursor !== '') {
                $parameters['cursor'] = $cursor;
            }
            $url = $this->squareBaseUrl() . '/v2/payments?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
            $response = $this->request('GET', $url, null, $this->squareHeaders($accessToken));
            if ($response['status'] < 200 || $response['status'] >= 300) {
                throw new DomainException('COM_MEMIPILATES_ERROR_PAYMENT_FAILED', [], $response['status'] ?: 503);
            }

            foreach (($response['json']['payments'] ?? []) as $payment) {
                $providerPaymentId = is_array($payment) ? (string) ($payment['id'] ?? '') : '';
                $reference = is_array($payment) ? (string) ($payment['reference_id'] ?? '') : '';
                $referenceMode = $reference === $expectedReference ? 'exact'
                    : ($acceptLegacyReference && $reference === $legacyReference ? 'legacy' : '');
                if (!is_array($payment) || $providerPaymentId === ''
                    || $referenceMode === ''
                    || (int) ($payment['amount_money']['amount'] ?? -1) !== (int) $order['total_cents']
                    || strtoupper((string) ($payment['amount_money']['currency'] ?? '')) !== $expectedCurrency
                    || (string) ($payment['location_id'] ?? '') !== $locationId) {
                    continue;
                }
                $status = strtoupper((string) ($payment['status'] ?? ''));
                $score = $status === 'COMPLETED' ? 3
                    : (in_array($status, ['APPROVED', 'PENDING'], true) ? 2 : 1);
                if ($referenceMode === 'exact') {
                    $exactCandidateIds[$providerPaymentId] = true;
                    $exactCandidateStatuses[$status !== '' ? $status : 'UNKNOWN'] = true;
                    if ($score >= $exactCandidateScore) {
                        $exactCandidate = $payment;
                        $exactCandidateScore = $score;
                    }
                } else {
                    $legacyCandidateIds[$providerPaymentId] = true;
                    $legacyCandidateStatuses[$status !== '' ? $status : 'UNKNOWN'] = true;
                    if ($score >= $legacyCandidateScore) {
                        $legacyCandidate = $payment;
                        $legacyCandidateScore = $score;
                    }
                }
            }

            $cursor = (string) ($response['json']['cursor'] ?? '');
            ++$pages;
            if ($pages >= 100 && $cursor !== '') {
                throw new DomainException('COM_MEMIPILATES_ERROR_PAYMENT_FAILED', [], 503);
            }
        } while ($cursor !== '');

        $candidate = $exactCandidate;
        $candidateIds = $exactCandidateIds;
        $candidateStatuses = $exactCandidateStatuses;
        $referenceMode = 'exact';
        if ($candidateIds === [] && $acceptLegacyReference) {
            $candidate = $legacyCandidate;
            $candidateIds = $legacyCandidateIds;
            $candidateStatuses = $legacyCandidateStatuses;
            $referenceMode = 'legacy';
        }
        if (count($candidateIds) > 1) {
            $this->audit->log(null, 'payment.reconcile_duplicate_candidates', 'order', (int) $order['id'], null, [
                'candidate_count' => count($candidateIds),
                'statuses' => implode(',', array_keys($candidateStatuses)),
                'reference_mode' => $referenceMode,
            ]);
            // Do not select, cancel or fulfill any one candidate: more than
            // one provider payment for an immutable order reference is a
            // possible double charge and requires explicit investigation.
            throw new DomainException('COM_MEMIPILATES_ERROR_PAYMENT_FAILED', [], 409);
        }

        return is_array($candidate) ? $candidate : null;
    }

    private function cancelSquarePaymentAttempt(string $paymentKey, string $accessToken): bool
    {
        if ($paymentKey === '' || strlen($paymentKey) > 45) {
            return false;
        }
        $response = $this->request(
            'POST',
            $this->squareBaseUrl() . '/v2/payments/cancel',
            ['idempotency_key' => $paymentKey],
            $this->squareHeaders($accessToken)
        );
        if ($response['status'] >= 200 && $response['status'] < 300) {
            return true;
        }
        $this->audit->log(null, 'payment.reconcile_cancel_error', 'order', null, null, [
            'http_status' => $response['status'],
        ]);

        return false;
    }

    private function failReconciledPaymentAttempt(int $orderId, string $expectedKey, string $reason): bool
    {
        return $this->tools->transaction(function () use ($orderId, $expectedKey, $reason): bool {
            $order = $this->tools->lockById('#__memi_orders', $orderId);
            if ($order === null || (string) $order['status'] !== 'payment_processing'
                || !hash_equals($expectedKey, (string) ($order['idempotency_key'] ?? ''))) {
                return false;
            }
            $this->markOrderFailed($orderId, $reason);
            $this->audit->log(null, 'payment.reconciled_failed', 'order', $orderId, null, [
                'provider_status' => mb_substr($reason, 0, 64),
            ]);

            return true;
        });
    }

    /** @return array<string,mixed> */
    private function loadOrderForUser(int $orderId, int $userId): array
    {
        $id = $orderId;
        $user = $userId;
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__memi_orders'))
            ->where($this->db->quoteName('id') . ' = :id')
            ->where($this->db->quoteName('user_id') . ' = :user_id')
            ->bind(':id', $id, ParameterType::INTEGER)
            ->bind(':user_id', $user, ParameterType::INTEGER);
        $this->db->setQuery($query);
        $order = $this->db->loadAssoc();
        if (!$order) {
            throw new DomainException('COM_MEMIPILATES_ERROR_ORDER_NOT_FOUND', [], 404);
        }

        return $order;
    }

    /** @return array<string,mixed> */
    private function squareCharge(array $order, string $sourceId, string $idempotencyKey): array
    {
        $accessToken = getenv('MEMI_SQUARE_ACCESS_TOKEN') ?: (string) $this->settings->get('square_access_token', '');
        if ($accessToken === '' || $this->publicLocationId() === '') {
            throw new DomainException('COM_MEMIPILATES_ERROR_SQUARE_NOT_CONFIGURED', [], 503);
        }
        $body = [
            'source_id' => $sourceId,
            'idempotency_key' => $idempotencyKey,
            'amount_money' => [
                'amount' => (int) $order['total_cents'],
                'currency' => (string) $order['currency'],
            ],
            'location_id' => $this->publicLocationId(),
            'reference_id' => $this->squarePaymentReference((int) $order['id'], $idempotencyKey),
            'note' => 'Memi Studio order ' . (int) $order['id'],
        ];
        $response = $this->request('POST', $this->squareBaseUrl() . '/v2/payments', $body, [
            'Authorization: Bearer ' . $accessToken,
            'Square-Version: 2024-08-21',
        ]);
        if ($response['status'] < 200 || $response['status'] >= 300 || !isset($response['json']['payment'])) {
            $categories = [];
            $codes = [];
            foreach (($response['json']['errors'] ?? []) as $providerError) {
                if (!is_array($providerError)) {
                    continue;
                }
                $category = strtoupper((string) ($providerError['category'] ?? ''));
                $code = strtoupper((string) ($providerError['code'] ?? ''));
                if ($category !== '') {
                    $categories[$category] = true;
                }
                if ($code !== '') {
                    $codes[$code] = true;
                }
            }
            $this->audit->log(null, 'payment.square_error', 'order', (int) $order['id'], null, [
                'http_status' => $response['status'],
                'error_count' => count($response['json']['errors'] ?? []),
            ]);
            $statusCode = $response['status'] >= 400 ? $response['status'] : 502;
            throw new DomainException('COM_MEMIPILATES_ERROR_PAYMENT_FAILED', [
                'square_error_categories' => implode(',', array_keys($categories)),
                'square_error_codes' => implode(',', array_keys($codes)),
            ], $statusCode);
        }

        /** @var array<string,mixed> $payment */
        $payment = $response['json']['payment'];

        $providerId = (string) ($payment['id'] ?? '');
        $amount = (int) ($payment['amount_money']['amount'] ?? -1);
        $currency = strtoupper((string) ($payment['amount_money']['currency'] ?? ''));
        $locationId = (string) ($payment['location_id'] ?? '');
        $referenceId = (string) ($payment['reference_id'] ?? '');
        $expectedReference = $this->squarePaymentReference((int) $order['id'], $idempotencyKey);
        if ($providerId === ''
            || $amount !== (int) $order['total_cents']
            || $currency !== strtoupper((string) $order['currency'])
            || $locationId !== $this->publicLocationId()
            || $referenceId !== $expectedReference) {
            $this->audit->log(null, 'payment.provider_mismatch', 'order', (int) $order['id'], null, [
                'provider_id_present' => $providerId !== '',
                'amount_matches' => $amount === (int) $order['total_cents'],
                'currency_matches' => $currency === strtoupper((string) $order['currency']),
                'location_matches' => $locationId === $this->publicLocationId(),
                'reference_matches' => $referenceId === $expectedReference,
            ]);
            throw new DomainException('COM_MEMIPILATES_ERROR_PAYMENT_FAILED', [], 502);
        }

        return $payment;
    }

    /** @return array{status:int,json:array<string,mixed>} */
    private function request(string $method, string $url, ?array $body, array $headers): array
    {
        if (!function_exists('curl_init')) {
            throw new DomainException('COM_MEMIPILATES_ERROR_HTTP_CLIENT_UNAVAILABLE', [], 503);
        }
        $curl = curl_init($url);
        if ($curl === false) {
            throw new DomainException('COM_MEMIPILATES_ERROR_PAYMENT_FAILED');
        }
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR);
        }
        curl_setopt_array($curl, $options);
        $responseBody = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $transportFailed = $responseBody === false;
        curl_close($curl);
        if ($transportFailed) {
            // The provider may still have received the idempotent request.
            // Keep the local operation reconcilable instead of assuming a
            // definitive rejection or exposing cURL diagnostics.
            throw new DomainException('COM_MEMIPILATES_ERROR_PAYMENT_FAILED', [], 503);
        }
        $decoded = is_string($responseBody) ? json_decode($responseBody, true) : null;

        return ['status' => $status, 'json' => is_array($decoded) ? $decoded : []];
    }

    private function insertPayment(array $order, array $squarePayment, string $idempotencyKey): int
    {
        $providerId = (string) ($squarePayment['id'] ?? '');
        if ($providerId === '') {
            throw new DomainException('COM_MEMIPILATES_ERROR_PAYMENT_FAILED');
        }
        $existing = $this->db->getQuery(true)
            ->select([$this->db->quoteName('id'), $this->db->quoteName('order_id')])
            ->from($this->db->quoteName('#__memi_payments'))
            ->where($this->db->quoteName('provider_payment_id') . ' = :provider_payment_id')
            ->bind(':provider_payment_id', $providerId);
        $this->db->setQuery($existing);
        $existingPayment = $this->db->loadAssoc();
        if ($existingPayment) {
            if ((int) $existingPayment['order_id'] !== (int) $order['id']) {
                $this->audit->log(null, 'payment.provider_order_mismatch', 'order', (int) $order['id'], null, [
                    'existing_order_id' => (int) $existingPayment['order_id'],
                ]);
                throw new DomainException('COM_MEMIPILATES_ERROR_PAYMENT_FAILED');
            }

            return (int) $existingPayment['id'];
        }

        $now = gmdate('Y-m-d H:i:s');
        $orderId = (int) $order['id'];
        $amount = (int) ($squarePayment['amount_money']['amount'] ?? $order['total_cents']);
        $currency = (string) ($squarePayment['amount_money']['currency'] ?? $order['currency']);
        $receipt = isset($squarePayment['receipt_url']) ? (string) $squarePayment['receipt_url'] : null;
        $cardBrand = isset($squarePayment['card_details']['card']['card_brand']) ? (string) $squarePayment['card_details']['card']['card_brand'] : null;
        $last4 = isset($squarePayment['card_details']['card']['last_4']) ? (string) $squarePayment['card_details']['card']['last_4'] : null;
        $provider = 'square';
        $completedStatus = 'completed';
        $query = $this->db->getQuery(true)
            ->insert($this->db->quoteName('#__memi_payments'))
            ->columns(['order_id', 'provider', 'provider_payment_id', 'status', 'amount_cents', 'currency', 'receipt_url', 'card_brand', 'card_last4', 'idempotency_key', 'created_at', 'updated_at'])
            ->values(':order_id, :provider, :provider_payment_id, :status, :amount_cents, :currency, :receipt_url, :card_brand, :card_last4, :idempotency_key, :created_at, :updated_at')
            ->bind(':order_id', $orderId, ParameterType::INTEGER)
            ->bind(':provider', $provider)
            ->bind(':provider_payment_id', $providerId)
            ->bind(':status', $completedStatus)
            ->bind(':amount_cents', $amount, ParameterType::INTEGER)
            ->bind(':currency', $currency)
            ->bind(':receipt_url', $receipt)
            ->bind(':card_brand', $cardBrand)
            ->bind(':card_last4', $last4)
            ->bind(':idempotency_key', $idempotencyKey)
            ->bind(':created_at', $now)
            ->bind(':updated_at', $now);
        $this->db->setQuery($query)->execute();

        return (int) $this->db->insertid();
    }

    private function fulfillPackageItems(int $userId, int $orderId): void
    {
        $profile = $this->tools->lockClientProfile($userId);
        $clientId = (int) $profile['id'];
        $order = $orderId;
        $packageItemType = 'package';
        $query = $this->db->getQuery(true)
            ->select('i.*, p.credits, p.validity_days, p.points_bonus')
            ->from($this->db->quoteName('#__memi_order_items', 'i'))
            ->join('INNER', $this->db->quoteName('#__memi_packages', 'p') . ' ON p.id = i.package_id')
            ->where('i.order_id = :order_id')
            ->where('i.item_type = :item_type')
            ->bind(':order_id', $order, ParameterType::INTEGER)
            ->bind(':item_type', $packageItemType);
        $this->db->setQuery($query);
        $items = $this->db->loadAssocList() ?: [];
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $loyaltyEnabled = $this->settings->getBool('loyalty_enabled', true);

        foreach ($items as $item) {
            $credits = max(0, (int) $item['credits']);
            $validity = max(0, (int) $item['validity_days']);
            $expires = $validity > 0 ? $now->modify('+' . $validity . ' days') : null;
            $expiresAt = $expires?->format('Y-m-d H:i:s');
            $created = gmdate('Y-m-d H:i:s');
            $packageId = (int) $item['package_id'];
            $user = $userId;
            $activeStatus = 'active';
            $insert = $this->db->getQuery(true)
                ->insert($this->db->quoteName('#__memi_customer_packages'))
                ->columns(['client_id', 'user_id', 'package_id', 'order_id', 'original_credits', 'remaining_credits', 'status', 'purchased_at', 'starts_at', 'expires_at', 'created_at', 'updated_at'])
                ->values(':client_id, :user_id, :package_id, :order_id, :original_credits, :remaining_credits, :status, :purchased_at, :starts_at, :expires_at, :created_at, :updated_at')
                ->bind(':client_id', $clientId, ParameterType::INTEGER)
                ->bind(':user_id', $user, ParameterType::INTEGER)
                ->bind(':package_id', $packageId, ParameterType::INTEGER)
                ->bind(':order_id', $order, ParameterType::INTEGER)
                ->bind(':original_credits', $credits, ParameterType::INTEGER)
                ->bind(':remaining_credits', $credits, ParameterType::INTEGER)
                ->bind(':status', $activeStatus)
                ->bind(':purchased_at', $created)
                ->bind(':starts_at', $created)
                ->bind(':expires_at', $expiresAt)
                ->bind(':created_at', $created)
                ->bind(':updated_at', $created);
            $this->db->setQuery($insert)->execute();
            $customerPackageId = (int) $this->db->insertid();
            if ($credits > 0) {
                $this->credits->grant($userId, $credits, 'purchase', 'order:' . $orderId . ':package:' . $customerPackageId, $customerPackageId, $orderId, $expires, $userId, 'Forfait acheté');
            }
            $bonus = max(0, (int) $item['points_bonus']);
            if ($loyaltyEnabled && $bonus > 0) {
                $this->points->award($userId, $bonus, 'package_purchase', 'order:' . $orderId . ':points', null, $orderId, $userId, 'Points de forfait');
            }
        }

        $orderData = $this->tools->lockById('#__memi_orders', $orderId);
        if ($orderData) {
            $this->fulfillPromotion($userId, $clientId, $orderData, $items, $loyaltyEnabled);
        }
        if ($loyaltyEnabled && $orderData) {
            $perDollar = max(0, $this->settings->getInt('points_per_dollar', 1));
            $orderPoints = (int) floor((int) $orderData['total_cents'] / 100) * $perDollar;
            if ($orderPoints > 0) {
                $this->points->award($userId, $orderPoints, 'order_paid', 'order:' . $orderId . ':spend-points', null, $orderId, $userId, 'Points d’achat');
            }
        }
        $this->notifications->queue($userId, 'payment.receipt', ['order_id' => $orderId]);
    }

    /**
     * Makes a paid promotion auditable and delivers any configured bonus once.
     * It runs inside the same transaction as payment fulfillment, so a failed
     * bonus cannot leave a recorded redemption without its entitlement.
     *
     * @param array<string,mixed> $order
     * @param list<array<string,mixed>> $items
     */
    private function fulfillPromotion(int $userId, int $clientId, array $order, array $items, bool $loyaltyEnabled): void
    {
        $promotionId = (int) ($order['promotion_id'] ?? 0);
        if ($promotionId <= 0) {
            return;
        }
        $promotion = $this->tools->lockById('#__memi_promotions', $promotionId);
        if ($promotion === null) {
            return;
        }
        $orderId = (int) $order['id'];
        $key = hash('sha256', 'promotion-redemption:order:' . $orderId);
        $existing = $this->db->getQuery(true)
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__memi_promotion_redemptions'))
            ->where($this->db->quoteName('idempotency_key') . ' = :idempotency_key')
            ->bind(':idempotency_key', $key);
        $this->db->setQuery(DatabaseTools::forUpdate($existing));
        if ((int) $this->db->loadResult() > 0) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s');
        $identifier = $promotionId;
        $client = $clientId;
        $orderIdentifier = $orderId;
        $discount = max(0, (int) ($order['discount_cents'] ?? 0));
        $redemption = $this->db->getQuery(true)
            ->insert($this->db->quoteName('#__memi_promotion_redemptions'))
            ->columns(['promotion_id', 'client_id', 'order_id', 'booking_id', 'discount_cents', 'idempotency_key', 'redeemed_at', 'created_at'])
            ->values(':promotion_id, :client_id, :order_id, NULL, :discount_cents, :idempotency_key, :redeemed_at, :created_at')
            ->bind(':promotion_id', $identifier, ParameterType::INTEGER)
            ->bind(':client_id', $client, ParameterType::INTEGER)
            ->bind(':order_id', $orderIdentifier, ParameterType::INTEGER)
            ->bind(':discount_cents', $discount, ParameterType::INTEGER)
            ->bind(':idempotency_key', $key)
            ->bind(':redeemed_at', $now)
            ->bind(':created_at', $now);
        $this->db->setQuery($redemption)->execute();

        $bonusCredits = max(0, (int) ($promotion['bonus_credits'] ?? 0));
        if ($bonusCredits > 0 && $items !== []) {
            $item = $items[0];
            $packageId = (int) ($item['package_id'] ?? 0);
            $validityDays = max(0, (int) ($item['validity_days'] ?? 0));
            if ($packageId > 0) {
                $issued = new \DateTimeImmutable($now, new \DateTimeZone('UTC'));
                $expires = $validityDays > 0 ? $issued->modify('+' . $validityDays . ' days') : null;
                $expiresAt = $expires?->format('Y-m-d H:i:s');
                $active = 'active';
                $allocation = $this->db->getQuery(true)
                    ->insert($this->db->quoteName('#__memi_customer_packages'))
                    ->columns(['client_id', 'user_id', 'package_id', 'order_id', 'status', 'original_credits', 'remaining_credits', 'credits_granted', 'purchased_at', 'starts_at', 'expires_at', 'created_at', 'updated_at'])
                    ->values(':client_id, :user_id, :package_id, :order_id, :status, :original_credits, :remaining_credits, :credits_granted, :purchased_at, :starts_at, :expires_at, :created_at, :updated_at')
                    ->bind(':client_id', $client, ParameterType::INTEGER)
                    ->bind(':user_id', $userId, ParameterType::INTEGER)
                    ->bind(':package_id', $packageId, ParameterType::INTEGER)
                    ->bind(':order_id', $orderIdentifier, ParameterType::INTEGER)
                    ->bind(':status', $active)
                    ->bind(':original_credits', $bonusCredits, ParameterType::INTEGER)
                    ->bind(':remaining_credits', $bonusCredits, ParameterType::INTEGER)
                    ->bind(':credits_granted', $bonusCredits, ParameterType::INTEGER)
                    ->bind(':purchased_at', $now)
                    ->bind(':starts_at', $now)
                    ->bind(':expires_at', $expiresAt)
                    ->bind(':created_at', $now)
                    ->bind(':updated_at', $now);
                $this->db->setQuery($allocation)->execute();
                $allocationId = (int) $this->db->insertid();
                $this->credits->grant($userId, $bonusCredits, 'promotion_bonus', 'order:' . $orderId . ':promotion-credits', $allocationId, $orderId, $expires, $userId, 'Crédits promotionnels');
            }
        }
        $bonusPoints = max(0, (int) ($promotion['bonus_points'] ?? 0));
        if ($loyaltyEnabled && $bonusPoints > 0) {
            $this->points->award($userId, $bonusPoints, 'promotion_bonus', 'order:' . $orderId . ':promotion-points', null, $orderId, $userId, 'Points promotionnels');
        }
    }

    /**
     * Validates a code under a promotion row lock before the pending order is
     * created. A package restriction table with no rows means “all packages”.
     *
     * @return array{id:?int,code:?string,discount_cents:int}
     */
    private function promotionForOrder(int $clientId, int $packageId, ?string $code, int $subtotal): array
    {
        if ($code === null || trim($code) === '') {
            return ['id' => null, 'code' => null, 'discount_cents' => 0];
        }
        $normalised = mb_strtoupper(trim($code));
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__memi_promotions'))
            ->where($this->db->quoteName('code') . ' = :code')
            ->where($this->db->quoteName('published') . ' = 1')
            ->where($this->db->quoteName('archived_at') . ' IS NULL')
            ->bind(':code', $normalised);
        $this->db->setQuery(DatabaseTools::forUpdate($query));
        $promotion = $this->db->loadAssoc();
        if (!$promotion) {
            throw new DomainException('COM_MEMIPILATES_ERROR_PROMOTION_INVALID');
        }
        $now = gmdate('Y-m-d H:i:s');
        if ((!empty($promotion['starts_at']) && (string) $promotion['starts_at'] > $now)
            || (!empty($promotion['ends_at']) && (string) $promotion['ends_at'] < $now)
            || max((int) ($promotion['minimum_amount_cents'] ?? 0), (int) ($promotion['minimum_order_cents'] ?? 0)) > $subtotal) {
            throw new DomainException('COM_MEMIPILATES_ERROR_PROMOTION_INVALID');
        }
        $promotionId = (int) $promotion['id'];
        if (!$this->promotionAppliesToPackage($promotionId, $packageId)
            || !$this->withinPromotionLimits($promotion, $clientId)) {
            throw new DomainException('COM_MEMIPILATES_ERROR_PROMOTION_INVALID');
        }
        $fixed = max(0, (int) ($promotion['discount_cents'] ?? 0));
        $percentBasisPoints = max(0, (int) ($promotion['discount_basis_points'] ?? 0));

        return [
            'id' => $promotionId,
            'code' => (string) $promotion['code'],
            'discount_cents' => min($subtotal, max($fixed, (int) round($subtotal * $percentBasisPoints / 10000))),
        ];
    }

    private function promotionAppliesToPackage(int $promotionId, int $packageId): bool
    {
        $promotion = $promotionId;
        $count = $this->db->getQuery(true)
            ->select('COUNT(*)')->from($this->db->quoteName('#__memi_promotion_packages'))
            ->where($this->db->quoteName('promotion_id') . ' = :promotion_id')
            ->bind(':promotion_id', $promotion, ParameterType::INTEGER);
        $this->db->setQuery($count);
        if ((int) $this->db->loadResult() === 0) {
            return true;
        }
        $package = $packageId;
        $allowed = $this->db->getQuery(true)
            ->select('1')->from($this->db->quoteName('#__memi_promotion_packages'))
            ->where($this->db->quoteName('promotion_id') . ' = :promotion_id')
            ->where($this->db->quoteName('package_id') . ' = :package_id')
            ->bind(':promotion_id', $promotion, ParameterType::INTEGER)
            ->bind(':package_id', $package, ParameterType::INTEGER);
        $this->db->setQuery($allowed, 0, 1);

        return (bool) $this->db->loadResult();
    }

    /** @param array<string,mixed> $order */
    private function assertPromotionClaimAvailable(array $order): void
    {
        $promotionId = (int) ($order['promotion_id'] ?? 0);
        if ($promotionId <= 0) {
            return;
        }
        // This row lock serialises payment claims for the same promotion. The
        // current order becomes payment_processing before the lock is
        // released, so the next checkout observes the claimed redemption.
        $promotion = $this->tools->lockById('#__memi_promotions', $promotionId);
        if ($promotion === null || !$this->withinPromotionLimits(
            $promotion,
            (int) $order['client_id'],
            (int) $order['id']
        )) {
            throw new DomainException('COM_MEMIPILATES_ERROR_PROMOTION_INVALID');
        }
    }

    /** @param array<string,mixed> $promotion */
    private function withinPromotionLimits(array $promotion, int $clientId, int $excludeOrderId = 0): bool
    {
        $promotionId = (int) $promotion['id'];
        $identifier = $promotionId;
        $paid = 'paid';
        $processing = 'payment_processing';
        $pending = 'pending';
        // Pending carts reserve a scarce code briefly. Processing attempts
        // remain claimed until Square or its webhook gives a definitive
        // result; releasing them after a timeout could oversubscribe a code
        // even though the card was actually charged.
        $reservationCutoff = gmdate('Y-m-d H:i:s', time() - 30 * 60);
        $count = $this->db->getQuery(true)
            ->select('COUNT(*)')->from($this->db->quoteName('#__memi_orders'))
            ->where($this->db->quoteName('promotion_id') . ' = :promotion_id')
            ->where('(' . $this->db->quoteName('status') . ' = :paid_status'
                . ' OR ' . $this->db->quoteName('status') . ' = :processing_status'
                . ' OR (' . $this->db->quoteName('status') . ' = :pending_status'
                . ' AND ' . $this->db->quoteName('created_at') . ' >= :reservation_cutoff))')
            ->bind(':promotion_id', $identifier, ParameterType::INTEGER)
            ->bind(':paid_status', $paid)
            ->bind(':processing_status', $processing)
            ->bind(':pending_status', $pending)
            ->bind(':reservation_cutoff', $reservationCutoff);
        if ($excludeOrderId > 0) {
            $excluded = $excludeOrderId;
            $count->where($this->db->quoteName('id') . ' <> :exclude_order_id')
                ->bind(':exclude_order_id', $excluded, ParameterType::INTEGER);
        }
        $this->db->setQuery($count);
        $total = (int) $this->db->loadResult();
        $maximum = $promotion['maximum_redemptions'] === null ? null : (int) $promotion['maximum_redemptions'];
        if ($maximum !== null && $total >= $maximum) {
            return false;
        }
        $perCustomer = $promotion['per_customer_limit'] === null ? null : (int) $promotion['per_customer_limit'];
        if ($perCustomer === null) {
            return true;
        }
        $client = $clientId;
        $perClient = $this->db->getQuery(true)
            ->select('COUNT(*)')->from($this->db->quoteName('#__memi_orders'))
            ->where($this->db->quoteName('promotion_id') . ' = :promotion_id')
            ->where($this->db->quoteName('client_id') . ' = :client_id')
            ->where('(' . $this->db->quoteName('status') . ' = :paid_status'
                . ' OR ' . $this->db->quoteName('status') . ' = :processing_status'
                . ' OR (' . $this->db->quoteName('status') . ' = :pending_status'
                . ' AND ' . $this->db->quoteName('created_at') . ' >= :reservation_cutoff))')
            ->bind(':promotion_id', $identifier, ParameterType::INTEGER)
            ->bind(':client_id', $client, ParameterType::INTEGER)
            ->bind(':paid_status', $paid)
            ->bind(':processing_status', $processing)
            ->bind(':pending_status', $pending)
            ->bind(':reservation_cutoff', $reservationCutoff);
        if ($excludeOrderId > 0) {
            $excluded = $excludeOrderId;
            $perClient->where($this->db->quoteName('id') . ' <> :exclude_order_id')
                ->bind(':exclude_order_id', $excluded, ParameterType::INTEGER);
        }
        $this->db->setQuery($perClient);

        return (int) $this->db->loadResult() < $perCustomer;
    }

    private function markOrderFailed(int $orderId, string $reason): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $id = $orderId;
        $failedStatus = 'payment_failed';
        $failure = mb_substr($reason, 0, 190);
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__memi_orders'))
            ->set($this->db->quoteName('status') . ' = :status')
            ->set($this->db->quoteName('failed_at') . ' = :failed_at')
            ->set($this->db->quoteName('failure_reason') . ' = :failure_reason')
            ->set($this->db->quoteName('updated_at') . ' = :updated_at')
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':status', $failedStatus)
            ->bind(':failed_at', $now)
            ->bind(':failure_reason', $failure)
            ->bind(':updated_at', $now)
            ->bind(':id', $id, ParameterType::INTEGER);
        $this->db->setQuery($query)->execute();

        $booking = $this->bookingForOrderForUpdate($orderId);
        if ($booking && (string) $booking['status'] === 'payment_pending') {
            $bookingId = (int) $booking['id'];
            $bookingFailure = 'payment_failed';
            $bookingUpdate = $this->db->getQuery(true)
                ->update($this->db->quoteName('#__memi_bookings'))
                ->set($this->db->quoteName('status') . ' = :status')
                ->set($this->db->quoteName('active_booking_key') . ' = NULL')
                ->set($this->db->quoteName('cancelled_at') . ' = :cancelled_at')
                ->set($this->db->quoteName('cancellation_reason') . ' = :reason')
                ->set($this->db->quoteName('updated_at') . ' = :updated_at')
                ->where($this->db->quoteName('id') . ' = :id')
                ->bind(':status', $bookingFailure)
                ->bind(':cancelled_at', $now)
                ->bind(':reason', $failure)
                ->bind(':updated_at', $now)
                ->bind(':id', $bookingId, ParameterType::INTEGER);
            $this->db->setQuery($bookingUpdate)->execute();
            $this->releaseCapacity((int) $booking['session_id']);
            $this->audit->log(null, 'booking.direct_payment_failed', 'booking', $bookingId, $booking, [
                'order_id' => $orderId,
                'session_id' => (int) $booking['session_id'],
                'reason' => $failure,
            ]);
        }
    }

    /** @return array<string,mixed>|null */
    private function paymentForOrder(int $orderId): ?array
    {
        $order = $orderId;
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__memi_payments'))
            ->where($this->db->quoteName('order_id') . ' = :order_id')
            ->order($this->db->quoteName('id') . ' DESC')
            ->bind(':order_id', $order, ParameterType::INTEGER);
        $this->db->setQuery($query, 0, 1);

        return $this->db->loadAssoc() ?: null;
    }

    private function verifyWebhookSignature(string $body, string $signature, string $url): bool
    {
        $key = getenv('MEMI_SQUARE_WEBHOOK_SIGNATURE_KEY') ?: (string) $this->settings->get('square_webhook_signature_key', '');
        if ($key === '' || $signature === '') {
            return false;
        }
        $expected = base64_encode(hash_hmac('sha256', $url . $body, $key, true));

        return hash_equals($expected, $signature);
    }

    private function recordWebhookFailure(
        string $eventId,
        string $eventType,
        string $payloadHash,
        bool $signatureValid,
        string $errorMessage
    ): void {
        try {
            $this->tools->transaction(function () use ($eventId, $eventType, $payloadHash, $signatureValid, $errorMessage): void {
                $event = mb_substr($eventId, 0, 128);
                $find = $this->db->getQuery(true)
                    ->select([$this->db->quoteName('id'), $this->db->quoteName('status')])
                    ->from($this->db->quoteName('#__memi_square_webhooks'))
                    ->where($this->db->quoteName('event_id') . ' = :event_id')
                    ->bind(':event_id', $event);
                $this->db->setQuery(DatabaseTools::forUpdate($find));
                $existing = $this->db->loadAssoc();
                $existingId = (int) ($existing['id'] ?? 0);
                $now = gmdate('Y-m-d H:i:s');
                $valid = $signatureValid ? 1 : 0;
                $failed = 'failed';
                $message = mb_substr($errorMessage, 0, 500);
                if ($existingId > 0) {
                    $processedStatus = 'processed';
                    if ((string) ($existing['status'] ?? '') === $processedStatus) {
                        // A concurrent duplicate may fail its insert after the
                        // first worker committed. Never turn the successfully
                        // processed event back into a retryable failed event.
                        return;
                    }
                    $update = $this->db->getQuery(true)
                        ->update($this->db->quoteName('#__memi_square_webhooks'))
                        ->set($this->db->quoteName('attempt_count') . ' = LEAST(65535, ' . $this->db->quoteName('attempt_count') . ' + 1)')
                        ->set($this->db->quoteName('signature_valid') . ' = :signature_valid')
                        ->set($this->db->quoteName('status') . ' = :status')
                        ->set($this->db->quoteName('error_message') . ' = :error_message')
                        ->set($this->db->quoteName('updated_at') . ' = :updated_at')
                        ->where($this->db->quoteName('id') . ' = :id')
                        ->where($this->db->quoteName('status') . ' <> :processed_status')
                        ->bind(':signature_valid', $valid, ParameterType::INTEGER)
                        ->bind(':status', $failed)
                        ->bind(':processed_status', $processedStatus)
                        ->bind(':error_message', $message)
                        ->bind(':updated_at', $now)
                        ->bind(':id', $existingId, ParameterType::INTEGER);
                    $this->db->setQuery($update)->execute();

                    return;
                }

                $attemptCount = 1;
                $insert = $this->db->getQuery(true)
                    ->insert($this->db->quoteName('#__memi_square_webhooks'))
                    ->columns(['event_id', 'square_event_id', 'event_type', 'signature_valid', 'payload_hash', 'status', 'received_at', 'attempt_count', 'error_message', 'created_at', 'updated_at'])
                    ->values(':event_id, :square_event_id, :event_type, :signature_valid, :payload_hash, :status, :received_at, :attempt_count, :error_message, :created_at, :updated_at')
                    ->bind(':event_id', $event)
                    ->bind(':square_event_id', $event)
                    ->bind(':event_type', $eventType)
                    ->bind(':signature_valid', $valid, ParameterType::INTEGER)
                    ->bind(':payload_hash', $payloadHash)
                    ->bind(':status', $failed)
                    ->bind(':received_at', $now)
                    ->bind(':attempt_count', $attemptCount, ParameterType::INTEGER)
                    ->bind(':error_message', $message)
                    ->bind(':created_at', $now)
                    ->bind(':updated_at', $now);
                $this->db->setQuery($insert)->execute();
            });
        } catch (\Throwable) {
            // Preserve the original webhook error even if diagnostic storage
            // is unavailable.
        }
    }

    /** @param array<string,mixed> $refund */
    private function syncRefundWebhook(array $refund): void
    {
        $providerRefundId = (string) ($refund['id'] ?? '');
        $providerPaymentId = (string) ($refund['payment_id'] ?? '');
        $amount = (int) ($refund['amount_money']['amount'] ?? -1);
        $currency = strtoupper((string) ($refund['amount_money']['currency'] ?? ''));
        if ($providerRefundId === '' || $providerPaymentId === '' || $amount < 0 || $currency === '') {
            return;
        }

        $query = $this->db->getQuery(true)
            ->select(['r.*', 'p.provider_payment_id', 'p.currency'])
            ->from($this->db->quoteName('#__memi_refunds', 'r'))
            ->join('INNER', $this->db->quoteName('#__memi_payments', 'p') . ' ON p.id = r.payment_id')
            ->where('r.provider_refund_id = :provider_refund_id')
            ->bind(':provider_refund_id', $providerRefundId);
        $query->setLimit(1);
        $this->db->setQuery(DatabaseTools::forUpdate($query));
        $local = $this->db->loadAssoc();
        if (!$local) {
            $processing = 'processing';
            $query = $this->db->getQuery(true)
                ->select(['r.*', 'p.provider_payment_id', 'p.currency'])
                ->from($this->db->quoteName('#__memi_refunds', 'r'))
                ->join('INNER', $this->db->quoteName('#__memi_payments', 'p') . ' ON p.id = r.payment_id')
                ->where('r.provider_refund_id IS NULL')
                ->where('r.status = :processing_status')
                ->where('r.amount_cents = :amount_cents')
                ->where('p.provider_payment_id = :provider_payment_id')
                ->order('r.id DESC')
                ->bind(':processing_status', $processing)
                ->bind(':amount_cents', $amount, ParameterType::INTEGER)
                ->bind(':provider_payment_id', $providerPaymentId);
            $query->setLimit(1);
            $this->db->setQuery(DatabaseTools::forUpdate($query));
            $local = $this->db->loadAssoc();
        }
        if (!$local) {
            return;
        }
        if ((string) $local['provider_payment_id'] !== $providerPaymentId
            || (int) $local['amount_cents'] !== $amount
            || strtoupper((string) $local['currency']) !== $currency) {
            $this->audit->log(null, 'refund.webhook_mismatch', 'refund', (int) $local['id'], null, [
                'payment_matches' => (string) $local['provider_payment_id'] === $providerPaymentId,
                'amount_matches' => (int) $local['amount_cents'] === $amount,
                'currency_matches' => strtoupper((string) $local['currency']) === $currency,
            ]);

            return;
        }

        $status = $this->normaliseRefundStatus((string) ($refund['status'] ?? 'PENDING'));
        $this->updateRefundResult((int) $local['id'], $providerRefundId, $status);
        $this->audit->log(null, 'refund.webhook_status', 'refund', (int) $local['id'], null, ['status' => $status]);
    }

    private function normaliseRefundStatus(string $providerStatus): string
    {
        return match (strtoupper($providerStatus)) {
            'COMPLETED' => 'completed',
            'REJECTED' => 'rejected',
            'FAILED', 'CANCELED', 'CANCELLED' => 'failed',
            'PENDING' => 'pending',
            default => 'processing',
        };
    }

    private function updateRefundResult(int $refundId, string $providerRefundId, string $status): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $terminal = in_array($status, ['completed', 'rejected', 'failed'], true);
        $processedAt = $terminal ? $now : null;
        $failureReason = in_array($status, ['rejected', 'failed'], true) ? $status : '';
        $identifier = $refundId;
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__memi_refunds'))
            ->set($this->db->quoteName('provider_refund_id') . ' = :provider_refund_id')
            ->set($this->db->quoteName('status') . ' = :status')
            ->set($this->db->quoteName('processed_at') . ' = :processed_at')
            ->set($this->db->quoteName('failure_reason') . ' = :failure_reason')
            ->set($this->db->quoteName('updated_at') . ' = :updated_at')
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':provider_refund_id', $providerRefundId)
            ->bind(':status', $status)
            ->bind(':processed_at', $processedAt)
            ->bind(':failure_reason', $failureReason)
            ->bind(':updated_at', $now)
            ->bind(':id', $identifier, ParameterType::INTEGER);
        $this->db->setQuery($query)->execute();
        if (in_array($status, ['rejected', 'failed'], true)) {
            $this->audit->log(null, 'refund.manual_required', 'refund', $refundId, null, [
                'provider_status' => $status,
            ]);
        }
    }

    private function syncPaymentStatus(string $providerPaymentId, string $status): void
    {
        $provider = $providerPaymentId;
        $updatedAt = gmdate('Y-m-d H:i:s');
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__memi_payments'))
            ->set($this->db->quoteName('provider_status') . ' = :provider_status')
            ->set($this->db->quoteName('updated_at') . ' = :updated_at')
            ->where($this->db->quoteName('provider_payment_id') . ' = :provider_payment_id')
            ->bind(':provider_status', $status)
            ->bind(':updated_at', $updatedAt)
            ->bind(':provider_payment_id', $provider);
        $this->db->setQuery($query)->execute();
    }

    /**
     * Completes an order when Square tells us a payment succeeded after the
     * browser request has timed out or the PHP worker has stopped. This method
     * is called inside handleWebhook()'s transaction, which makes fulfillment
     * mutually exclusive with the normal checkout completion transaction.
     *
     * @param array<string,mixed> $payment
     */
    private function reconcileCompletedWebhookPayment(array $payment): void
    {
        if ((string) ($payment['status'] ?? '') !== 'COMPLETED') {
            return;
        }
        $providerPaymentId = (string) ($payment['id'] ?? '');
        if ($providerPaymentId === '') {
            return;
        }
        $reference = (string) ($payment['reference_id'] ?? '');
        $referenceData = $this->parseSquarePaymentReference($reference);
        if ($referenceData === null) {
            return;
        }
        $orderId = $referenceData['order_id'];
        if ($orderId <= 0) {
            return;
        }

        $order = $this->tools->lockById('#__memi_orders', $orderId);
        if ($order === null) {
            return;
        }
        $currentPaymentKey = (string) ($order['idempotency_key'] ?? '');
        if ($referenceData['legacy'] && strncmp($currentPaymentKey, 'pay-', 4) === 0) {
            $auditAction = (string) $order['status'] === 'paid'
                ? 'payment.webhook_duplicate_charge'
                : 'payment.webhook_attempt_mismatch';
            $this->audit->log(null, $auditAction, 'order', $orderId, null, [
                'legacy_reference_on_current_attempt' => true,
            ]);

            return;
        }
        if (!$referenceData['legacy']) {
            $expectedReference = $this->squarePaymentReference($orderId, $currentPaymentKey);
            if (!hash_equals($expectedReference, $reference)) {
                $auditAction = (string) $order['status'] === 'paid'
                    ? 'payment.webhook_duplicate_charge'
                    : 'payment.webhook_attempt_mismatch';
                $this->audit->log(null, $auditAction, 'order', $orderId, null, [
                    'provider_payment_id_present' => true,
                ]);

                return;
            }
        }
        $amount = (int) ($payment['amount_money']['amount'] ?? -1);
        $currency = strtoupper((string) ($payment['amount_money']['currency'] ?? ''));
        $expectedCurrency = strtoupper((string) $order['currency']);
        $location = (string) ($payment['location_id'] ?? '');
        if ($amount !== (int) $order['total_cents'] || $currency !== $expectedCurrency
            || ($this->publicLocationId() !== '' && $location !== $this->publicLocationId())) {
            $this->audit->log(null, 'payment.webhook_mismatch', 'order', $orderId, null, [
                'amount_matches' => $amount === (int) $order['total_cents'],
                'currency_matches' => $currency === $expectedCurrency,
                'location_matches' => $this->publicLocationId() === '' || $location === $this->publicLocationId(),
            ]);

            return;
        }

        if ((string) $order['status'] === 'paid') {
            $existingPayment = $this->paymentForOrder($orderId);
            if ($existingPayment && (string) ($existingPayment['provider_payment_id'] ?? '') !== $providerPaymentId) {
                $this->audit->log(null, 'payment.webhook_duplicate_charge', 'order', $orderId, null, [
                    'existing_payment_id' => (int) $existingPayment['id'],
                ]);
            }
            return;
        }
        if (!in_array((string) $order['status'], ['pending', 'payment_processing', 'payment_failed'], true)) {
            return;
        }

        // Square's Payment object intentionally does not expose the
        // idempotency key used to create it. Reconciliation therefore relies
        // on the signed event plus the attempt-specific reference (which is
        // bound to that key), amount, currency and location.
        $localPaymentKey = (string) ($order['idempotency_key'] ?: $order['order_key']);
        if ($localPaymentKey === '') {
            $localPaymentKey = hash('sha256', 'square-webhook-payment:' . $providerPaymentId);
        }
        $this->prepareSessionOrderForPayment($order);
        $paymentId = $this->insertPayment($order, $payment, $localPaymentKey);

        $now = gmdate('Y-m-d H:i:s');
        $identifier = $orderId;
        $paid = 'paid';
        $update = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__memi_orders'))
            ->set($this->db->quoteName('status') . ' = :status')
            ->set($this->db->quoteName('paid_at') . ' = :paid_at')
            ->set($this->db->quoteName('updated_at') . ' = :updated_at')
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':status', $paid)
            ->bind(':paid_at', $now)
            ->bind(':updated_at', $now)
            ->bind(':id', $identifier, ParameterType::INTEGER);
        $this->db->setQuery($update)->execute();
        $this->fulfillOrderItems((int) $order['user_id'], $orderId);
        $this->audit->log(null, 'payment.webhook_complete', 'payment', $paymentId, null, ['order_id' => $orderId]);
    }

    private function squareBaseUrl(): string
    {
        return $this->isSandbox() ? 'https://connect.squareupsandbox.com' : 'https://connect.squareup.com';
    }

    private function isSandbox(): bool
    {
        return (string) $this->settings->get('square_environment', 'sandbox') !== 'production';
    }
}
