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
            $discount = $this->promotionDiscount($userId, $package, $promotionCode, $subtotal);
            $taxRateBasisPoints = max(0, (int) ($package['tax_rate_basis_points'] ?? 0));
            $taxable = max(0, $subtotal - $discount);
            $tax = (int) round($taxable * $taxRateBasisPoints / 10000);
            $total = $taxable + $tax;
            $orderKey = bin2hex(random_bytes(16));
            $user = $userId;
            $packageIdentifier = $packageId;
            $pendingStatus = 'pending';
            $currency = (string) $this->settings->get('currency', 'CAD');
            $query = $this->db->getQuery(true)
                ->insert($this->db->quoteName('#__memi_orders'))
                ->columns([
                    'client_id', 'user_id', 'status', 'currency', 'subtotal_cents', 'discount_cents', 'tax_cents',
                    'total_cents', 'promotion_code', 'order_key', 'created_at', 'updated_at',
                ])
                ->values(':client_id, :user_id, :status, :currency, :subtotal_cents, :discount_cents, :tax_cents, :total_cents, :promotion_code, :order_key, :created_at, :updated_at')
                ->bind(':client_id', $clientId, ParameterType::INTEGER)
                ->bind(':user_id', $user, ParameterType::INTEGER)
                ->bind(':status', $pendingStatus)
                ->bind(':currency', $currency)
                ->bind(':subtotal_cents', $subtotal, ParameterType::INTEGER)
                ->bind(':discount_cents', $discount, ParameterType::INTEGER)
                ->bind(':tax_cents', $tax, ParameterType::INTEGER)
                ->bind(':total_cents', $total, ParameterType::INTEGER)
                ->bind(':promotion_code', $promotionCode)
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
            $this->markOrderFailed($orderId, 'transport_error');

            throw $error;
        }

        if (($squarePayment['status'] ?? '') !== 'COMPLETED') {
            $this->markOrderFailed($orderId, (string) ($squarePayment['status'] ?? 'unknown'));
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

            $this->fulfillPackageItems($userId, $orderId);
            $this->audit->log($userId, 'payment.complete', 'payment', $paymentId, null, [
                'order_id' => $orderId,
                'amount_cents' => (int) $order['total_cents'],
            ]);

            return [
                'order_id' => $orderId,
                'payment_id' => $paymentId,
                'status' => 'paid',
                'receipt_url' => $squarePayment['receipt_url'] ?? null,
            ];
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

            $paymentKey = (string) ($order['idempotency_key'] ?: $requestedKey);
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
            throw new DomainException('COM_MEMIPILATES_ERROR_WEBHOOK_SIGNATURE', [], 401);
        }
        $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        $eventId = (string) ($payload['event_id'] ?? '');
        if ($eventId === '' || strlen($eventId) > 128) {
            throw new DomainException('COM_MEMIPILATES_ERROR_WEBHOOK_INVALID', [], 400);
        }

        $this->tools->transaction(function () use ($payload, $eventId): void {
            $event = $eventId;
            $find = $this->db->getQuery(true)
                ->select($this->db->quoteName('id'))
                ->from($this->db->quoteName('#__memi_square_webhooks'))
                ->where($this->db->quoteName('event_id') . ' = :event_id')
                ->bind(':event_id', $event);
            $this->db->setQuery(DatabaseTools::forUpdate($find));
            if ((int) $this->db->loadResult() > 0) {
                return;
            }
            $now = gmdate('Y-m-d H:i:s');
            $type = (string) ($payload['type'] ?? 'unknown');
            $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            $processedStatus = 'processed';
            $insert = $this->db->getQuery(true)
                ->insert($this->db->quoteName('#__memi_square_webhooks'))
                ->columns(['event_id', 'event_type', 'payload_hash', 'received_at', 'processed_at', 'status'])
                ->values(':event_id, :event_type, :payload_hash, :received_at, :processed_at, :status')
                ->bind(':event_id', $event)
                ->bind(':event_type', $type)
                ->bind(':payload_hash', $hash)
                ->bind(':received_at', $now)
                ->bind(':processed_at', $now)
                ->bind(':status', $processedStatus);
            $this->db->setQuery($insert)->execute();

            $payment = $payload['data']['object']['payment'] ?? null;
            if (is_array($payment) && isset($payment['id'])) {
                $this->syncPaymentStatus((string) $payment['id'], (string) ($payment['status'] ?? ''));
                $this->reconcileCompletedWebhookPayment($payment);
            }
        });
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

    private function publicApplicationId(): string
    {
        return (string) $this->settings->get('square_application_id', '');
    }

    private function publicLocationId(): string
    {
        return (string) $this->settings->get('square_location_id', '');
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
            'reference_id' => 'memi-order-' . (int) $order['id'],
            'note' => 'Memi Studio order ' . (int) $order['id'],
        ];
        $response = $this->request('POST', $this->squareBaseUrl() . '/v2/payments', $body, [
            'Authorization: Bearer ' . $accessToken,
            'Square-Version: 2024-08-21',
        ]);
        if ($response['status'] < 200 || $response['status'] >= 300 || !isset($response['json']['payment'])) {
            $this->audit->log(null, 'payment.square_error', 'order', (int) $order['id'], null, [
                'http_status' => $response['status'],
                'error_count' => count($response['json']['errors'] ?? []),
            ]);
            throw new DomainException('COM_MEMIPILATES_ERROR_PAYMENT_FAILED');
        }

        /** @var array<string,mixed> $payment */
        $payment = $response['json']['payment'];

        return $payment;
    }

    /** @return array{status:int,json:array<string,mixed>} */
    private function request(string $method, string $url, array $body, array $headers): array
    {
        if (!function_exists('curl_init')) {
            throw new DomainException('COM_MEMIPILATES_ERROR_HTTP_CLIENT_UNAVAILABLE', [], 503);
        }
        $curl = curl_init($url);
        if ($curl === false) {
            throw new DomainException('COM_MEMIPILATES_ERROR_PAYMENT_FAILED');
        }
        $json = json_encode($body, JSON_THROW_ON_ERROR);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $responseBody = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $decoded = is_string($responseBody) ? json_decode($responseBody, true) : null;

        return ['status' => $status, 'json' => is_array($decoded) ? $decoded : []];
    }

    private function insertPayment(array $order, array $squarePayment, string $idempotencyKey): int
    {
        $providerId = (string) $squarePayment['id'];
        $existing = $this->db->getQuery(true)
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__memi_payments'))
            ->where($this->db->quoteName('provider_payment_id') . ' = :provider_payment_id')
            ->bind(':provider_payment_id', $providerId);
        $this->db->setQuery($existing);
        $existingId = (int) $this->db->loadResult();
        if ($existingId > 0) {
            return $existingId;
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
            if ($bonus > 0) {
                $this->points->award($userId, $bonus, 'package_purchase', 'order:' . $orderId . ':points', null, $orderId, $userId, 'Points de forfait');
            }
        }

        $orderData = $this->tools->lockById('#__memi_orders', $orderId);
        if ($orderData) {
            $perDollar = max(0, $this->settings->getInt('points_per_dollar', 0));
            $orderPoints = (int) floor((int) $orderData['total_cents'] / 100) * $perDollar;
            if ($orderPoints > 0) {
                $this->points->award($userId, $orderPoints, 'order_paid', 'order:' . $orderId . ':spend-points', null, $orderId, $userId, 'Points d’achat');
            }
        }
        $this->notifications->queue($userId, 'payment.receipt', ['order_id' => $orderId]);
    }

    private function promotionDiscount(int $userId, array $package, ?string $code, int $subtotal): int
    {
        // The booking flow deliberately validates promotions on the server.
        // Full eligibility is stored in promotion_json by the administrator.
        if ($code === null || trim($code) === '') {
            return 0;
        }
        $normalised = mb_strtoupper(trim($code));
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__memi_promotions'))
            ->where($this->db->quoteName('code') . ' = :code')
            ->where($this->db->quoteName('published') . ' = 1')
            ->bind(':code', $normalised);
        $this->db->setQuery($query);
        $promotion = $this->db->loadAssoc();
        if (!$promotion) {
            throw new DomainException('COM_MEMIPILATES_ERROR_PROMOTION_INVALID');
        }
        $now = gmdate('Y-m-d H:i:s');
        if ((!empty($promotion['starts_at']) && (string) $promotion['starts_at'] > $now)
            || (!empty($promotion['ends_at']) && (string) $promotion['ends_at'] < $now)
            || (int) $promotion['minimum_amount_cents'] > $subtotal) {
            throw new DomainException('COM_MEMIPILATES_ERROR_PROMOTION_INVALID');
        }
        $fixed = max(0, (int) ($promotion['discount_cents'] ?? 0));
        $percentBasisPoints = max(0, (int) ($promotion['discount_basis_points'] ?? 0));

        return min($subtotal, max($fixed, (int) round($subtotal * $percentBasisPoints / 10000)));
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
        $reference = (string) ($payment['reference_id'] ?? '');
        if (!preg_match('/^memi-order-(\d+)$/D', $reference, $matches)) {
            return;
        }
        $orderId = (int) $matches[1];
        if ($orderId <= 0) {
            return;
        }

        $order = $this->tools->lockById('#__memi_orders', $orderId);
        if ($order === null) {
            return;
        }
        $amount = (int) ($payment['amount_money']['amount'] ?? -1);
        $currency = strtoupper((string) ($payment['amount_money']['currency'] ?? ''));
        $expectedCurrency = strtoupper((string) $order['currency']);
        $paymentKey = (string) ($payment['idempotency_key'] ?? '');
        $orderKey = (string) ($order['idempotency_key'] ?? '');
        $location = (string) ($payment['location_id'] ?? '');
        if ($amount !== (int) $order['total_cents'] || $currency !== $expectedCurrency
            || ($this->publicLocationId() !== '' && $location !== $this->publicLocationId())
            || ($orderKey !== '' && ($paymentKey === '' || !hash_equals($orderKey, $paymentKey)))) {
            $this->audit->log(null, 'payment.webhook_mismatch', 'order', $orderId, null, [
                'amount_matches' => $amount === (int) $order['total_cents'],
                'currency_matches' => $currency === $expectedCurrency,
                'location_matches' => $this->publicLocationId() === '' || $location === $this->publicLocationId(),
                'idempotency_matches' => $orderKey === '' || ($paymentKey !== '' && hash_equals($orderKey, $paymentKey)),
            ]);

            return;
        }

        $paymentId = $this->insertPayment($order, $payment, $paymentKey !== '' ? $paymentKey : (string) $order['order_key']);
        if ((string) $order['status'] === 'paid') {
            return;
        }
        if (!in_array((string) $order['status'], ['pending', 'payment_processing', 'payment_failed'], true)) {
            return;
        }

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
        $this->fulfillPackageItems((int) $order['user_id'], $orderId);
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
