<?php

declare(strict_types=1);

namespace App\Message\Test;

final class ProcessMpesaPaymentProbeMessage
{
    public function __construct(
        public readonly int $mpesaPaymentId,
    ) {
    }
}
