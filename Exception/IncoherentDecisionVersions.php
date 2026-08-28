<?php

declare(strict_types=1);

namespace Storm\Chronicler\Exception;

use LogicException;
use Storm\Contracts\Chronicler\ChroniclerExceptionContract;

/**
 * A decision batch claims versions that are not exactly `expectedVersion + 1 .. expectedVersion + N`.
 *
 * The decision path persists the versions the PRODUCER stamps, while the head moves by arithmetic;
 * the caller of `DecisionAppend` owns its decision's positions, unlike a fact, whose position the
 * store allocates. A skewed, gapped or disordered batch would divorce `stream_heads` from the durable
 * rows and corrupt the next OCC base. A caller bug to fix, not a runtime condition to handle; hence a
 * `LogicException` thrown BEFORE the head moves, the write-side mirror of the repository's read-side
 * continuity guard.
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
