<?php

declare(strict_types=1);

namespace Storm\Chronicler\Exception;

use InvalidArgumentException;
use Storm\Contracts\Chronicler\InvalidPosition as InvalidPositionContract;
use Storm\Contracts\Chronicler\Position;

/**
 * A value the store cannot accept as a position: not the base-10 form of a positive integer, or a
 * comparison across two different Position implementations.
 *
 * Implements the contracted InvalidPositionContract on an SPL `InvalidArgumentException`, so ports
 * never surface a concrete. Both forms are caller errors refused at the boundary, never carried on
 * as a value object that looks valid until some later read explodes far from the bug.
 */
final class InvalidPosition extends InvalidArgumentException implements InvalidPositionContract
{
    public static function notAPositiveInteger(string $value): self
    {
        return new self(sprintf('A position must be a positive integer string, got "%s".', $value));
    }

    public static function typeMismatch(Position $other): self
    {
        return new self(sprintf(
            'Cannot compare a SequencePosition with %s; a store uses a single position type.',
            $other::class,
        ));
    }
}
