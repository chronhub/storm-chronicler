<?php

declare(strict_types=1);

namespace Storm\Chronicler\Erasure;

use Storm\Contracts\Chronicler\StorageFailure;
use Storm\Stream\StreamName;
use Throwable;

/**
 * Maintenance port: permanently erases a stream from the event store.
 *
 * Append-only is the rule, and the log is the source of truth. This port is the segregated exception,
 * kept off the core store ports so their contract stays honest and their decorators carry no
 * destructive operation; a caller reaches it only by asking for it.
 *
 * The scope is Chronicler-owned state only, removed atomically:
 *
 * - The stream's events
 * - Its stream head
 * - Its undelivered outbox rows, the `pending` and `failed` ones
 *
 * These publication-index rows name events whose payload the relay reads from `event_store`, so
 * removing them prevents orphaned work referring to erased events; archived delivery rows keep their
 * own disposal. The erase leaves the projector-owned state untouched, the projection rows and the
 * derived-stream link bookkeeping, and the snapshots too: read models do not un-project when their
 * source is erased, so reset those through the projector.
 *
 * The framework gives the atomic brick: the bundle composes the snapshot half on top, and the
 * application composes the rest of its own delete process, with or without resetting the dependent
 * projections and whatever else hangs off them. The port itself stays snapshot-blind: a
 * `SnapshotDeletingStreamEraser` decoration deletes the stream's snapshot at the erase, since a
 * surviving snapshot would resurrect or pollute a re-created id.
 *
 * Legitimate uses are test or dev cleanup and a stream created in error. The right to erasure under
 * GDPR goes through crypto-shredding instead, encrypting per-subject PII and dropping the key, since a
 * hard delete leaves gaps and breaks downstream state.
 */
interface StreamEraser
{
    /**
     * Erase the stream and its stream head in one transaction, so a stream never survives half-erased.
     *
     * The stream is the one `$streamName->toString()` identifies, with its events.
     *
     * That promise covers a concurrent writer, not just a mid-way failure: the erase fences the
     * stream first, so an append lands wholly before it, and is erased with the rest, or wholly
     * after it, starting a fresh coherent stream. It never straddles it. An implementation that
     * cannot fence must say so and require writers quiesced.
     *
     * @return int events removed, 0 when the stream was unknown
     *
     * @throws StorageFailure on a non-retryable store failure of the erase
     * @throws Throwable the raw driver failure of the statements this port issues, unnameable here:
     *                   naming it would drag the driver into a package built to stay free of it, and
     *                   the retry boundary below matches it by pattern, so translation happens at the
     *                   consuming frontier and not at the source
     */
    public function erase(StreamName $streamName): int;
}
