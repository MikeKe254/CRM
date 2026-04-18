<?php

declare(strict_types=1);

namespace App\Async\Inbox;

/**
 * Thrown by ExternalEventMessageMapper when an inbox row carries an event_type
 * that has no registered handler in Patronr yet.
 *
 * The relay command catches this specifically to mark the row dead immediately
 * (no point retrying — the code won't change at runtime).
 */
final class UnknownEventTypeException extends \RuntimeException
{
}
