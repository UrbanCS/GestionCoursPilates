<?php

declare(strict_types=1);

namespace MemiPwa;

use Joomla\Database\DatabaseInterface;

final class CronRunner
{
    private readonly DatabaseInterface $db;

    public function __construct(private readonly Context $context)
    {
        $this->db = $context->database();
    }

    /** @return array{captured:int,queued:int,delivered:int,retried:int,failed:int} */
    public function run(): array
    {
        if (!$this->lock()) {
            throw new \RuntimeException('Une autre exécution du cron est déjà en cours.');
        }

        $stats = ['captured' => 0, 'queued' => 0, 'delivered' => 0, 'retried' => 0, 'failed' => 0];
        try {
            $stats['captured'] = $this->captureEvents();
            $stats['queued'] = $this->queueDeliveries();
            $deliveryStats = $this->deliver();
            $stats = array_merge($stats, $deliveryStats);
            Schema::setSetting($this->db, 'last_cron_at', gmdate('Y-m-d H:i:s'));
            Schema::setSetting($this->db, 'last_cron_result', json_encode($stats, JSON_THROW_ON_ERROR));

            return $stats;
        } finally {
            $this->unlock();
        }
    }

    private function captureEvents(): int
    {
        $installedAt = Schema::setting($this->db, 'installed_at', gmdate('Y-m-d H:i:s'));
        $captured = 0;

        $courseSql = 'SELECT s.id, s.starts_at, s.created_at AS source_date, c.title, c.description'
            . ' FROM #__memi_sessions AS s INNER JOIN #__memi_courses AS c ON c.id = s.course_id'
            . ' WHERE s.created_at > ' . $this->db->quote($installedAt)
            . ' AND s.status IN (' . $this->db->quote('published') . ', ' . $this->db->quote('open') . ')'
            . ' AND s.is_private = 0 AND s.archived_at IS NULL AND c.published = 1 AND c.archived_at IS NULL';
        foreach ($this->rows($courseSql) as $row) {
            $date = $this->studioDate((string) $row['starts_at']);
            $captured += $this->insertEvent(
                'courses',
                'session',
                (int) $row['id'],
                'Nouveau cours : ' . (string) $row['title'],
                $date === '' ? 'Un nouveau cours est maintenant offert.' : 'Nouvelle séance le ' . $date . '.',
                '/index.php/horaire-des-cours?view=schedule',
                (string) $row['source_date'],
                null
            );
        }

        $promotionSql = 'SELECT id, title, description, code, ends_at, created_at AS source_date'
            . ' FROM #__memi_promotions WHERE created_at > ' . $this->db->quote($installedAt)
            . ' AND published = 1 AND archived_at IS NULL'
            . ' AND (starts_at IS NULL OR starts_at <= UTC_TIMESTAMP())'
            . ' AND (ends_at IS NULL OR ends_at >= UTC_TIMESTAMP())';
        foreach ($this->rows($promotionSql) as $row) {
            $body = trim(strip_tags((string) ($row['description'] ?? '')));
            if ($body === '') {
                $body = 'Une nouvelle promotion est offerte chez Memi Studio.';
            }
            $captured += $this->insertEvent(
                'promotions',
                'promotion',
                (int) $row['id'],
                (string) $row['title'],
                mb_substr($body, 0, 240),
                '/index.php/component/memipilates/?view=catalog&entity=package',
                (string) $row['source_date'],
                $row['ends_at'] ? (string) $row['ends_at'] : null
            );
        }

        $rewardSql = 'SELECT id, title, description, available_until, created_at AS source_date'
            . ' FROM #__memi_rewards WHERE created_at > ' . $this->db->quote($installedAt)
            . ' AND published = 1 AND archived_at IS NULL'
            . ' AND (available_from IS NULL OR available_from <= UTC_TIMESTAMP())'
            . ' AND (available_until IS NULL OR available_until >= UTC_TIMESTAMP())';
        foreach ($this->rows($rewardSql) as $row) {
            $body = trim(strip_tags((string) ($row['description'] ?? '')));
            if ($body === '') {
                $body = 'Une nouvelle récompense est offerte avec vos points de fidélité.';
            }
            $captured += $this->insertEvent(
                'other',
                'reward',
                (int) $row['id'],
                'Nouvelle récompense : ' . (string) $row['title'],
                mb_substr($body, 0, 240),
                '/index.php/component/memipilates/?view=dashboard',
                (string) $row['source_date'],
                $row['available_until'] ? (string) $row['available_until'] : null
            );
        }

        $announcementSql = 'SELECT id, title, body, target_url, published_at, expires_at'
            . ' FROM #__memi_pwa_announcements WHERE created_at > ' . $this->db->quote($installedAt)
            . ' AND status = ' . $this->db->quote('published')
            . ' AND published_at <= UTC_TIMESTAMP() AND (expires_at IS NULL OR expires_at >= UTC_TIMESTAMP())';
        foreach ($this->rows($announcementSql) as $row) {
            $captured += $this->insertEvent(
                'other',
                'announcement',
                (int) $row['id'],
                (string) $row['title'],
                mb_substr(trim((string) $row['body']), 0, 240),
                (string) ($row['target_url'] ?: '/app/'),
                (string) $row['published_at'],
                $row['expires_at'] ? (string) $row['expires_at'] : null
            );
        }

        return $captured;
    }

    private function insertEvent(
        string $category,
        string $sourceType,
        int $sourceId,
        string $title,
        string $body,
        string $url,
        string $availableAt,
        ?string $expiresAt
    ): int {
        $dedupe = hash('sha256', $sourceType . ':' . $sourceId);
        $now = gmdate('Y-m-d H:i:s');
        $sql = 'INSERT IGNORE INTO #__memi_pwa_events ('
            . 'category, source_type, source_id, dedupe_key, title, body, target_url, available_at, expires_at, status, created_at, updated_at'
            . ') VALUES (' . implode(', ', [
                $this->db->quote($category), $this->db->quote($sourceType), (string) $sourceId,
                $this->db->quote($dedupe), $this->db->quote(mb_substr($title, 0, 180)), $this->db->quote($body),
                $this->db->quote($url), $this->db->quote($availableAt), $expiresAt ? $this->db->quote($expiresAt) : 'NULL',
                $this->db->quote('queued'), $this->db->quote($now), $this->db->quote($now),
            ]) . ')';
        $this->db->setQuery($this->db->replacePrefix($sql))->execute();

        return $this->db->getAffectedRows() > 0 ? 1 : 0;
    }

    private function queueDeliveries(): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $sql = 'INSERT IGNORE INTO #__memi_pwa_deliveries ('
            . 'event_id, subscription_id, user_id, status, attempt_count, next_attempt_at, created_at, updated_at)'
            . ' SELECT e.id, s.id, s.user_id, ' . $this->db->quote('queued') . ', 0, '
            . $this->db->quote($now) . ', ' . $this->db->quote($now) . ', ' . $this->db->quote($now)
            . ' FROM #__memi_pwa_events AS e'
            . ' INNER JOIN #__memi_pwa_push_subscriptions AS s ON s.enabled = 1'
            . ' INNER JOIN #__memi_pwa_preferences AS p ON p.user_id = s.user_id'
            . ' WHERE e.status = ' . $this->db->quote('queued')
            . ' AND e.available_at <= ' . $this->db->quote($now)
            . ' AND (e.expires_at IS NULL OR e.expires_at >= ' . $this->db->quote($now) . ')'
            . ' AND ((e.category = ' . $this->db->quote('courses') . ' AND p.notify_courses = 1)'
            . ' OR (e.category = ' . $this->db->quote('promotions') . ' AND p.notify_promotions = 1)'
            . ' OR (e.category = ' . $this->db->quote('other') . ' AND p.notify_other = 1))';
        $this->db->setQuery($this->db->replacePrefix($sql))->execute();
        $queued = $this->db->getAffectedRows();

        $update = 'UPDATE #__memi_pwa_events SET status = ' . $this->db->quote('distributed')
            . ', updated_at = ' . $this->db->quote($now)
            . ' WHERE status = ' . $this->db->quote('queued') . ' AND available_at <= ' . $this->db->quote($now);
        $this->db->setQuery($this->db->replacePrefix($update))->execute();

        return max(0, $queued);
    }

    /** @return array{delivered:int,retried:int,failed:int} */
    private function deliver(): array
    {
        $stats = ['delivered' => 0, 'retried' => 0, 'failed' => 0];
        $now = gmdate('Y-m-d H:i:s');
        $sql = 'SELECT d.id AS delivery_id, d.event_id, d.subscription_id, d.attempt_count,'
            . ' e.category, e.title, e.body, e.target_url,'
            . ' s.endpoint, s.public_key, s.auth_token, s.content_encoding'
            . ' FROM #__memi_pwa_deliveries AS d'
            . ' INNER JOIN #__memi_pwa_events AS e ON e.id = d.event_id'
            . ' INNER JOIN #__memi_pwa_push_subscriptions AS s ON s.id = d.subscription_id AND s.enabled = 1'
            . ' WHERE d.status IN (' . $this->db->quote('queued') . ', ' . $this->db->quote('retry') . ')'
            . ' AND d.next_attempt_at <= ' . $this->db->quote($now)
            . ' ORDER BY d.id ASC LIMIT 100';
        $deliveries = $this->rows($sql);
        if ($deliveries === []) {
            return $stats;
        }

        try {
            $results = (new PushService($this->db))->send($deliveries);
        } catch (\Throwable $error) {
            error_log('Memi PWA push batch failed: ' . $error->getMessage());
            return $stats;
        }

        foreach ($deliveries as $delivery) {
            $deliveryId = (int) $delivery['delivery_id'];
            $subscriptionId = (int) $delivery['subscription_id'];
            $attempt = (int) $delivery['attempt_count'] + 1;
            $result = $results[$deliveryId] ?? ['success' => false, 'expired' => false, 'status' => 0, 'reason' => 'Aucun rapport Web Push reçu.'];
            $stamp = gmdate('Y-m-d H:i:s');
            if ($result['success']) {
                $this->updateDelivery($deliveryId, 'delivered', $attempt, $stamp, $stamp, null, null);
                $this->updateSubscription($subscriptionId, true, false, $stamp);
                ++$stats['delivered'];
                continue;
            }

            $permanent = $result['expired'] || in_array((int) $result['status'], [404, 410], true) || $attempt >= 5;
            if ($permanent) {
                $this->updateDelivery($deliveryId, 'failed', $attempt, $stamp, null, null, $result['reason']);
                $disable = $result['expired'] || in_array((int) $result['status'], [404, 410], true);
                $this->updateSubscription($subscriptionId, false, $disable, $stamp);
                ++$stats['failed'];
                continue;
            }

            $minutes = min(240, 5 * (2 ** ($attempt - 1)));
            $next = gmdate('Y-m-d H:i:s', time() + ($minutes * 60));
            $this->updateDelivery($deliveryId, 'retry', $attempt, $stamp, null, $next, $result['reason']);
            $this->updateSubscription($subscriptionId, false, false, $stamp);
            ++$stats['retried'];
        }

        return $stats;
    }

    private function updateDelivery(int $id, string $status, int $attempt, string $lastAttempt, ?string $delivered, ?string $next, ?string $reason): void
    {
        $next ??= $lastAttempt;
        $sql = 'UPDATE #__memi_pwa_deliveries SET status = ' . $this->db->quote($status)
            . ', attempt_count = ' . $attempt . ', last_attempt_at = ' . $this->db->quote($lastAttempt)
            . ', delivered_at = ' . ($delivered ? $this->db->quote($delivered) : 'NULL')
            . ', next_attempt_at = ' . $this->db->quote($next)
            . ', error_code = ' . ($reason ? $this->db->quote(mb_substr($reason, 0, 80)) : 'NULL')
            . ', updated_at = ' . $this->db->quote($lastAttempt) . ' WHERE id = ' . $id;
        $this->db->setQuery($this->db->replacePrefix($sql))->execute();
    }

    private function updateSubscription(int $id, bool $success, bool $disable, string $stamp): void
    {
        $sql = 'UPDATE #__memi_pwa_push_subscriptions SET '
            . ($success
                ? 'failure_count = 0, last_success_at = ' . $this->db->quote($stamp)
                : 'failure_count = failure_count + 1, last_failure_at = ' . $this->db->quote($stamp))
            . ($disable ? ', enabled = 0' : '')
            . ', updated_at = ' . $this->db->quote($stamp) . ' WHERE id = ' . $id;
        $this->db->setQuery($this->db->replacePrefix($sql))->execute();
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sql): array
    {
        $this->db->setQuery($this->db->replacePrefix($sql));

        return $this->db->loadAssocList() ?: [];
    }

    private function studioDate(string $utc): string
    {
        try {
            return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('America/Toronto'))
                ->format('j M \à H\hi');
        } catch (\Throwable) {
            return '';
        }
    }

    private function lock(): bool
    {
        $this->db->setQuery("SELECT GET_LOCK('memi_pwa_cron', 0)");

        return (int) $this->db->loadResult() === 1;
    }

    private function unlock(): void
    {
        try {
            $this->db->setQuery("SELECT RELEASE_LOCK('memi_pwa_cron')")->loadResult();
        } catch (\Throwable) {
        }
    }
}
