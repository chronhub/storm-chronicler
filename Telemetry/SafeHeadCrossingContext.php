<?php

declare(strict_types=1);

namespace Storm\Chronicler\Telemetry;

/**
 * A bounded summary of absent positions presumed aborted by a committed ratchet.
 *
 * The summary describes one committed ratchet. `floorBefore` and `floorAfter` delimit the interval
 * that ratchet crossed; every range is clipped to `]floorBefore, floorAfter]` and `missingPositions`
 * counts the absent positions of that interval alone, so it never exceeds the position delta. A hole
 * spanning multiple ratchets contributes disjoint portions to their summaries; each summary may
 * also include other holes crossed by its ratchet. `gapCount` counts the crossed portions per
 * ratchet, so its sum across ratchets is not a count of distinct holes.
 *
 * `ranges` samples the five lowest crossed ranges ordered by their first position; `truncated` says
 * `gapCount` exceeds that sample.
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
