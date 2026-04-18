<?php

declare(strict_types=1);

namespace App\Message\Payment;

final class ProcessMpesaStkCallbackMessage
{
    public function __construct(
        public readonly int $externalEventInboxId,
    ) {
    }
}
