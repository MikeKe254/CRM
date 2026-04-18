<?php

declare(strict_types=1);

namespace App\MessageHandler\Test;

use App\Message\Test\ProcessMpesaPaymentProbeMessage;
use App\Services\Test\AsyncMpesaProbeService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ProcessMpesaPaymentProbeHandler
{
    public function __construct(
        private readonly AsyncMpesaProbeService $probeService,
    ) {
    }

    public function __invoke(ProcessMpesaPaymentProbeMessage $message): void
    {
        $this->probeService->processPayment($message->mpesaPaymentId);
    }
}
