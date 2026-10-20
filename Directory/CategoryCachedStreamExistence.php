<?php

declare(strict_types=1);

namespace Storm\Chronicler\Directory;

use Storm\Chronicler\Erasure\CacheEvictingStreamEraser;
use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\Clock;
use Storm\Stream\StreamCategory;
use Storm\Stream\StreamName;

/**
 * Caches the category-level answers of `StreamExistence`, per process.
 *
 * Only `hasCategory()` answers are remembered:
 *
 * - The categories confirmed alive, stable knowledge since categories are code-level concepts born at
 *   a deployment
 *
 * - Each category confirmed absent, until a short TTL expires
 *
 * The rule is never a durable false negative: an unknown category is always confirmed live before
 * answering, and a remembered negative expires on its own, so a category born after the negative is
 * seen at most one TTL later.
 *
 * `hasStream()` never uses the cache, not even the negative category gate: a negative remembered before
 * a category's first stream would serve a stale `false` for the exact question until the TTL, breaking
 * read-your-writes right after an append. The exact probe is one primary-key read, so the gate would
 * save almost nothing and cost the port's own rule. A confirmed stream feeds its category back into
 * the positive set for free.
 *
 * Erasure may hollow a category: the eraser decorator evicts the category's positive so the next
 * `hasCategory()` re-confirms. The eviction is per process and does not cross workers, accepted since
 * erasure is a dev and test path by contract. A drop, restore or partition detach performed outside
 * Storm leaves a stale positive with no bound: the cache trusts Storm-driven erasure alone, and a
 * worker recycle resets it. The stale answer costs a consumer one empty read, never a wrong durable
 * decision.
 *
 * In worker mode the state lives with the process, booted once; in a one-shot CLI process it dies with
 * the process, which costs nothing.
 *
 * @see StreamExistence the contract, and where the two questions are told apart
 * @see CacheEvictingStreamEraser the erasure-side eviction
 */
final class CategoryCachedStreamExistence implements StreamExistence
{
    /**
     * Hard cap on remembered negatives.
     *
     * A flood of distinct garbage categories, hostile 404 probing, must not grow the map without bound
     * in a worker that lives for days. The positive set needs no cap: only a real append can put a
     * category there, and categories are code-level concepts.
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
        // always the live probe, the port's rule kept to the letter: a remembered-absent category must
        // never answer for the exact stream, since a first append may have created the category right
        // after the negative was cached, and this call is how a caller would find out
        $exists = $this->inner->hasStream($streamName);

        if ($exists) {
            $this->remember($streamName->category->value); // an existing stream proves its category alive
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
        // equivalent: a set, read by `isset()` alone, so the value is arbitrary
        // @infection-ignore-all
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
