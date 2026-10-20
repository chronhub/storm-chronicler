<?php

declare(strict_types=1);

namespace Storm\Chronicler\Telemetry;

/**
 * A bounded summary of the absent positions one committed ratchet presumed aborted.
 *
 * - `floorBefore` and `floorAfter` delimit the interval the ratchet crossed, and every range is clipped
 *   to `]floorBefore, floorAfter]`.
 *
 * - `missingPositions` counts the absent positions of that interval alone, so it never exceeds the
 *   position delta.
 *
 * - `gapCount` counts the crossed portions of this ratchet. A hole spanning several ratchets contributes
 *   disjoint portions to their summaries, and a summary may include other holes its ratchet crossed, so
 *   the sum across ratchets is not a count of distinct holes.
 *
 * - `ranges` samples the five lowest crossed ranges, ordered by their first position; `truncated` says
 *   `gapCount` exceeds that sample.
 */
final readonly class SafeHeadCrossingContext
{
    public function __construct(
        public int $floorBefore,
        public int $floorAfter,
        public float $graceSeconds,
        public int $gapCount,
        public int $missingPositions,
        /** @var list<array{from: int, to: int}> */
        public array $ranges,
        public bool $truncated,
    ) {}
}
