<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Exception\InvalidPosition;
use Storm\Chronicler\Record\SequencePosition;
use Storm\Contracts\Chronicler\Position;

final class SequencePositionTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Construction
    // -------------------------------------------------------------------------

    #[Test]
    public function builds_from_int(): void
    {
        $position = SequencePosition::fromInt(42);

        $this->assertSame('42', $position->toString());
        $this->assertSame('42', (string) $position);
    }

    #[Test]
    public function builds_from_string(): void
    {
        $this->assertSame('42', SequencePosition::fromString('42')->toString());
    }

    #[Test]
    public function the_ordinal_and_the_string_render_the_same_value(): void
    {
        // the two contracted forms of one fact: `toOrdinal()` is what the checkpoint schema persists,
        // `toString()` its decimal rendering; diverging would let the freshness math and the outbox
        // payload disagree about the same position
        $position = SequencePosition::fromInt(42);

        $this->assertSame(42, $position->toOrdinal());
        $this->assertSame((string) $position->toOrdinal(), $position->toString());
    }

    #[Test]
    public function accepts_the_first_position_as_a_string(): void
    {
        // the floor itself, the position every stream opens on: the refusals below prove what is
        // out of range, and only this proves the range starts where the guard says it does
        $this->assertSame('1', SequencePosition::fromString('1')->toString());
    }

    #[Test]
    public function is_a_position(): void
    {
        $this->assertInstanceOf(Position::class, SequencePosition::fromInt(1));
    }

    #[Test]
    #[DataProvider('invalid_strings')]
    public function rejects_non_positive_integer_strings(string $value): void
    {
        $this->expectException(InvalidPosition::class);

        SequencePosition::fromString($value);
    }

    // -------------------------------------------------------------------------
    // Comparison
    // -------------------------------------------------------------------------

    #[Test]
    public function compares_ordering(): void
    {
        $a = SequencePosition::fromInt(5);
        $b = SequencePosition::fromInt(9);

        $this->assertTrue($b->isAfter($a));
        $this->assertFalse($a->isAfter($b));
        $this->assertFalse($a->isAfter($a));

        $this->assertTrue($a->isBefore($b));
        $this->assertFalse($b->isBefore($a));
        $this->assertFalse($a->isBefore($a));
    }

    #[Test]
    public function compares_equality(): void
    {
        $this->assertTrue(SequencePosition::fromInt(7)->equals(SequencePosition::fromInt(7)));
        $this->assertFalse(SequencePosition::fromInt(7)->equals(SequencePosition::fromInt(8)));
    }

    #[Test]
    public function throws_when_comparing_a_foreign_position_type(): void
    {
        $foreign = new class() implements Position
        {
            public function isBefore(Position $other): bool
            {
                return false;
            }

            public function isAfter(Position $other): bool
            {
                return false;
            }

            public function equals(Position $other): bool
            {
                return false;
            }

            public function toString(): string
            {
                return '1';
            }

            public function toOrdinal(): int
            {
                return 1;
            }

            public function __toString(): string
            {
                return $this->toString();
            }
        };

        $this->expectException(InvalidPosition::class);

        SequencePosition::fromInt(1)->isAfter($foreign);
    }

    // -------------------------------------------------------------------------
    // Data Providers
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function invalid_strings(): array
    {
        return [
            'zero' => ['0'],
            'negative' => ['-1'],
            'empty' => [''],
            'non numeric' => ['abc'],
            'leading zero' => ['007'],
            'decimal' => ['1.5'],
            'leading whitespace' => [' 1'],
        ];
    }

    #[Test]
    #[Group('adversarial')]
    public function from_int_rejects_zero(): void
    {
        // every factory holds the invariant, not only the string one; a zero position would pass as
        // a "valid" VO until some read fails far from the bug
        $this->expectException(InvalidPosition::class);

        SequencePosition::fromInt(0);
    }

    #[Test]
    #[Group('adversarial')]
    public function from_int_rejects_a_negative(): void
    {
        $this->expectException(InvalidPosition::class);

        SequencePosition::fromInt(-1);
    }
}
