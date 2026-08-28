<?php

declare(strict_types=1);

namespace Storm\Chronicler\Exception;

use RuntimeException;
use Storm\Contracts\Chronicler\UndecodableRow;
use Storm\Contracts\Chronicler\UnknownEventType as UnknownEventTypeContract;

/**
 * A stored type can't be resolved to a class: it is neither a registered alias nor a loadable FQCN,
 * or it is loadable and not a domain event.
 *
 * The two causes call for opposite dispositions, so each carries its own through
 * `resolvableByAnotherRuntime()`.
 * An unresolved alias is usually a wiring or DEPLOYMENT state, the `#[EventType]` scan not run or a
 * binary older than the row it is reading, and a corrected runtime clears it on its own; a loadable
 * class that is not a domain event will never decode, whatever ships next. A classifier that reads
 * the type alone routes both down one lane, and dead-lettering the first breaks its aggregate's
 * order over a condition that resolves itself.
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
     * The one implementation that answers per INSTANCE: true for an alias this runtime does not
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
     * The stored type resolves to a loadable class that is not a domain event: the store holds
     * domain events exclusively, so the row was not written by this application, a restore or
     * import artifact, and hydrating it would instantiate an arbitrary payload class.
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
