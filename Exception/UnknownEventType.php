<?php

declare(strict_types=1);

namespace Storm\Chronicler\Exception;

use RuntimeException;
use Storm\Contracts\Chronicler\UndecodableRow;
use Storm\Contracts\Chronicler\UnknownEventType as UnknownEventTypeContract;

/**
 * A stored type that resolves to no domain event class.
 *
 * The two causes take opposite dispositions, so each factory sets its own:
 *
 * - `cannotResolve()`: the type is neither a registered alias nor a loadable FQCN, usually because the
 *   `#[EventType]` scan did not run or the binary is older than the row. A corrected runtime clears it.
 *
 * - `notAnEvent()`: the type loads a class that is not a domain event, which never decodes, whatever
 *   ships next.
 */
final class UnknownEventType extends RuntimeException implements UndecodableRow, UnknownEventTypeContract
{
    private function __construct(
        string $message,
        private readonly bool $resolvable,
    ) {
        parent::__construct($message);
    }

    /**
     * {@inheritDoc}
     *
     * The one implementation that answers per instance: true for an alias this runtime does not
     * declare, false for a class it loads and refuses.
     */
    public function resolvableByAnotherRuntime(): bool
    {
        return $this->resolvable;
    }

    public static function cannotResolve(string $type): self
    {
        return new self(sprintf(
            'Cannot resolve stored event type "%s" to a class: not a registered alias, not a loadable FQCN. '
            .'Is the #[EventType] scan wired (the StormBundle)?',
            $type,
        ), resolvable: true);
    }

    /**
     * The stored type resolves to a loadable class that is not a domain event.
     *
     * The store holds domain events only, so this application did not write the row: it is a restore
     * or import artifact, and hydrating it would instantiate an arbitrary payload class.
     */
    public static function notAnEvent(string $type, string $class): self
    {
        return new self(sprintf(
            'Stored event type "%s" resolves to "%s", which is not a DomainEvent; the event store holds domain events exclusively.',
            $type,
            $class,
        ), resolvable: false);
    }
}
