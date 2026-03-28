<?php

declare(strict_types=1);

namespace App\Services\Loyalty;

use App\Services\Customer\CustomerService;
use App\Services\Loyalty\DTO\LoyaltyAccount;
use Doctrine\DBAL\Connection;

/**
 * All loyalty logic: enrollment, point calculation, awarding, tier resolution.
 *
 * Flow per transaction:
 *   1. findOrEnroll($companyId, $msisdn)     — get or create account
 *   2. calculatePoints($companyId, $amount)  — how many points will be awarded
 *   3. award($accountId, ...)                — write ledger + update balance + resolve tier
 */
final class LoyaltyService
{
    public function __construct(
        private readonly Connection $db,
        private readonly CustomerService $customers,
    ) {}

    // =========================================================================
    // PROGRAM
    // =========================================================================

    /**
     * Get the active loyalty program for a company.
     * Returns null if no program is configured.
     */
    public function getProgram(int $companyId): ?array
    {
        return $this->db->fetchAssociative(
            'SELECT * FROM loyalty_programs
              WHERE company_id = :company_id AND is_active = 1
              ORDER BY branch_id DESC, id ASC
              LIMIT 1',
            ['company_id' => $companyId],
        ) ?: null;
    }

    // =========================================================================
    // ACCOUNT
    // =========================================================================

    /**
     * Get loyalty account for a customer, with program branding and tier resolved.
     * Returns null if the customer is not enrolled.
     */
    public function getAccount(int $companyId, string $msisdn): ?LoyaltyAccount
    {
        $msisdn = $this->customers->normalizePhone($msisdn);
        if ($msisdn === null) {
            return null;
        }

        $row = $this->fetchAccountRow($companyId, $msisdn);

        return $row ? LoyaltyAccount::fromRow($row) : null;
    }

    /**
     * Enroll a customer in the company loyalty program.
     * If already enrolled, returns the existing account.
     * Also creates/links the customer record.
     */
    public function findOrEnroll(int $companyId, string $msisdn, ?string $firstName = null): ?LoyaltyAccount
    {
        $msisdn = $this->customers->normalizePhone($msisdn);
        if ($msisdn === null) {
            return null;
        }

        $program = $this->getProgram($companyId);
        if ($program === null) {
            return null;
        }

        // Ensure customer exists
        $customer = $this->customers->findOrCreate($companyId, $msisdn, $firstName);

        // Check existing account
        $existing = $this->fetchAccountRow($companyId, $msisdn);
        if ($existing !== null) {
            return LoyaltyAccount::fromRow($existing);
        }

        // Enroll
        $this->db->insert('loyalty_accounts', [
            'company_id'          => $companyId,
            'loyalty_program_id'  => $program['id'],
            'customer_id'         => $customer['id'],
            'msisdn'              => $msisdn,
            'points_balance'      => 0,
            'total_points_earned' => 0,
            'total_points_redeemed' => 0,
            'enrolled_at'         => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $accountId = (int) $this->db->lastInsertId();

        // Award enrollment bonus if configured
        $bonusPoints = (int) ($program['enroll_bonus_points'] ?? 0);
        if ($bonusPoints > 0) {
            $this->db->executeStatement(
                'UPDATE loyalty_accounts
                    SET points_balance = :pts, total_points_earned = :pts, updated_at = NOW()
                  WHERE id = :id',
                ['pts' => $bonusPoints, 'id' => $accountId],
            );

            $this->writeLedger($accountId, $companyId, 'enroll_bonus', $bonusPoints, $bonusPoints, 'Enrollment bonus');
        }

        // Resolve initial tier
        $this->resolveAndUpdateTier($accountId, $companyId);

        $row = $this->fetchAccountRow($companyId, $msisdn);
        return $row ? LoyaltyAccount::fromRow($row) : null;
    }

    // =========================================================================
    // POINTS
    // =========================================================================

    /**
     * Calculate how many points will be awarded for a given amount.
     * Returns 0 if no program is configured or amount is too low.
     */
    public function calculatePoints(int $companyId, float $amount): int
    {
        $program = $this->getProgram($companyId);
        if ($program === null || $amount <= 0) {
            return 0;
        }

        $unitAmount  = (float) $program['unit_amount'];
        $pointsPerUnit = (int) $program['points_per_unit'];

        if ($unitAmount <= 0) {
            return 0;
        }

        return (int) floor(($amount / $unitAmount) * $pointsPerUnit);
    }

    /**
     * Award points to a loyalty account after a completed transaction.
     * Writes to ledger, updates balance, resolves tier.
     * Returns points awarded (0 if account not found or 0 points calculated).
     */
    public function award(
        int $loyaltyAccountId,
        int $companyId,
        float $amount,
        int $posTransactionId,
        ?int $cashierUserId,
    ): int {
        $points = $this->calculatePoints($companyId, $amount);

        if ($points <= 0) {
            return 0;
        }

        // Atomic update
        $this->db->executeStatement(
            'UPDATE loyalty_accounts
                SET points_balance      = points_balance + :pts,
                    total_points_earned = total_points_earned + :pts,
                    updated_at          = NOW()
              WHERE id = :id AND company_id = :company_id',
            ['pts' => $points, 'id' => $loyaltyAccountId, 'company_id' => $companyId],
        );

        // Fetch new balance for ledger
        $newBalance = (int) $this->db->fetchOne(
            'SELECT points_balance FROM loyalty_accounts WHERE id = :id',
            ['id' => $loyaltyAccountId],
        );

        $this->writeLedger(
            $loyaltyAccountId,
            $companyId,
            'earn',
            $points,
            $newBalance,
            null,
            $posTransactionId,
            $cashierUserId,
        );

        $this->resolveAndUpdateTier($loyaltyAccountId, $companyId);

        return $points;
    }

    /**
     * Resolve the correct tier for an account based on current points_balance
     * and update loyalty_tier_id on the account row.
     */
    public function resolveAndUpdateTier(int $loyaltyAccountId, int $companyId): void
    {
        $account = $this->db->fetchAssociative(
            'SELECT la.points_balance, la.loyalty_program_id
               FROM loyalty_accounts la
              WHERE la.id = :id AND la.company_id = :company_id
              LIMIT 1',
            ['id' => $loyaltyAccountId, 'company_id' => $companyId],
        );

        if (!$account) {
            return;
        }

        // Find the highest tier the customer qualifies for
        $tier = $this->db->fetchAssociative(
            'SELECT id FROM loyalty_tiers
              WHERE loyalty_program_id = :program_id
                AND min_points <= :balance
              ORDER BY min_points DESC
              LIMIT 1',
            [
                'program_id' => $account['loyalty_program_id'],
                'balance'    => $account['points_balance'],
            ],
        );

        $this->db->executeStatement(
            'UPDATE loyalty_accounts SET loyalty_tier_id = :tier_id, updated_at = NOW() WHERE id = :id',
            ['tier_id' => $tier ? $tier['id'] : null, 'id' => $loyaltyAccountId],
        );
    }

    // =========================================================================
    // REDEMPTION
    // =========================================================================

    /**
     * Get redemption config for a company's loyalty program (if enabled).
     */
    public function getRedemptionConfig(int $companyId): ?array
    {
        return $this->db->fetchAssociative(
            'SELECT id, program_name, points_name, points_symbol,
                    kes_per_point, max_redemption_pct
               FROM loyalty_programs
              WHERE company_id = :cid AND is_active = 1 AND redemption_enabled = 1
              LIMIT 1',
            ['cid' => $companyId],
        ) ?: null;
    }

    /**
     * Redeem points from a loyalty account as payment.
     * Returns false if the account has insufficient points.
     */
    public function redeemPoints(
        int  $companyId,
        int  $loyaltyAccountId,
        int  $points,
        ?int $posTransactionId = null,
        ?int $cashierUserId    = null,
    ): bool {
        if ($points <= 0) {
            return false;
        }

        $affected = $this->db->executeStatement(
            'UPDATE loyalty_accounts
                SET points_balance        = points_balance - :pts,
                    total_points_redeemed = total_points_redeemed + :pts,
                    updated_at            = NOW()
              WHERE id = :id
                AND company_id   = :cid
                AND points_balance >= :pts',
            ['pts' => $points, 'id' => $loyaltyAccountId, 'cid' => $companyId],
        );

        if ($affected === 0) {
            return false; // Insufficient balance
        }

        $balanceAfter = (int) ($this->db->fetchOne(
            'SELECT points_balance FROM loyalty_accounts WHERE id = :id',
            ['id' => $loyaltyAccountId],
        ) ?? 0);

        $this->writeLedger(
            loyaltyAccountId: $loyaltyAccountId,
            companyId:        $companyId,
            type:             'redeem',
            points:           -$points,
            balanceAfter:     $balanceAfter,
            note:             'Redeemed as payment',
            posTransactionId: $posTransactionId,
            createdByUserId:  $cashierUserId,
        );

        $this->resolveAndUpdateTier($loyaltyAccountId, $companyId);

        return true;
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

    private function fetchAccountRow(int $companyId, string $msisdn): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT
                la.*,
                lp.program_name,
                lp.points_name,
                lp.points_symbol,
                lt.id           AS tier_id,
                lt.name         AS tier_name,
                lt.color        AS tier_color,
                nt.name         AS next_tier_name,
                nt.min_points   AS next_tier_min_points
               FROM loyalty_accounts la
               JOIN loyalty_programs  lp ON lp.id = la.loyalty_program_id
          LEFT JOIN loyalty_tiers     lt ON lt.id = la.loyalty_tier_id
          LEFT JOIN loyalty_tiers     nt ON nt.loyalty_program_id = lp.id
                                        AND nt.min_points > la.points_balance
              WHERE la.company_id = :company_id
                AND la.msisdn     = :msisdn
              ORDER BY nt.min_points ASC
              LIMIT 1',
            ['company_id' => $companyId, 'msisdn' => $msisdn],
        );

        return $row ?: null;
    }

    private function writeLedger(
        int $loyaltyAccountId,
        int $companyId,
        string $type,
        int $points,
        int $balanceAfter,
        ?string $note = null,
        ?int $posTransactionId = null,
        ?int $createdByUserId = null,
    ): void {
        $this->db->insert('loyalty_ledger', [
            'company_id'          => $companyId,
            'loyalty_account_id'  => $loyaltyAccountId,
            'pos_transaction_id'  => $posTransactionId,
            'type'                => $type,
            'points'              => $points,
            'balance_after'       => $balanceAfter,
            'note'                => $note,
            'created_by_user_id'  => $createdByUserId,
            'created_at'          => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }
}
