<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Message\Sms\SendSmsMessage;
use App\Services\Encryption\CredentialEncryptionService;
use App\Services\Sms\Contract\SmsCredentials;
use App\Services\Sms\Contract\SmsOutboundRequest;
use App\Util\PhoneNormalizer;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Orchestration layer for outbound SMS.
 *
 * Responsibilities:
 *  - Resolve the active sms_config for a company (optionally a specific config).
 *  - Resolve the correct sender ID from sms_sender_ids (or adapter default).
 *  - Enforce Patronr sender ID policy (system-owned — adapter handles its own).
 *  - Write the sms_outbox row (status=pending) before dispatching.
 *  - Dispatch SendSmsMessage to the notifications transport.
 *
 * sendNow() is called by the Messenger handler after the message is consumed.
 */
final class SmsService
{
    public function __construct(
        private readonly Connection $db,
        private readonly SmsProviderRegistry $registry,
        private readonly CredentialEncryptionService $encryption,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    // -------------------------------------------------------------------------
    // Public queueing API
    // -------------------------------------------------------------------------

    /**
     * Queue a transactional SMS (OTP, receipt, loyalty notification, etc.).
     *
     * Returns the sms_outbox id.
     */
    public function queueTransactional(
        int $companyId,
        string $recipient,
        string $message,
        ?int $configId = null,
        array $context = [],
    ): int {
        return $this->queue(
            companyId: $companyId,
            recipient: $recipient,
            message: $message,
            messageType: SmsOutboundRequest::TYPE_TRANSACTIONAL,
            configId: $configId,
            context: $context,
        );
    }

    /**
     * Queue a promotional SMS (campaigns, bulk loyalty messages, etc.).
     *
     * Returns the sms_outbox id.
     */
    public function queuePromotional(
        int $companyId,
        string $recipient,
        string $message,
        ?int $configId = null,
        array $context = [],
    ): int {
        return $this->queue(
            companyId: $companyId,
            recipient: $recipient,
            message: $message,
            messageType: SmsOutboundRequest::TYPE_PROMOTIONAL,
            configId: $configId,
            context: $context,
        );
    }

    // -------------------------------------------------------------------------
    // Handler entry point (called by SendSmsHandler)
    // -------------------------------------------------------------------------

    /**
     * Load the outbox row, decrypt credentials, send via the adapter, update status.
     *
     * Network exceptions are re-thrown so Messenger retries with backoff.
     * Provider-level rejections (bad sender, insufficient credits, invalid number)
     * are logged and written as failed — no retry (they are non-transient).
     */
    public function sendNow(int $smsOutboxId): void
    {
        $outbox = $this->db->fetchAssociative(
            'SELECT * FROM sms_outbox WHERE id = :id LIMIT 1',
            ['id' => $smsOutboxId],
        );

        if ($outbox === false) {
            $this->logger->error('sms_outbox row not found', ['sms_outbox_id' => $smsOutboxId]);

            return;
        }

        if ((string) ($outbox['status'] ?? '') === 'sent') {
            // Already sent (duplicate delivery from Messenger at-least-once guarantee).
            return;
        }

        $configId = (int) ($outbox['sms_config_id'] ?? 0);
        $config   = $this->loadConfig($configId);

        if ($config === null) {
            $this->markFailed($smsOutboxId, 'sms_config not found or inactive.');

            return;
        }

        $providerKey = (string) ($config['provider_key'] ?? '');

        if (!$this->registry->has($providerKey)) {
            $this->markFailed($smsOutboxId, sprintf('No adapter registered for provider "%s".', $providerKey));

            return;
        }

        $adapter = $this->registry->get($providerKey);

        // Decrypt credentials
        try {
            $rawJson     = (bool) ($config['credentials_encrypted'] ?? false)
                ? $this->encryption->decrypt((string) ($config['credentials_json'] ?? ''))
                : (string) ($config['credentials_json'] ?? '');
            $credentials = new SmsCredentials(json_decode($rawJson, true) ?? []);
        } catch (\Throwable $e) {
            $this->markFailed($smsOutboxId, 'Credential decryption failed: ' . $e->getMessage());

            return;
        }

        $request = new SmsOutboundRequest(
            recipient:   (string) ($outbox['recipient_msisdn'] ?? ''),
            message:     (string) ($outbox['message_body'] ?? ''),
            senderId:    (string) ($outbox['sender_id'] ?? ''),
            messageType: (string) ($outbox['message_type'] ?? SmsOutboundRequest::TYPE_TRANSACTIONAL),
            smsOutboxId: $smsOutboxId,
        );

        // Mark as queued (worker has picked it up)
        $this->db->executeStatement(
            'UPDATE sms_outbox SET status = :status, updated_at = NOW() WHERE id = :id',
            ['status' => 'queued', 'id' => $smsOutboxId],
        );

        try {
            $result = $adapter->send($request, $credentials);
        } catch (\Throwable $e) {
            // Network error — re-throw so Messenger retries.
            $this->db->executeStatement(
                'UPDATE sms_outbox SET status = :status, failure_reason = :reason, updated_at = NOW() WHERE id = :id',
                ['status' => 'pending', 'reason' => $e->getMessage(), 'id' => $smsOutboxId],
            );
            throw $e;
        }

        if ($result->success) {
            $this->db->executeStatement(
                'UPDATE sms_outbox
                    SET status              = :status,
                        provider_message_id = :msg_id,
                        provider_response   = :response,
                        sent_at             = NOW(),
                        updated_at          = NOW()
                  WHERE id = :id',
                [
                    'status'   => 'sent',
                    'msg_id'   => $result->providerMessageId,
                    'response' => json_encode($result->rawResponse, JSON_UNESCAPED_UNICODE),
                    'id'       => $smsOutboxId,
                ],
            );

            $this->logger->info('SMS sent', [
                'sms_outbox_id'       => $smsOutboxId,
                'provider'            => $providerKey,
                'provider_message_id' => $result->providerMessageId,
                'recipient'           => $request->recipient,
            ]);
        } else {
            $this->markFailed($smsOutboxId, $result->errorMessage ?? 'Provider returned failure.', $result->rawResponse);

            $this->logger->warning('SMS provider rejected message', [
                'sms_outbox_id' => $smsOutboxId,
                'provider'      => $providerKey,
                'error'         => $result->errorMessage,
                'recipient'     => $request->recipient,
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function queue(
        int $companyId,
        string $recipient,
        string $message,
        string $messageType,
        ?int $configId,
        array $context,
    ): int {
        $normalised = PhoneNormalizer::normalize($recipient);
        if ($normalised === null) {
            throw new \InvalidArgumentException(
                sprintf('Invalid recipient MSISDN "%s" — cannot queue SMS.', $recipient),
            );
        }

        $config = $configId !== null
            ? $this->loadConfig($configId)
            : $this->loadDefaultConfig($companyId);

        if ($config === null) {
            throw new \RuntimeException(
                sprintf('No active SMS config found for company %d.', $companyId),
            );
        }

        $senderId = $this->resolveSenderId($config, $messageType);

        $this->db->insert('sms_outbox', [
            'company_id'       => $companyId,
            'branch_id'        => $context['branch_id'] ?? null,
            'sms_config_id'    => (int) $config['id'],
            'provider_key'     => (string) $config['provider_key'],
            'sender_id'        => $senderId,
            'message_type'     => $messageType,
            'recipient_msisdn' => $normalised,
            'message_body'     => $message,
            'customer_id'                => $context['customer_id'] ?? null,
            'loyalty_account_id'         => $context['loyalty_account_id'] ?? null,
            'loyalty_notification_id'    => $context['loyalty_notification_id'] ?? null,
            'status'           => 'pending',
            'created_at'       => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'updated_at'       => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $outboxId = (int) $this->db->lastInsertId();

        $this->bus->dispatch(new SendSmsMessage($outboxId));

        return $outboxId;
    }

    private function loadConfig(int $configId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM sms_configs WHERE id = :id AND is_active = 1 AND deleted_at IS NULL LIMIT 1',
            ['id' => $configId],
        );

        return $row === false ? null : $row;
    }

    private function loadDefaultConfig(int $companyId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM sms_configs
              WHERE company_id = :company_id
                AND is_active  = 1
                AND is_default = 1
                AND deleted_at IS NULL
              LIMIT 1',
            ['company_id' => $companyId],
        );

        return $row === false ? null : $row;
    }

    private function resolveSenderId(array $config, string $messageType): string
    {
        $providerKey = (string) ($config['provider_key'] ?? '');

        // Patronr sender IDs are system-owned — adapter enforces them, nothing to resolve here.
        // We store a placeholder so the outbox row is not blank.
        if ($providerKey === 'patronr') {
            return $messageType === SmsOutboundRequest::TYPE_PROMOTIONAL ? 'PatronrInfo' : 'PATRONR';
        }

        // Try dedicated sms_sender_ids row first (type match or 'both').
        $row = $this->db->fetchAssociative(
            "SELECT sender_id FROM sms_sender_ids
              WHERE sms_config_id = :config_id
                AND type IN (:type, 'both')
                AND is_default = 1
                AND is_active  = 1
              ORDER BY FIELD(type, :type, 'both') ASC
              LIMIT 1",
            ['config_id' => (int) $config['id'], 'type' => $messageType],
        );

        if ($row !== false) {
            return (string) $row['sender_id'];
        }

        // Fall back to the inline defaults on the config row.
        $fallback = $messageType === SmsOutboundRequest::TYPE_PROMOTIONAL
            ? (string) ($config['default_sender_id_promotional'] ?? '')
            : (string) ($config['default_sender_id_transactional'] ?? '');

        if ($fallback !== '') {
            return $fallback;
        }

        throw new \RuntimeException(
            sprintf(
                'No sender ID configured for message type "%s" on sms_config #%d.',
                $messageType,
                (int) $config['id'],
            ),
        );
    }

    private function markFailed(int $outboxId, string $reason, array $rawResponse = []): void
    {
        $this->db->executeStatement(
            'UPDATE sms_outbox
                SET status          = :status,
                    failure_reason  = :reason,
                    provider_response = :response,
                    failed_at       = NOW(),
                    updated_at      = NOW()
              WHERE id = :id',
            [
                'status'   => 'failed',
                'reason'   => mb_substr($reason, 0, 65535),
                'response' => $rawResponse !== [] ? json_encode($rawResponse, JSON_UNESCAPED_UNICODE) : null,
                'id'       => $outboxId,
            ],
        );
    }
}
