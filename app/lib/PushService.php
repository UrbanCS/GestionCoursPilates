<?php

declare(strict_types=1);

namespace MemiPwa;

use Joomla\Database\DatabaseInterface;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

final class PushService
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    public function isReady(): bool
    {
        return class_exists(WebPush::class)
            && extension_loaded('curl')
            && extension_loaded('mbstring')
            && extension_loaded('openssl')
            && Schema::setting($this->db, 'vapid_public_key') !== ''
            && Schema::setting($this->db, 'vapid_private_key') !== '';
    }

    /**
     * @param list<array<string,mixed>> $deliveries
     * @return array<int,array{success:bool,expired:bool,status:int,reason:string}>
     */
    public function send(array $deliveries): array
    {
        if (!$this->isReady()) {
            throw new \RuntimeException('La librairie Web Push ou les clés VAPID ne sont pas disponibles.');
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => Schema::setting($this->db, 'vapid_subject', 'mailto:info@memistudio.ca'),
                'publicKey' => Schema::setting($this->db, 'vapid_public_key'),
                'privateKey' => Schema::setting($this->db, 'vapid_private_key'),
            ],
        ], [
            'TTL' => 86400,
            'urgency' => 'normal',
            'batchSize' => 100,
        ]);
        $webPush->setReuseVAPIDHeaders(true);

        /** @var array<string,int> $deliveryByEndpoint */
        $deliveryByEndpoint = [];
        foreach ($deliveries as $delivery) {
            $endpoint = (string) ($delivery['endpoint'] ?? '');
            $deliveryId = (int) ($delivery['delivery_id'] ?? 0);
            if ($endpoint === '' || $deliveryId < 1) {
                continue;
            }
            try {
                $subscription = Subscription::create([
                    'endpoint' => $endpoint,
                    'publicKey' => (string) ($delivery['public_key'] ?? ''),
                    'authToken' => (string) ($delivery['auth_token'] ?? ''),
                    'contentEncoding' => (string) ($delivery['content_encoding'] ?? 'aes128gcm'),
                ]);
                $payload = json_encode([
                    'title' => (string) ($delivery['title'] ?? 'Memi Studio'),
                    'body' => (string) ($delivery['body'] ?? ''),
                    'category' => (string) ($delivery['category'] ?? 'other'),
                    'url' => (string) ($delivery['target_url'] ?? '/app/'),
                    'eventId' => (int) ($delivery['event_id'] ?? 0),
                    'icon' => '/app/assets/icons/icon-memi-192.png',
                    'badge' => '/app/assets/icons/badge-96.png',
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $webPush->queueNotification($subscription, $payload);
                $deliveryByEndpoint[hash('sha256', $endpoint)] = $deliveryId;
            } catch (\Throwable $error) {
                $results[$deliveryId] = [
                    'success' => false,
                    'expired' => true,
                    'status' => 0,
                    'reason' => 'Abonnement Web Push invalide : ' . get_debug_type($error),
                ];
            }
        }

        $results ??= [];
        foreach ($webPush->flush() as $report) {
            $endpoint = (string) $report->getRequest()->getUri();
            $deliveryId = $deliveryByEndpoint[hash('sha256', $endpoint)] ?? 0;
            if ($deliveryId < 1) {
                continue;
            }
            $response = $report->getResponse();
            $results[$deliveryId] = [
                'success' => $report->isSuccess(),
                'expired' => $report->isSubscriptionExpired(),
                'status' => $response ? $response->getStatusCode() : 0,
                'reason' => mb_substr(trim((string) $report->getReason()), 0, 500),
            ];
        }

        return $results;
    }
}
