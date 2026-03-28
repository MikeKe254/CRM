<?php

declare(strict_types=1);

namespace App\Services\Patronr;

use App\Services\Patronr\DTO\CheckoutDraft;
use Doctrine\DBAL\Connection;

/**
 * Manages the multi-step checkout draft lifecycle.
 *
 * One active draft per terminal at a time (enforced by UNIQUE key on terminal_identifier + company_id).
 * The draft is created at step 1 and updated as the waiter progresses.
 * On step 5 completion (or cancellation) the draft is deleted.
 *
 * Payload keys by step:
 *   Step 1: area_id (int|null), amount (string), description (string|null)
 *   Step 2: + payment_method_id (int), payment_method_key (string), config_id (int|null)
 *   Step 3: + pos_transaction_id (int), mode (manual|api), api_checkout_request_id (string|null)
 *   Step 4: + msisdn (string|null), customer_id (int|null), loyalty_account_id (int|null)
 *   Step 5: complete — draft is deleted
 */
final class CheckoutService
{
    private const DRAFT_TTL_MINUTES = 30;

    public function __construct(
        private readonly Connection $db,
    ) {}

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Get the active draft for a terminal. Returns null if none or expired.
     */
    public function getActiveDraft(string $terminalIdentifier, int $companyId): ?CheckoutDraft
    {
        $row = $this->fetchDraftRow($terminalIdentifier, $companyId);

        if ($row === null) {
            return null;
        }

        $draft = CheckoutDraft::fromRow($row);

        if ($draft->isExpired()) {
            $this->cancelDraft($terminalIdentifier, $companyId);
            return null;
        }

        return $draft;
    }

    /**
     * Start a brand-new checkout draft at step 1.
     * Cancels any existing draft for this terminal first.
     */
    public function startDraft(
        string $terminalIdentifier,
        int    $companyId,
        int    $branchId,
        ?int   $cashierUserId = null,
    ): CheckoutDraft {
        // Cancel any existing draft
        $this->cancelDraft($terminalIdentifier, $companyId);

        $expiresAt = (new \DateTimeImmutable())
            ->modify('+' . self::DRAFT_TTL_MINUTES . ' minutes')
            ->format('Y-m-d H:i:s');

        $this->db->insert('pos_checkout_drafts', [
            'company_id'          => $companyId,
            'branch_id'           => $branchId,
            'terminal_identifier' => $terminalIdentifier,
            'cashier_user_id'     => $cashierUserId,
            'step'                => 1,
            'payload'             => '{}',
            'expires_at'          => $expiresAt,
            'created_at'          => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $row = $this->fetchDraftRow($terminalIdentifier, $companyId);

        return CheckoutDraft::fromRow($row);
    }

    /**
     * Advance the draft to the next step, merging new payload data.
     * Returns the updated draft.
     */
    public function advanceDraft(
        string $terminalIdentifier,
        int    $companyId,
        int    $nextStep,
        array  $newPayload,
        ?int   $posTransactionId = null,
    ): CheckoutDraft {
        $row = $this->fetchDraftRow($terminalIdentifier, $companyId);

        if ($row === null) {
            throw new \RuntimeException('No active checkout draft found for this terminal.');
        }

        // Merge payload
        $existing = json_decode((string) ($row['payload'] ?? '{}'), true) ?? [];
        $merged   = array_merge($existing, $newPayload);

        $expiresAt = (new \DateTimeImmutable())
            ->modify('+' . self::DRAFT_TTL_MINUTES . ' minutes')
            ->format('Y-m-d H:i:s');

        $this->db->executeStatement(
            'UPDATE pos_checkout_drafts
                SET step                = :step,
                    payload             = :payload,
                    pos_transaction_id  = COALESCE(:txn_id, pos_transaction_id),
                    expires_at          = :expires_at,
                    updated_at          = NOW()
              WHERE terminal_identifier = :terminal
                AND company_id          = :company_id',
            [
                'step'       => $nextStep,
                'payload'    => json_encode($merged, JSON_UNESCAPED_UNICODE),
                'txn_id'     => $posTransactionId,
                'expires_at' => $expiresAt,
                'terminal'   => $terminalIdentifier,
                'company_id' => $companyId,
            ],
        );

        $updated = $this->fetchDraftRow($terminalIdentifier, $companyId);
        return CheckoutDraft::fromRow($updated);
    }

    /**
     * Complete and delete the draft (called at step 5).
     */
    public function completeDraft(string $terminalIdentifier, int $companyId): void
    {
        $this->db->executeStatement(
            'DELETE FROM pos_checkout_drafts
              WHERE terminal_identifier = :terminal AND company_id = :company_id',
            ['terminal' => $terminalIdentifier, 'company_id' => $companyId],
        );
    }

    /**
     * Cancel and delete the draft.
     */
    public function cancelDraft(string $terminalIdentifier, int $companyId): void
    {
        $this->db->executeStatement(
            'DELETE FROM pos_checkout_drafts
              WHERE terminal_identifier = :terminal AND company_id = :company_id',
            ['terminal' => $terminalIdentifier, 'company_id' => $companyId],
        );
    }

    /**
     * Delete all expired drafts across all terminals. Run periodically.
     */
    public function purgeExpired(): int
    {
        return (int) $this->db->executeStatement(
            'DELETE FROM pos_checkout_drafts WHERE expires_at < NOW()',
        );
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

    private function fetchDraftRow(string $terminalIdentifier, int $companyId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM pos_checkout_drafts
              WHERE terminal_identifier = :terminal
                AND company_id          = :company_id
              LIMIT 1',
            ['terminal' => $terminalIdentifier, 'company_id' => $companyId],
        );

        return $row ?: null;
    }
}
