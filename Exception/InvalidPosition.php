<?php

declare(strict_types=1);

namespace Storm\Chronicler\Exception;

use InvalidArgumentException;
use Storm\Contracts\Chronicler\InvalidPosition as InvalidPositionContract;
use Storm\Contracts\Chronicler\Position;

/**
 * The store's refusal of a malformed or foreign position.
 *
 * A malformed position is not the base-10 form of a positive integer; a foreign one is a different
 * `Position` implementation in a comparison. Both are caller errors, refused at the boundary and never
 * carried on as a value object that looks valid until a later read fails far from the bug.
 *
 * The contracted `InvalidPositionContract` sits on an SPL `InvalidArgumentException`, so ports never
 * surface a concrete type.
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
