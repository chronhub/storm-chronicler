<?php

declare(strict_types=1);

namespace Storm\Chronicler\Exception;

use LogicException;

/**
 * Two event classes claim the same stored alias, whether canonical or via `replaces`; the alias
 * must be unique across the registered events, since it resolves back to exactly one class.
 */
final class DuplicateEventAlias extends LogicException
{
    public static function between(string $alias, string $classA, string $classB): self
    {
        return new self(sprintf(
            'Event alias "%s" is claimed by both %s and %s; aliases must be unique.',
            $alias,
            $classA,
            $classB,
        ));
    }
}
