<?php

declare(strict_types=1);

namespace Storm\Chronicler\Directory;

use Storm\Contracts\Chronicler\StorageFailure;

/**
 * Windowed browse over the store's streams: name and last version, from `stream_heads`, never a
 * DISTINCT over `event_store`. A module-local port like StreamReader and StreamHeadStore: its
 * vocabulary is the store's own.
 *
 * Windowed only, by design: `stream_heads` holds one row per stream, and one aggregate IS one
 * stream, so a full listing is as large as the store's population. The read is resumable: pass
 * the last stream name seen as `$afterStream` to browse the next slice, and a category narrows
 * it to one lane, the bare category stream plus every `category-` qualified one.
 *
 * Failures cross this port as the contracted StorageFailure with the driver cause preserved,
 * like every other stream_heads read.
 */
interface StreamDirectory
{
    /**
     * Browse stream heads in stream-name order, strictly after `$afterStream`, capped at
     * `$limit`. A null `$category` browses every stream; a category narrows to its lane.
     *
     * @param  positive-int  $limit
     * @return list<StreamHead>
     *
     * @throws StorageFailure on a store failure of the browse
     */
    public function browse(?string $category, string $afterStream, int $limit): array;
}
