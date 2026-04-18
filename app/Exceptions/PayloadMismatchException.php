<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Raised when an event envelope or payload does not conform to the V1 contract
 * defined in docs/EVENT_CONTRACT.md.
 *
 * Thrown by {@see \App\Domain\Events\EventContract::assertEnvelopeValid()}
 * and {@see \App\Domain\Events\EventContract::assertPayloadValid()}.
 *
 * Consumers: the {@see \App\Jobs\DispatchDomainEventsJob} catches this to mark
 * the offending {@see \App\Models\DomainEvent} row as failed with last_error.
 */
class PayloadMismatchException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly array $errors = [],
        public readonly ?string $eventType = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function context(): array
    {
        return [
            'event_type' => $this->eventType,
            'errors' => $this->errors,
        ];
    }
}
