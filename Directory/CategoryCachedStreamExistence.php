<?php

declare(strict_types=1);

namespace Storm\Chronicler\Directory;

use Storm\Chronicler\Erasure\CacheEvictingStreamEraser;
use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\Clock;
use Storm\Stream\StreamCategory;
use Storm\Stream\StreamName;

/**
 * Category-level cache over StreamExistence, where the caching boundary IS the method boundary: only
 * `hasCategory` answers are remembered, the exact `hasStream` probe stays live on every call, its
 * key space unbounded and erasure revoking per-stream positives.
 *
 * What is remembered, per process: the set of categories confirmed alive, stable knowledge since
 * categories are code-level concepts born at a deployment, plus each confirmed-absent category,
 * bounded by a short TTL. The golden rule is never a durable false negative: an unknown category is
 * always confirmed live before answering, and a remembered negative expires on its own, so a
 * category born after the negative is seen at most one TTL later.
 *
 * `hasStream` never rides the cache, not even the negative category gate: a negative remembered
 * before a category's FIRST stream would otherwise serve a stale `false` for the exact question
 * until the TTL, breaking read-your-writes right after an append. The exact probe is one PK read;
 * the gate would save almost nothing and cost the port's own rule. A confirmed stream feeds the
 * category back into the positive set for free. Erasure may hollow a category: the eraser decorator
 * evicts its CATEGORY positive so the next `hasCategory` re-confirms; the eviction is per-process
 * and does not cross workers, assumed since erasure is a dev/test path by contract. A drop, restore
 * or partition detach performed OUTSIDE Storm leaves a stale positive with no bound: the cache
 * trusts Storm-driven erasure alone, and a worker recycle is the reset; the stale answer costs a
 * consumer one empty read, never a wrong durable decision.
 *
 * In worker mode the state lives with the process, boot-once; in a one-shot CLI process it dies
 * with it, a no-op, not a defect.
 *
 * @see StreamExistence the contract, and where the two questions are told apart
 * @see CacheEvictingStreamEraser the erasure-side eviction
 */
final class CategoryCachedStreamExistence implements StreamExistence
{
    /**
     * Hard cap on remembered negatives: a flood of DISTINCT garbage categories, hostile 404 probing,
     * must not grow the map unbounded in a worker that lives for days. The positive set needs no cap;
     * only a real append can put a category there, and categories are code-level concepts.
     */
    private const int MAX_REMEMBERED_NEGATIVES = 1000;

    /** @var array<string, true> categories confirmed alive; no expiry, eviction is erasure-driven */
    private array $alive = [];

    /** @var array<string, PointInTime> categories confirmed absent, each until its expiry */
    private array $absentUntil = [];

    /**
     * @param  Clock<PointInTime>  $clock
     * @param  positive-int  $negativeTtlSeconds
     */
    public function __construct(
        private readonly StreamExistence $inner,
        private readonly Clock $clock,
        private readonly int $negativeTtlSeconds = 30,
    ) {}

    public function hasStream(StreamName $streamName): bool
    {
        // ALWAYS the live probe, the port's rule kept to the letter. A remembered-absent category
        // must never answer for the exact stream: the category may have been born by a first append
        // right after the negative was cached, and this call is exactly how a caller would find out.
        $exists = $this->inner->hasStream($streamName);

        if ($exists) {
            $this->remember($streamName->category); // an existing stream proves its category alive
        }

        return $exists;
    }

    public function hasCategory(StreamCategory $category): bool
    {
        $category = $category->value;

        if (isset($this->alive[$category])) {
            return true;
        }

        $until = $this->absentUntil[$category] ?? null;

        if ($until !== null && $this->clock->now()->isBefore($until)) {
            return false; // remembered negative, still within its TTL
        }

        $lives = $this->inner->hasCategory(new StreamCategory($category));

        if ($lives) {
            $this->remember($category);
        } else {
            if (count($this->absentUntil) >= self::MAX_REMEMBERED_NEGATIVES) {
                $this->sweepNegatives();
            }

            $this->absentUntil[$category] = $this->clock->now()->addSeconds($this->negativeTtlSeconds);
        }

        return $lives;
    }

    /**
     * Evict a category from both sides of the cache; the next probe re-confirms against the store.
     */
    public function forgetCategory(StreamCategory $category): void
    {
        unset($this->alive[$category->value], $this->absentUntil[$category->value]);
    }

    private function remember(string $category): void
    {
        // @infection-ignore-all; equivalent: a set, read by `isset()` alone, so the value is arbitrary
        $this->alive[$category] = true;

        unset($this->absentUntil[$category]);
    }

    /**
     * Bound the negative map: drop the expired entries first; when every survivor is still live,
     * reset the map entirely, since a lost negative only costs the next probe, never a wrong answer.
     */
    private function sweepNegatives(): void
    {
        $now = $this->clock->now();

        $this->absentUntil = array_filter(
            $this->absentUntil,
            $now->isBefore(...),
        );

        if (count($this->absentUntil) >= self::MAX_REMEMBERED_NEGATIVES) {
            $this->absentUntil = [];
        }
    }
}
