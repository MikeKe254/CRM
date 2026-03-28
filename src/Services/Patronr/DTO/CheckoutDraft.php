<?php

declare(strict_types=1);

namespace App\Services\Patronr\DTO;

/**
 * Represents an in-progress checkout session on a POS terminal.
 * Hydrated from pos_checkout_drafts by CheckoutService.
 */
final class CheckoutDraft
{
    public function __construct(
        public readonly int                  $id,
        public readonly string               $terminalIdentifier,
        public readonly int                  $companyId,
        public readonly int                  $branchId,
        public readonly ?int                 $cashierUserId,
        public readonly int                  $step,
        public readonly array                $payload,
        public readonly ?int                 $posTransactionId,
        public readonly \DateTimeImmutable   $expiresAt,
        public readonly \DateTimeImmutable   $createdAt,
    ) {}

    /** Read a value from the JSON payload with an optional default. */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->payload[$key] ?? $default;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt <= new \DateTimeImmutable();
    }

    public static function fromRow(array $row): self
    {
        return new self(
            id:                  (int) $row['id'],
            terminalIdentifier:  (string) $row['terminal_identifier'],
            companyId:           (int) $row['company_id'],
            branchId:            (int) $row['branch_id'],
            cashierUserId:       isset($row['cashier_user_id']) ? (int) $row['cashier_user_id'] : null,
            step:                (int) $row['step'],
            payload:             is_array($row['payload'])
                                     ? $row['payload']
                                     : (json_decode((string) ($row['payload'] ?? '{}'), true) ?? []),
            posTransactionId:    isset($row['pos_transaction_id']) ? (int) $row['pos_transaction_id'] : null,
            expiresAt:           new \DateTimeImmutable($row['expires_at']),
            createdAt:           new \DateTimeImmutable($row['created_at']),
        );
    }
}
