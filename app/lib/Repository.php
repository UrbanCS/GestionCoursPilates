<?php

declare(strict_types=1);

namespace MemiPwa;

use Joomla\Database\DatabaseInterface;

final class Repository
{
    private readonly DatabaseInterface $db;

    public function __construct(private readonly Context $context)
    {
        $this->db = $context->database();
    }

    /** @return array<string,mixed> */
    public function state(): array
    {
        $publicKey = Schema::setting($this->db, 'vapid_public_key');
        $pushReady = (new PushService($this->db))->isReady();
        if (!$this->context->isAuthenticated()) {
            return [
                'authenticated' => false,
                'csrf' => $this->context->csrfToken(),
                'pushAvailable' => $pushReady,
                'vapidPublicKey' => $publicKey,
            ];
        }

        $userId = $this->context->userId();
        $this->ensurePreferences($userId);
        $identity = $this->context->identity();
        $preferences = $this->preferences($userId);

        return [
            'authenticated' => true,
            'csrf' => $this->context->csrfToken(),
            'user' => [
                'id' => $userId,
                'name' => trim((string) ($identity->name ?? '')),
                'email' => trim((string) ($identity->email ?? '')),
                'administrator' => $this->context->isAdministrator(),
            ],
            'metrics' => $this->metrics($userId),
            'preferences' => $preferences,
            'subscribed' => $this->subscriptionCount($userId) > 0,
            'pushAvailable' => $pushReady,
            'vapidPublicKey' => $publicKey,
            'courses' => $this->upcomingCourses(),
            'promotions' => $this->promotions(),
            'announcements' => $this->announcements(),
            'notifications' => $this->notifications($preferences),
            'admin' => $this->context->isAdministrator() ? $this->adminState() : null,
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    /** @return array{courses:bool,promotions:bool,other:bool} */
    public function savePreferences(int $userId, array $input): array
    {
        $values = [
            'courses' => !empty($input['courses']) ? 1 : 0,
            'promotions' => !empty($input['promotions']) ? 1 : 0,
            'other' => !empty($input['other']) ? 1 : 0,
        ];
        $now = gmdate('Y-m-d H:i:s');
        $sql = 'INSERT INTO ' . $this->db->quoteName('#__memi_pwa_preferences')
            . ' (' . implode(', ', array_map([$this->db, 'quoteName'], ['user_id', 'notify_courses', 'notify_promotions', 'notify_other', 'created_at', 'updated_at'])) . ')'
            . ' VALUES (' . $userId . ', ' . $values['courses'] . ', ' . $values['promotions'] . ', ' . $values['other'] . ', '
            . $this->db->quote($now) . ', ' . $this->db->quote($now) . ')'
            . ' ON DUPLICATE KEY UPDATE '
            . $this->db->quoteName('notify_courses') . ' = VALUES(' . $this->db->quoteName('notify_courses') . '), '
            . $this->db->quoteName('notify_promotions') . ' = VALUES(' . $this->db->quoteName('notify_promotions') . '), '
            . $this->db->quoteName('notify_other') . ' = VALUES(' . $this->db->quoteName('notify_other') . '), '
            . $this->db->quoteName('updated_at') . ' = VALUES(' . $this->db->quoteName('updated_at') . ')';
        $this->db->setQuery($sql)->execute();

        return array_map(static fn (int $value): bool => $value === 1, $values);
    }

    /** @param array<string,mixed> $subscription */
    public function saveSubscription(int $userId, array $subscription): int
    {
        $endpoint = trim((string) ($subscription['endpoint'] ?? ''));
        $keys = isset($subscription['keys']) && is_array($subscription['keys']) ? $subscription['keys'] : [];
        $publicKey = trim((string) ($keys['p256dh'] ?? ''));
        $authToken = trim((string) ($keys['auth'] ?? ''));
        $encoding = trim((string) ($subscription['contentEncoding'] ?? 'aes128gcm'));
        if (strlen($endpoint) > 4096 || !str_starts_with($endpoint, 'https://')) {
            throw new HttpError('L’abonnement de notification est invalide.', 422, 'invalid_subscription');
        }
        if (!preg_match('/^[A-Za-z0-9_-]{20,255}$/D', $publicKey) || !preg_match('/^[A-Za-z0-9_-]{8,255}$/D', $authToken)) {
            throw new HttpError('Les clés de notification sont invalides.', 422, 'invalid_subscription_keys');
        }
        if (!in_array($encoding, ['aes128gcm', 'aesgcm'], true)) {
            $encoding = 'aes128gcm';
        }

        $hash = hash('sha256', $endpoint);
        $now = gmdate('Y-m-d H:i:s');
        $sql = 'INSERT INTO ' . $this->db->quoteName('#__memi_pwa_push_subscriptions')
            . ' (' . implode(', ', array_map([$this->db, 'quoteName'], [
                'user_id', 'endpoint_hash', 'endpoint', 'public_key', 'auth_token', 'content_encoding',
                'enabled', 'failure_count', 'created_at', 'updated_at',
            ])) . ') VALUES ('
            . $userId . ', ' . $this->db->quote($hash) . ', ' . $this->db->quote($endpoint) . ', '
            . $this->db->quote($publicKey) . ', ' . $this->db->quote($authToken) . ', ' . $this->db->quote($encoding) . ', '
            . '1, 0, ' . $this->db->quote($now) . ', ' . $this->db->quote($now) . ')'
            . ' ON DUPLICATE KEY UPDATE '
            . $this->db->quoteName('user_id') . ' = VALUES(' . $this->db->quoteName('user_id') . '), '
            . $this->db->quoteName('public_key') . ' = VALUES(' . $this->db->quoteName('public_key') . '), '
            . $this->db->quoteName('auth_token') . ' = VALUES(' . $this->db->quoteName('auth_token') . '), '
            . $this->db->quoteName('content_encoding') . ' = VALUES(' . $this->db->quoteName('content_encoding') . '), '
            . $this->db->quoteName('enabled') . ' = 1, ' . $this->db->quoteName('failure_count') . ' = 0, '
            . $this->db->quoteName('updated_at') . ' = VALUES(' . $this->db->quoteName('updated_at') . ')';
        $this->db->setQuery($sql)->execute();

        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__memi_pwa_push_subscriptions'))
            ->where($this->db->quoteName('endpoint_hash') . ' = ' . $this->db->quote($hash));
        $this->db->setQuery($query, 0, 1);

        return (int) $this->db->loadResult();
    }

    public function removeSubscription(int $userId, string $endpoint): void
    {
        $hash = hash('sha256', trim($endpoint));
        $now = gmdate('Y-m-d H:i:s');
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__memi_pwa_push_subscriptions'))
            ->set($this->db->quoteName('enabled') . ' = 0')
            ->set($this->db->quoteName('updated_at') . ' = ' . $this->db->quote($now))
            ->where($this->db->quoteName('user_id') . ' = ' . $userId)
            ->where($this->db->quoteName('endpoint_hash') . ' = ' . $this->db->quote($hash));
        $this->db->setQuery($query)->execute();
    }

    /** @return array<string,mixed> */
    public function createAnnouncement(int $administratorId, array $input): array
    {
        $title = Api::text($input['title'] ?? '', 180);
        $body = Api::text($input['body'] ?? '', 1200);
        $url = trim((string) ($input['url'] ?? ''));
        if ($title === '' || $body === '') {
            throw new HttpError('Le titre et le message sont obligatoires.', 422, 'announcement_incomplete');
        }
        if ($url !== '' && (!str_starts_with($url, '/') || str_starts_with($url, '//'))) {
            throw new HttpError('Le lien doit être un chemin du site commençant par « / ».', 422, 'invalid_announcement_url');
        }
        $expiresAt = trim((string) ($input['expiresAt'] ?? ''));
        if ($expiresAt !== '') {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $expiresAt, new \DateTimeZone('America/Toronto'));
            if (!$parsed) {
                throw new HttpError('La date d’expiration est invalide.', 422, 'invalid_announcement_expiry');
            }
            $expiresAt = $parsed->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } else {
            $expiresAt = '';
        }

        $now = gmdate('Y-m-d H:i:s');
        $query = $this->db->getQuery(true)
            ->insert($this->db->quoteName('#__memi_pwa_announcements'))
            ->columns(array_map([$this->db, 'quoteName'], [
                'title', 'body', 'target_url', 'status', 'published_at', 'expires_at',
                'created_by', 'created_at', 'updated_at',
            ]))
            ->values(implode(', ', [
                $this->db->quote($title), $this->db->quote($body), $url === '' ? 'NULL' : $this->db->quote($url),
                $this->db->quote('published'), $this->db->quote($now), $expiresAt === '' ? 'NULL' : $this->db->quote($expiresAt),
                (string) $administratorId, $this->db->quote($now), $this->db->quote($now),
            ]));
        $this->db->setQuery($query)->execute();
        $id = (int) $this->db->insertid();

        return ['id' => $id, 'title' => $title, 'publishedAt' => self::iso($now)];
    }

    /** @return array{courses:bool,promotions:bool,other:bool} */
    public function preferences(int $userId): array
    {
        $query = $this->db->getQuery(true)
            ->select(['notify_courses', 'notify_promotions', 'notify_other'])
            ->from($this->db->quoteName('#__memi_pwa_preferences'))
            ->where($this->db->quoteName('user_id') . ' = ' . $userId);
        $this->db->setQuery($query, 0, 1);
        $row = $this->db->loadAssoc() ?: [];

        return [
            'courses' => (int) ($row['notify_courses'] ?? 0) === 1,
            'promotions' => (int) ($row['notify_promotions'] ?? 0) === 1,
            'other' => (int) ($row['notify_other'] ?? 0) === 1,
        ];
    }

    private function ensurePreferences(int $userId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $sql = 'INSERT IGNORE INTO ' . $this->db->quoteName('#__memi_pwa_preferences')
            . ' (' . implode(', ', array_map([$this->db, 'quoteName'], ['user_id', 'created_at', 'updated_at'])) . ')'
            . ' VALUES (' . $userId . ', ' . $this->db->quote($now) . ', ' . $this->db->quote($now) . ')';
        $this->db->setQuery($sql)->execute();
    }

    /** @return array{credits:int,points:int,upcomingBookings:int} */
    private function metrics(int $userId): array
    {
        $now = gmdate('Y-m-d H:i:s');
        // Match the component ledger services exactly: expirations are recorded as
        // balancing entries, so filtering old rows would count the debit twice.
        $credits = $this->scalar('SELECT COALESCE(SUM(credits_delta), 0) FROM #__memi_credit_ledger WHERE user_id = ' . $userId);
        $points = $this->scalar('SELECT COALESCE(SUM(points_delta), 0) FROM #__memi_points_ledger WHERE user_id = ' . $userId);
        $bookings = $this->scalar('SELECT COUNT(*) FROM #__memi_bookings AS b INNER JOIN #__memi_sessions AS s ON s.id = b.session_id'
            . ' WHERE b.user_id = ' . $userId . ' AND b.status IN (' . $this->db->quote('confirmed') . ', ' . $this->db->quote('pending') . ')'
            . ' AND s.starts_at >= ' . $this->db->quote($now));

        return ['credits' => max(0, $credits), 'points' => max(0, $points), 'upcomingBookings' => max(0, $bookings)];
    }

    /** @return list<array<string,mixed>> */
    private function upcomingCourses(): array
    {
        $levels = array_values(array_filter(array_map('intval', $this->context->identity()->getAuthorisedViewLevels() ?? [1]), static fn (int $id): bool => $id > 0));
        $access = $levels !== [] ? implode(',', $levels) : '1';
        $now = gmdate('Y-m-d H:i:s');
        $query = $this->db->getQuery(true)
            ->select(['s.id', 's.starts_at', 's.ends_at', 's.capacity', 's.reserved_count', 'c.title', 'c.description', 'i.display_name AS instructor', 'r.title AS room'])
            ->from($this->db->quoteName('#__memi_sessions', 's'))
            ->join('INNER', $this->db->quoteName('#__memi_courses', 'c') . ' ON c.id = s.course_id')
            ->join('LEFT', $this->db->quoteName('#__memi_instructors', 'i') . ' ON i.id = s.instructor_id')
            ->join('LEFT', $this->db->quoteName('#__memi_rooms', 'r') . ' ON r.id = s.room_id')
            ->where('s.starts_at >= ' . $this->db->quote($now))
            ->where('s.status IN (' . $this->db->quote('published') . ', ' . $this->db->quote('open') . ')')
            ->where('s.is_private = 0')
            ->where('s.archived_at IS NULL')
            ->where('c.published = 1')
            ->where('c.archived_at IS NULL')
            ->where('c.access IN (' . $access . ')')
            ->order('s.starts_at ASC');
        $this->db->setQuery($query, 0, 8);

        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'description' => trim(strip_tags((string) ($row['description'] ?? ''))),
                'startsAt' => self::iso((string) $row['starts_at']),
                'endsAt' => self::iso((string) $row['ends_at']),
                'instructor' => trim((string) ($row['instructor'] ?? '')),
                'room' => trim((string) ($row['room'] ?? '')),
                'remaining' => max(0, (int) $row['capacity'] - (int) $row['reserved_count']),
                'url' => '/index.php/component/memipilates/?view=booking&session_id=' . (int) $row['id'],
            ];
        }, $this->db->loadAssocList() ?: []);
    }

    /** @return list<array<string,mixed>> */
    private function promotions(): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $query = $this->db->getQuery(true)
            ->select(['id', 'code', 'title', 'description', 'discount_type', 'discount_cents', 'discount_basis_points', 'bonus_credits', 'bonus_points', 'starts_at', 'ends_at'])
            ->from($this->db->quoteName('#__memi_promotions'))
            ->where('published = 1')
            ->where('archived_at IS NULL')
            ->where('(starts_at IS NULL OR starts_at <= ' . $this->db->quote($now) . ')')
            ->where('(ends_at IS NULL OR ends_at >= ' . $this->db->quote($now) . ')')
            ->order('created_at DESC');
        $this->db->setQuery($query, 0, 8);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'title' => (string) $row['title'],
            'description' => trim(strip_tags((string) ($row['description'] ?? ''))),
            'discountCents' => (int) $row['discount_cents'],
            'discountPercent' => round((int) $row['discount_basis_points'] / 100, 2),
            'bonusCredits' => (int) $row['bonus_credits'],
            'bonusPoints' => (int) $row['bonus_points'],
            'endsAt' => self::iso((string) ($row['ends_at'] ?? '')),
            'url' => '/index.php/component/memipilates/?view=offers',
        ], $this->db->loadAssocList() ?: []);
    }

    /** @return list<array<string,mixed>> */
    private function announcements(): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $query = $this->db->getQuery(true)
            ->select(['id', 'title', 'body', 'target_url', 'published_at', 'expires_at'])
            ->from($this->db->quoteName('#__memi_pwa_announcements'))
            ->where('status = ' . $this->db->quote('published'))
            ->where('published_at <= ' . $this->db->quote($now))
            ->where('(expires_at IS NULL OR expires_at >= ' . $this->db->quote($now) . ')')
            ->order('published_at DESC');
        $this->db->setQuery($query, 0, 12);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'body' => (string) $row['body'],
            'url' => (string) ($row['target_url'] ?? ''),
            'publishedAt' => self::iso((string) $row['published_at']),
            'expiresAt' => self::iso((string) ($row['expires_at'] ?? '')),
        ], $this->db->loadAssocList() ?: []);
    }

    /** @param array{courses:bool,promotions:bool,other:bool} $preferences
     *  @return list<array<string,mixed>>
     */
    private function notifications(array $preferences): array
    {
        $enabled = array_keys(array_filter($preferences));
        if ($enabled === []) {
            return [];
        }
        $now = gmdate('Y-m-d H:i:s');
        $categories = implode(', ', array_map([$this->db, 'quote'], $enabled));
        $query = $this->db->getQuery(true)
            ->select(['id', 'category', 'title', 'body', 'target_url', 'available_at'])
            ->from($this->db->quoteName('#__memi_pwa_events'))
            ->where('category IN (' . $categories . ')')
            ->where('available_at <= ' . $this->db->quote($now))
            ->where('(expires_at IS NULL OR expires_at >= ' . $this->db->quote($now) . ')')
            ->order('available_at DESC');
        $this->db->setQuery($query, 0, 40);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'category' => (string) $row['category'],
            'title' => (string) $row['title'],
            'body' => (string) $row['body'],
            'url' => (string) ($row['target_url'] ?? ''),
            'availableAt' => self::iso((string) $row['available_at']),
        ], $this->db->loadAssocList() ?: []);
    }

    /** @return array<string,mixed> */
    private function adminState(): array
    {
        return [
            'activeSubscriptions' => $this->scalar('SELECT COUNT(*) FROM #__memi_pwa_push_subscriptions WHERE enabled = 1'),
            'optedInUsers' => $this->scalar('SELECT COUNT(*) FROM #__memi_pwa_preferences WHERE notify_courses = 1 OR notify_promotions = 1 OR notify_other = 1'),
            'queuedDeliveries' => $this->scalar('SELECT COUNT(*) FROM #__memi_pwa_deliveries WHERE status IN (' . $this->db->quote('queued') . ', ' . $this->db->quote('retry') . ')'),
            'lastCronAt' => self::iso(Schema::setting($this->db, 'last_cron_at')),
            'pushLibraryReady' => (new PushService($this->db))->isReady(),
        ];
    }

    private function subscriptionCount(int $userId): int
    {
        return $this->scalar('SELECT COUNT(*) FROM #__memi_pwa_push_subscriptions WHERE user_id = ' . $userId . ' AND enabled = 1');
    }

    private function scalar(string $sql): int
    {
        $this->db->setQuery($this->db->replacePrefix($sql));

        return (int) $this->db->loadResult();
    }

    private static function iso(string $date): ?string
    {
        $date = trim($date);

        return $date === '' ? null : str_replace(' ', 'T', $date) . 'Z';
    }
}
