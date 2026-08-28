<?php

declare(strict_types=1);

namespace Storm\Chronicler\Erasure;

use Storm\Contracts\Chronicler\StorageFailure;
use Storm\Stream\StreamName;
use Throwable;

/**
 * Maintenance port: permanently erase a stream from the event store.
 *
 * Append-only is the rule; the log is the source of truth. This is the deliberate, segregated
 * exception, kept off the core store ports so their contract stays honest and their decorators
 * carry no destructive op. You reach for it on purpose.
 *
 * Scope is Chronicler-owned only, removed atomically: the stream's events, its stream-head, and its
 * UNDELIVERED outbox rows, the `pending` and `failed` ones. An es_outbox row is self-carrying, so a
 * survivor would later publish an event of a stream that no longer exists; published rows are
 * history and keep their own disposal. It does NOT touch the projector-owned state, the projection
 * rows and the derived-stream link bookkeeping, nor snapshots; read models don't "un-project" when
 * their source is erased, so reset those via the projector.
 *
 * The framework gives the atomic brick; the bundle composes the snapshot half on top and the
 * application composes the rest of its own delete process, with or without resetting the dependent
 * projections and whatever else hangs off them. The port itself stays snapshot-blind: a
 * `SnapshotDeletingStreamEraser` decoration deletes the stream's snapshot at the erase, since a
 * surviving snapshot would resurrect or pollute a re-created id.
 *
 * Legitimate use: test or dev cleanup, a stream created in error. NOT the GDPR answer; for the
 * right-to-erasure prefer crypto-shredding, encrypting per-subject PII, and dropping the key, since a
 * hard delete leaves gaps and breaks downstream state.
 */
interface StreamEraser
{
    /**
     * Erase the stream identified by `$streamName->toString()`, its events and its stream-head, in
     * one transaction so a stream never survives half-erased.
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
     *                   the retry boundary below pattern-matches it on purpose, so translation
     *                   happens at the consuming frontier and not at the source
     */
    public function erase(StreamName $streamName): int;
}
