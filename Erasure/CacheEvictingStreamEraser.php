<?php

declare(strict_types=1);

namespace Storm\Chronicler\Erasure;

use Storm\Chronicler\Directory\CategoryCachedStreamExistence;
use Storm\Stream\StreamCategory;
use Storm\Stream\StreamName;

/**
 * Erasure-side eviction for the category cache: an erased stream may have been its category's
 * last, so the liveness the cache remembers is evicted and the next probe re-confirms against the
 * store. Per-process by design: the eviction does not cross workers; erasure is a dev/test path
 * by the eraser's own contract, and the cache's negative TTL bounds any residue.
 */
final readonly class CacheEvictingStreamEraser implements StreamEraser
{
    public function __construct(
        private StreamEraser $inner,
        private CategoryCachedStreamExistence $cache,
    ) {}

    public function erase(StreamName $streamName): int
    {
        $removed = $this->inner->erase($streamName);

        if ($removed > 0) {
            $this->cache->forgetCategory(new StreamCategory($streamName->category));
        }

        return $removed;
    }
}
