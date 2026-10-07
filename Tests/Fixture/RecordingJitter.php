<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests\Fixture;

use Storm\Contracts\Random\Jitter;

/**
 * A jitter that keeps every range it is asked for and answers one fixed end of it, so a backoff's
 * bounds and its use of the draw are asserted exactly instead of sampled.
 */
final class RecordingJitter implements Jitter
{
    /** @var list<array{int, int}> */
    public array $asked = [];

    private function __construct(private readonly bool $highest) {}

    public static function lowest(): self
    {
        return new self(false);
    }

    public static function highest(): self
    {
        return new self(true);
    }

    public function between(int $min, int $max): int
    {
        $this->asked[] = [$min, $max];

        return $this->highest ? $max : $min;
    }
}
