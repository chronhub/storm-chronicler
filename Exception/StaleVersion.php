<?php

declare(strict_types=1);

namespace Storm\Chronicler\Exception;

use Storm\Contracts\Chronicler\RetryableConcurrencyConflict;

/**
 * The optimistic pre-check, a stream-head CAS, saw the stream past the loaded version: a
 * competing writer won between a load and append. Safe to retry; the contracted
 * RetryableConcurrencyConflict marker says so portably: reload the aggregate and reapply.
 * See ConcurrencyException for the handling rationale.
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
