<?php

declare(strict_types=1);

namespace App\Async\Inbox;

use Doctrine\DBAL\Connection;

final class ExternalEventInboxRepository
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DEAD = 'dead';

    public function __construct(
        private readonly Connection $db,
    ) {
    }

    public function ensureTable(): void
    {
        $this->db->executeStatement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS external_event_inbox (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                source_app VARCHAR(50) NOT NULL,
                provider VARCHAR(50) NOT NULL,
                event_type VARCHAR(100) NOT NULL,
                event_direction ENUM('inbound') NOT NULL DEFAULT 'inbound',
                dedupe_key VARCHAR(191) NOT NULL,
                company_id BIGINT UNSIGNED NULL,
                branch_id BIGINT UNSIGNED NULL,
                customer_id BIGINT UNSIGNED NULL,
                related_entity_type VARCHAR(50) NULL,
                related_entity_id BIGINT UNSIGNED NULL,
                payload_json LONGTEXT NOT NULL,
                headers_json LONGTEXT NULL,
                meta_json LONGTEXT NULL,
                status ENUM('pending', 'processing', 'processed', 'failed', 'dead') NOT NULL DEFAULT 'pending',
                attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
                max_attempts INT UNSIGNED NOT NULL DEFAULT 10,
                available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                claimed_at DATETIME NULL,
                claimed_by VARCHAR(100) NULL,
                processed_at DATETIME NULL,
                last_error TEXT NULL,
                last_error_at DATETIME NULL,
                received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_external_event_dedupe (source_app, provider, event_type, dedupe_key),
                KEY idx_external_event_status_available (status, available_at),
                KEY idx_external_event_provider_type (provider, event_type),
                KEY idx_external_event_company (company_id),
                KEY idx_external_event_branch (branch_id),
                KEY idx_external_event_received (received_at),
                KEY idx_external_event_related (related_entity_type, related_entity_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function claimBatch(int $limit, string $claimToken): array
    {
        $limit = max(1, $limit);

        $this->db->executeStatement(
            sprintf(
                "UPDATE external_event_inbox
                    SET status = '%s',
                        claimed_at = NOW(),
                        claimed_by = :claim_token,
                        attempt_count = attempt_count + 1,
                        updated_at = NOW()
                  WHERE status IN ('%s', '%s')
                    AND available_at <= NOW()
                  ORDER BY id ASC
                  LIMIT %d",
                self::STATUS_PROCESSING,
                self::STATUS_PENDING,
                self::STATUS_FAILED,
                $limit,
            ),
            ['claim_token' => $claimToken],
        );

        return $this->db->fetchAllAssociative(
            'SELECT *
               FROM external_event_inbox
              WHERE claimed_by = :claim_token
                AND status = :status
              ORDER BY id ASC',
            [
                'claim_token' => $claimToken,
                'status' => self::STATUS_PROCESSING,
            ],
        );
    }

    public function markProcessed(int $id): void
    {
        $this->db->executeStatement(
            'UPDATE external_event_inbox
                SET status = :status,
                    processed_at = NOW(),
                    updated_at = NOW()
              WHERE id = :id',
            [
                'status' => self::STATUS_PROCESSED,
                'id' => $id,
            ],
        );
    }

    public function markFailed(int $id, string $error, int $attemptCount, int $maxAttempts): void
    {
        $isDead = $attemptCount >= $maxAttempts;
        $delaySeconds = $this->backoffSeconds($attemptCount);

        $this->db->executeStatement(
            'UPDATE external_event_inbox
                SET status = :status,
                    available_at = CASE
                        WHEN :is_dead = 1 THEN available_at
                        ELSE DATE_ADD(NOW(), INTERVAL :delay SECOND)
                    END,
                    last_error = :error,
                    last_error_at = NOW(),
                    updated_at = NOW()
              WHERE id = :id',
            [
                'status' => $isDead ? self::STATUS_DEAD : self::STATUS_FAILED,
                'is_dead' => $isDead ? 1 : 0,
                'delay' => $delaySeconds,
                'error' => mb_substr($error, 0, 65535),
                'id' => $id,
            ],
        );
    }

    public function markRelatedEntity(int $id, string $type, int $entityId): void
    {
        $this->db->executeStatement(
            'UPDATE external_event_inbox
                SET related_entity_type = :type,
                    related_entity_id = :entity_id,
                    updated_at = NOW()
              WHERE id = :id',
            [
                'type' => $type,
                'entity_id' => $entityId,
                'id' => $id,
            ],
        );
    }

    /**
     * Reset rows that have been stuck in `processing` for longer than the given
     * number of minutes back to `pending` so they can be re-claimed.
     *
     * This handles relay worker crashes mid-batch.  A row is considered stale
     * when it has been claimed but not completed within the grace window.
     *
     * Returns the number of rows rescued.
     */
    public function rescueStale(int $olderThanMinutes = 5): int
    {
        return (int) $this->db->executeStatement(
            sprintf(
                "UPDATE external_event_inbox
                    SET status     = '%s',
                        claimed_by = NULL,
                        claimed_at = NULL,
                        updated_at = NOW()
                  WHERE status = '%s'
                    AND claimed_at < DATE_SUB(NOW(), INTERVAL %d MINUTE)",
                self::STATUS_PENDING,
                self::STATUS_PROCESSING,
                max(1, $olderThanMinutes),
            ),
        );
    }

    /**
     * Mark a row permanently dead without incrementing the retry counter.
     * Use for unrecoverable situations (e.g. unknown event type) where retrying
     * will never succeed.
     */
    public function markDead(int $id, string $reason): void
    {
        $this->db->executeStatement(
            'UPDATE external_event_inbox
                SET status        = :status,
                    last_error    = :error,
                    last_error_at = NOW(),
                    updated_at    = NOW()
              WHERE id = :id',
            [
                'status' => self::STATUS_DEAD,
                'error'  => mb_substr($reason, 0, 65535),
                'id'     => $id,
            ],
        );
    }

    public function find(int $id): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM external_event_inbox WHERE id = :id LIMIT 1',
            ['id' => $id],
        );

        return $row === false ? null : $row;
    }

    private function backoffSeconds(int $attemptCount): int
    {
        return match (true) {
            $attemptCount <= 1 => 30,
            $attemptCount === 2 => 120,
            $attemptCount === 3 => 300,
            $attemptCount === 4 => 900,
            default => 3600,
        };
    }
}
