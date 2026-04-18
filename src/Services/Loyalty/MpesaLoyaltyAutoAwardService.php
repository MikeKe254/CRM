<?php

declare(strict_types=1);

namespace App\Services\Loyalty;

use App\Util\PhoneNormalizer;
use Doctrine\DBAL\Connection;

final class MpesaLoyaltyAutoAwardService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoyaltyService $loyaltyService,
    ) {
    }

    public function tryAwardFromMpesaPayment(int $mpesaPaymentId, ?int $posTransactionId = null, ?float $awardedAmount = null): void
    {
        $payment = $this->connection->fetchAssociative(
            'SELECT * FROM mpesa_payments WHERE id = ? LIMIT 1',
            [$mpesaPaymentId]
        );

        if ($payment === false) {
            return;
        }

        if ((int) ($payment['loyalty_auto_awarded'] ?? 0) === 1) {
            $this->writeAuditLog(
                (int) ($payment['company_id'] ?? 0),
                isset($payment['branch_id']) ? (int) $payment['branch_id'] : null,
                'loyalty.auto_award.duplicate_skipped',
                [
                    'mpesa_payment_id' => $mpesaPaymentId,
                    'pos_transaction_id' => $posTransactionId,
                ],
            );
            return;
        }

        $companyId = (int) ($payment['company_id'] ?? 0);
        $branchId = isset($payment['branch_id']) && $payment['branch_id'] !== null ? (int) $payment['branch_id'] : null;
        $amount = $awardedAmount ?? (float) ($payment['amount'] ?? 0);
        $msisdn = PhoneNormalizer::normalize((string) ($payment['msisdn'] ?? ''));

        if ($companyId <= 0 || $branchId === null || $amount <= 0 || $msisdn === null) {
            return;
        }

        $company = $this->connection->fetchAssociative(
            'SELECT loyalty_module_enabled FROM companies WHERE id = ? LIMIT 1',
            [$companyId]
        );
        if ($company === false || (int) ($company['loyalty_module_enabled'] ?? 0) !== 1) {
            return;
        }

        $program = $this->connection->fetchAssociative(
            'SELECT *
               FROM loyalty_programs
              WHERE company_id = ?
                AND branch_id = ?
                AND is_active = 1
                AND auto_award_enabled = 1
              ORDER BY id ASC
              LIMIT 1',
            [$companyId, $branchId]
        );
        if ($program === false) {
            return;
        }

        $mpesaConfig = $this->connection->fetchAssociative(
            'SELECT id
               FROM mpesa_configs
              WHERE company_id = ?
                AND branch_id = ?
                AND shortcode = ?
                AND is_active = 1
                AND deleted_at IS NULL
                AND auto_award_loyalty = 1
              ORDER BY id ASC
              LIMIT 1',
            [$companyId, $branchId, (string) ($payment['short_code'] ?? '')]
        );
        if ($mpesaConfig === false) {
            return;
        }

        $customer = $this->findCustomerByMsisdn($companyId, $msisdn);
        $account = $this->findAccountByMsisdn($companyId, (int) $program['id'], $msisdn);
        $autoEnroll = (int) ($program['auto_enroll_on_payment'] ?? 0) === 1;

        if (($customer === null || $account === null) && !$autoEnroll) {
            $this->writeAuditLog($companyId, $branchId, 'loyalty.auto_award.skipped', [
                'reason' => 'customer_not_enrolled_and_auto_enroll_off',
                'mpesa_payment_id' => $mpesaPaymentId,
                'msisdn' => $msisdn,
            ]);
            return;
        }

        try {
            $this->connection->beginTransaction();

            if ($customer === null) {
                $customer = $this->createCustomer(
                    companyId: $companyId,
                    msisdn: $msisdn,
                    firstName: trim((string) ($payment['first_name'] ?? '')) ?: null,
                );
            } else {
                $this->connection->executeStatement(
                    'UPDATE customers SET last_seen_at = NOW(), updated_at = NOW() WHERE id = ?',
                    [(int) $customer['id']]
                );
            }

            if ($account === null) {
                $account = $this->createLoyaltyAccount($companyId, (int) $program['id'], (int) $customer['id'], $msisdn);

                $this->writeAuditLog($companyId, $branchId, 'loyalty.auto_enroll.created', [
                    'mpesa_payment_id' => $mpesaPaymentId,
                    'customer_id' => (int) $customer['id'],
                    'msisdn' => $msisdn,
                    'program_id' => (int) $program['id'],
                ]);

                $enrollBonus = max(0, (int) ($program['enroll_bonus_points'] ?? 0));
                if ($enrollBonus > 0) {
                    $balanceAfterBonus = $this->applyPointsDelta((int) $account['id'], $companyId, $enrollBonus, true);
                    $this->writeLedger(
                        loyaltyAccountId: (int) $account['id'],
                        companyId: $companyId,
                        type: 'enroll_bonus',
                        points: $enrollBonus,
                        balanceAfter: $balanceAfterBonus,
                        note: 'Enrollment bonus',
                        posTransactionId: $posTransactionId,
                        mpesaPaymentId: $mpesaPaymentId,
                        createdByUserId: null,
                    );
                }
            }

            $points = $this->calculatePoints((float) ($program['unit_amount'] ?? 0), (int) ($program['points_per_unit'] ?? 0), $amount);
            if ($points <= 0.0) {
                $this->connection->commit();
                return;
            }

            $balanceAfter = $this->applyPointsDelta((int) $account['id'], $companyId, $points, true);
            $this->writeLedger(
                loyaltyAccountId: (int) $account['id'],
                companyId: $companyId,
                type: 'earn',
                points: $points,
                balanceAfter: $balanceAfter,
                note: 'Auto-awarded from M-Pesa callback',
                posTransactionId: $posTransactionId,
                mpesaPaymentId: $mpesaPaymentId,
                createdByUserId: null,
            );

            $this->writeAuditLog($companyId, $branchId, 'loyalty.auto_award.awarded', [
                'mpesa_payment_id' => $mpesaPaymentId,
                'pos_transaction_id' => $posTransactionId,
                'customer_id' => (int) $customer['id'],
                'loyalty_account_id' => (int) $account['id'],
                'msisdn' => $msisdn,
                'points' => $points,
                'amount' => $amount,
                'program_id' => (int) $program['id'],
            ]);

            $this->connection->executeStatement(
                'UPDATE mpesa_payments
                    SET loyalty_auto_awarded = 1,
                        loyalty_awarded_at = NOW(),
                        loyalty_points_awarded = ?,
                        loyalty_account_id = ?,
                        customer_id = ?
                  WHERE id = ?',
                [$points, (int) $account['id'], (int) $customer['id'], $mpesaPaymentId]
            );

            if ($posTransactionId !== null) {
                $this->connection->executeStatement(
                    'UPDATE pos_transactions
                        SET customer_id = COALESCE(customer_id, ?),
                            loyalty_account_id = COALESCE(loyalty_account_id, ?),
                            msisdn = COALESCE(msisdn, ?),
                            loyalty_points_awarded = loyalty_points_awarded + ?,
                            loyalty_auto_awarded = 1,
                            loyalty_auto_awarded_amount = loyalty_auto_awarded_amount + ?,
                            loyalty_awarded_at = NOW(),
                            loyalty_award_source = ?,
                            mpesa_payment_id = COALESCE(mpesa_payment_id, ?),
                            updated_at = NOW()
                      WHERE id = ?',
                    [
                        (int) $customer['id'],
                        (int) $account['id'],
                        $msisdn,
                        $points,
                        $amount,
                        'mpesa_callback',
                        $mpesaPaymentId,
                        $posTransactionId,
                    ]
                );
            }

            $this->connection->commit();

            // Post-commit: tier resolution, lifecycle stage, notification closed-loop,
            // and birthday bonus — all delegated to LoyaltyService so logic stays in
            // one place and the intelligence layer stays accurate for M-Pesa members.
            $awardedAccountId = (int) $account['id'];
            try {
                $this->loyaltyService->resolveAndUpdateTier($awardedAccountId, $companyId);
                $this->loyaltyService->updateLifecycleStage($awardedAccountId, $companyId);
                $this->loyaltyService->markNotificationReturned($awardedAccountId, $companyId);
                $this->loyaltyService->checkAndAwardBirthdayBonus($awardedAccountId, $companyId);
            } catch (\Throwable) {
                // Non-fatal — the payment and points are committed; side effects
                // can be repaired by the backfill command if needed.
            }
        } catch (\Throwable) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
        }
    }

    private function calculatePoints(float $unitAmount, int $pointsPerUnit, float $amount): float
    {
        if ($unitAmount <= 0 || $pointsPerUnit <= 0 || $amount <= 0) {
            return 0.0;
        }

        return round(($amount / $unitAmount) * $pointsPerUnit, 2);
    }

    private function findCustomerByMsisdn(int $companyId, string $msisdn): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM customers WHERE company_id = ? AND msisdn = ? LIMIT 1',
            [$companyId, $msisdn]
        );

        return $row === false ? null : $row;
    }

    private function createCustomer(int $companyId, string $msisdn, ?string $firstName = null): array
    {
        $this->connection->insert('customers', [
            'company_id' => $companyId,
            'msisdn' => $msisdn,
            'first_name' => $firstName,
            'gender' => 'unknown',
            'enrolled_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return $this->findCustomerByMsisdn($companyId, $msisdn) ?? [];
    }

    private function findAccountByMsisdn(int $companyId, int $programId, string $msisdn): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT la.*
               FROM loyalty_accounts la
              WHERE la.company_id = ?
                AND la.loyalty_program_id = ?
                AND la.msisdn = ?
              LIMIT 1',
            [$companyId, $programId, $msisdn]
        );

        return $row === false ? null : $row;
    }

    private function createLoyaltyAccount(int $companyId, int $programId, int $customerId, string $msisdn): array
    {
        $this->connection->insert('loyalty_accounts', [
            'company_id' => $companyId,
            'loyalty_program_id' => $programId,
            'customer_id' => $customerId,
            'msisdn' => $msisdn,
            'points_balance' => 0,
            'total_points_earned' => 0,
            'total_points_redeemed' => 0,
            'enrolled_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return $this->findAccountByMsisdn($companyId, $programId, $msisdn) ?? [];
    }

    private function applyPointsDelta(int $accountId, int $companyId, float $points, bool $earn): float
    {
        if ($earn) {
            // Maintain intelligence columns alongside balance — keeps lifecycle
            // and segment data accurate for the M-Pesa path.
            $this->connection->executeStatement(
                'UPDATE loyalty_accounts
                    SET points_balance      = points_balance + ?,
                        total_points_earned = total_points_earned + ?,
                        last_transaction_at = NOW(),
                        visit_count         = visit_count + 1,
                        updated_at          = NOW()
                  WHERE id = ? AND company_id = ?',
                [$points, $points, $accountId, $companyId]
            );
        }

        return (float) ($this->connection->fetchOne(
            'SELECT points_balance FROM loyalty_accounts WHERE id = ? LIMIT 1',
            [$accountId]
        ) ?? 0);
    }

    private function writeLedger(
        int $loyaltyAccountId,
        int $companyId,
        string $type,
        float $points,
        float $balanceAfter,
        ?string $note = null,
        ?int $posTransactionId = null,
        ?int $mpesaPaymentId = null,
        ?int $createdByUserId = null,
    ): void {
        $this->connection->insert('loyalty_ledger', [
            'company_id' => $companyId,
            'loyalty_account_id' => $loyaltyAccountId,
            'pos_transaction_id' => $posTransactionId,
            'mpesa_payment_id' => $mpesaPaymentId,
            'type' => $type,
            'points' => $points,
            'balance_after' => $balanceAfter,
            'note' => $note,
            'created_by_user_id' => $createdByUserId,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    private function writeAuditLog(int $companyId, ?int $branchId, string $actionKey, array $context = []): void
    {
        try {
            $this->connection->insert('user_activity_logs', [
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'user_id' => null,
                'actor_type' => 'system',
                'action' => 'SYSTEM',
                'description' => $this->buildAuditDescription($actionKey, $context),
                'subject_type' => 'mpesa_payment',
                'subject_id' => $context['mpesa_payment_id'] ?? null,
                'metadata' => json_encode(array_merge(['action_key' => $actionKey], $context), JSON_UNESCAPED_UNICODE),
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
        }
    }

    private function buildAuditDescription(string $actionKey, array $context): string
    {
        return match ($actionKey) {
            'loyalty.auto_award.awarded' => sprintf(
                'Auto-awarded %d pts to loyalty account #%s (customer #%s) from M-Pesa payment #%s · KES %s',
                $context['points'] ?? 0,
                $context['loyalty_account_id'] ?? '?',
                $context['customer_id'] ?? '?',
                $context['mpesa_payment_id'] ?? '?',
                $context['amount'] ?? '?',
            ),
            'loyalty.auto_enroll.created' => sprintf(
                'Auto-enrolled customer #%s into loyalty program #%s from M-Pesa payment #%s',
                $context['customer_id'] ?? '?',
                $context['program_id'] ?? '?',
                $context['mpesa_payment_id'] ?? '?',
            ),
            'loyalty.auto_award.duplicate_skipped' => sprintf(
                'Skipped duplicate auto-award for M-Pesa payment #%s — already awarded',
                $context['mpesa_payment_id'] ?? '?',
            ),
            'loyalty.auto_award.skipped' => sprintf(
                'Skipped auto-award for M-Pesa payment #%s — %s',
                $context['mpesa_payment_id'] ?? '?',
                str_replace('_', ' ', $context['reason'] ?? 'unknown'),
            ),
            default => ucfirst(str_replace(['.', '_'], ' ', $actionKey)),
        };
    }

}
