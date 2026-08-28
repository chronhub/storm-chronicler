<?php

declare(strict_types=1);

namespace Storm\Chronicler\Query;

/**
 * A {@see QueryFilter} whose position window the runner stamps before each read, so a
 * checkpoint-less query fold can be paged and re-windowed without the runner knowing the filter's
 * selection.
 *
 * The persistent runner builds its own immutable {@see ProjectionFilter} to protect the
 * checkpoint; a query fold has no checkpoint, so its filter can be mutable: the runner calls
 * `window()` to advance `(after, head]` and cap the page, then `apply()` emits the read. The caller
 * owns the selection, category and stream; the runner owns the window. Mutation is safe precisely
 * because a checkpoint-less read has no position to corrupt.
 *
 * Contract for an implementation's `apply()`: honor the stamped window, `sequence_no > after AND
 * sequence_no <= head`, cap at `limit`, and order by `sequence_no ASC`, the catch-up order the fold
 * pages on. The window MUST be valid before the first `apply()`; an implementation defaults it to an
 * unbounded read so a one-shot `apply()` without `window()` still works.
 *
 * @see \Storm\Projector\Query\QueryProjectionRunner::stream()
 */
interface MutableQueryFilter extends QueryFilter
{
    /**
     * Stamp the position window and page size for the next `apply()`. Called by the runner per page,
     * mutating by design with no per-page allocation.
     *
     * @param  int  $after  exclusive lower bound `sequence_no > after`, the last folded position
     * @param  int  $head  inclusive upper bound `sequence_no <= head`, the safe-head snapshot
     * @param  positive-int  $limit  page cap; a full page means the window may hold more
     */
    public function window(int $after, int $head, int $limit): void;
}
