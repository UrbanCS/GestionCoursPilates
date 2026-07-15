<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;

/** Writes privacy-safe audit records. Tokens, cards and secrets are redacted. */
final class AuditLogger
{
    public function __construct(private readonly DatabaseDriver $db)
    {
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param array<string, mixed>      $context
     */
    public function log(
        ?int $actorId,
        string $action,
        string $entityType,
        ?int $entityId,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        array $context = []
    ): void {
        $now = gmdate('Y-m-d H:i:s');
        $actor = $actorId ?? 0;
        $id = $entityId;
        $safeBefore = json_encode($this->redact($before), JSON_THROW_ON_ERROR);
        $afterWithContext = $this->redact($after) ?? [];
        if ($context !== []) {
            $afterWithContext['_context'] = $this->redact($context);
        }
        $safeAfter = json_encode($afterWithContext, JSON_THROW_ON_ERROR);
        $ip = (string) Factory::getApplication()->input->server->getString('REMOTE_ADDR', '');
        // The audit log retains the request address only when present. Unlike
        // QR and payment data, IP addresses can be needed for fraud review;
        // deployments must set their retention policy in accordance with law.
        $requestId = (string) Factory::getApplication()->input->server->getString('HTTP_X_REQUEST_ID', '');
        $reasonValue = $reason ?? '';
        $safeRequestId = mb_substr($requestId, 0, 128);
        $safeIp = mb_substr($ip, 0, 45);

        $query = $this->db->getQuery(true)
            ->insert($this->db->quoteName('#__memi_audit_log'))
            ->columns([
                'actor_user_id', 'action', 'entity_type', 'entity_id', 'old_values', 'new_values',
                'reason', 'request_id', 'ip_address', 'created_at',
            ])
            ->values(':actor, :action, :entity_type, :entity_id, :old_values, :new_values, :reason, :request_id, :ip_address, :created_at')
            ->bind(':actor', $actor, ParameterType::INTEGER)
            ->bind(':action', $action)
            ->bind(':entity_type', $entityType)
            ->bind(':entity_id', $id, ParameterType::INTEGER)
            ->bind(':old_values', $safeBefore)
            ->bind(':new_values', $safeAfter)
            ->bind(':reason', $reasonValue)
            ->bind(':request_id', $safeRequestId)
            ->bind(':ip_address', $safeIp)
            ->bind(':created_at', $now);
        $this->db->setQuery($query)->execute();
    }

    /** @param array<string, mixed>|null $value */
    private function redact(?array $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $restricted = ['token', 'secret', 'authorization', 'card', 'cvv', 'source_id', 'access_token', 'qr'];

        foreach ($value as $key => $item) {
            foreach ($restricted as $needle) {
                if (str_contains(strtolower((string) $key), $needle)) {
                    $value[$key] = '[redacted]';
                    continue 2;
                }
            }

            if (is_array($item)) {
                $value[$key] = $this->redact($item);
            }
        }

        return $value;
    }
}
