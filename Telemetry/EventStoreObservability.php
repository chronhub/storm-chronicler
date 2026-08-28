<?php

declare(strict_types=1);

namespace Storm\Chronicler\Telemetry;

/**
 * Observability port for the event store, the single seam through which the Postgres event store
 * surfaces operational data. Body code calls one method per public operation with a typed Context
 * DTO; the implementation decides what to do, whether a structured log, metrics, an audit DB write,
 * or no-op.
 *
 * The default is a null implementation that ships with the Chronicler package, so the store runs
 * untouched when no Telemetry implementation is wired; the Storm Telemetry package is opt-in. The
 * Telemetry implementation, when installed, fans this single port out to Monolog plus optional
 * metrics plus optional audit DB. Adding a tracer or Prometheus exporter later is a fanout change in
 * that implementation; the store body stays as-is.
 *
 * FAIL-OPEN is the implementation's contract: these hooks fire on the store's own paths, inside
 * the append transaction, in a read's `finally`, and right before an OCC throw, so an implementation
 * that throws would ROLLBACK an append already written, mask a read's original failure, or eat the
 * OCC signal the retry-forward machinery needs. Observability must never change the store's
 * outcome: catch your own sink failures, count them, and return; a throwing sink is a bug in the
 * implementation, not a condition the store handles.
 *
 * NON-BLOCKING is the contract's other half: `recordAppend` runs inside the append transaction
 * with row locks held, so every millisecond an implementation spends is added to every append. An
 * implementation must not perform synchronous network I/O on these hooks; a logging fanout needs
 * buffered or local handlers, never a socket handler awaiting a remote ack.
 */
interface EventStoreObservability
{
    /**
     * A successful append via either write verb, appendTo or appendAny; fires after the events are
     * persisted and the head moved. Not called on OCC conflicts, which go to recordOccConflict, or
     * other failures.
     */
    public function recordAppend(AppendContext $ctx): void;

    /**
     * A read via retrieveAll or retrieveByFilter; fires when the consumer stops consuming the returned
     * Generator, whichever way: full exhaustion, an early break where the store's `finally` runs at
     * generator destruction, or a throw mid-hydration. The duration and count reflect what the
     * consumer actually saw, not the full result set.
     */
    public function recordLoad(LoadContext $ctx): void;

    /**
     * An OCC conflict caught at append time: either StaleVersion, where the pre-check on stream_heads
     * lost the CAS, or DuplicateVersion, the UNIQUE constraint backstop. Fires before the exception is
     * re-thrown to the caller.
     */
    public function recordOccConflict(OccConflictContext $ctx): void;
}
