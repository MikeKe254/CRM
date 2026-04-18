<?php

declare(strict_types=1);

namespace App\Async\Inbox;

use App\Message\Payment\ProcessMpesaC2bConfirmationMessage;
use App\Message\Payment\ProcessMpesaStkCallbackMessage;

final class ExternalEventMessageMapper
{
    /**
     * Map an inbox row to a typed Messenger message.
     *
     * @throws UnknownEventTypeException when the event_type has no registered handler.
     *         The relay command catches this and marks the row dead immediately —
     *         retrying an unknown type will never succeed.
     */
    public function map(array $row): object
    {
        $eventType = (string) ($row['event_type'] ?? '');
        $id = (int) ($row['id'] ?? 0);

        return match ($eventType) {
            'mpesa.stk_callback'     => new ProcessMpesaStkCallbackMessage($id),
            'mpesa.c2b_confirmation' => new ProcessMpesaC2bConfirmationMessage($id),
            default => throw new UnknownEventTypeException(
                sprintf('No handler registered for external event type "%s" (inbox id %d)', $eventType, $id),
            ),
        };
    }
}
