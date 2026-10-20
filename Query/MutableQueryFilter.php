<?php

declare(strict_types=1);

namespace Storm\Chronicler\Query;

/**
 * A `QueryFilter` whose position window the runner stamps before each read.
 *
 * A checkpoint-less query fold can then be paged and re-windowed without the runner knowing the
 * filter's selection.
 *
 * The persistent runner builds its own immutable {@see ProjectionFilter} to protect the checkpoint; a
 * query fold has no checkpoint, so its filter can be mutable. The runner calls `window()` to advance
 * `(after, head]` and cap the page, then `apply()` emits the read. The caller owns the selection,
 * category and stream, and the runner owns the window; mutation is safe because a checkpoint-less read
 * has no position to corrupt.
 *
 * An implementation's `apply()` must:
 *
 * - Honor the stamped window, `sequence_no > after AND sequence_no <= head`
 * - Cap at `limit`
 * - Order by `sequence_no ASC`, the catch-up order the fold pages on
 *
 * The window must be valid before the first `apply()`: the runner stamps it before every page, and a
 * caller reading the filter outside the runner stamps its own, since an implementation defaults it to
 * an unbounded read.
 *
 * @see \Storm\Projector\Query\QueryProjectionRunner::stream()
 */
interface MutableQueryFilter extends QueryFilter
{
    /**
     * Stamp the position window and page size for the next `apply()`.
     *
     * The runner calls it once per page and it mutates the filter in place, so a page allocates nothing.
     *
     * @param  int  $after  exclusive lower bound `sequence_no > after`, the last folded position
     * @param  int  $head  inclusive upper bound `sequence_no <= head`, the safe-head snapshot
     * @param  positive-int  $limit  page cap; a full page means the window may hold more
     */
    public function window(int $after, int $head, int $limit): void;
}
