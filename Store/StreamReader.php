<?php

declare(strict_types=1);

namespace Storm\Chronicler\Store;

use Generator;
use Storm\Chronicler\Query\QueryFilter;
use Storm\Chronicler\Record\EventRecord;
use Storm\Contracts\Chronicler\InvalidPosition;
use Storm\Contracts\Chronicler\Position;
use Storm\Contracts\Chronicler\UndecodableRow;
use Storm\Contracts\Chronicler\UnknownEventType;
use Storm\Contracts\Clock\ClockExceptionContract;
use Storm\Contracts\Serializer\SerializationExceptionContract;
use Storm\Stream\StreamName;

/**
 * The read side of the event store: loads events and resolves the commit-order safe watermark.
 *
 * Events load as lazy `EventRecord` cursors. The port is separate from the write verbs
 * `DecisionAppend` and `FactAppend`, so readers such as aggregate reconstitution, projectors,
 * LiveQuery and snapshot catch-up inject no write capability, and reads bypass the write-side
 * decorators entirely: there is nothing to transact and nothing to retry, since a half-consumed
 * generator cannot be replayed.
 *
 * Reads must be bounded. The generator is lazy at the PHP level, but the driver, pdo_pgsql, buffers the
 * whole result set client-side at execute, so a read is as big as its query, not as its consumption.
 * The snapshot policy bounds aggregate reconstitution, and every catch-up read goes through
 * `retrieveByFilter()` with a filter that carries a `LIMIT`. Never feed an unbounded category-wide
 * `retrieveAll()` millions of rows expecting to stream them; slice with a filter.
 *
 * A server-side cursor for genuinely unbounded replays waits for its trigger, the first real need for a
 * full-category replay.
 */
interface StreamReader
{
    /**
     * Load a stream's events in append order, in the given direction, forward by default.
     *
     * - A qualified name such as `order-123` loads one aggregate ordered by `version`, identical to
     *   `sequence_no` order within a stream and provided by the `UNIQUE` index, so the hot
     *   rehydration read pays no sort.
     *
     * - A bare category name such as `order` loads every aggregate of the category, ordered by the
     *   global `sequence_no`.
     *
     * No match yields an empty generator, never an exception. Callers give "empty" its meaning: the
     * aggregate repository returns null, a projector continues, an API returns 404.
     *
     * @return Generator<int, EventRecord>
     *
     * @throws InvalidPosition when a row's `sequence_no` is not a positive integer
     * @throws ClockExceptionContract when a stored point in time failed to be parsed
     * @throws SerializationExceptionContract when the stored event type is unknown or not deserializable
     * @throws UnknownEventType when the stored `type` resolves to no known class, or to a class
     *                          that is not a domain event
     * @throws UndecodableRow when this runtime cannot decode a stored row, a missing or duplicated
     *                        version step, an upcaster that threw, or a row written by a newer
     *                        release; `resolvableByAnotherRuntime()` separates a deployment state
     *                        from a row no release will decode
     */
    public function retrieveAll(StreamName $streamName, Direction $direction = Direction::Forward): Generator;

    /**
     * Load the events a `QueryFilter` selects, streamed as `EventRecord`s.
     *
     * The filter shapes the query with its criteria, order, limit and join; the store owns the columns
     * and the row mapping. This is the read primitive of catch-up reads, projector poll cycles and
     * LiveQuery; `retrieveAll()` stays the hot path for aggregate reconstitution.
     *
     * No match yields an empty generator, never an exception.
     *
     * @return Generator<int, EventRecord>
     *
     * @throws InvalidPosition when a row's `sequence_no` is not a positive integer
     * @throws ClockExceptionContract when a stored point in time failed to be parsed
     * @throws SerializationExceptionContract when a stored event type is unknown or not deserializable
     * @throws UnknownEventType when the stored `type` resolves to no known class, or to a class
     *                          that is not a domain event
     * @throws UndecodableRow when this runtime cannot decode a stored row, a missing or duplicated
     *                        version step, an upcaster that threw, or a row written by a newer
     *                        release; `resolvableByAnotherRuntime()` separates a deployment state
     *                        from a row no release will decode
     *
     * @see EventRecord
     */
    public function retrieveByFilter(QueryFilter $filter): Generator;

    /**
     * The highest event position committed and visible to the current statement.
     *
     * This is a freshness target, not a catch-up bound. It can sit above an unresolved
     * sequence gap, so a scanner must keep using `safeHeadPosition()`.
     *
     * Returns null when no committed event is visible.
     */
    public function committedHeadPosition(): ?Position;

    /**
     * The commit-order safe watermark, the highest position up to which every position is settled.
     *
     * Settled means committed, aborted, or presumed aborted after the grace window. `sequence_no` is
     * assigned at `INSERT`, but transactions commit out of order, so a reader near the head can
     * momentarily see a higher position while a lower one is still in flight. Catch-up readers such as
     * projectors bound their reads at this watermark, and a gap below it is treated as permanent, a
     * burned sequence. The gap scan of `SafeHeadAdvancer` computes it: a hole younger than the grace
     * holds the watermark, an older one is presumed aborted and stepped over.
     *
     * The safety is bounded by the grace rather than absolute. A transaction that stays in flight
     * longer than the grace is stepped over, and if it then commits, its position lands below the
     * watermark and a filtered projection that already passed it skips it. This residual is accepted
     * in exchange for no gap-tracking machinery, following the Marten precedent. Operationally, make
     * the assumption true: keep the writers' PostgreSQL `transaction_timeout` strictly under the grace,
     * so no transaction can outlive its presumption of abort; `idle_in_transaction_session_timeout`
     * does not cover it, since it ends a session sitting idle in a transaction and never one that is
     * busy.
     *
     * The precondition is a fresh snapshot, since the horizon is the calling backend's snapshot. Under
     * `READ COMMITTED` it is taken per statement, fresh even inside an open transaction, but a
     * `REPEATABLE READ` or `SERIALIZABLE` transaction pins `xmin` at its start, so the watermark would be
     * computed against a stale horizon and could stall catch-up. Standalone callers run it in
     * autocommit; the persistent projector reads it inside its batch transaction but pins the
     * connection to `READ COMMITTED` for this reason, in `ProjectionRunner`.
     *
     * Returns null when nothing is safe to read yet: the store is empty, or the lowest position is in
     * flight.
     */
    public function safeHeadPosition(): ?Position;
}
