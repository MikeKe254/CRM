<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Async\Inbox\ExternalEventInboxRepository;
use App\Services\Loyalty\MpesaLoyaltyAutoAwardService;
use App\Services\Patronr\TransactionRecordService;
use App\Util\PhoneNormalizer;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class MpesaExternalEventProcessor
{
    public function __construct(
        private readonly Connection $db,
        private readonly ExternalEventInboxRepository $inbox,
        private readonly TransactionRecordService $transactions,
        private readonly MpesaLoyaltyAutoAwardService $loyaltyAutoAward,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
        private readonly string $unhashUrl,
        private readonly string $unhashApiKey,
    ) {
    }

    public function processStkCallbackEvent(int $externalEventInboxId): void
    {
        $event = $this->requireEvent($externalEventInboxId, 'mpesa.stk_callback');
        $rawPayload = (string) ($event['payload_json'] ?? '');
        $this->appendLog('stk_callbacks.log', $rawPayload);

        $payload = $this->decodeJson($rawPayload);
        $callback = is_array($payload) ? ($payload['Body']['stkCallback'] ?? null) : null;
        if (!is_array($callback)) {
            return;
        }

        $meta = $this->decodeJson((string) ($event['meta_json'] ?? ''));
        $checkoutRequestId = (string) ($callback['CheckoutRequestID'] ?? ($meta['checkout_request_id'] ?? ''));
        if ($checkoutRequestId === '') {
            return;
        }

        $companyId = isset($event['company_id']) && $event['company_id'] !== null ? (int) $event['company_id'] : null;
        if ($companyId === null) {
            $subdomain = is_array($meta) ? (string) ($meta['subdomain'] ?? '') : '';
            if ($subdomain !== '') {
                $row = $this->db->fetchAssociative(
                    'SELECT id FROM companies WHERE subdomain = :subdomain AND deleted_at IS NULL LIMIT 1',
                    ['subdomain' => $subdomain],
                );
                $companyId = $row !== false ? (int) $row['id'] : null;
            }
        }

        $resultCode = (int) ($callback['ResultCode'] ?? -1);
        $resultDescription = (string) ($callback['ResultDesc'] ?? '');
        $status = $resultCode === 0 ? 'SUCCESS' : 'FAILED';
        $posStatus = $resultCode === 0 ? 'complete' : 'failed';

        $mpesaReceipt = null;
        $transactionDate = null;
        $phoneNumber = null;
        $amount = null;

        if ($resultCode === 0) {
            foreach ((array) ($callback['CallbackMetadata']['Item'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $name = $item['Name'] ?? null;
                $value = $item['Value'] ?? null;

                if ($name === 'MpesaReceiptNumber') {
                    $mpesaReceipt = $value !== null ? (string) $value : null;
                }
                if ($name === 'TransactionDate' && $value !== null) {
                    $transactionDate = $this->parseSafaricomDate((string) $value);
                }
                if ($name === 'PhoneNumber' && $value !== null) {
                    $phoneNumber = PhoneNormalizer::normalize((string) $value);
                }
                if ($name === 'Amount' && $value !== null) {
                    $amount = (float) $value;
                }
            }
        }

        $stkLog = $this->db->fetchAssociative(
            'SELECT * FROM stk_push_logs WHERE checkout_request_id = :checkout_request_id LIMIT 1',
            ['checkout_request_id' => $checkoutRequestId],
        ) ?: null;

        $posTransactionId = $this->db->fetchOne(
            'SELECT id FROM pos_transactions WHERE api_checkout_request_id = :checkout_request_id ORDER BY id DESC LIMIT 1',
            ['checkout_request_id' => $checkoutRequestId],
        );
        $posTransactionId = $posTransactionId !== false && $posTransactionId !== null ? (int) $posTransactionId : null;

        try {
            $this->db->beginTransaction();

            $this->db->executeStatement(
                'UPDATE stk_push_logs
                    SET status = :status,
                        result_code = :result_code,
                        result_description = :result_description,
                        mpesa_receipt = :mpesa_receipt,
                        transaction_date = :transaction_date
                  WHERE checkout_request_id = :checkout_request_id',
                [
                    'status' => $status,
                    'result_code' => $resultCode,
                    'result_description' => $resultDescription,
                    'mpesa_receipt' => $mpesaReceipt,
                    'transaction_date' => $transactionDate,
                    'checkout_request_id' => $checkoutRequestId,
                ],
            );

            $this->db->executeStatement(
                'UPDATE pos_transactions
                    SET status = :status,
                        api_receipt = :api_receipt,
                        api_raw_response = :api_raw_response,
                        completed_at = NOW()
                  WHERE api_checkout_request_id = :checkout_request_id',
                [
                    'status' => $posStatus,
                    'api_receipt' => $mpesaReceipt,
                    'api_raw_response' => json_encode(['ResultCode' => $resultCode, 'ResultDesc' => $resultDescription]),
                    'checkout_request_id' => $checkoutRequestId,
                ],
            );

            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->rollbackQuietly();
            $this->appendLog('stk_callbacks.log', json_encode([
                'error' => $exception->getMessage(),
                'checkout_request_id' => $checkoutRequestId,
            ], JSON_UNESCAPED_SLASHES));

            throw $exception;
        }

        if ($resultCode !== 0 || $mpesaReceipt === null || $stkLog === null) {
            return;
        }

        $mpesaPaymentId = $this->ensureStkPayment(
            companyId: $companyId ?? (int) ($stkLog['company_id'] ?? 0),
            branchId: isset($stkLog['branch_id']) ? (int) $stkLog['branch_id'] : null,
            shortcode: (string) ($stkLog['shortcode'] ?? ''),
            msisdn: $phoneNumber ?? PhoneNormalizer::normalize((string) ($stkLog['phone'] ?? '')),
            amount: $amount ?? (float) ($stkLog['amount'] ?? 0),
            receipt: $mpesaReceipt,
            accountReference: (string) ($stkLog['account_reference'] ?? ''),
            transactionDate: $transactionDate,
            channel: (string) ($stkLog['channel'] ?? 'BILL'),
        );

        if ($mpesaPaymentId !== null) {
            $this->inbox->markRelatedEntity($externalEventInboxId, 'mpesa_payment', $mpesaPaymentId);

            if ($posTransactionId !== null) {
                $this->transactions->linkMpesaPayment($posTransactionId, $mpesaPaymentId);
            }

            $this->loyaltyAutoAward->tryAwardFromMpesaPayment(
                $mpesaPaymentId,
                $posTransactionId,
                (float) ($stkLog['amount'] ?? 0),
            );
        }
    }

    public function processC2bConfirmationEvent(int $externalEventInboxId): void
    {
        $event = $this->requireEvent($externalEventInboxId, 'mpesa.c2b_confirmation');
        $rawPayload = (string) ($event['payload_json'] ?? '');
        $this->appendLog('c2b_confirmations.log', $rawPayload);

        $payload = $this->decodeJson($rawPayload);
        if (!is_array($payload)) {
            return;
        }

        $meta = $this->decodeJson((string) ($event['meta_json'] ?? ''));
        $shortcode = is_array($meta) ? (string) ($meta['shortcode'] ?? '') : '';
        if ($shortcode === '') {
            throw new \RuntimeException('Missing shortcode in external event meta.');
        }

        $transactionTime = $this->parseSafaricomDate((string) ($payload['TransTime'] ?? ''));

        $company = $this->db->fetchAssociative(
            'SELECT company_id, branch_id, forward_urls
               FROM mpesa_configs
              WHERE shortcode = :shortcode
                AND is_active = 1
                AND deleted_at IS NULL
              LIMIT 1',
            ['shortcode' => $shortcode],
        );

        if ($company === false) {
            $this->logger->warning('C2B: no mpesa_configs row for shortcode', [
                'shortcode'        => $shortcode,
                'transaction_time' => $transactionTime,
                'inbox_id'         => $externalEventInboxId,
            ]);
            $this->appendLog('c2b_confirmations.log', json_encode([
                'error'            => 'No mpesa_configs row found for shortcode.',
                'shortcode'        => $shortcode,
                'transaction_time' => $transactionTime,
            ], JSON_UNESCAPED_SLASHES));
            return;
        }

        $msisdn = (string) ($payload['MSISDN'] ?? '');
        if ($msisdn !== '') {
            $msisdn = $this->unhashMsisdn($msisdn) ?? $msisdn;
        }
        $msisdn = PhoneNormalizer::normalize($msisdn);

        $receipt    = (string) ($payload['TransID'] ?? '');
        $amount     = (float) ($payload['TransAmount'] ?? 0);
        $companyId  = (int) ($company['company_id'] ?? 0);
        $branchId   = (int) ($company['branch_id'] ?? 0);
        $billRef    = (string) ($payload['BillRefNumber'] ?? '');

        $mpesaPaymentId = 0;

        try {
            $this->db->beginTransaction();

            $existingId = $receipt !== ''
                ? $this->db->fetchOne(
                    'SELECT id FROM mpesa_payments WHERE company_id = :company_id AND transaction_id = :transaction_id LIMIT 1',
                    ['company_id' => $companyId, 'transaction_id' => $receipt],
                )
                : false;

            if ($existingId !== false && $existingId !== null) {
                $this->db->executeStatement(
                    'UPDATE mpesa_payments
                        SET msisdn              = COALESCE(:msisdn, msisdn),
                            amount              = :amount,
                            reference           = :reference,
                            first_name          = :first_name,
                            middle_name         = :middle_name,
                            last_name           = :last_name,
                            account             = :account,
                            branch_id           = :branch_id,
                            status_code         = 0,
                            status_description  = :status_description,
                            deleted_at          = NULL
                      WHERE id = :id',
                    [
                        'msisdn'             => $msisdn,
                        'amount'             => $amount,
                        'reference'          => $billRef,
                        'first_name'         => (string) ($payload['FirstName']  ?? ''),
                        'middle_name'        => (string) ($payload['MiddleName'] ?? ''),
                        'last_name'          => (string) ($payload['LastName']   ?? ''),
                        'account'            => $billRef,
                        'branch_id'          => $branchId,
                        'status_description' => 'Confirmed',
                        'id'                 => (int) $existingId,
                    ],
                );
                $mpesaPaymentId = (int) $existingId;
            } else {
                $this->db->executeStatement(
                    "INSERT INTO mpesa_payments
                     (bestguess, short_code, client_id, msisdn, amount, reference,
                      transaction_id, first_name, middle_name, last_name, account,
                      method, reference_id, payment_id, status_code, status_description,
                      company_id, branch_id, created_at)
                     VALUES
                     ('BILL', :shortcode, 0, :msisdn, :amount, :reference, :transaction_id,
                      :first_name, :middle_name, :last_name, :account, 'mpesa', 0, 0, 0, 'Confirmed',
                      :company_id, :branch_id, NOW())",
                    [
                        'shortcode'   => $shortcode,
                        'msisdn'      => $msisdn,
                        'amount'      => $amount,
                        'reference'   => $billRef,
                        'transaction_id' => $receipt,
                        'first_name'  => (string) ($payload['FirstName']  ?? ''),
                        'middle_name' => (string) ($payload['MiddleName'] ?? ''),
                        'last_name'   => (string) ($payload['LastName']   ?? ''),
                        'account'     => $billRef,
                        'company_id'  => $companyId,
                        'branch_id'   => $branchId,
                    ],
                );
                $mpesaPaymentId = (int) $this->db->lastInsertId();
            }

            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->rollbackQuietly();
            $this->logger->error('C2B: DB write failed', [
                'shortcode'        => $shortcode,
                'inbox_id'         => $externalEventInboxId,
                'error'            => $exception->getMessage(),
                'transaction_time' => $transactionTime,
            ]);
            $this->appendLog('c2b_confirmations.log', json_encode([
                'error'            => $exception->getMessage(),
                'shortcode'        => $shortcode,
                'transaction_time' => $transactionTime,
            ], JSON_UNESCAPED_SLASHES));
            throw $exception;
        }

        if ($mpesaPaymentId > 0) {
            $this->inbox->markRelatedEntity($externalEventInboxId, 'mpesa_payment', $mpesaPaymentId);
            $this->loyaltyAutoAward->tryAwardFromMpesaPayment(
                $mpesaPaymentId,
                null,
                $amount,
            );
        }
    }

    private function requireEvent(int $id, string $expectedType): array
    {
        $row = $this->inbox->find($id);
        if ($row === null) {
            throw new \RuntimeException(sprintf('external_event_inbox row %d not found.', $id));
        }

        if ((string) ($row['event_type'] ?? '') !== $expectedType) {
            throw new \RuntimeException(sprintf('Expected event type "%s" but found "%s".', $expectedType, (string) ($row['event_type'] ?? '')));
        }

        return $row;
    }

    private function ensureStkPayment(
        int $companyId,
        ?int $branchId,
        string $shortcode,
        ?string $msisdn,
        float $amount,
        string $receipt,
        string $accountReference,
        ?string $transactionDate,
        string $channel,
    ): ?int {
        if ($companyId <= 0 || $branchId === null || $shortcode === '' || $receipt === '' || $amount <= 0) {
            return null;
        }

        $existingId = $this->db->fetchOne(
            'SELECT id FROM mpesa_payments WHERE company_id = :company_id AND transaction_id = :transaction_id LIMIT 1',
            ['company_id' => $companyId, 'transaction_id' => $receipt],
        );

        if ($existingId !== false && $existingId !== null) {
            $this->db->executeStatement(
                'UPDATE mpesa_payments
                    SET msisdn = COALESCE(:msisdn, msisdn),
                        amount = :amount,
                        reference = :reference,
                        account = :account,
                        branch_id = :branch_id,
                        status_code = 0,
                        status_description = :status_description,
                        deleted_at = NULL
                  WHERE id = :id',
                [
                    'msisdn' => $msisdn,
                    'amount' => $amount,
                    'reference' => $accountReference,
                    'account' => $accountReference,
                    'branch_id' => $branchId,
                    'status_description' => 'STK callback success',
                    'id' => (int) $existingId,
                ],
            );

            return (int) $existingId;
        }

        $this->db->executeStatement(
            "INSERT INTO mpesa_payments
            (bestguess, short_code, client_id, msisdn, amount, reference,
             transaction_id, first_name, middle_name, last_name, account,
             method, reference_id, payment_id, status_code, status_description,
             company_id, branch_id, created_at)
            VALUES
            (:bestguess, :shortcode, 0, :msisdn, :amount, :reference,
             :transaction_id, NULL, NULL, NULL, :account,
             'mpesa', 0, 0, 0, :status_description, :company_id, :branch_id, :created_at)",
            [
                'bestguess' => $channel !== '' ? $channel : 'BILL',
                'shortcode' => $shortcode,
                'msisdn' => $msisdn,
                'amount' => $amount,
                'reference' => $accountReference,
                'transaction_id' => $receipt,
                'account' => $accountReference,
                'status_description' => 'STK callback success',
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'created_at' => $transactionDate ?: (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        );

        return (int) $this->db->lastInsertId();
    }

    private function unhashMsisdn(string $value): ?string
    {
        if (ctype_digit($value) && strlen($value) <= 15) {
            return $value;
        }

        try {
            $response = $this->httpClient->request('POST', $this->unhashUrl, [
                'json' => [
                    'api_key' => $this->unhashApiKey,
                    'hashed_phone' => $value,
                ],
                'timeout' => 5,
                'max_duration' => 5,
            ]);

            $data = $response->toArray(false);
        } catch (\Throwable) {
            return null;
        }

        return (is_array($data) && ($data['success'] ?? false) === true)
            ? (string) $data['phone_number']
            : null;
    }

    private function appendLog(string $fileName, string $rawPayload): void
    {
        $logDirectory = $this->projectDir . '/var/log';
        if (!is_dir($logDirectory)) {
            mkdir($logDirectory, 0777, true);
        }

        $entry = sprintf(
            "[%s] %s%s",
            (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            trim($rawPayload),
            PHP_EOL,
        );

        file_put_contents($logDirectory . '/' . $fileName, $entry, FILE_APPEND | LOCK_EX);
    }

    private function parseSafaricomDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('YmdHis', $value);

        return $date instanceof DateTimeImmutable ? $date->format('Y-m-d H:i:s') : null;
    }

    private function decodeJson(string $value): ?array
    {
        if ($value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function rollbackQuietly(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
    }
}
