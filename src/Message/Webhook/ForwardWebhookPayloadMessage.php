<?php

declare(strict_types=1);

namespace App\Message\Webhook;

/**
 * Queued fan-out to a single third-party forward URL.
 *
 * One message is dispatched per URL so each destination gets independent
 * retry semantics — a slow or failing target doesn't block others, and
 * Messenger's backoff only applies to the URL that actually failed.
 */
final class ForwardWebhookPayloadMessage
{
    public function __construct(
        public readonly string $rawPayload,
        public readonly string $url,
        public readonly string $source = 'webhook',
    ) {
    }
}
