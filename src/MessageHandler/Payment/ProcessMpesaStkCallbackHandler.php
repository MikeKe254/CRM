<?php

declare(strict_types=1);

namespace App\MessageHandler\Payment;

use App\Message\Payment\ProcessMpesaStkCallbackMessage;
use App\Services\Payment\MpesaExternalEventProcessor;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ProcessMpesaStkCallbackHandler
{
    public function __construct(
        private readonly MpesaExternalEventProcessor $processor,
    ) {
    }

    public function __invoke(ProcessMpesaStkCallbackMessage $message): void
    {
        $this->processor->processStkCallbackEvent($message->externalEventInboxId);
    }
}
