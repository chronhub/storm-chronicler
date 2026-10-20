<?php

declare(strict_types=1);

namespace Storm\Chronicler\Exception;

use RuntimeException;
use Storm\Chronicler\Record\EventRecord;
use Storm\Contracts\Chronicler\ChroniclerExceptionContract;
use Storm\Contracts\Message\DomainEvent;

/**
 * An `EventRecord` was read whose envelope does not wrap a `DomainEvent`.
 *
 * The event store holds domain events only, so a record always carries one. This signals a corrupt read
 * or a misuse, such as an envelope built by hand around a non-event message, and fails fast instead of
 * handing back something that is not a domain event.
 */
final class NotADomainEvent extends RuntimeException implements ChroniclerExceptionContract
{
    public static function got(object $message): self
    {
        return new self(sprintf(
            'An EventRecord must wrap a %s, got %s. The event store only stores domain events.',
            DomainEvent::class,
            $message::class,
        ));
    }
}
