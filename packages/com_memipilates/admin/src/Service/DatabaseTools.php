<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;

/** Small, audited helpers shared by write services. */
final class DatabaseTools
{
    public function __construct(private readonly DatabaseDriver $db)
    {
    }

    /**
     * Run a database operation atomically. Nested work is intentionally not
     * supported: orchestration services own their complete transaction.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $attempt = 0;
        while (true) {
            $started = false;
            $committed = false;
            try {
                $this->db->transactionStart();
                $started = true;
                $result = $callback();
                $this->db->transactionCommit();
                $committed = true;

                return $result;
            } catch (\Throwable $error) {
                if ($started && !$committed) {
                    try {
                        $this->db->transactionRollback();
                    } catch (\Throwable) {
                        // Preserve the original database exception below.
                    }
                }
                // A commit error has an unknown outcome and must never be
                // replayed. Deadlocks before commit are safe to retry because
                // all component writes use idempotency keys/unique guards.
                if ($started && !$committed && $attempt < 2 && $this->isRetryableLockError($error)) {
                    ++$attempt;
                    usleep(random_int(10000, 30000) * $attempt);
                    continue;
                }

                throw $error;
            }
        }
    }

    /** @return array<string, mixed>|null */
    public function lockById(string $table, int $id): ?array
    {
        $identifier = $id;
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName($table))
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':id', $identifier, ParameterType::INTEGER);

        $this->db->setQuery(self::forUpdate($query));

        return $this->db->loadAssoc() ?: null;
    }

    /**
     * Locks a profile row before changing any customer ledger. The profile is
     * created lazily so old Joomla accounts can use the component directly.
     */
    public function lockClientProfile(int $userId): array
    {
        $user = $userId;
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__memi_client_profiles'))
            ->where($this->db->quoteName('user_id') . ' = :user_id')
            ->bind(':user_id', $user, ParameterType::INTEGER);
        $this->db->setQuery(self::forUpdate($query));
        $profile = $this->db->loadAssoc();

        if ($profile) {
            return $profile;
        }

        $now = gmdate('Y-m-d H:i:s');
        $insert = $this->db->getQuery(true)
            ->insert($this->db->quoteName('#__memi_client_profiles'))
            ->columns([
                $this->db->quoteName('user_id'),
                $this->db->quoteName('created_at'),
                $this->db->quoteName('updated_at'),
            ])
            ->values(':user_id, :created_at, :updated_at')
            ->bind(':user_id', $user, ParameterType::INTEGER)
            ->bind(':created_at', $now)
            ->bind(':updated_at', $now);
        try {
            $this->db->setQuery($insert)->execute();
        } catch (\Throwable $error) {
            // A first visit can race in two browser tabs. The unique index is
            // the authority; after its expected duplicate error, re-read and
            // lock the profile created by the other request.
            if (!str_contains(strtolower($error->getMessage()), 'duplicate')) {
                throw $error;
            }
        }

        return $this->lockClientProfile($userId);
    }

    public function getDatabase(): DatabaseDriver
    {
        return $this->db;
    }

    /**
     * Adds a MySQL row lock without discarding Joomla's bound parameters.
     * DatabaseDriver::setQuery(string) creates a fresh query object, so casting
     * a bound query to a string before appending FOR UPDATE loses every bind.
     */
    public static function forUpdate(QueryInterface $query): QueryInterface
    {
        $sql = (string) $query;
        $locked = clone $query;
        $locked->setQuery($sql . ' FOR UPDATE');
        // $sql already contains any limit from the original query. Prevent
        // DatabaseDriver from applying it a second time to the raw SQL string.
        $locked->setLimit();

        return $locked;
    }

    private function isRetryableLockError(\Throwable $error): bool
    {
        $message = strtolower($error->getMessage());

        return str_contains($message, 'deadlock')
            || str_contains($message, 'lock wait timeout')
            || in_array((int) $error->getCode(), [1205, 1213], true);
    }
}
