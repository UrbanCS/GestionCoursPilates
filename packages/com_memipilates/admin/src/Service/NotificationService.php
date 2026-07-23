<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;

/**
 * Queues mail inside the database. SchedulerService sends due messages using
 * Joomla's configured mailer so SMTP stays owned by the existing site.
 */
final class NotificationService
{
    private bool $languageLoaded = false;

    public function __construct(
        private readonly DatabaseDriver $db,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
        private readonly WaitlistOfferTokenService $waitlistOfferTokens,
        private readonly WaitlistOfferFailureService $waitlistOfferFailures
    ) {
    }

    /** @param array<string, scalar|null> $payload */
    public function queue(int $userId, string $templateKey, array $payload, ?\DateTimeInterface $sendAfter = null, ?string $idempotencyKey = null): int
    {
        $profile = (new DatabaseTools($this->db))->lockClientProfile($userId);
        $clientId = (int) $profile['id'];
        $now = gmdate('Y-m-d H:i:s');
        $sendAt = ($sendAfter ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $key = $idempotencyKey ?: hash('sha256', $userId . '|' . $templateKey . '|' . $sendAt . '|' . $json);
        $user = $userId;
        $waitlistId = isset($payload['waitlist_id']) && (int) $payload['waitlist_id'] > 0 ? (int) $payload['waitlist_id'] : null;
        $queuedStatus = $this->queuedStatus();
        $query = $this->db->getQuery(true)
            ->insert($this->db->quoteName('#__memi_notifications'))
            ->columns(['client_id', 'user_id', 'waitlist_id', 'template_key', 'payload', 'scheduled_at', 'next_attempt_at', 'status', 'idempotency_key', 'created_at', 'updated_at'])
            ->values(':client_id, :user_id, :waitlist_id, :template_key, :payload, :scheduled_at, :next_attempt_at, :status, :idempotency_key, :created_at, :updated_at')
            ->bind(':client_id', $clientId, ParameterType::INTEGER)
            ->bind(':user_id', $user, ParameterType::INTEGER)
            ->bind(':waitlist_id', $waitlistId, ParameterType::INTEGER)
            ->bind(':template_key', $templateKey)
            ->bind(':payload', $json)
            ->bind(':scheduled_at', $sendAt)
            ->bind(':next_attempt_at', $sendAt)
            ->bind(':status', $queuedStatus)
            ->bind(':idempotency_key', $key)
            ->bind(':created_at', $now)
            ->bind(':updated_at', $now);

        try {
            $this->db->setQuery($query)->execute();

            return (int) $this->db->insertid();
        } catch (\Throwable $error) {
            // Duplicate notification key is an expected idempotent outcome.
            if (!str_contains(strtolower($error->getMessage()), 'duplicate')) {
                throw $error;
            }

            return 0;
        }
    }

    /** @return int number sent */
    public function sendDue(int $limit = 100): int
    {
        $this->loadLanguage();
        $now = gmdate('Y-m-d H:i:s');
        $maxAttempts = $this->maxAttempts();
        $this->recoverStaleClaims($now, $maxAttempts);
        $queued = $this->queuedStatus();
        $retry = $this->retryStatus();
        $query = $this->db->getQuery(true)
            ->select('n.*, u.name, u.email')
            ->from($this->db->quoteName('#__memi_notifications', 'n'))
            ->join('INNER', $this->db->quoteName('#__users', 'u') . ' ON u.id = n.user_id')
            ->where('n.status IN (:queued_status, :retry_status)')
            ->where('COALESCE(n.next_attempt_at, n.scheduled_at) <= :now')
            ->where('n.attempt_count < :max_attempts')
            ->order('COALESCE(n.next_attempt_at, n.scheduled_at) ASC, n.id ASC')
            ->bind(':queued_status', $queued)
            ->bind(':retry_status', $retry)
            ->bind(':now', $now)
            ->bind(':max_attempts', $maxAttempts, ParameterType::INTEGER);
        $this->db->setQuery($query, 0, max(1, min($limit, 500)));
        $notifications = $this->db->loadAssocList() ?: [];
        $sent = 0;

        foreach ($notifications as $notification) {
            $notificationId = (int) $notification['id'];
            if (!$this->claimForDelivery($notificationId, $maxAttempts)) {
                continue;
            }

            $attempt = (int) ($notification['attempt_count'] ?? 0) + 1;
            try {
                $payload = json_decode((string) $notification['payload'], true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($payload)) {
                    throw new \UnexpectedValueException('Notification payload must be an object.');
                }

                $body = $this->body((string) $notification['template_key'], $payload, (int) $notification['user_id']);
                $mailer = Factory::getMailer();
                $this->configureSender($mailer);
                $mailer->addRecipient((string) $notification['email'], (string) $notification['name']);
                $mailer->setSubject($this->subject((string) $notification['template_key'], $payload));
                $mailer->isHtml(true);
                $mailer->setBody($body);
                $mailer->AltBody = $this->plainText($body);
                $mailer->send();
                $this->markSent($notificationId);
                ++$sent;
            } catch (\Throwable $error) {
                $this->markDeliveryFailure($notification, $attempt, $maxAttempts, $this->failureSummary($error));
            }
        }

        return $sent;
    }

    /** @param array<string, mixed> $payload */
    private function subject(string $templateKey, array $payload): string
    {
        return match ($templateKey) {
            'booking.confirmed' => Text::_('COM_MEMIPILATES_EMAIL_BOOKING_SUBJECT'),
            'booking.cancelled_on_time' => Text::_('COM_MEMIPILATES_EMAIL_CANCELLED_ON_TIME_SUBJECT'),
            'booking.cancelled_late' => Text::_('COM_MEMIPILATES_EMAIL_CANCELLED_LATE_SUBJECT'),
            'booking.reminder' => Text::_('COM_MEMIPILATES_EMAIL_REMINDER_SUBJECT'),
            'session.cancelled' => Text::_('COM_MEMIPILATES_EMAIL_SESSION_CANCELLED_SUBJECT'),
            'waitlist.joined' => Text::_('COM_MEMIPILATES_EMAIL_WAITLIST_JOINED_SUBJECT'),
            'waitlist.offer' => Text::_('COM_MEMIPILATES_EMAIL_WAITLIST_OFFER_SUBJECT'),
            'payment.receipt' => Text::_('COM_MEMIPILATES_EMAIL_PAYMENT_RECEIPT_SUBJECT'),
            'credit.expiring' => Text::_('COM_MEMIPILATES_EMAIL_CREDIT_EXPIRING_SUBJECT'),
            default => Text::_('COM_MEMIPILATES_EMAIL_GENERIC_SUBJECT'),
        };
    }

    /** @param array<string, mixed> $payload */
    private function body(string $templateKey, array $payload, int $userId): string
    {
        $context = $this->notificationContext($payload);
        if ($templateKey === 'payment.receipt') {
            $context = $this->paymentContext($context, $userId);
        }

        if ($templateKey === 'waitlist.offer') {
            $offer = $this->waitlistOfferContext($payload, $userId);
            $token = $this->waitlistOfferTokens->issue($offer['waitlist_id'], $userId, $offer['offered_at'], $offer['expires_at']);
            $url = Uri::root() . 'index.php?' . http_build_query([
                'option' => 'com_memipilates',
                'view' => 'waitlistoffer',
                'id' => $offer['waitlist_id'],
                'token' => $token,
            ], '', '&', PHP_QUERY_RFC3986);
            $context = $this->notificationContext(array_merge($context, ['session_id' => $offer['session_id']]));
            $expiry = $this->formatStudioDate($offer['expires_at']);
            $message = $this->escape(Text::sprintf('COM_MEMIPILATES_EMAIL_WAITLIST_OFFER_BODY', $expiry));
            $label = $this->escape(Text::_('COM_MEMIPILATES_EMAIL_WAITLIST_OFFER_ACTION'));

            return $this->htmlDocument('<p>' . $message . '</p>' . $this->sessionDetails($context)
                . '<p><a href="' . $this->escape($url) . '">' . $label . '</a></p>');
        }

        $introKey = match ($templateKey) {
            'booking.confirmed' => 'COM_MEMIPILATES_EMAIL_BOOKING_CONFIRMED_BODY',
            'booking.cancelled_on_time' => 'COM_MEMIPILATES_EMAIL_CANCELLED_ON_TIME_BODY',
            'booking.cancelled_late' => 'COM_MEMIPILATES_EMAIL_CANCELLED_LATE_BODY',
            'booking.reminder' => 'COM_MEMIPILATES_EMAIL_REMINDER_BODY',
            'session.cancelled' => 'COM_MEMIPILATES_EMAIL_SESSION_CANCELLED_BODY',
            'waitlist.joined' => 'COM_MEMIPILATES_EMAIL_WAITLIST_JOINED_BODY',
            'payment.receipt' => 'COM_MEMIPILATES_EMAIL_PAYMENT_RECEIPT_BODY',
            'credit.expiring' => 'COM_MEMIPILATES_EMAIL_CREDIT_EXPIRING_BODY',
            default => 'COM_MEMIPILATES_EMAIL_GENERIC_BODY',
        };

        $details = match ($templateKey) {
            'payment.receipt' => $this->paymentDetails($context),
            'credit.expiring' => $this->creditExpiryDetails($context),
            default => $this->sessionDetails($context),
        };

        return $this->htmlDocument('<p>' . $this->escape(Text::_($introKey)) . '</p>' . $details);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function notificationContext(array $payload): array
    {
        $sessionId = max(0, (int) ($payload['session_id'] ?? 0));
        if ($sessionId <= 0) {
            return $payload;
        }

        $identifier = $sessionId;
        $query = $this->db->getQuery(true)
            ->select([
                's.id AS session_id', 's.starts_at', 'c.title AS session_title',
                'i.display_name AS instructor_name', 'r.title AS room_title',
                'l.title AS location_title',
            ])
            ->from($this->db->quoteName('#__memi_sessions', 's'))
            ->join('INNER', $this->db->quoteName('#__memi_courses', 'c') . ' ON c.id = s.course_id')
            ->join('LEFT', $this->db->quoteName('#__memi_instructors', 'i') . ' ON i.id = s.instructor_id')
            ->join('LEFT', $this->db->quoteName('#__memi_rooms', 'r') . ' ON r.id = s.room_id')
            ->join('LEFT', $this->db->quoteName('#__memi_locations', 'l') . ' ON l.id = r.location_id')
            ->where('s.id = :session_id')
            ->bind(':session_id', $identifier, ParameterType::INTEGER);
        $this->db->setQuery($query, 0, 1);
        $stored = $this->db->loadAssoc() ?: [];

        // Prefer the immutable snapshot queued by the workflow. Fill only
        // fields absent from legacy payloads with the current catalogue data.
        foreach ($stored as $key => $value) {
            if (!array_key_exists($key, $payload) || trim((string) $payload[$key]) === '') {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }

    /** @param array<string,mixed> $context */
    private function sessionDetails(array $context): string
    {
        $items = [];
        $title = trim((string) ($context['session_title'] ?? ''));
        if ($title !== '') {
            $items[] = $this->detail('COM_MEMIPILATES_EMAIL_LABEL_COURSE', $title);
        }

        $startsAt = trim((string) ($context['starts_at'] ?? ''));
        if ($startsAt !== '') {
            $items[] = $this->detail('COM_MEMIPILATES_EMAIL_LABEL_WHEN', $this->formatStudioDate($startsAt));
        }

        $instructor = trim((string) ($context['instructor_name'] ?? ''));
        if ($instructor !== '') {
            $items[] = $this->detail('COM_MEMIPILATES_EMAIL_LABEL_INSTRUCTOR', $instructor);
        }

        $location = array_values(array_filter([
            trim((string) ($context['location_title'] ?? '')),
            trim((string) ($context['room_title'] ?? '')),
        ], static fn (string $value): bool => $value !== ''));
        if ($location !== []) {
            $items[] = $this->detail('COM_MEMIPILATES_EMAIL_LABEL_LOCATION', implode(' · ', array_unique($location)));
        }

        $reason = trim((string) ($context['reason'] ?? ''));
        if ($reason !== '') {
            $items[] = $this->detail('COM_MEMIPILATES_EMAIL_LABEL_REASON', $reason);
        }

        if (array_key_exists('credit_restored', $context)) {
            $items[] = $this->detail(
                'COM_MEMIPILATES_EMAIL_LABEL_CREDIT_RESTORED',
                (bool) $context['credit_restored']
                    ? Text::_('COM_MEMIPILATES_EMAIL_VALUE_YES')
                    : Text::_('COM_MEMIPILATES_EMAIL_VALUE_NO')
            );
        }

        if (isset($context['position']) && (int) $context['position'] > 0) {
            $items[] = $this->detail('COM_MEMIPILATES_EMAIL_LABEL_WAITLIST_POSITION', (string) (int) $context['position']);
        }

        return $items === [] ? '' : '<dl>' . implode('', $items) . '</dl>';
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function paymentContext(array $payload, int $userId): array
    {
        $orderId = max(0, (int) ($payload['order_id'] ?? 0));
        if ($orderId <= 0 || $userId <= 0) {
            return $payload;
        }

        $identifier = $orderId;
        $owner = $userId;
        $query = $this->db->getQuery(true)
            ->select([
                'o.id AS order_id', 'o.order_number', 'o.currency', 'o.total_cents',
                'o.paid_at',
            ])
            ->from($this->db->quoteName('#__memi_orders', 'o'))
            ->where('o.id = :order_id')
            ->where('o.user_id = :user_id')
            ->bind(':order_id', $identifier, ParameterType::INTEGER)
            ->bind(':user_id', $owner, ParameterType::INTEGER);
        $this->db->setQuery($query, 0, 1);
        $order = $this->db->loadAssoc() ?: [];
        if ($order === []) {
            return $payload;
        }

        $itemsQuery = $this->db->getQuery(true)
            ->select(['title_snapshot', 'quantity'])
            ->from($this->db->quoteName('#__memi_order_items'))
            ->where($this->db->quoteName('order_id') . ' = :order_id')
            ->order($this->db->quoteName('id') . ' ASC')
            ->bind(':order_id', $identifier, ParameterType::INTEGER);
        $this->db->setQuery($itemsQuery);
        $itemLabels = [];
        foreach ($this->db->loadAssocList() ?: [] as $item) {
            $title = trim((string) ($item['title_snapshot'] ?? ''));
            if ($title === '') {
                continue;
            }
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $itemLabels[] = $quantity > 1 ? $quantity . ' x ' . $title : $title;
        }
        $order['order_items'] = implode(', ', $itemLabels);

        return array_merge($payload, $order);
    }

    /** @param array<string,mixed> $context */
    private function paymentDetails(array $context): string
    {
        $items = [];
        $orderId = max(0, (int) ($context['order_id'] ?? 0));
        $orderNumber = trim((string) ($context['order_number'] ?? ''));
        if ($orderNumber !== '' || $orderId > 0) {
            $items[] = $this->detail(
                'COM_MEMIPILATES_EMAIL_LABEL_ORDER',
                $orderNumber !== '' ? $orderNumber : '#' . $orderId
            );
        }
        $orderItems = trim((string) ($context['order_items'] ?? ''));
        if ($orderItems !== '') {
            $items[] = $this->detail('COM_MEMIPILATES_EMAIL_LABEL_PURCHASE', $orderItems);
        }
        if (isset($context['total_cents'])) {
            $items[] = $this->detail(
                'COM_MEMIPILATES_EMAIL_LABEL_TOTAL',
                $this->formatMoney((int) $context['total_cents'], (string) ($context['currency'] ?? 'CAD'))
            );
        }
        $paidAt = trim((string) ($context['paid_at'] ?? ''));
        if ($paidAt !== '') {
            $items[] = $this->detail('COM_MEMIPILATES_EMAIL_LABEL_PAID_AT', $this->formatStudioDate($paidAt));
        }

        return $items === [] ? '' : '<dl>' . implode('', $items) . '</dl>';
    }

    /** @param array<string,mixed> $context */
    private function creditExpiryDetails(array $context): string
    {
        $items = [];
        $package = trim((string) ($context['package_title'] ?? ''));
        if ($package !== '') {
            $items[] = $this->detail('COM_MEMIPILATES_EMAIL_LABEL_PACKAGE', $package);
        }
        if (isset($context['remaining_credits'])) {
            $items[] = $this->detail('COM_MEMIPILATES_EMAIL_LABEL_REMAINING_CREDITS', (string) max(0, (int) $context['remaining_credits']));
        }
        $expiresAt = trim((string) ($context['expires_at'] ?? ''));
        if ($expiresAt !== '') {
            $items[] = $this->detail('COM_MEMIPILATES_EMAIL_LABEL_EXPIRES_AT', $this->formatStudioDate($expiresAt));
        }

        return $items === [] ? '' : '<dl>' . implode('', $items) . '</dl>';
    }

    private function formatMoney(int $cents, string $currency): string
    {
        $code = strtoupper(trim($currency));
        if (!preg_match('/^[A-Z]{3}$/D', $code)) {
            $code = 'CAD';
        }

        return number_format(max(0, $cents) / 100, 2, '.', ' ') . ' ' . $code;
    }

    private function detail(string $labelKey, string $value): string
    {
        return '<dt><strong>' . $this->escape(Text::_($labelKey)) . '</strong></dt><dd>' . $this->escape($value) . '</dd>';
    }

    /** Database dates are UTC; email dates are always rendered in studio time. */
    private function formatStudioDate(string $utc): string
    {
        try {
            $date = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));

            return $date->setTimezone($this->settings->timezone())->format('Y-m-d H:i T');
        } catch (\Throwable) {
            return $utc;
        }
    }

    private function htmlDocument(string $content): string
    {
        $language = str_replace('_', '-', (string) Factory::getApplication()->getLanguage()->getTag());

        return '<!doctype html><html lang="' . $this->escape($language) . '"><head><meta charset="utf-8"></head><body>' . $content . '</body></html>';
    }

    /** CLI and task-scheduler executions do not dispatch the component first. */
    private function loadLanguage(): void
    {
        if ($this->languageLoaded) {
            return;
        }

        $language = Factory::getApplication()->getLanguage();
        $loaded = $language->load('com_memipilates', JPATH_ADMINISTRATOR, null, true);
        if (!$loaded) {
            $language->load('com_memipilates', JPATH_SITE, null, true);
        }
        $this->languageLoaded = true;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function plainText(string $html): string
    {
        $withLinks = preg_replace_callback(
            '/<a\s+[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/is',
            static fn (array $match): string => strip_tags((string) $match[2]) . ' (' . (string) $match[1] . ')',
            $html
        ) ?? $html;
        $withBreaks = preg_replace('/<\/(?:p|dt|dd|dl)>|<br\s*\/?>/i', "\n", $withLinks) ?? $withLinks;
        $text = html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/[ \t]+\n|\n{3,}/', "\n", $text));
    }

    /**
     * Reconstructs an offer only from its current database state. This keeps
     * pre-1.5 queued messages compatible without ever trusting or retaining a
     * bearer token from their legacy JSON payload.
     *
     * @param array<string,mixed> $payload
     * @return array{waitlist_id:int,session_id:int,offered_at:string,expires_at:string}
     */
    private function waitlistOfferContext(array $payload, int $userId): array
    {
        $waitlistId = max(0, (int) ($payload['waitlist_id'] ?? 0));
        if ($waitlistId <= 0 || $userId <= 0) {
            throw new \UnexpectedValueException('Wait-list offer context is incomplete.');
        }

        $identifier = $waitlistId;
        $query = $this->db->getQuery(true)
            ->select(['user_id', 'session_id', 'status', 'offered_at', 'offer_expires_at'])
            ->from($this->db->quoteName('#__memi_waitlist'))
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':id', $identifier, ParameterType::INTEGER);
        $this->db->setQuery($query, 0, 1);
        $entry = $this->db->loadAssoc();
        if (!$entry || (int) $entry['user_id'] !== $userId || (string) $entry['status'] !== 'offered') {
            throw new \UnexpectedValueException('Wait-list offer is no longer active.');
        }

        $offeredAt = (string) ($entry['offered_at'] ?? '');
        $expiresAt = (string) ($entry['offer_expires_at'] ?? '');
        if ($offeredAt === '' || $expiresAt === '') {
            throw new \UnexpectedValueException('Wait-list offer dates are incomplete.');
        }

        return [
            'waitlist_id' => $waitlistId,
            'session_id' => (int) $entry['session_id'],
            'offered_at' => $offeredAt,
            'expires_at' => $expiresAt,
        ];
    }

    private function markSent(int $id): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $identifier = $id;
        $sent = 'sent';
        $empty = '';
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__memi_notifications'))
            ->set($this->db->quoteName('status') . ' = :status')
            ->set($this->db->quoteName('error_message') . ' = :error_message')
            ->set($this->db->quoteName('sent_at') . ' = :sent_at')
            ->set($this->db->quoteName('failed_at') . ' = NULL')
            ->set($this->db->quoteName('next_attempt_at') . ' = NULL')
            ->set($this->db->quoteName('updated_at') . ' = :updated_at')
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':status', $sent)
            ->bind(':error_message', $empty)
            ->bind(':sent_at', $now)
            ->bind(':updated_at', $now)
            ->bind(':id', $identifier, ParameterType::INTEGER);
        $this->db->setQuery($query)->execute();
    }

    /** @param array<string,mixed> $notification */
    private function markDeliveryFailure(array $notification, int $attempt, int $maxAttempts, string $failure): void
    {
        $id = (int) $notification['id'];
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $terminal = $attempt >= $maxAttempts;
        $status = $terminal ? 'failed' : $this->retryStatus();
        $failedAt = $terminal ? $now->format('Y-m-d H:i:s') : null;
        $nextAttemptAt = $terminal ? null : $now->modify('+' . $this->retryDelayMinutes($attempt) . ' minutes')->format('Y-m-d H:i:s');
        $identifier = $id;
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__memi_notifications'))
            ->set($this->db->quoteName('status') . ' = :status')
            ->set($this->db->quoteName('error_message') . ' = :error_message')
            ->set($this->db->quoteName('failed_at') . ' = :failed_at')
            ->set($this->db->quoteName('next_attempt_at') . ' = :next_attempt_at')
            ->set($this->db->quoteName('updated_at') . ' = :updated_at')
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':status', $status)
            ->bind(':error_message', $failure)
            ->bind(':failed_at', $failedAt)
            ->bind(':next_attempt_at', $nextAttemptAt)
            ->bind(':updated_at', $now->format('Y-m-d H:i:s'))
            ->bind(':id', $identifier, ParameterType::INTEGER);
        $this->db->setQuery($query)->execute();
        if ($terminal) {
            $this->releaseFailedWaitlistOffer($notification, 'delivery_attempts_exhausted');
        }
        try {
            $this->audit->log(null, 'notification.delivery_failure', 'notification', $id, null, [
                'attempt_count' => $attempt,
                'status' => $status,
                'next_attempt_at' => $nextAttemptAt,
                'failure_type' => $failure,
            ]);
        } catch (\Throwable) {
            // Delivery state and waitlist capacity take precedence over telemetry.
        }
    }

    /** Claims one eligible message atomically so concurrent cron jobs cannot send it twice. */
    private function claimForDelivery(int $id, int $maxAttempts): bool
    {
        $now = gmdate('Y-m-d H:i:s');
        $identifier = $id;
        $queued = $this->queuedStatus();
        $retry = $this->retryStatus();
        $sending = 'sending';
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__memi_notifications'))
            ->set($this->db->quoteName('status') . ' = :sending_status')
            ->set($this->db->quoteName('attempt_count') . ' = ' . $this->db->quoteName('attempt_count') . ' + 1')
            ->set($this->db->quoteName('last_attempt_at') . ' = :last_attempt_at')
            ->set($this->db->quoteName('updated_at') . ' = :updated_at')
            ->where($this->db->quoteName('id') . ' = :id')
            ->where($this->db->quoteName('status') . ' IN (:queued_status, :retry_status)')
            ->where('COALESCE(' . $this->db->quoteName('next_attempt_at') . ', ' . $this->db->quoteName('scheduled_at') . ') <= :now')
            ->where($this->db->quoteName('attempt_count') . ' < :max_attempts')
            ->bind(':sending_status', $sending)
            ->bind(':last_attempt_at', $now)
            ->bind(':updated_at', $now)
            ->bind(':id', $identifier, ParameterType::INTEGER)
            ->bind(':queued_status', $queued)
            ->bind(':retry_status', $retry)
            ->bind(':now', $now)
            ->bind(':max_attempts', $maxAttempts, ParameterType::INTEGER);
        $this->db->setQuery($query)->execute();

        return $this->db->getAffectedRows() === 1;
    }

    /**
     * A crashed PHP process cannot mark its claimed mail as sent or failed.
     * Claims older than 15 minutes become retryable, or terminal when their
     * configured attempt budget is already exhausted.
     */
    private function recoverStaleClaims(string $now, int $maxAttempts): void
    {
        $cutoff = (new \DateTimeImmutable($now, new \DateTimeZone('UTC')))->modify('-15 minutes')->format('Y-m-d H:i:s');
        $sending = 'sending';
        $failed = 'failed';
        $failure = 'Delivery claim timed out before completion.';
        $terminalQuery = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__memi_notifications'))
            ->where($this->db->quoteName('status') . ' = :sending_status')
            ->where($this->db->quoteName('updated_at') . ' < :cutoff')
            ->where($this->db->quoteName('attempt_count') . ' >= :max_attempts')
            ->bind(':sending_status', $sending)
            ->bind(':cutoff', $cutoff)
            ->bind(':max_attempts', $maxAttempts, ParameterType::INTEGER);
        $this->db->setQuery($terminalQuery);
        $terminalClaims = $this->db->loadAssocList() ?: [];

        foreach ($terminalClaims as $notification) {
            $identifier = (int) $notification['id'];
            $terminal = $this->db->getQuery(true)
                ->update($this->db->quoteName('#__memi_notifications'))
                ->set($this->db->quoteName('status') . ' = :failed_status')
                ->set($this->db->quoteName('error_message') . ' = :error_message')
                ->set($this->db->quoteName('failed_at') . ' = :failed_at')
                ->set($this->db->quoteName('next_attempt_at') . ' = NULL')
                ->set($this->db->quoteName('updated_at') . ' = :updated_at')
                ->where($this->db->quoteName('id') . ' = :id')
                ->where($this->db->quoteName('status') . ' = :sending_status')
                ->where($this->db->quoteName('updated_at') . ' < :cutoff')
                ->where($this->db->quoteName('attempt_count') . ' >= :max_attempts')
                ->bind(':failed_status', $failed)
                ->bind(':error_message', $failure)
                ->bind(':failed_at', $now)
                ->bind(':updated_at', $now)
                ->bind(':id', $identifier, ParameterType::INTEGER)
                ->bind(':sending_status', $sending)
                ->bind(':cutoff', $cutoff)
                ->bind(':max_attempts', $maxAttempts, ParameterType::INTEGER);
            $this->db->setQuery($terminal)->execute();
            if ($this->db->getAffectedRows() === 1) {
                $this->releaseFailedWaitlistOffer($notification, 'delivery_claim_timeout');
            }
        }

        $retry = $this->retryStatus();
        $recoverable = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__memi_notifications'))
            ->set($this->db->quoteName('status') . ' = :retry_status')
            ->set($this->db->quoteName('error_message') . ' = :error_message')
            ->set($this->db->quoteName('failed_at') . ' = NULL')
            ->set($this->db->quoteName('next_attempt_at') . ' = :next_attempt_at')
            ->set($this->db->quoteName('updated_at') . ' = :updated_at')
            ->where($this->db->quoteName('status') . ' = :sending_status')
            ->where($this->db->quoteName('updated_at') . ' < :cutoff')
            ->where($this->db->quoteName('attempt_count') . ' < :max_attempts')
            ->bind(':retry_status', $retry)
            ->bind(':error_message', $failure)
            ->bind(':next_attempt_at', $now)
            ->bind(':updated_at', $now)
            ->bind(':sending_status', $sending)
            ->bind(':cutoff', $cutoff)
            ->bind(':max_attempts', $maxAttempts, ParameterType::INTEGER);
        $this->db->setQuery($recoverable)->execute();
        $this->finalizeExhaustedRetries($now, $maxAttempts);
    }

    /** Apply a lowered attempt limit to already queued/retry notifications. */
    private function finalizeExhaustedRetries(string $now, int $maxAttempts): void
    {
        $queued = $this->queuedStatus();
        $retry = $this->retryStatus();
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__memi_notifications'))
            ->where($this->db->quoteName('status') . ' IN (:queued_status, :retry_status)')
            ->where($this->db->quoteName('attempt_count') . ' >= :max_attempts')
            ->bind(':queued_status', $queued)
            ->bind(':retry_status', $retry)
            ->bind(':max_attempts', $maxAttempts, ParameterType::INTEGER);
        $this->db->setQuery($query);
        $notifications = $this->db->loadAssocList() ?: [];

        foreach ($notifications as $notification) {
            $identifier = (int) $notification['id'];
            $failed = 'failed';
            $failure = 'Maximum delivery attempts reached.';
            $update = $this->db->getQuery(true)
                ->update($this->db->quoteName('#__memi_notifications'))
                ->set($this->db->quoteName('status') . ' = :failed_status')
                ->set($this->db->quoteName('error_message') . ' = :error_message')
                ->set($this->db->quoteName('failed_at') . ' = :failed_at')
                ->set($this->db->quoteName('next_attempt_at') . ' = NULL')
                ->set($this->db->quoteName('updated_at') . ' = :updated_at')
                ->where($this->db->quoteName('id') . ' = :id')
                ->where($this->db->quoteName('status') . ' IN (:queued_status, :retry_status)')
                ->where($this->db->quoteName('attempt_count') . ' >= :max_attempts')
                ->bind(':failed_status', $failed)
                ->bind(':error_message', $failure)
                ->bind(':failed_at', $now)
                ->bind(':updated_at', $now)
                ->bind(':id', $identifier, ParameterType::INTEGER)
                ->bind(':queued_status', $queued)
                ->bind(':retry_status', $retry)
                ->bind(':max_attempts', $maxAttempts, ParameterType::INTEGER);
            $this->db->setQuery($update)->execute();
            if ($this->db->getAffectedRows() === 1) {
                $this->releaseFailedWaitlistOffer($notification, 'delivery_attempt_limit_reduced');
            }
        }
    }

    /** @param array<string,mixed> $notification */
    private function releaseFailedWaitlistOffer(array $notification, string $failureCode): void
    {
        if ((string) ($notification['template_key'] ?? '') !== 'waitlist.offer') {
            return;
        }

        try {
            $payload = json_decode((string) ($notification['payload'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return;
        }
        if (!is_array($payload)) {
            return;
        }

        if (!isset($payload['waitlist_id']) && !empty($notification['waitlist_id'])) {
            $payload['waitlist_id'] = (int) $notification['waitlist_id'];
        }
        try {
            $offer = $this->waitlistOfferContext($payload, (int) ($notification['user_id'] ?? 0));
        } catch (\Throwable) {
            return;
        }
        $sessionId = $this->waitlistOfferFailures->fail($offer['waitlist_id'], $offer['offered_at'], null, $failureCode);
        if ($sessionId === null) {
            return;
        }

        try {
            // The failed offer is already committed and its hold released.
            // Manual=true ensures a terminal delivery failure advances even
            // when the original offer was issued manually.
            ComponentServices::waitlist()->offerNext($sessionId, null, true);
        } catch (\Throwable) {
            try {
                $this->audit->log(null, 'waitlist.offer.deferred', 'session', $sessionId, null, [
                    'source' => $failureCode,
                ]);
            } catch (\Throwable) {
                // Capacity is already free; a telemetry error cannot undo it.
            }
        }
    }

    private function maxAttempts(): int
    {
        return max(1, min($this->settings->getInt('notification_max_attempts', 5), 20));
    }

    private function retryDelayMinutes(int $attempt): int
    {
        $base = max(1, min($this->settings->getInt('notification_retry_base_minutes', 5), 1440));
        $multiplier = 2 ** min(8, max(0, $attempt - 1));

        return min(1440, $base * $multiplier);
    }

    private function failureSummary(\Throwable $error): string
    {
        return mb_substr('Notification delivery failed (' . get_debug_type($error) . ').', 0, 300);
    }

    private function queuedStatus(): string
    {
        return 'queued';
    }

    private function retryStatus(): string
    {
        return 'retry';
    }

    /** Use the studio display name while preserving Joomla's configured mailbox. */
    private function configureSender(object $mailer): void
    {
        $application = Factory::getApplication();
        $address = trim((string) $application->get('mailfrom', ''));
        if ($address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) === false || !method_exists($mailer, 'setSender')) {
            return;
        }

        $configuredName = trim((string) $this->settings->get('email_from_name', ''));
        $fallbackName = trim((string) $application->get('fromname', ''));
        $mailer->setSender([$address, $configuredName !== '' ? $configuredName : $fallbackName]);
    }
}
