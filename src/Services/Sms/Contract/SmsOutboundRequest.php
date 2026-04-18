<?php

declare(strict_types=1);

namespace App\Services\Sms\Contract;

/**
 * Everything a provider adapter needs to send a single SMS.
 *
 * Built by SmsService and passed to SmsProviderInterface::send().
 * The adapter must never resolve sender IDs or message types on its own —
 * SmsService handles that before constructing this object.
 */
final class SmsOutboundRequest
{
    public const TYPE_TRANSACTIONAL = 'transactional';
    public const TYPE_PROMOTIONAL   = 'promotional';

    public function __construct(
        /** Normalized MSISDN (e.g. 254712345678). */
        public readonly string  $recipient,
        public readonly string  $message,
        /** Resolved sender ID — already validated against policy before this is built. */
        public readonly string  $senderId,
        /** 'transactional' | 'promotional' */
        public readonly string  $messageType,
        /** sms_outbox.id — for logging / linking back to the audit trail. */
        public readonly int     $smsOutboxId,
    ) {
    }
}
