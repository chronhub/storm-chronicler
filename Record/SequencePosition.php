<?php

declare(strict_types=1);

namespace Storm\Chronicler\Record;

use Override;
use Storm\Chronicler\Exception\InvalidPosition;
use Storm\Contracts\Chronicler\Position;

/**
 * The default Position: a monotonic int64, the `event_store.sequence_no` column, assigned at
 * append by a Postgres identity sequence. Not strictly dense: an aborted append burns its
 * reserved positions, and the safe-head classifies those gaps as permanent. Its string form is
 * the integer in base 10, stable and ordering-preserving, which is what travels in the outbox.
 *
 * Comparisons assume one position type per store; mixing implementations is a bug and raises
 * InvalidPosition.
 */
final readonly class SequencePosition implements Position
{
    /**
     * @param  positive-int  $value
     */
    private function __construct(
        private int $value,
    ) {}

    /**
     * @throws InvalidPosition when `$value` is not a positive integer: the invariant every factory
     *                         holds, not only the string one; a zero or negative position would ride
     *                         as a "valid" VO until some read explodes far from the bug
     */
    public static function fromInt(int $value): self
    {
        if ($value < 1) {
            throw InvalidPosition::notAPositiveInteger((string) $value);
        }

        return new self($value);
    }

    /**
     * @throws InvalidPosition when `$value` is not the base-10 form of a positive integer
     */
    public static function fromString(string $value): self
    {
        $int = (int) $value;

        if ($int < 1 || (string) $int !== $value) {
            throw InvalidPosition::notAPositiveInteger($value);
        }

        return new self($int);
    }

    public function isBefore(Position $other): bool
    {
        return $this->value < $this->sameType($other)->value;
    }

    public function isAfter(Position $other): bool
    {
        return $this->value > $this->sameType($other)->value;
    }

    public function equals(Position $other): bool
    {
        return $this->value === $this->sameType($other)->value;
    }

    public function toString(): string
    {
        return (string) $this->value;
    }

    public function toOrdinal(): int
    {
        return $this->value;
    }

    #[Override]
    public function __toString(): string
    {
        return $this->toString();
    }

    private function sameType(Position $other): self
    {
        if (! $other instanceof self) {
            throw InvalidPosition::typeMismatch($other);
        }

        return $other;
    }
}
