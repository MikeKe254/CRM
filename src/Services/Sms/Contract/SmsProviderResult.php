<?php

declare(strict_types=1);

namespace App\Services\Sms\Contract;

/**
 * Normalised result returned by every provider adapter.
 *
 * Adapters never throw on provider-level failures (bad sender, low credits,
 * invalid number) — they return a failed result with an error message.
 * They DO throw (or let exceptions propagate) for network errors so that
 * Messenger can retry the message with its backoff strategy.
 */
final class SmsProviderResult
{
    private function __construct(
        public readonly bool    $success,
        public readonly ?string $providerMessageId,
        public readonly ?string $errorMessage,
        public readonly array   $rawResponse,
    ) {
    }

    public static function success(string $providerMessageId, array $rawResponse = []): self
    {
        return new self(
            success: true,
            providerMessageId: $providerMessageId !== '' ? $providerMessageId : null,
            errorMessage: null,
            rawResponse: $rawResponse,
        );
    }

    public static function failure(string $errorMessage, array $rawResponse = []): self
    {
        return new self(
            success: false,
            providerMessageId: null,
            errorMessage: $errorMessage,
            rawResponse: $rawResponse,
        );
    }
}
