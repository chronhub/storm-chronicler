<?php

declare(strict_types=1);

namespace Storm\Chronicler\Exception;

use LogicException;

/**
 * Two classes claim the same stored alias, canonical or through `replaces`, or one class declares two sorts.
 *
 * An alias resolves back to exactly one class, whatever sort declared it, and a class is an event or
 * a command, never both.
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

    public static function twoSortsOnOneClass(string $class, string $aliasA, string $aliasB): self
    {
        return new self(sprintf(
            '%s declares two aliases, "%s" and "%s": a class is an event or a command, never both.',
            $class,
            $aliasA,
            $aliasB,
        ));
    }
}
