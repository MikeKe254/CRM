<?php

declare(strict_types=1);

namespace App\MessageHandler\Sms;

use App\Message\Sms\SendSmsMessage;
use App\Services\Sms\SmsService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SendSmsHandler
{
    public function __construct(
        private readonly SmsService $smsService,
    ) {
    }

    public function __invoke(SendSmsMessage $message): void
    {
        $this->smsService->sendNow($message->smsOutboxId);
    }
}
