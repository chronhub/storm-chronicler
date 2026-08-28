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
 * The read side of the event store: load stream events as EventRecords, which are lazy cursors,
 * and resolve the commit-order safe watermark.
 *
 * Deliberately a separate port from the write verbs `DecisionAppend` and `FactAppend`: readers
 * such as aggregate reconstitution, projectors, LiveQuery, and snapshot catch-up inject no write
 * capability, and reads bypass the write-side decorators entirely. There is nothing to transact,
 * and nothing to retry: a half-consumed Generator cannot be replayed.
 *
 * Bounded reads only. The Generator is lazy at the PHP level, but the driver, pdo_pgsql, buffers
 * the WHOLE result set client-side at execute, so a read is as big as its query, not as its
 * consumption. Aggregate reconstitution is bounded by the snapshot policy; every catch-up read
 * goes through `retrieveByFilter()` with a LIMIT-carrying filter. Do NOT feed an unbounded
 * category-wide `retrieveAll` millions of rows expecting to stream them; slice with a filter. A
 * server-side cursor for genuinely unbounded replays is deliberately not built; the trigger is
 * the first real full-category replay need.
 */
interface StreamReader
{
    /**
     * Load a stream's events in append order, in the given direction; forward, oldest before
     * newest, is the default. A qualified name such as `order-123` loads one aggregate ordered
     * by `version`, which is identical to `sequence_no` order within a stream and provided by
     * the UNIQUE index, so the hot rehydration read pays no sort. A bare category name such as
     * `order` loads every aggregate in the category, ordered by the global `sequence_no`.
     *
     * No match yields an empty Generator, never an exception. Callers give "empty" its meaning:
     * the aggregate repository returns null, a projector continues, an API returns 404.
     *
     * @return Generator<int, EventRecord>
     *
     * @throws InvalidPosition when a row's `sequence_no` is not a positive integer
     * @throws ClockExceptionContract when a stored point in time failed to be parsed
     * @throws SerializationExceptionContract when the stored event type is unknown / not deserializable
     * @throws UnknownEventType when the stored `type` resolves to no known class, or to a class
     *                          that is not a domain event
     * @throws UndecodableRow when this runtime cannot decode a stored row, a missing or duplicated
     *                        version step, an upcaster that threw, or a row written by a newer
     *                        release; `resolvableByAnotherRuntime()` separates a deployment state
     *                        from a row no release will decode
     */
    public function retrieveAll(StreamName $streamName, Direction $direction = Direction::Forward): Generator;

    /**
     * Load events selected by a QueryFilter with its criteria, order, limit, and join, streamed
     * as EventRecords. The filter shapes the query; the store owns the columns and the row
     * mapping. This is the read primitive for catch-up reads, projector poll cycles and
     * LiveQuery; `retrieveAll()` stays the hot path for aggregate reconstitution.
     *
     * No match yields an empty Generator, never an exception.
     *
     * @return Generator<int, EventRecord>
     *
     * @throws InvalidPosition when a row's `sequence_no` is not a positive integer
     * @throws ClockExceptionContract when a stored point in time failed to be parsed
     * @throws SerializationExceptionContract when a stored event type is unknown / not deserializable
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
     * The commit-order safe watermark: the highest Position `P` such that every position up to
     * `P` is settled: committed, aborted, or PRESUMED aborted after the grace window.
     *
     * `sequence_no` is assigned at INSERT, but transactions commit out of order, so a reader near
     * the head can momentarily see a higher position while a lower one is still in-flight.
     * Catch-up readers such as projectors bound their reads at this watermark; a gap below it is
     * treated as permanent, a burned sequence. Computed by the gap-scan `SafeHeadAdvancer`: a hole
     * younger than the grace holds the watermark, a hole older than it is presumed aborted and
     * stepped over.
     *
     * The safety is therefore BOUNDED by the grace, not absolute: a transaction that stays
     * in-flight longer than the grace is stepped over, and if it then commits, its position lands
     * below the watermark and a filtered projection that already passed it will skip it; the
     * accepted residual is deliberately traded against gap-tracking machinery, following the
     * Marten precedent. Operationally, make the assumption true: keep the writers' PostgreSQL
     * `transaction_timeout` strictly UNDER the grace, so no transaction can outlive its presumption
     * of abort; `idle_in_transaction_session_timeout` does not cover it, since it terminates a
     * session sitting IDLE in a transaction and never one that is busy.
     *
     * Precondition, a fresh snapshot: the horizon is the calling backend's snapshot. Under READ
     * COMMITTED it is taken per-statement, fresh even inside an open transaction, but a REPEATABLE
     * READ or SERIALIZABLE transaction pins `xmin` at its start, so the watermark would be
     * computed against a stale horizon and could stall catch-up. Standalone callers run it in
     * autocommit; the persistent projector reads it inside its batch transaction but pins the
     * connection to READ COMMITTED for exactly this reason; see `ProjectionRunner`.
     *
     * Returns null when nothing is safe to read yet; the store is empty, or the lowest position
     * is in-flight.
     */
    public function safeHeadPosition(): ?Position;
}
