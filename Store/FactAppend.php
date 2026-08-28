<?php

declare(strict_types=1);

namespace Storm\Chronicler\Store;

use Storm\Contracts\Chronicler\ConcurrencyException;
use Storm\Contracts\Chronicler\StorageFailure;
use Storm\Contracts\Serializer\SubjectForgotten;
use Storm\Stream\Stream;
use Throwable;

/**
 * The fact-append port: append a stream of FACTS WITHOUT optimistic concurrency. A fact records
 * something that already happened outside any invariant, such as a metering tick, telemetry, an
 * audit entry, or a device's dispensed cash. The store allocates versions at append time by an
 * atomic head bump, so concurrent appenders never conflict: they serialize on the head row and
 * each gets its own dense range. There is no `StaleVersion` and no redo; where the CAS path
 * pays a re-queue plus a full handler re-run per collision, a fact append pays a row-lock wait.
 *
 * Know the wait's true size: the bump is the append transaction's FIRST statement and the row
 * lock is held to its COMMIT, inserts plus outbox plus fsync included, so same-stream fact
 * producers serialize on the winner's whole transaction, the same wall the CAS path has minus
 * the redo. Per-subject fact streams keep that wall cold; a single high-fan-in fact stream, one
 * global audit or metering stream, caps at ~1/tx-duration by design.
 *
 * DELIBERATELY a separate, narrow, intent-named port, not a method on the decision port:
 * appending unguarded to a stream whose events feed decisions is a silent lost update. The
 * injection surface is the misuse guard: inject `FactAppend` only where recording facts IS the
 * intent. Two rules of use form the contract:
 *
 * - A stream is EITHER a decision stream, an aggregate that CAS via `DecisionAppend::appendTo`,
 *   OR a fact stream through this port, never both; a CAS against a head that facts keep moving
 *   is a permanent lottery, and the `UNIQUE (category, stream, version)` backstop punishes it.
 *
 * - The litmus is downstream: if anything reads the stream's state to DECIDE before writing, it
 *   is not recording a fact, so use the aggregate path.
 *
 * At-least-once delivery stays covered by the consumer inbox: `es_inbox` commits with the
 * append in the same transaction, exactly as on the aggregate path, so a redelivered command
 * does not double-record its fact.
 *
 * @see DecisionAppend::appendTo the guarded sibling for decision streams
 */
interface FactAppend
{
    /**
     * Append the stream's messages as facts: versions are assigned by the store from an
     * atomically allocated range. Any producer-set aggregate-version header is overwritten; a
     * fact's position is decided at the store, never by the producer.
     *
     * @throws ConcurrencyException when the stamped version collides with a row already stored, the
     *                              unique key refusing it
     * @throws SubjectForgotten when a personal payload names a tombstoned subject: no key can be
     *                          issued for it, so the write refuses rather than storing in clear
     * @throws StorageFailure on a non-retryable store failure of the head bump; a retryable
     *                        `RetryableStorageFailure` is retried like any `RetryableException`
     * @throws Throwable the raw driver failure of the statements this port issues, unnameable here:
     *                   naming it would drag the driver into a package built to stay free of it, and
     *                   the retry boundary below pattern-matches it on purpose, so translation
     *                   happens at the consuming frontier and not at the source
     */
    public function appendAny(Stream $stream): void;
}
