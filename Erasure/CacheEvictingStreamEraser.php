<?php

declare(strict_types=1);

namespace Storm\Chronicler\Erasure;

use Storm\Chronicler\Directory\CategoryCachedStreamExistence;
use Storm\Stream\StreamName;

/**
 * Erasure-side eviction for the category cache.
 *
 * An erased stream may have been its category's last, so the liveness the cache remembers is evicted
 * and the next probe re-confirms against the store. The eviction is per process and does not cross
 * workers: erasure is a dev and test path by the eraser's own contract. Within the process the cache's
 * negative TTL bounds the residue, while another worker keeps its positive until it recycles.
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
            $this->cache->forgetCategory($streamName->category);
        }

        return $removed;
    }
}
