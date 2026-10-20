<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests\Fixture;

use Storm\Contracts\Random\Jitter;

use function count;
use function min;

/**
 * A jitter that keeps every range it is asked for and answers a chosen end of it, so a backoff's
 * bounds and its use of the draw are asserted exactly instead of sampled.
 */
final class RecordingJitter implements Jitter
{
    /** @var list<array{int, int}> */
    public array $asked = [];

    /**
     * @param  non-empty-list<bool>  $highestEnds  the end each successive draw answers, the last one
     *                                             repeating
     */
    private function __construct(private readonly array $highestEnds) {}

    public static function lowest(): self
    {
        return new self([false]);
    }

    public static function highest(): self
    {
        return new self([true]);
    }

    public static function lowestThenHighest(): self
    {
        return new self([false, true]);
    }

    public function between(int $min, int $max): int
    {
        $highest = $this->highestEnds[min(count($this->asked), count($this->highestEnds) - 1)];
        $this->asked[] = [$min, $max];

        return $highest ? $max : $min;
    }
}
