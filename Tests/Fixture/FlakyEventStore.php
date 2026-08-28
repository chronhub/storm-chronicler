<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests\Fixture;

use Doctrine\DBAL\Exception\RetryableException;
use RuntimeException;
use Storm\Chronicler\Store\DecisionAppend;
use Storm\Chronicler\Store\FactAppend;
use Storm\Chronicler\Store\RetryEventStore;
use Storm\Stream\Stream;

/**
 * Test double: a write chain whose append verbs throw a RetryableException the first
 * `$failures` times, then succeed, to exercise RetryEventStore.
 *
 * @see RetryEventStore
 */
final class FlakyEventStore implements DecisionAppend, FactAppend
{
    public int $attempts = 0;

    public function __construct(
        private readonly int $failures,
    ) {}

    public function appendTo(Stream $stream, int $expectedVersion): void
    {
        $this->attempts++;

        if ($this->attempts <= $this->failures) {
            throw new class('transient') extends RuntimeException implements RetryableException {};
        }
    }

    public function appendAny(Stream $stream): void
    {
        $this->attempts++;

        if ($this->attempts <= $this->failures) {
            throw new class('transient') extends RuntimeException implements RetryableException {};
        }
    }
}
