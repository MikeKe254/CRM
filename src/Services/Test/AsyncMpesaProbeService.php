<?php

declare(strict_types=1);

namespace App\Services\Test;

use Doctrine\DBAL\Connection;

final class AsyncMpesaProbeService
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    public function ensureProbeTable(): void
    {
        $this->db->executeStatement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS async_mpesa_probe_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                mpesa_payment_id INT NOT NULL,
                company_id INT NULL,
                branch_id INT NULL,
                transaction_id VARCHAR(255) NULL,
                amount DECIMAL(12,2) NULL,
                status_description VARCHAR(255) NULL,
                processed_by VARCHAR(64) NOT NULL,
                notes VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_async_mpesa_probe_payment (mpesa_payment_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL
        );
    }

    public function processPayment(int $mpesaPaymentId, string $processedBy = 'async-worker'): void
    {
        $this->ensureProbeTable();

        $payment = $this->db->fetchAssociative(
            'SELECT id, company_id, branch_id, transaction_id, amount, status_description
               FROM mpesa_payments
              WHERE id = :id
              LIMIT 1',
            ['id' => $mpesaPaymentId],
        );

        if ($payment === false) {
            $this->db->executeStatement(
                <<<'SQL'
                INSERT INTO async_mpesa_probe_logs
                    (mpesa_payment_id, company_id, branch_id, transaction_id, amount, status_description, processed_by, notes)
                VALUES
                    (:mpesa_payment_id, :company_id, :branch_id, :transaction_id, :amount, :status_description, :processed_by, :notes)
                ON DUPLICATE KEY UPDATE
                    company_id = VALUES(company_id),
                    branch_id = VALUES(branch_id),
                    transaction_id = VALUES(transaction_id),
                    amount = VALUES(amount),
                    status_description = VALUES(status_description),
                    processed_by = VALUES(processed_by),
                    notes = VALUES(notes),
                    created_at = CURRENT_TIMESTAMP
                SQL,
                [
                    'mpesa_payment_id' => $mpesaPaymentId,
                    'company_id' => null,
                    'branch_id' => null,
                    'transaction_id' => null,
                    'amount' => null,
                    'status_description' => null,
                    'processed_by' => $processedBy,
                    'notes' => 'mpesa_payment_not_found',
                ],
            );

            return;
        }

        $this->db->executeStatement(
            <<<'SQL'
            INSERT INTO async_mpesa_probe_logs
                (mpesa_payment_id, company_id, branch_id, transaction_id, amount, status_description, processed_by, notes)
            VALUES
                (:mpesa_payment_id, :company_id, :branch_id, :transaction_id, :amount, :status_description, :processed_by, :notes)
            ON DUPLICATE KEY UPDATE
                company_id = VALUES(company_id),
                branch_id = VALUES(branch_id),
                transaction_id = VALUES(transaction_id),
                amount = VALUES(amount),
                status_description = VALUES(status_description),
                processed_by = VALUES(processed_by),
                notes = VALUES(notes),
                created_at = CURRENT_TIMESTAMP
            SQL,
            [
                'mpesa_payment_id' => (int) $payment['id'],
                'company_id' => $payment['company_id'] !== null ? (int) $payment['company_id'] : null,
                'branch_id' => $payment['branch_id'] !== null ? (int) $payment['branch_id'] : null,
                'transaction_id' => $payment['transaction_id'],
                'amount' => $payment['amount'],
                'status_description' => $payment['status_description'],
                'processed_by' => $processedBy,
                'notes' => 'processed_from_async_queue',
            ],
        );
    }
}
