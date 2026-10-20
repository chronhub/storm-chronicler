<?php

declare(strict_types=1);

namespace Storm\Chronicler\Telemetry;

/**
 * The observability port of the event store, the single seam through which it surfaces operational data.
 *
 * The store body calls one method per public operation with a typed context object, and the
 * implementation decides what to do with it: a structured log, metrics, an audit database write, or
 * nothing.
 *
 * The default null implementation ships with the Chronicler package, so the store runs untouched when no
 * telemetry is wired; the Storm Telemetry package is opt-in. Once installed, it fans this port out to
 * Monolog plus optional metrics and an optional audit database. Adding a tracer or a Prometheus exporter
 * is a fan-out change in that implementation; the store body stays as it is.
 *
 * An implementation must fail open. The hooks fire on the store's own paths, inside the append
 * transaction, in a read's `finally`, and right before an OCC throw, so a throwing implementation would
 * roll back an append already written, mask a read's original failure, or lose the OCC signal the
 * retry-forward machinery needs. Observability must never change the store's outcome: catch your own
 * sink failures, count them, and return. A throwing sink is a bug in the implementation, not a condition
 * the store handles.
 *
 * An implementation must not block. `recordAppend()` runs inside the append transaction with row locks
 * held, so every millisecond it spends is added to every append. It must not perform synchronous network
 * I/O on these hooks: a logging fan-out needs buffered or local handlers, never a socket handler awaiting
 * a remote ack.
 */
interface EventStoreObservability
{
    /**
     * Records a successful append through either write verb, `appendTo()` or `appendAny()`.
     *
     * It fires after the events are persisted and the head has moved, and never on a failure: an OCC
     * conflict goes to `recordOccConflict()`.
     */
    public function recordAppend(AppendContext $ctx): void;

    /**
     * Records a read through `retrieveAll()` or `retrieveByFilter()`.
     *
     * It fires when the consumer stops consuming the returned generator, whichever way:
     *
     * - Full exhaustion
     * - An early break, where the store's `finally` runs at generator destruction
     * - A throw mid-hydration
     *
     * The duration and the count reflect what the consumer actually saw, not the full result set.
     */
    public function recordLoad(LoadContext $ctx): void;

    /**
     * Records an OCC conflict caught at append time, before the exception is rethrown to the caller.
     *
     * The conflict is a `StaleVersion`, where the pre-check on `stream_heads` lost the CAS, or a
     * `DuplicateVersion`, from the `UNIQUE` constraint backstop.
     */
    public function recordOccConflict(OccConflictContext $ctx): void;
}
