<?php

declare(strict_types=1);

namespace App\Async;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Thin async dispatch layer.
 *
 * Application services and handlers should dispatch through this class rather
 * than calling MessageBusInterface directly.  Routing stays in messenger.yaml —
 * this class never encodes lane knowledge beyond the convenience helpers.
 *
 * Usage:
 *   $this->queue->dispatch(new SomeMessage(...));
 *   $this->queue->dispatchPayment(new ProcessMpesaStkCallbackMessage(...));
 *   $this->queue->dispatchNotification(new SendSmsNotificationMessage(...));
 */
final class QueueDispatcher
{
    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * Dispatch a message using the routing defined in messenger.yaml.
     * This is the default path — routing is by message class.
     */
    public function dispatch(object $message): void
    {
        $this->bus->dispatch($message);
    }

    /**
     * Explicitly route a message to the payments transport.
     * Use when the message class is not yet registered in messenger.yaml routing.
     */
    public function dispatchPayment(object $message): void
    {
        $this->bus->dispatch(
            Envelope::wrap($message, [new TransportNamesStamp(['payments'])]),
        );
    }

    /**
     * Explicitly route a message to the notifications transport.
     * Use when the message class is not yet registered in messenger.yaml routing.
     */
    public function dispatchNotification(object $message): void
    {
        $this->bus->dispatch(
            Envelope::wrap($message, [new TransportNamesStamp(['notifications'])]),
        );
    }

    /**
     * Explicitly route a message to the integrations transport.
     * Use for third-party fan-out, webhook forwarding, and external API calls.
     */
    public function dispatchIntegration(object $message): void
    {
        $this->bus->dispatch(
            Envelope::wrap($message, [new TransportNamesStamp(['integrations'])]),
        );
    }

    /**
     * Explicitly route a message to the maintenance transport.
     */
    public function dispatchMaintenance(object $message): void
    {
        $this->bus->dispatch(
            Envelope::wrap($message, [new TransportNamesStamp(['maintenance'])]),
        );
    }
}
