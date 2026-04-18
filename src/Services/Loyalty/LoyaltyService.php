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
    public function getProgram(int $companyId, ?int $branchId = null): ?array
    {
        if ($branchId !== null) {
            return $this->db->fetchAssociative(
                'SELECT * FROM loyalty_programs
                  WHERE company_id = :company_id
                    AND branch_id = :branch_id
                    AND is_active = 1
                  ORDER BY id ASC
                  LIMIT 1',
                ['company_id' => $companyId, 'branch_id' => $branchId],
            ) ?: null;
        }

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
    public function getAccount(int $companyId, string $msisdn, ?int $branchId = null): ?LoyaltyAccount
    {
        $msisdn = $this->customers->normalizePhone($msisdn);
        if ($msisdn === null) {
            return null;
        }

        $row = $this->fetchAccountRow($companyId, $msisdn, $branchId);

        return $row ? LoyaltyAccount::fromRow($row) : null;
    }

    /**
     * Enroll a customer in the company loyalty program.
     * If already enrolled, returns the existing account.
     * Also creates/links the customer record.
     */
    public function findOrEnroll(int $companyId, string $msisdn, ?string $firstName = null, ?int $branchId = null): ?LoyaltyAccount
    {
        $msisdn = $this->customers->normalizePhone($msisdn);
        if ($msisdn === null) {
            return null;
        }

        $program = $this->getProgram($companyId, $branchId);
        if ($program === null) {
            return null;
        }

        // Ensure customer exists
        $customer = $this->customers->findOrCreate($companyId, $msisdn, $firstName);

        // Check existing account
        $existing = $this->fetchAccountRow($companyId, $msisdn, $branchId);
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
        $bonusPoints = (float) ($program['enroll_bonus_points'] ?? 0);
        if ($bonusPoints > 0.0) {
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

        $row = $this->fetchAccountRow($companyId, $msisdn, $branchId);
        return $row ? LoyaltyAccount::fromRow($row) : null;
    }

    // =========================================================================
    // POINTS
    // =========================================================================

    /**
     * Calculate how many points will be awarded for a given amount.
     * Applies the highest active point multiplier when one is present.
     * Returns a decimal value (e.g. 0.99, 1.50) — never floors to zero.
     * Returns 0.0 if no program is configured or amount is invalid.
     */
    public function calculatePoints(int $companyId, float $amount, ?int $branchId = null): float
    {
        $program = $this->getProgram($companyId, $branchId);
        if ($program === null || $amount <= 0) {
            return 0.0;
        }

        $unitAmount    = (float) $program['unit_amount'];
        $pointsPerUnit = (int)   $program['points_per_unit'];

        if ($unitAmount <= 0) {
            return 0.0;
        }

        $base = round(($amount / $unitAmount) * $pointsPerUnit, 2);

        // Apply highest active multiplier if any exist for this program
        $multiplier = $this->getActiveMultiplier((int) $program['id']);
        if ($multiplier > 1.0) {
            $base = round($base * $multiplier, 2);
        }

        return $base;
    }

    /**
     * Return the highest active point multiplier for a loyalty program right now.
     * Checks day-of-week, time window, and date range.
     * Returns 1.0 (no boost) if the loyalty_point_multipliers table does not exist
     * or no active multiplier matches the current moment.
     */
    public function getActiveMultiplier(int $loyaltyProgramId): float
    {
        // Guard: table may not exist yet (segment 42 not yet applied)
        try {
            $now     = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Nairobi'));
            $dayMap  = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
            $dayKey  = $dayMap[(int) $now->format('N')];
            $timeNow = $now->format('H:i:s');
            $dateNow = $now->format('Y-m-d');

            $multiplier = $this->db->fetchOne(
                "SELECT MAX(multiplier)
                   FROM loyalty_point_multipliers
                  WHERE loyalty_program_id = :program_id
                    AND is_active = 1
                    AND (applies_on IS NULL OR FIND_IN_SET(:day, applies_on) > 0)
                    AND (time_from IS NULL OR :time >= time_from)
                    AND (time_to   IS NULL OR :time <= time_to)
                    AND (valid_from IS NULL OR :date >= valid_from)
                    AND (valid_to   IS NULL OR :date <= valid_to)",
                [
                    'program_id' => $loyaltyProgramId,
                    'day'        => $dayKey,
                    'time'       => $timeNow,
                    'date'       => $dateNow,
                ],
            );

            return $multiplier !== false && $multiplier !== null ? (float) $multiplier : 1.0;
        } catch (\Throwable) {
            // Table does not exist yet — return no boost
            return 1.0;
        }
    }

    /**
     * Award points to a loyalty account after a completed transaction.
     * Writes to ledger, updates balance, resolves tier, maintains intelligence columns.
     * Returns points awarded as a decimal (0.0 if nothing awarded).
     */
    public function award(
        int $loyaltyAccountId,
        int $companyId,
        float $amount,
        int $posTransactionId,
        ?int $cashierUserId,
        ?int $branchId = null,
    ): float {
        $points = $this->calculatePoints($companyId, $amount, $branchId);

        if ($points <= 0.0) {
            return 0.0;
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // Atomic update — maintain intelligence columns alongside balance
        $this->db->executeStatement(
            'UPDATE loyalty_accounts
                SET points_balance          = points_balance + :pts,
                    total_points_earned     = total_points_earned + :pts,
                    last_transaction_at     = :now,
                    visit_count             = visit_count + 1,
                    updated_at              = :now
              WHERE id = :id AND company_id = :company_id',
            ['pts' => $points, 'now' => $now, 'id' => $loyaltyAccountId, 'company_id' => $companyId],
        );

        // Fetch new balance for ledger
        $newBalance = (float) $this->db->fetchOne(
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
        $this->updateLifecycleStage($loyaltyAccountId, $companyId);
        $this->markNotificationReturned($loyaltyAccountId, $companyId);
        $this->checkAndAwardBirthdayBonus($loyaltyAccountId, $companyId);

        return $points;
    }

    /**
     * Resolve the correct tier for an account based on current points_balance
     * and update loyalty_tier_id on the account row.
     * Queues a tier_upgrade notification when the tier improves.
     */
    public function resolveAndUpdateTier(int $loyaltyAccountId, int $companyId): void
    {
        $account = $this->db->fetchAssociative(
            'SELECT la.points_balance, la.loyalty_program_id, la.loyalty_tier_id
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
            'SELECT id, name, perks_description FROM loyalty_tiers
              WHERE loyalty_program_id = :program_id
                AND min_points <= :balance
              ORDER BY min_points DESC
              LIMIT 1',
            [
                'program_id' => $account['loyalty_program_id'],
                'balance'    => $account['points_balance'],
            ],
        );

        $newTierId = $tier ? $tier['id'] : null;
        $oldTierId = $account['loyalty_tier_id'];

        $this->db->executeStatement(
            'UPDATE loyalty_accounts SET loyalty_tier_id = :tier_id, updated_at = NOW() WHERE id = :id',
            ['tier_id' => $newTierId, 'id' => $loyaltyAccountId],
        );

        // Queue tier upgrade notification if tier improved
        if ($tier && $newTierId !== $oldTierId && $oldTierId !== null) {
            $this->queueAutomationNotification(
                $loyaltyAccountId,
                $companyId,
                $account['loyalty_program_id'],
                'tier_upgrade',
                ['tier_name' => $tier['name'], 'perks_description' => $tier['perks_description'] ?? ''],
            );
        }
    }

    // =========================================================================
    // REDEMPTION
    // =========================================================================

    /**
     * Get redemption config for a company's loyalty program (if enabled).
     */
    public function getRedemptionConfig(int $companyId, ?int $branchId = null): ?array
    {
        if ($branchId !== null) {
            return $this->db->fetchAssociative(
                'SELECT id, program_name, points_name, points_symbol,
                        kes_per_point, max_redemption_pct
                   FROM loyalty_programs
                  WHERE company_id = :cid
                    AND branch_id = :branch_id
                    AND is_active = 1
                    AND redemption_enabled = 1
                  LIMIT 1',
                ['cid' => $companyId, 'branch_id' => $branchId],
            ) ?: null;
        }

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

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // Maintain last_transaction_at on redemption too — it's an engagement signal
        $this->db->executeStatement(
            'UPDATE loyalty_accounts SET last_transaction_at = :now, updated_at = :now WHERE id = :id',
            ['now' => $now, 'id' => $loyaltyAccountId],
        );

        $balanceAfter = (float) ($this->db->fetchOne(
            'SELECT points_balance FROM loyalty_accounts WHERE id = :id',
            ['id' => $loyaltyAccountId],
        ) ?? 0);

        $this->writeLedger(
            loyaltyAccountId: $loyaltyAccountId,
            companyId:        $companyId,
            type:             'redeem',
            points:           -(float) $points,
            balanceAfter:     $balanceAfter,
            note:             'Redeemed as payment',
            posTransactionId: $posTransactionId,
            createdByUserId:  $cashierUserId,
        );

        $this->resolveAndUpdateTier($loyaltyAccountId, $companyId);
        $this->updateLifecycleStage($loyaltyAccountId, $companyId);
        $this->markNotificationReturned($loyaltyAccountId, $companyId);

        return true;
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

    private function fetchAccountRow(int $companyId, string $msisdn, ?int $branchId = null): ?array
    {
        if ($branchId !== null) {
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
                    AND lp.branch_id  = :branch_id
                  ORDER BY nt.min_points ASC
                  LIMIT 1',
                ['company_id' => $companyId, 'msisdn' => $msisdn, 'branch_id' => $branchId],
            );

            return $row ?: null;
        }

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
        float $points,
        float $balanceAfter,
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

    /**
     * Compute and store the lifecycle stage for an account based on last_transaction_at.
     *
     * Stages:
     *   new      — no earn/redeem ever, or enrolled < 14 days with no transaction
     *   active   — transacted within last 60 days
     *   at_risk  — last transaction 61–90 days ago
     *   lapsing  — last transaction 91–180 days ago
     *   churned  — no transaction and enrolled > 14 days, or last transaction > 180 days ago
     */
    public function updateLifecycleStage(int $loyaltyAccountId, int $companyId): void
    {
        $account = $this->db->fetchAssociative(
            'SELECT last_transaction_at, enrolled_at FROM loyalty_accounts
              WHERE id = :id AND company_id = :cid LIMIT 1',
            ['id' => $loyaltyAccountId, 'cid' => $companyId],
        );

        if (!$account) {
            return;
        }

        $now = new \DateTimeImmutable();

        if ($account['last_transaction_at'] === null) {
            $enrolledAt = new \DateTimeImmutable($account['enrolled_at']);
            $daysSinceEnroll = (int) $now->diff($enrolledAt)->days;
            $stage = $daysSinceEnroll <= 14 ? 'new' : 'churned';
        } else {
            $lastTxn = new \DateTimeImmutable($account['last_transaction_at']);
            $daysSince = (int) $now->diff($lastTxn)->days;

            $stage = match (true) {
                $daysSince <= 60  => 'active',
                $daysSince <= 90  => 'at_risk',
                $daysSince <= 180 => 'lapsing',
                default           => 'churned',
            };
        }

        $this->db->executeStatement(
            'UPDATE loyalty_accounts SET lifecycle_stage = :stage WHERE id = :id',
            ['stage' => $stage, 'id' => $loyaltyAccountId],
        );
    }

    /**
     * Mark any open notification for this account as resolved (returned).
     * Called on every earn transaction — if the member transacts after receiving
     * a win-back, almost-tier, or expiry-warning notification, that loop is closed.
     * Public so the M-Pesa auto-award path can close the same loop.
     */
    public function markNotificationReturned(int $loyaltyAccountId, int $companyId): void
    {
        try {
            $this->db->executeStatement(
                "UPDATE loyalty_notifications
                    SET returned_at = NOW()
                  WHERE loyalty_account_id = :id
                    AND company_id         = :cid
                    AND returned_at        IS NULL
                    AND sent_at            >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
                ['id' => $loyaltyAccountId, 'cid' => $companyId],
            );
        } catch (\Throwable) {
            // Table may not exist on older deployments — non-fatal
        }
    }

    /**
     * Award a birthday bonus if:
     *   - The member's birth_month matches the current month
     *   - No birthday_bonus ledger entry exists for this account in the current month
     *   - A birthday_bonus automation is configured and active for this program
     * Public so the M-Pesa auto-award path can trigger the same bonus.
     */
    public function checkAndAwardBirthdayBonus(int $loyaltyAccountId, int $companyId): void
    {
        try {
            $account = $this->db->fetchAssociative(
                'SELECT la.loyalty_program_id, c.birth_month
                   FROM loyalty_accounts la
                   JOIN customers c ON c.id = la.customer_id
                  WHERE la.id = :id AND la.company_id = :cid LIMIT 1',
                ['id' => $loyaltyAccountId, 'cid' => $companyId],
            );

            if (!$account || empty($account['birth_month'])) {
                return;
            }

            $currentMonth = (int) (new \DateTimeImmutable())->format('n');
            if ((int) $account['birth_month'] !== $currentMonth) {
                return;
            }

            // Check if already awarded this month
            $alreadyAwarded = $this->db->fetchOne(
                "SELECT 1 FROM loyalty_ledger
                  WHERE loyalty_account_id = :id
                    AND type               = 'enroll_bonus'
                    AND note               = 'Birthday bonus'
                    AND YEAR(created_at)   = YEAR(NOW())
                    AND MONTH(created_at)  = MONTH(NOW())
                  LIMIT 1",
                ['id' => $loyaltyAccountId],
            );

            if ($alreadyAwarded) {
                return;
            }

            // Check automation config
            $automation = $this->db->fetchAssociative(
                "SELECT threshold_points FROM loyalty_automations
                  WHERE loyalty_program_id = :pid
                    AND trigger_type       = 'birthday_bonus'
                    AND is_active          = 1
                  LIMIT 1",
                ['pid' => $account['loyalty_program_id']],
            );

            if (!$automation || (int) ($automation['threshold_points'] ?? 0) <= 0) {
                return;
            }

            $bonusPoints = (float) $automation['threshold_points'];

            $this->db->executeStatement(
                'UPDATE loyalty_accounts
                    SET points_balance      = points_balance + :pts,
                        total_points_earned = total_points_earned + :pts,
                        updated_at          = NOW()
                  WHERE id = :id',
                ['pts' => $bonusPoints, 'id' => $loyaltyAccountId],
            );

            $newBalance = (float) $this->db->fetchOne(
                'SELECT points_balance FROM loyalty_accounts WHERE id = :id',
                ['id' => $loyaltyAccountId],
            );

            $this->writeLedger($loyaltyAccountId, $companyId, 'enroll_bonus', $bonusPoints, $newBalance, 'Birthday bonus');
        } catch (\Throwable) {
            // Non-fatal — birthday bonus is a nice-to-have
        }
    }

    /**
     * Queue an automation notification if the trigger is configured and active.
     * Replaces template variables ({first_name}, {tier_name}, etc.) with real values.
     */
    private function queueAutomationNotification(
        int $loyaltyAccountId,
        int $companyId,
        int $loyaltyProgramId,
        string $triggerType,
        array $vars = [],
    ): void {
        try {
            $automation = $this->db->fetchAssociative(
                'SELECT message_template FROM loyalty_automations
                  WHERE loyalty_program_id = :pid
                    AND trigger_type       = :type
                    AND is_active          = 1
                  LIMIT 1',
                ['pid' => $loyaltyProgramId, 'type' => $triggerType],
            );

            if (!$automation) {
                return;
            }

            // Fetch member first name for the template
            $firstName = (string) ($this->db->fetchOne(
                'SELECT c.first_name FROM loyalty_accounts la
                   JOIN customers c ON c.id = la.customer_id
                  WHERE la.id = :id LIMIT 1',
                ['id' => $loyaltyAccountId],
            ) ?? '');

            $message = $automation['message_template'];
            $replacements = array_merge(['first_name' => $firstName], $vars);
            foreach ($replacements as $key => $value) {
                $message = str_replace('{' . $key . '}', (string) $value, $message);
            }

            $this->db->insert('loyalty_notifications', [
                'company_id'         => $companyId,
                'loyalty_account_id' => $loyaltyAccountId,
                'trigger_type'       => $triggerType,
                'channel'            => 'sms',
                'message_text'       => $message,
                'sent_at'            => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // Non-fatal
        }
    }
}
