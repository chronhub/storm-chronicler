<?php

declare(strict_types=1);

namespace Storm\Chronicler\Exception;

use LogicException;
use Storm\Contracts\Chronicler\ChroniclerExceptionContract;

/**
 * A decision batch claims versions other than exactly `expectedVersion + 1 .. expectedVersion + N`.
 *
 * The decision path persists the versions the producer stamps, while the head moves by arithmetic: the
 * caller of `DecisionAppend` owns its decision's positions, where the store allocates a fact's. A
 * skewed, gapped or disordered batch would leave `stream_heads` disagreeing with the durable rows and
 * corrupt the next OCC base.
 *
 * It is a caller bug to fix rather than a runtime condition to handle, so a `LogicException` is thrown
 * before the head moves. It mirrors on the write side the repository's read-side continuity guard.
 */
final class IncoherentDecisionVersions extends LogicException implements ChroniclerExceptionContract
{
    public static function forStream(string $stream, int $expected, int|string $claimed, int $position): self
    {
        return new self(sprintf(
            'The decision batch for stream "%s" claims version %s at offset %d where exactly %d was required (expectedVersion + offset + 1) — a skewed batch would divorce the stream head from the durable rows; nothing was written.',
            $stream,
            (string) $claimed,
            $position,
            $expected,
        ));
    }
}
