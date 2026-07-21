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
    public function __construct(
        private readonly DatabaseDriver $db,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit
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
        $queuedStatus = $this->queuedStatus();
        $query = $this->db->getQuery(true)
            ->insert($this->db->quoteName('#__memi_notifications'))
            ->columns(['client_id', 'user_id', 'template_key', 'payload', 'scheduled_at', 'status', 'idempotency_key', 'created_at', 'updated_at'])
            ->values(':client_id, :user_id, :template_key, :payload, :scheduled_at, :status, :idempotency_key, :created_at, :updated_at')
            ->bind(':client_id', $clientId, ParameterType::INTEGER)
            ->bind(':user_id', $user, ParameterType::INTEGER)
            ->bind(':template_key', $templateKey)
            ->bind(':payload', $json)
            ->bind(':scheduled_at', $sendAt)
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
        $now = gmdate('Y-m-d H:i:s');
        $this->recoverStaleClaims($now);
        $status = $this->queuedStatus();
        $query = $this->db->getQuery(true)
            ->select('n.*, u.name, u.email')
            ->from($this->db->quoteName('#__memi_notifications', 'n'))
            ->join('INNER', $this->db->quoteName('#__users', 'u') . ' ON u.id = n.user_id')
            ->where('n.status = :status')
            ->where('n.scheduled_at <= :now')
            ->order('n.id ASC')
            ->bind(':status', $status)
            ->bind(':now', $now);
        $this->db->setQuery($query, 0, max(1, min($limit, 500)));
        $notifications = $this->db->loadAssocList() ?: [];
        $sent = 0;

        foreach ($notifications as $notification) {
            if (!$this->claimForDelivery((int) $notification['id'])) {
                continue;
            }
            try {
                $payload = json_decode((string) $notification['payload'], true, 512, JSON_THROW_ON_ERROR);
                $mailer = Factory::getMailer();
                $this->configureSender($mailer);
                $mailer->addRecipient((string) $notification['email'], (string) $notification['name']);
                $mailer->setSubject($this->subject((string) $notification['template_key'], $payload));
                $mailer->isHtml(true);
                $mailer->setBody($this->body((string) $notification['template_key'], $payload));
                $mailer->AltBody = strip_tags($this->body((string) $notification['template_key'], $payload));
                $mailer->send();
                $this->mark((int) $notification['id'], 'sent', null);
                ++$sent;
            } catch (\Throwable $error) {
                $this->mark((int) $notification['id'], 'failed', mb_substr($error->getMessage(), 0, 300));
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
            'session.cancelled' => Text::_('COM_MEMIPILATES_EMAIL_SESSION_CANCELLED_SUBJECT'),
            'waitlist.offer' => Text::_('COM_MEMIPILATES_EMAIL_WAITLIST_OFFER_SUBJECT'),
            default => Text::_('COM_MEMIPILATES_EMAIL_GENERIC_SUBJECT'),
        };
    }

    /** @param array<string, mixed> $payload */
    private function body(string $templateKey, array $payload): string
    {
        if ($templateKey === 'waitlist.offer') {
            $waitlistId = max(0, (int) ($payload['waitlist_id'] ?? 0));
            $token = (string) ($payload['acceptance_token'] ?? '');
            if ($waitlistId > 0 && preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $token)) {
                $url = Uri::root() . 'index.php?' . http_build_query([
                    'option' => 'com_memipilates',
                    'view' => 'waitlistoffer',
                    'id' => $waitlistId,
                    'token' => $token,
                ], '', '&', PHP_QUERY_RFC3986);
                $expiry = htmlspecialchars((string) ($payload['expires_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $message = htmlspecialchars(Text::sprintf('COM_MEMIPILATES_EMAIL_WAITLIST_OFFER_BODY', $expiry), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $label = htmlspecialchars(Text::_('COM_MEMIPILATES_EMAIL_WAITLIST_OFFER_ACTION'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                return '<!doctype html><html lang="fr"><body><p>' . $message . '</p><p><a href="'
                    . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . $label . '</a></p></body></html>';
            }
        }

        $safe = [];
        foreach ($payload as $key => $value) {
            $safe['{{' . $key . '}}'] = htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $template = '<p>' . htmlspecialchars(Text::_('COM_MEMIPILATES_EMAIL_GENERIC_BODY'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        $template = str_replace(array_keys($safe), array_values($safe), $template);

        return '<!doctype html><html lang="fr"><body>' . $template . '</body></html>';
    }

    private function mark(int $id, string $status, ?string $failure): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $identifier = $id;
        $sentAt = $status === 'sent' ? $now : null;
        $failedAt = $status === 'failed' ? $now : null;
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__memi_notifications'))
            ->set($this->db->quoteName('status') . ' = :status')
            ->set($this->db->quoteName('error_message') . ' = :failure')
            ->set($this->db->quoteName('sent_at') . ' = :sent_at')
            ->set($this->db->quoteName('failed_at') . ' = :failed_at')
            ->set($this->db->quoteName('updated_at') . ' = :updated_at')
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':status', $status)
            ->bind(':failure', $failure)
            ->bind(':sent_at', $sentAt)
            ->bind(':failed_at', $failedAt)
            ->bind(':updated_at', $now)
            ->bind(':id', $identifier, ParameterType::INTEGER);
        $this->db->setQuery($query)->execute();
    }

    /** Claims one queued message atomically so concurrent cron jobs cannot send it twice. */
    private function claimForDelivery(int $id): bool
    {
        $now = gmdate('Y-m-d H:i:s');
        $identifier = $id;
        $queued = $this->queuedStatus();
        $sending = 'sending';
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__memi_notifications'))
            ->set($this->db->quoteName('status') . ' = :sending_status')
            ->set($this->db->quoteName('updated_at') . ' = :updated_at')
            ->where($this->db->quoteName('id') . ' = :id')
            ->where($this->db->quoteName('status') . ' = :queued_status')
            ->bind(':sending_status', $sending)
            ->bind(':updated_at', $now)
            ->bind(':id', $identifier, ParameterType::INTEGER)
            ->bind(':queued_status', $queued);
        $this->db->setQuery($query)->execute();

        return $this->db->getAffectedRows() === 1;
    }

    /**
     * A crashed PHP process cannot mark its claimed mail as sent or failed.
     * Return only claims older than 15 minutes to the queue; normal parallel
     * cron runs leave fresh claims untouched.
     */
    private function recoverStaleClaims(string $now): void
    {
        $cutoff = (new \DateTimeImmutable($now, new \DateTimeZone('UTC')))->modify('-15 minutes')->format('Y-m-d H:i:s');
        $queued = $this->queuedStatus();
        $sending = 'sending';
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__memi_notifications'))
            ->set($this->db->quoteName('status') . ' = :queued_status')
            ->set($this->db->quoteName('updated_at') . ' = :updated_at')
            ->where($this->db->quoteName('status') . ' = :sending_status')
            ->where($this->db->quoteName('updated_at') . ' < :cutoff')
            ->bind(':queued_status', $queued)
            ->bind(':updated_at', $now)
            ->bind(':sending_status', $sending)
            ->bind(':cutoff', $cutoff);
        $this->db->setQuery($query)->execute();
    }

    private function queuedStatus(): string
    {
        return 'queued';
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
