<?php

declare(strict_types=1);

namespace App\MessageHandler\Webhook;

use App\Message\Webhook\ForwardWebhookPayloadMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsMessageHandler]
final class ForwardWebhookPayloadHandler
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ForwardWebhookPayloadMessage $message): void
    {
        try {
            $response = $this->httpClient->request('POST', $message->url, [
                'body'        => $message->rawPayload,
                'headers'     => ['Content-Type' => 'application/json'],
                'timeout'     => 8,
                'max_duration' => 8,
                'verify_peer' => false,
                'verify_host' => false,
            ]);

            $statusCode = $response->getStatusCode();
        } catch (\Throwable $e) {
            // Network error or timeout — let Messenger retry with backoff.
            $this->logger->warning('Webhook forward network error', [
                'url'    => $message->url,
                'source' => $message->source,
                'error'  => $e->getMessage(),
            ]);
            throw $e;
        }

        if ($statusCode >= 400) {
            // Non-2xx/3xx response — treat as failure so Messenger retries.
            $this->logger->warning('Webhook forward rejected by target', [
                'url'    => $message->url,
                'source' => $message->source,
                'status' => $statusCode,
            ]);
            throw new \RuntimeException(sprintf(
                'Webhook forward to %s returned HTTP %d [source=%s]',
                $message->url,
                $statusCode,
                $message->source,
            ));
        }

        $this->logger->info('Webhook forward delivered', [
            'url'    => $message->url,
            'source' => $message->source,
            'status' => $statusCode,
        ]);
    }
}
