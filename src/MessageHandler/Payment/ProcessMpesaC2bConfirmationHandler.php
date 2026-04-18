<?php

declare(strict_types=1);

namespace App\MessageHandler\Payment;

use App\Message\Payment\ProcessMpesaC2bConfirmationMessage;
use App\Services\Payment\MpesaExternalEventProcessor;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ProcessMpesaC2bConfirmationHandler
{
    public function __construct(
        private readonly MpesaExternalEventProcessor $processor,
    ) {
    }

    public function __invoke(ProcessMpesaC2bConfirmationMessage $message): void
    {
        $this->processor->processC2bConfirmationEvent($message->externalEventInboxId);
    }
}
