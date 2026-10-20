<?php

declare(strict_types=1);

namespace Storm\Chronicler\Directory;

use Storm\Contracts\Chronicler\StorageFailure;

/**
 * Windowed browse over the store's streams, name and last version, read from `stream_heads`.
 *
 * It never runs a `DISTINCT` over `event_store`. It is a module-local port like `StreamReader` and
 * `StreamHeadStore`, with the store's own vocabulary.
 *
 * The browse is windowed only: `stream_heads` holds one row per stream, and one aggregate is one
 * stream, so a full listing is as large as the store's population. The read is resumable: pass the
 * last stream name seen as `$afterStream` to browse the next slice, and a category narrows it to one
 * lane, the bare category stream plus every `category-` qualified one. Folding the category's case
 * leaves the qualifier bytes and the `$afterStream` cursor unchanged.
 *
 * Failures cross this port as the contracted `StorageFailure` with the driver cause preserved, like
 * every other `stream_heads` read.
 */
interface StreamDirectory
{
    /**
     * Browse stream heads in name order, strictly after `$afterStream`, capped at `$limit`.
     *
     * A null `$category` browses every stream; a category narrows to its lane, read under the
     * `StreamCategory` rule, trimmed and lowercased. A value that is no category under that rule, the
     * raw filter of an HTTP edge, names a lane that cannot exist and browses nothing.
     *
     * @param  positive-int  $limit
     * @return list<StreamHead>
     *
     * @throws StorageFailure on a store failure of the browse
     */
    public function browse(?string $category, string $afterStream, int $limit): array;
}
