<?php

declare(strict_types=1);

namespace Storm\Chronicler\Exception;

use Storm\Contracts\Chronicler\RetryableConcurrencyConflict;

/**
 * The optimistic pre-check, a stream-head CAS, saw the stream past the loaded version.
 *
 * A competing writer won between the load and the append, so a retry is safe: the contracted
 * `RetryableConcurrencyConflict` marker says so portably, and the caller reloads the aggregate and
 * reapplies.
 */
final class StaleVersion extends ConcurrencyException implements RetryableConcurrencyConflict
{
    public static function forStream(string $stream, int $expected, int $actual): self
    {
        return new self(sprintf(
            'Concurrency conflict on stream "%s": expected version %d, but it is at %d.',
            $stream,
            $expected,
            $actual,
        ));
    }
}
