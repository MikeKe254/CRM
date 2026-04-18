<?php

declare(strict_types=1);

namespace App\Message\Sms;

/**
 * Dispatched to the notifications transport after sms_outbox row is written.
 * The handler loads the outbox row, decrypts credentials, and calls the adapter.
 */
final class SendSmsMessage
{
    public function __construct(
        public readonly int $smsOutboxId,
    ) {
    }
}
