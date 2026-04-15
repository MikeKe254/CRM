<?php

declare(strict_types=1);

namespace App\Services\Patronr;

use App\Services\Customer\CustomerService;
use App\Services\Loyalty\LoyaltyService;
use Doctrine\DBAL\Connection;

/**
 * Creates and manages pos_transaction records.
 *
 * Responsibilities:
 *   - Create a transaction row at step 3 (when payment is initiated)
 *   - Mark it complete (after manual confirm or API callback)
 *   - Mark it failed
 *   - Trigger loyalty award on completion
 *   - Link back to mpesa_payments for Safaricom callback reconciliation
 */
final class TransactionRecordService
{
    public function __construct(
        private readonly Connection $db,
        private readonly CustomerService $customers,
        private readonly LoyaltyService $loyalty,
    ) {}

    // =========================================================================
    // CREATE
    // =========================================================================

    /**
     * Create a new pos_transaction row in 'pending' status.
     * Called at checkout step 3 when payment is initiated.
     * Returns the new transaction id.
     */
    public function create(
        int     $companyId,
        int     $branchId,
        ?int    $areaId,
        string  $terminalIdentifier,
        ?int    $cashierUserId,
        int     $paymentMethodId,
        float   $amount,
        ?string $description,
        string  $mode,
        ?int    $mpesaConfigId = null,
        ?int    $pesapalConfigId = null,
        ?string $revenueSourceType = null,
        ?int    $revenueSourceId = null,
        ?int    $eventId = null,
        ?int    $covers = null,
        float   $grossAmount = 0.0,
        float   $discountAmount = 0.0,
        ?string $discountReason = null,
        ?int    $discountByUserId = null,
    ): int {
        $this->db->insert('pos_transactions', [
            'company_id'             => $companyId,
            'branch_id'              => $branchId,
            'area_id'                => $areaId,
            'terminal_identifier'    => $terminalIdentifier,
            'cashier_user_id'        => $cashierUserId,
            'payment_method_id'      => $paymentMethodId,
            'mpesa_config_id'        => $mpesaConfigId,
            'pesapal_config_id'      => $pesapalConfigId,
            'amount'                 => $amount,
            'gross_amount'           => $grossAmount > 0.0 ? $grossAmount : $amount,
            'discount_amount'        => $discountAmount,
            'discount_reason'        => $discountReason,
            'discount_by_user_id'    => $discountByUserId,
            'description'            => $description,
            'mode'                   => $mode,
            'revenue_source_type'    => $revenueSourceType,
            'revenue_source_id'      => $revenueSourceId,
            'event_id'               => $eventId,
            'covers'                 => $covers,
            'status'                 => 'pending',
            'created_at'             => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->lastInsertId();
    }

    // =========================================================================
    // STATUS TRANSITIONS
    // =========================================================================

    /**
     * Mark transaction as processing (API call dispatched, awaiting callback).
     */
    public function markProcessing(
        int     $transactionId,
        string  $checkoutRequestId,
        ?string $merchantRequestId = null,
    ): void {
        $this->db->executeStatement(
            'UPDATE pos_transactions
                SET status                    = \'processing\',
                    api_checkout_request_id   = :checkout_req,
                    api_merchant_request_id   = :merchant_req,
                    updated_at                = NOW()
              WHERE id = :id',
            [
                'checkout_req' => $checkoutRequestId,
                'merchant_req' => $merchantRequestId,
                'id'           => $transactionId,
            ],
        );
    }

    /**
     * Mark transaction as complete.
     * Optionally captures customer phone and awards loyalty points.
     * Returns points awarded (0 if no loyalty).
     */
    public function markComplete(
        int     $transactionId,
        ?string $apiReceipt = null,
        ?array  $apiRawResponse = null,
        ?string $msisdn = null,
        ?int    $cashierUserId = null,
        ?float  $earnableAmount = null, // amount eligible for points (excludes loyalty-redeemed portion)
    ): float {
        $txn = $this->fetchTransaction($transactionId);
        if ($txn === null) {
            return 0;
        }

        $customerId       = null;
        $loyaltyAccountId = null;
        $pointsAwarded    = (float) ($txn['loyalty_points_awarded'] ?? 0);
        $alreadyAutoAwarded = (bool) ($txn['loyalty_auto_awarded'] ?? false);
        $alreadyAutoAwardedAmount = (float) ($txn['loyalty_auto_awarded_amount'] ?? 0);

        // Amount eligible for points: defaults to full transaction amount
        $pointsBase = $earnableAmount ?? (float) $txn['amount'];
        $manualPointsBase = max(0.0, $pointsBase - $alreadyAutoAwardedAmount);

        // Resolve customer and award loyalty if msisdn provided and this transaction
        // was not already awarded by the callback-side auto-award flow.
        if ($msisdn !== null && $manualPointsBase > 0) {
            $normalised = $this->customers->normalizePhone($msisdn);

            if ($normalised !== null) {
                $customer   = $this->customers->findOrCreate($txn['company_id'], $normalised);
                $customerId = (int) $customer['id'];
                $this->customers->touchLastSeen($customerId);

                $account = $this->loyalty->findOrEnroll($txn['company_id'], $normalised, null, (int) $txn['branch_id']);

                if ($account !== null) {
                    $loyaltyAccountId = $account->id;
                    $manualPointsAwarded = $this->loyalty->award(
                        loyaltyAccountId: $account->id,
                        companyId:        $txn['company_id'],
                        amount:           $manualPointsBase,
                        posTransactionId: $transactionId,
                        cashierUserId:    $cashierUserId,
                        branchId:         (int) $txn['branch_id'],
                    );
                    $pointsAwarded += $manualPointsAwarded;
                }
            }
        }

        if ($alreadyAutoAwarded) {
            $customerId       = $txn['customer_id'] !== null ? (int) $txn['customer_id'] : null;
            $loyaltyAccountId = $txn['loyalty_account_id'] !== null ? (int) $txn['loyalty_account_id'] : null;
            $pointsAwarded    = max($pointsAwarded, (float) ($txn['loyalty_points_awarded'] ?? 0));
        }

        $this->db->executeStatement(
            'UPDATE pos_transactions
                SET status                  = \'complete\',
                    api_receipt             = :receipt,
                    api_raw_response        = :raw,
                    msisdn                  = COALESCE(:msisdn, msisdn),
                    customer_id             = COALESCE(:customer_id, customer_id),
                    loyalty_account_id      = COALESCE(:loyalty_id, loyalty_account_id),
                    loyalty_points_awarded  = :points,
                    completed_at            = NOW(),
                    updated_at              = NOW()
              WHERE id = :id',
            [
                'receipt'     => $apiReceipt,
                'raw'         => $apiRawResponse ? json_encode($apiRawResponse) : null,
                'msisdn'      => $msisdn ? $this->customers->normalizePhone($msisdn) : null,
                'customer_id' => $customerId,
                'loyalty_id'  => $loyaltyAccountId,
                'points'      => $pointsAwarded,
                'id'          => $transactionId,
            ],
        );

        return $pointsAwarded;
    }

    /**
     * Sync callback-side loyalty award data from mpesa_payments into the linked
     * POS transaction without re-awarding points.
     */
    public function syncAutoAwardedMpesaPaymentToTransaction(int $transactionId, int $mpesaPaymentId, ?float $awardedAmount = null): void
    {
        $payment = $this->db->fetchAssociative(
            'SELECT id, transaction_id, msisdn, customer_id, loyalty_account_id,
                    loyalty_points_awarded, loyalty_auto_awarded, loyalty_awarded_at
               FROM mpesa_payments
              WHERE id = :id
              LIMIT 1',
            ['id' => $mpesaPaymentId],
        );

        if (!$payment) {
            return;
        }

        $alreadyLinked = (bool) $this->db->fetchOne(
            'SELECT 1
               FROM loyalty_ledger
              WHERE mpesa_payment_id = :mpesa_id
                AND pos_transaction_id = :txn_id
              LIMIT 1',
            [
                'mpesa_id' => $mpesaPaymentId,
                'txn_id' => $transactionId,
            ],
        );

        $params = [
            'id' => $transactionId,
            'mpesa_id' => $mpesaPaymentId,
            'receipt' => $payment['transaction_id'] ?: null,
            'msisdn' => $payment['msisdn'] ?: null,
            'customer_id' => $payment['customer_id'] !== null ? (int) $payment['customer_id'] : null,
            'loyalty_account_id' => $payment['loyalty_account_id'] !== null ? (int) $payment['loyalty_account_id'] : null,
            'points' => (float) ($payment['loyalty_points_awarded'] ?? 0),
            'auto_awarded' => (int) ((bool) ($payment['loyalty_auto_awarded'] ?? false)),
            'awarded_at' => $payment['loyalty_awarded_at'] ?: null,
            'award_source' => (bool) ($payment['loyalty_auto_awarded'] ?? false) ? 'mpesa_callback' : null,
            'awarded_amount' => $awardedAmount,
            'already_linked' => (int) $alreadyLinked,
        ];

        $this->db->executeStatement(
            'UPDATE pos_transactions
                SET mpesa_payment_id = COALESCE(mpesa_payment_id, :mpesa_id),
                    api_receipt = COALESCE(:receipt, api_receipt),
                    msisdn = COALESCE(:msisdn, msisdn),
                    customer_id = COALESCE(:customer_id, customer_id),
                    loyalty_account_id = COALESCE(:loyalty_account_id, loyalty_account_id),
                    loyalty_points_awarded = CASE
                        WHEN :auto_awarded = 1 AND :already_linked = 0 THEN loyalty_points_awarded + :points
                        ELSE loyalty_points_awarded
                    END,
                    loyalty_auto_awarded = CASE
                        WHEN :auto_awarded = 1 THEN 1
                        ELSE loyalty_auto_awarded
                    END,
                    loyalty_auto_awarded_amount = CASE
                        WHEN :auto_awarded = 1 AND :already_linked = 0 THEN loyalty_auto_awarded_amount + COALESCE(:awarded_amount, 0)
                        ELSE loyalty_auto_awarded_amount
                    END,
                    loyalty_awarded_at = CASE
                        WHEN :auto_awarded = 1 THEN COALESCE(:awarded_at, loyalty_awarded_at)
                        ELSE loyalty_awarded_at
                    END,
                    loyalty_award_source = CASE
                        WHEN :auto_awarded = 1 THEN COALESCE(:award_source, loyalty_award_source)
                        ELSE loyalty_award_source
                    END,
                    updated_at = NOW()
              WHERE id = :id',
            $params,
        );

        if ((bool) ($payment['loyalty_auto_awarded'] ?? false)) {
            $this->db->executeStatement(
                'UPDATE loyalty_ledger
                    SET pos_transaction_id = COALESCE(pos_transaction_id, :txn_id)
                  WHERE mpesa_payment_id = :mpesa_id',
                [
                    'txn_id' => $transactionId,
                    'mpesa_id' => $mpesaPaymentId,
                ],
            );
        }
    }

    /**
     * Mark transaction as failed.
     */
    public function markFailed(int $transactionId, ?array $apiRawResponse = null): void
    {
        $this->db->executeStatement(
            'UPDATE pos_transactions
                SET status           = \'failed\',
                    api_raw_response = COALESCE(:raw, api_raw_response),
                    updated_at       = NOW()
              WHERE id = :id',
            [
                'raw' => $apiRawResponse ? json_encode($apiRawResponse) : null,
                'id'  => $transactionId,
            ],
        );
    }

    /**
     * Mark transaction as cancelled (user aborted).
     */
    public function markCancelled(int $transactionId): void
    {
        $this->db->executeStatement(
            'UPDATE pos_transactions SET status = \'cancelled\', updated_at = NOW() WHERE id = :id',
            ['id' => $transactionId],
        );
    }

    /**
     * Record one leg of a split payment (or the sole method for a single-method checkout).
     * Safe to call multiple times — duplicate (txn_id, split_index) is silently ignored.
     */
    public function recordSplitLeg(
        int     $posTransactionId,
        int     $splitIndex,
        int     $paymentMethodId,
        string  $methodKey,
        float   $amount,
        ?int    $mpesaConfigId = null,
        ?string $apiReceipt = null,
        ?int    $mpesaPaymentId = null,
        ?string $paymentNotes = null,
    ): void {
        // Upsert: if the leg already exists (e.g. double-confirm) update the receipt only
        $existing = $this->db->fetchOne(
            'SELECT id FROM pos_transaction_splits WHERE pos_transaction_id = :txn AND split_index = :idx LIMIT 1',
            ['txn' => $posTransactionId, 'idx' => $splitIndex],
        );

        if ($existing) {
            if ($apiReceipt !== null || $mpesaPaymentId !== null) {
                $this->db->executeStatement(
                    'UPDATE pos_transaction_splits
                        SET api_receipt = COALESCE(:receipt, api_receipt),
                            mpesa_payment_id = COALESCE(:mp_id, mpesa_payment_id)
                      WHERE id = :id',
                    ['receipt' => $apiReceipt, 'mp_id' => $mpesaPaymentId, 'id' => $existing],
                );
            }
            return;
        }

        $this->db->insert('pos_transaction_splits', [
            'pos_transaction_id' => $posTransactionId,
            'split_index'        => $splitIndex,
            'payment_method_id'  => $paymentMethodId,
            'method_key'         => $methodKey,
            'amount'             => $amount,
            'mpesa_config_id'    => $mpesaConfigId,
            'api_receipt'        => $apiReceipt,
            'mpesa_payment_id'   => $mpesaPaymentId,
            'payment_notes'      => $paymentNotes,
        ]);
    }

    /**
     * Link a Safaricom callback mpesa_payments row to this transaction.
     * Called by the Mpesa webhook controller after a callback arrives.
     */
    public function linkMpesaPayment(int $transactionId, int $mpesaPaymentId): void
    {
        $this->db->executeStatement(
            'UPDATE pos_transactions SET mpesa_payment_id = :mpesa_id, updated_at = NOW() WHERE id = :id',
            ['mpesa_id' => $mpesaPaymentId, 'id' => $transactionId],
        );
    }

    // =========================================================================
    // READ
    // =========================================================================

    public function getById(int $transactionId, int $companyId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT pt.*,
                    pm.name         AS payment_method_name,
                    pm.method_key,
                    a.name          AS area_name
               FROM pos_transactions pt
               JOIN payment_methods  pm ON pm.id = pt.payment_method_id
          LEFT JOIN areas             a  ON a.id  = pt.area_id
              WHERE pt.id         = :id
                AND pt.company_id = :company_id
              LIMIT 1',
            ['id' => $transactionId, 'company_id' => $companyId],
        );

        return $row ?: null;
    }

    /**
     * Fetch recent transactions for a terminal.
     */
    public function getRecentForTerminal(
        string $terminalIdentifier,
        int    $companyId,
        int    $limit = 50,
    ): array {
        return $this->db->fetchAllAssociative(
            "SELECT pt.*,
                    pm.name       AS payment_method_name,
                    pm.method_key,
                    a.name        AS area_name
               FROM pos_transactions pt
               JOIN payment_methods  pm ON pm.id = pt.payment_method_id
          LEFT JOIN areas             a  ON a.id  = pt.area_id
              WHERE pt.terminal_identifier = :terminal
                AND pt.company_id          = :company_id
                AND pt.status IN ('complete', 'processing')
              ORDER BY pt.created_at DESC
              LIMIT {$limit}",
            ['terminal' => $terminalIdentifier, 'company_id' => $companyId],
        );
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

    private function fetchTransaction(int $id): ?array
    {
        return $this->db->fetchAssociative(
            'SELECT * FROM pos_transactions WHERE id = :id LIMIT 1',
            ['id' => $id],
        ) ?: null;
    }
}
