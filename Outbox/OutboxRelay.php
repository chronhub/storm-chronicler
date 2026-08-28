<?php

declare(strict_types=1);

namespace Storm\Chronicler\Outbox;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use Storm\Chronicler\Console\RelayOutboxCommand;
use Storm\Chronicler\Record\StoredEventReader;
use Storm\Contracts\Chronicler\UndecodableRow;
use Storm\Contracts\Serializer\SerializationExceptionContract;
use Storm\Message\Message;
use Storm\Support\Error\AuditDigest;
use Storm\Support\OutboxDisposal;
use Throwable;

/**
 * Drains the outbox: reads the `pending` rows that are DUE, locked with `SKIP LOCKED` so parallel
 * relays don't collide and `ORDER BY id` so insertion order, hence per-aggregate version order, is
 * kept, reconstructs each Message, publishes it, then disposes of the row at the end of the batch in
 * one statement for the whole batch, inside the same drain transaction. Disposal deletes by default,
 * since a queue is not a ledger, or moves the row to the append-only `es_outbox_archive` when
 * `storm.outbox.disposal: archive`; see {@see \Storm\Support\OutboxDisposal}.
 *
 * Ordering is scoped per partition, where `partition_key` is the qualified stream: the relay keeps
 * order within a partition, so a stuck row blocks only its own aggregate while other partitions keep
 * draining, head-of-line elimination.
 *
 * Safe N-worker parallel draining takes THREE guards, because the claim is rows-first. Each one
 * covers what the previous one leaves open:
 *
 * - The relay claims the partition with a Postgres transaction-scoped advisory lock,
 *   `pg_try_advisory_xact_lock`, before touching it. Row-level `SKIP LOCKED` alone would not do,
 *   since it locks the rows a worker already read, not the partition, so a sibling grabs the tail
 *   and races.
 *
 * - It verifies it holds the partition's pending HEAD, because the advisory alone would not do
 *   either: the rows were locked at the SELECT, before any claim, so the advisory winner may hold
 *   the tail while the sibling row-locked the head, and publishing past it would put v2 on the wire
 *   before v1.
 *
 * - It verifies the claimed window has no HOLE, because holding the head is not holding the range.
 *   A sibling's row lock, still held or lingering on a transaction rolling back after a crash, may
 *   hide a DUE row in the MIDDLE of the window, so the relay would publish around it. The probe
 *   counts the partition's due pending rows up to the highest claimed id and refuses the partition
 *   when the claim holds fewer.
 *
 * Together the three make the promise hold: a partition's rows are only published by a relay that
 * holds every due pending row up to the last one it publishes. The lock auto-releases at commit or
 * rollback, so a crashed relay frees its partitions with no stale-lease window; chosen over a
 * `lease_until` column precisely to avoid that bookkeeping.
 *
 * `publish()` runs inside the drain transaction by design: the `published` mark may only follow a
 * successful publication, at-least-once, so the two share one unit of work. Two consequences the
 * publisher must respect, per the {@see OutboxPublisher} contract: it must be a fast, durable handoff,
 * since the batch's row locks, the partition advisory locks and the transaction are all held for its
 * whole duration, and it must not assume its own writes are durable when it returns, since they commit
 * with the drain. Each publish is fenced by a savepoint, so a publisher that fails, even one that
 * aborts the transaction or half-writes first from an in-process handler chain riding this connection,
 * leaves zero trace, and the failure handling below always gets to commit: a poison row cannot disarm
 * its own remedy.
 *
 * Failure handling, all per-row so a failure of one row never re-emits the others:
 *
 * - Success: the row is disposed of, deleted or archived, in the same transaction. At-least-once:
 *   disposal only happens after a successful publication, so a crash before commit re-delivers the
 *   whole batch and the inbox dedups.
 *
 * - Permanent failure, either the row can never decode or deserialize from a corrupt payload or an
 *   unknown event type, or `publish()` threw an UnrecoverablePublishFailure because the consumer
 *   rejected it or it is unroutable: dead-lettered immediately with `status = 'failed'` and
 *   `last_error`, since retrying could never help. The partition is not blocked; the bad row leaves
 *   `pending`.
 *
 * - Runtime incompatibility, the row decodes for SOME binary but not this one: a missing,
 *   duplicated, or itself-crashing upcaster on a version step, or a row written by a newer runtime;
 *   stalled. Neither of the two lanes above fits. Dead-lettering would be wrong, since the row is not
 *   poison and a corrected deployment makes it publishable, so parking it in `failed` would demand a
 *   manual requeue for a fault the deploy already fixed, and would break its aggregate's order
 *   meanwhile. Burning `attempts` would be wrong too, since that budget measures the BROKER's health,
 *   and a five-attempt cap would dead-letter the whole incompatible type within minutes, long before
 *   an operator can ship the upcaster. So the row stays `pending`, `attempts` untouched, `last_error`
 *   recorded, `next_attempt_at` set to a cooldown of its own so the relay stops re-decoding a row
 *   whose verdict cannot change until a deployment does, and its partition is held so the tail keeps
 *   order; the refused tail is realigned onto the stall cooldown, so it stops refilling the claim
 *   window while nothing can move, and the whole partition wakes with its head. Unlike the two lanes
 *   above, this one NEVER resolves itself, so the drain still succeeds but carries the count and the
 *   first cause out in {@see OutboxDrainResult}.
 *
 * - Transient failure, publish threw because brokers are down or a handler errored: `attempts` is
 *   bumped, a `next_attempt_at` is set on exponential back-off, and the row stays `pending`; its
 *   partition is blocked for the rest of the pass so its tail keeps order, and the outage is surfaced
 *   as {@see OutboxDrainIncomplete}, the first failure as cause and the committed progress carried, so
 *   the caller and its scheduler retry without losing sight of the work that did land. While the row is
 *   cooling down it is out of the claim entirely, so it burns no `attempts` and, crucially, takes no
 *   slot from the other partitions; it still gates its own tail through the probe's `cooling_floor`,
 *   and that DUE tail, refused behind it, is lazily realigned onto its `next_attempt_at`, so the tail
 *   leaves the claim too and the partition wakes as one. Once `attempts` reaches maxAttempts, the row
 *   is dead-lettered instead, so a genuine poison message cannot block its partition indefinitely.
 *
 * A dead-lettered row leaves the `pending` index, so its partition unblocks; it stays for inspection
 * or replay via `storm:outbox:failed`. Note the trade-off: dead-lettering a row breaks ordering for
 * its aggregate, since later events get published while it sits in `failed`; that is an operational
 * incident to alert, fix, and replay, strictly better than freezing the partition.
 *
 * @see \Storm\Message\Message
 */
final readonly class OutboxRelay
{
    /**
     * Fences each publish inside the drain transaction. `publish()` may run arbitrary code on this
     * very connection, since a synchronously routed event bus executes handlers in-process, per the
     * OutboxPublisher contract: if that code aborts the transaction, a failed raw statement leaving
     * 25P02 on everything after, or half-writes before failing, ROLLBACK TO SAVEPOINT un-aborts the
     * drain and erases the attempt's traces. Without it, the failure handling below would die with the
     * failure it handles: the attempts-bump or dead-letter UPDATE could never commit, so the next tick
     * replays the identical crash forever in a hot loop and `maxAttempts` never engages.
     */
    private const string PUBLISH_SAVEPOINT = 'storm_outbox_publish';

    public function __construct(
        private Connection $connection,
        private StoredEventReader $reader,
        private OutboxPublisher $publisher,
        // @infection-ignore-all; equivalent: the bundle always sets this argument, so the default serves a standalone construction no production wiring takes
        private int $maxAttempts = 5,
        /**
         * Exponential back-off between retries, `base * 2^(attempts - 1)` capped, so a broker outage
         * spans a real wall-clock window instead of burning `maxAttempts` on every drain tick.
         */
        // @infection-ignore-all; equivalent: the bundle always sets this argument, so the default serves a standalone construction no production wiring takes
        private int $backoffBaseSeconds = 1,
        // @infection-ignore-all; equivalent: the bundle always sets this argument, so the default serves a standalone construction no production wiring takes
        private int $backoffMaxSeconds = 60,
        private OutboxDisposal $disposal = OutboxDisposal::Delete,
        /**
         * How long a row this runtime cannot decode is left alone before the relay tries it again.
         * Its own knob, not the publish back-off: the verdict cannot change until a deployment does,
         * so the useful scale is a deploy, minutes, not a broker hiccup, seconds.
         */
        // @infection-ignore-all; equivalent: the bundle always sets this argument, so the default serves a standalone construction no production wiring takes
        private int $stalledCooldownSeconds = 300,
    ) {}

    /**
     * A stalled row, one this runtime cannot decode, does NOT throw: the drain did all the work it
     * could and committed it, so the outcome carries the count and the first cause instead. Reusing
     * OutboxDrainIncomplete would say "transient publish failure", which this is not, and its daemon
     * handling counts the tick as idle, which would bury exactly the condition that never self-heals.
     * Turning that outcome into an alert belongs to the caller; {@see RelayOutboxCommand} exits
     * non-zero on it.
     *
     * @param  positive-int  $batch  a throughput knob, not a fairness one: the claim takes the
     *                               `batch` oldest pending rows that are DUE, and a due tail refused
     *                               behind a cold blocker is realigned onto it by the lazy cooling of
     *                               `realignRefused()`, so a partition in back-off or stalled costs
     *                               one refused window per row before it leaves the claim entirely.
     *                               What gates a cooling row's tail is the partition probe's
     *                               `cooling_floor`, never its presence in the window. The claim's
     *                               advisory-lock footprint scales with it: one transaction-scoped
     *                               lock per distinct partition seen, held to commit, up to `batch`
     *                               of them in the one-partition-per-row shape, per relay worker, so
     *                               `max_locks_per_transaction` must cover it; the sizing relation
     *                               is in the deployment operations guide
     *
     * @throws OutboxDrainIncomplete on a transient publish failure, thrown after committing the
     *                               progress made so far, which it carries in
     *                               {@see OutboxDrainIncomplete::$progress}; the first failure is its
     *                               `previous`
     * @throws Exception on a DBAL failure of the drain's own statements
     */
    public function drain(int $batch = 100): OutboxDrainResult
    {
        $transient = null;

        $result = $this->connection->transactional(function (Connection $connection) use ($batch, &$transient): OutboxDrainResult {
            $rows = $this->claimDueRows($connection, $batch);
            $claim = ClaimSummary::of($rows);

            $failed = 0;

            // Rows this runtime cannot decode: counted apart from $failed, since they are not parked
            // but frozen, and carried out with their first cause, a count alone naming no schema step
            // being an answer an operator cannot act on.
            $stalled = 0;
            $stalledCause = null;

            /** @var array<string, true> $stalledPartitions keyed for dedup, first-seen order kept */
            $stalledPartitions = [];

            /** @var list<int> $publishedIds disposed of in ONE statement at the end of the batch */
            $publishedIds = [];

            // Per-partition ordering, that is per-aggregate stream: a stuck row blocks only ITS partition,
            // not the whole log. Once a partition is blocked, its later rows are skipped, order kept;
            // other partitions keep draining, head-of-line elimination.
            $blocked = [];

            // Among the blocked, the partitions whose blocker is COLD: a cooling or stalled row,
            // `next_attempt_at` in the future, as opposed to one merely row-locked by a sibling
            // relay. The distinction decides what happens to the refused rows below: a cold
            // blocker's DUE tail would otherwise refill the claim window on every tick, only to be
            // refused again, starving foreign partitions for as long as the blocker cools, so
            // those rows are realigned onto their blocker at the end of the pass, lazy cooling. A
            // HOT refusal is left alone: the next tick, lock released, is the right retry.
            /** @var array<string, true> $coldBlocked */
            $coldBlocked = [];

            /** @var list<int> $refusedCold ids refused behind a cold blocker, realigned in ONE statement */
            $refusedCold = [];

            // Safe N-worker draining: $owned caches, per partition, whether THIS relay holds its
            // advisory lock, taken once at first sight. Only the owner drains a partition, so two
            // relays can't interleave its rows; row-level SKIP LOCKED alone wouldn't: it locks the
            // rows a worker already read, not the partition, so a sibling picks up the tail and races.
            // The advisory alone wouldn't either: the claim is rows-first, so the owner may hold the
            // TAIL while a sibling row-locked the head; hence the pending-head check below the claim.
            $owned = [];

            // Per partition, the lowest pending row that is NOT due yet, or null. Read with the head
            // in one probe: the due-filter keeps cooling rows out of the claim, so this is what
            // keeps them gating their own tail.
            $coolingFloor = [];

            foreach ($rows as $row) {
                $partition = $row->partition;

                if (isset($blocked[$partition])) {
                    // an earlier row here is stuck, or another relay owns it, so keep order, skip
                    if (isset($coldBlocked[$partition])) {
                        $refusedCold[] = $row->id;
                    }

                    continue;
                }

                if (! isset($owned[$partition])) {
                    $owned[$partition] = $this->tryOwnPartition($connection, $partition);

                    if (! $owned[$partition]) {
                        $blocked[$partition] = true; // another relay owns this partition this tick

                        continue;
                    }

                    $probe = $this->probePartition($connection, $partition, $claim->maxFor($partition));
                    $coolingFloor[$partition] = $probe->coolingFloor;

                    if (! $probe->headIsOurs($row->id)) {
                        // an older pending row exists that the claim did not hand us; never publish
                        // past it. TWO distinct causes, and the probe already tells them apart:
                        $blocked[$partition] = true;

                        if ($probe->headIsCooling()) {
                            // the head is COOLING: the due-filter kept it out of the claim, no
                            // sibling holds it. Cold blocker: realign the refused tail onto it,
                            // or the same due tail refills the window every tick until a deploy.
                            $coldBlocked[$partition] = true;
                            $refusedCold[] = $row->id;
                        }
                        // else the head is due but row-locked by a sibling relay: HOT contention,
                        // deliberately NOT cooled; the next tick is the right retry.

                        continue;
                    }

                    if ($probe->dueInWindow !== $claim->countFor($partition)) {
                        // The window has a HOLE: a due pending row at or below our highest claimed id
                        // that the claim did not hand us. The head-check above cannot see it, since we
                        // may well hold the head, and the cooling floor cannot either, since the row is
                        // DUE: it is hidden behind a sibling's row lock, held or lingering on a
                        // transaction rolling back after a crash, or its cooldown lapsed between the
                        // claim's snapshot and this probe's. Either way, publishing our rows would
                        // jump over it. HOT refusal, deliberately not cooled: the adverse lock
                        // resolves by the next tick, whose claim then takes the full window.
                        $blocked[$partition] = true;

                        continue;
                    }
                }

                if ($coolingFloor[$partition] !== null && $row->id > $coolingFloor[$partition]) {
                    // Behind a cooling row of this partition: the floor gates its tail even though the
                    // due-filter hides it from the claim. A row can never BE the floor, since a not-due
                    // row is filtered out of the claim. The floor is cold by definition: realign.
                    $blocked[$partition] = true;
                    $coldBlocked[$partition] = true;
                    $refusedCold[] = $row->id;

                    continue;
                }

                try {
                    $message = $this->reader->toMessage($row->raw);
                } catch (UndecodableRow $e) {
                    if (! $e->resolvableByAnotherRuntime()) {
                        // no release will ever decode it: a type that loads and is not a domain event.
                        // Poison, so dead-letter now, and the partition is NOT blocked, the bad row
                        // leaving `pending` while the tail proceeds.
                        $this->deadLetter($connection, $row->raw, $e);
                        $failed++;

                        continue;
                    }

                    // Runtime incompatibility, NOT poison: this binary lacks the version step, wires two
                    // upcasters onto it, an indeterminate migration being as undecodable as a missing
                    // one, ships an upcaster whose own code fails on the payload, does not declare the
                    // type's alias at all, or the row came from a newer binary. All are deployment
                    // states a corrected runtime clears on its own, so neither dead-letter, which would
                    // demand a requeue for a fault the deploy already fixed and would break the
                    // aggregate's order meanwhile, nor the retry budget, which measures the broker.
                    // Freeze the row where it is, hold its partition so the tail keeps order, and report
                    // it: this is the one lane that never heals by itself.
                    // The digest is taken from the call, never `??=`'d onto it: `??=` short-circuits,
                    // so the second stalled row would keep its cause and never be written at all.
                    $digest = $this->stall($connection, $row->raw, $e);
                    $stalledCause ??= $digest;
                    $stalledPartitions[$partition] = true;
                    $blocked[$partition] = true;
                    $coldBlocked[$partition] = true; // the stall cooldown just made this head cold
                    $stalled++;

                    continue;
                } catch (SerializationExceptionContract $e) {
                    // Permanent by construction: a payload malformed here is malformed everywhere, so
                    // dead-letter now. The partition is NOT blocked; the bad row leaves `pending`.
                    // Caught at the CONTRACT the serializer port promises, so a conforming alternative
                    // serializer dead-letters its poison row too instead of aborting the whole drain.
                    $this->deadLetter($connection, $row->raw, $e);
                    $failed++;

                    continue;
                }

                // The fence: a failed publish must leave ZERO trace, an in-process handler's partial
                // writes evaporating, and must NOT take the drain transaction down with it;
                // otherwise bump/dead-letter below could never commit.
                $connection->createSavepoint(self::PUBLISH_SAVEPOINT);

                try {
                    $this->publisher->publish($message);
                    $connection->releaseSavepoint(self::PUBLISH_SAVEPOINT);
                } catch (UnrecoverablePublishFailure $e) {
                    $this->erasePublishAttempt($connection);

                    // Permanent publish failure, consumer rejected or unroutable, so dead-letter now;
                    // partition not blocked, no point spending the retry budget.
                    $this->deadLetter($connection, $row->raw, $e, $row->attempts + 1);
                    $failed++;

                    continue;
                } catch (Throwable $e) {
                    $this->erasePublishAttempt($connection);

                    $attempts = $row->attempts + 1;

                    if ($attempts >= $this->maxAttempts) {
                        // Out of attempts: treat as poison, so dead-letter; partition not blocked, keep going.
                        $this->deadLetter($connection, $row->raw, $e, $attempts);
                        $failed++;

                        continue;
                    }

                    // Transient: bump attempts, schedule the next attempt with back-off, leave pending,
                    // block this partition since its tail must keep order, but other partitions drain on.
                    $connection->executeStatement(
                        /** @lang PostgreSQL */
                        <<<'SQL'
                            UPDATE es_outbox
                            SET attempts = :attempts,
                                last_error = :error,
                                next_attempt_at = clock_timestamp() + (:delay * interval '1 second')
                            WHERE id = :id
                            SQL,
                        ['attempts' => $attempts, 'error' => AuditDigest::digest($e), 'delay' => $this->backoffSeconds($attempts), 'id' => $row->id],
                        ['attempts' => ParameterType::INTEGER, 'delay' => ParameterType::INTEGER],
                    );

                    $blocked[$partition] = true;
                    $coldBlocked[$partition] = true; // the back-off just made this head cold
                    $transient ??= $e; // remember the first outage to surface after the commit

                    continue;
                }

                $publishedIds[] = $row->id;
            }

            $this->realignRefused($connection, $refusedCold);
            $this->dispose($connection, $publishedIds);

            // A full batch means the cap cut the drain, not that the queue is empty. Read from the
            // claim's own size rather than a second probe: over-reading one row would LOCK it under
            // FOR UPDATE and hold it from another worker, and a probe after the batch cannot see past
            // our own locks. It can say "more" over a queue that ended exactly on the cap, which costs
            // one extra run; the other direction costs a deploy over events still flying.
            return new OutboxDrainResult(count($publishedIds), $failed, $stalled, $stalledCause, array_keys($stalledPartitions), count($rows) === $batch);
        });

        if ($transient !== null) {
            // Progress + the bumped attempts are committed; now surface the outage so the run
            // retries, carrying the committed counts; fail forward, the work done must show.
            throw OutboxDrainIncomplete::after($result, $transient);
        }

        return $result;
    }

    /**
     * How many rows are still `pending`, whatever the reason this drain did not take them.
     *
     * The batch signal answers one question only, whether the cap cut the run short, and a SHORT batch
     * is not the same fact as an empty table: a row backing off from an EARLIER run is not due, so no
     * claim takes it and no full batch reports it, and the deployment gate that loops until a run
     * relays 0 reads that silence as a drained queue. Rows another worker holds under `SKIP LOCKED`
     * count here too, deliberately: to the operator asking whether the outbox is empty, held and
     * cooling are the same answer.
     *
     * Outside the drain's transaction, and a plain count: it claims no row and holds no lock, so it
     * cannot withhold work from another worker the way an over-read of the claim would.
     *
     * @throws Exception on a DBAL failure of the count
     */
    public function countPending(): int
    {
        return (int) $this->connection->fetchOne(
            /** @lang PostgreSQL */
            "SELECT count(*) FROM es_outbox WHERE status = 'pending'",
        );
    }

    /**
     * The claim: the `batch` oldest pending rows that are DUE, row-locked with `SKIP LOCKED` so
     * parallel relays do not collide, in global id order so per-partition insertion order is kept.
     *
     * The due-filter is what makes the batch fair. Its cost, measured in shape not in numbers:
     * `next_attempt_at` is not in `es_outbox_pending_idx (id) WHERE status='pending'`, so the
     * scan pays a heap fetch per row it skips. In the steady state, nothing cools, the first
     * `batch` entries pass, and that fetch is one the query owed anyway to return the payload;
     * the cost only appears with a cooling backlog, where it buys the fairness it pays for.
     * Not pre-optimized into the index: an INCLUDE column serves index-ONLY scans, not the
     * filter of an ordinary index scan, so the fix would mean a key column and a different
     * scan order. Measure the tick before touching the schema. The filter's blind spot, a
     * frozen partition's DUE tail passing it every tick, is closed by `realignRefused()`.
     *
     * @return list<OutboxRow>
     *
     * @throws Exception on a DBAL failure of the claim
     */
    private function claimDueRows(Connection $connection, int $batch): array
    {
        $rows = $connection->fetchAllAssociative(
            /** @lang PostgreSQL */
            <<<'SQL'
                SELECT id, partition_key, partition_key AS stream, type, event_version, header, content, attempts
                FROM es_outbox
                WHERE status = 'pending'
                  AND (next_attempt_at IS NULL OR next_attempt_at <= clock_timestamp())
                ORDER BY id
                LIMIT :batch
                FOR UPDATE SKIP LOCKED
                SQL,
            ['batch' => $batch],
            ['batch' => ParameterType::INTEGER],
        );

        return array_map(OutboxRow::of(...), $rows);
    }

    /**
     * Claim the partition for this tick with a transaction-scoped advisory lock.
     *
     * @throws Exception on a DBAL failure of the lock attempt
     */
    private function tryOwnPartition(Connection $connection, string $partition): bool
    {
        return (bool) $connection->fetchOne(
            /** @lang PostgreSQL */
            'SELECT pg_try_advisory_xact_lock(hashtextextended(:partition, 0))',
            ['partition' => $partition],
        );
    }

    /**
     * One probe, THREE answers, because owning a partition is neither owning its head nor seeing
     * its holes, and both would put a later version on the wire first. One statement over the same
     * `(partition_key, status = 'pending')` rows: enriching its aggregates costs nothing the pass
     * did not already pay, where a second query would ship its own plan, and a hot-path query
     * ships its index in the same commit; this one adds neither.
     *
     * `head`: the claim is rows-first, since the SELECT row-locked a batch BEFORE any partition
     * claim, so a sibling may hold the row lock on an OLDER pending row of this very partition;
     * its SKIP LOCKED hid that row from us. If any pending row is older than the first one WE
     * hold, it belongs to someone else's batch, or to the next tick; order defers to it.
     *
     * `cooling_floor`: the lowest pending row of this partition that is NOT due. The claim filters
     * those out, so they are invisible to the loop, and invisible is exactly the danger: a cooling
     * row in the MIDDLE of a partition would let the rows behind it sail past. That state is
     * reachable, not theoretical: dead-letter a row, let a later one cool on a transient failure,
     * then `storm:outbox:failed <id> --retry` requeues the first one due UNDER the cooling one.
     * Nothing may be published at or above this floor.
     *
     * `due_in_window`: how many due pending rows sit at or below the highest id the claim handed
     * us for this partition. The head answers "is there a hole BEFORE my first row"; this answers
     * "is there a hole ANYWHERE in my window", the hidden row being older than rows we hold but
     * newer than our first. More due rows in the window than the claim holds means one is not
     * ours; publishing the window would jump over it.
     *
     * @throws Exception on a DBAL failure of the probe
     */
    private function probePartition(Connection $connection, string $partition, int $maxClaimed): PartitionProbe
    {
        $probe = $connection->fetchAssociative(
            /** @lang PostgreSQL */
            <<<'SQL'
                SELECT min(id) AS head,
                       min(id) FILTER (WHERE next_attempt_at > clock_timestamp()) AS cooling_floor,
                       count(*) FILTER (WHERE id <= :max_claimed
                                          AND (next_attempt_at IS NULL OR next_attempt_at <= clock_timestamp())) AS due_in_window
                FROM es_outbox
                WHERE partition_key = :partition AND status = 'pending'
                SQL,
            ['partition' => $partition, 'max_claimed' => $maxClaimed],
            ['max_claimed' => ParameterType::INTEGER],
        );

        if ($probe === false) {
            return new PartitionProbe(null, null, 0);
        }

        return new PartitionProbe(
            $probe['head'] === null ? null : (int) $probe['head'],
            $probe['cooling_floor'] === null ? null : (int) $probe['cooling_floor'],
            (int) $probe['due_in_window'],
        );
    }

    /**
     * Dispose of the batch's published rows in ONE statement, inside the drain transaction so it is
     * atomic with the publications: a plain DELETE, or the atomic move with `DELETE … RETURNING` piped
     * into the archive INSERT, so the row is never in both tables and never in neither. `archived_at`
     * defaults to now, the publication instant. Failed, pending, and blocked rows are untouched by
     * construction: only successfully published ids reach this list.
     *
     * @param  list<int>  $publishedIds
     *
     * @throws Exception on a DBAL failure of the disposal statement
     */
    private function dispose(Connection $connection, array $publishedIds): void
    {
        if ($publishedIds === []) {
            return;
        }

        $sql = match ($this->disposal) {
            OutboxDisposal::Delete => /** @lang PostgreSQL */ 'DELETE FROM es_outbox WHERE id IN (:ids)',
            OutboxDisposal::Archive => /** @lang PostgreSQL */ <<<'SQL'
                WITH moved AS (
                    DELETE FROM es_outbox WHERE id IN (:ids)
                    RETURNING id, position, partition_key, type, event_version, header, content, attempts, occurred_at
                )
                INSERT INTO es_outbox_archive (id, position, partition_key, type, event_version, header, content, attempts, occurred_at)
                SELECT id, position, partition_key, type, event_version, header, content, attempts, occurred_at FROM moved
                SQL,
        };

        $connection->executeStatement($sql, ['ids' => $publishedIds], ['ids' => ArrayParameterType::INTEGER]);
    }

    /**
     * Lazy cooling, the fairness backstop: rows refused in THIS pass because their partition's
     * blocker is cold, a head or an older row cooling or stalled with `next_attempt_at` in the
     * future, are realigned onto that blocker's `next_attempt_at`, in one statement over rows the
     * claim already row-locked, so no new locks and no query outside the drain's own pass. Without
     * it, a frozen partition's DUE tail passes the due-filter on every tick, fills the claim window,
     * gets refused by the very guards that hold its order, and starves foreign partitions for as long
     * as the blocker cools; for a stalled head, until a deployment. With it, a frozen partition costs
     * one wasted window per row, the next tick's claim excludes the whole tail, and the partition
     * wakes together with its blocker. Ordering is untouched: publication stays gated by the
     * head-check and the cooling floor; this only schedules the next look.
     *
     * The blocker is resolved inside the statement, per row, as the lowest still-cooling pending row
     * below it in its partition, rather than carried over from the probe: a blocker whose cooldown
     * lapsed mid-pass then resolves to the next cold row or to NULL, leaving the refused row due,
     * the safe direction, since an extra look costs a window, a lost one costs latency a deploy
     * would have to notice. Rows refused for a HOT blocker, one merely row-locked by a sibling
     * relay, are deliberately never in this list: that contention resolves by the next tick, and
     * cooling a healthy partition would trade a tick of delay for a cooldown of it.
     *
     * @param  list<int>  $refusedIds
     *
     * @throws Exception on a DBAL failure of the realignment statement
     */
    private function realignRefused(Connection $connection, array $refusedIds): void
    {
        if ($refusedIds === []) {
            return;
        }

        $connection->executeStatement(
            /** @lang PostgreSQL */
            <<<'SQL'
                UPDATE es_outbox AS refused
                SET next_attempt_at = (
                    SELECT blocker.next_attempt_at
                    FROM es_outbox AS blocker
                    WHERE blocker.partition_key = refused.partition_key
                      AND blocker.status = 'pending'
                      AND blocker.id < refused.id
                      AND blocker.next_attempt_at > clock_timestamp()
                    ORDER BY blocker.id
                    LIMIT 1
                )
                WHERE refused.id IN (:ids)
                SQL,
            ['ids' => $refusedIds],
            ['ids' => ArrayParameterType::INTEGER],
        );
    }

    /**
     * Un-aborts the drain transaction after a failed publish: roll back to the pre-publish savepoint,
     * one of the few statements Postgres allows in an aborted transaction, which is the point, then
     * release it so retries within the same drain don't stack shadowed savepoints.
     *
     * @throws Exception on a DBAL failure of the savepoint statements
     */
    private function erasePublishAttempt(Connection $connection): void
    {
        $connection->rollbackSavepoint(self::PUBLISH_SAVEPOINT);
        $connection->releaseSavepoint(self::PUBLISH_SAVEPOINT);
    }

    /**
     * @param  array<string, mixed>  $row
     *
     * @throws Exception
     */
    private function deadLetter(Connection $connection, array $row, Throwable $error, ?int $attempts = null): void
    {
        $connection->executeStatement(
            /** @lang PostgreSQL */
            <<<'SQL'
                UPDATE es_outbox
                SET status = 'failed', attempts = :attempts, last_error = :error, failed_at = clock_timestamp()
                WHERE id = :id
                SQL,
            [
                'attempts' => $attempts ?? ((int) $row['attempts'] + 1),
                'error' => AuditDigest::digest($error),
                'id' => $row['id'],
            ],
            ['attempts' => ParameterType::INTEGER],
        );
    }

    /**
     * Freeze a row this runtime cannot decode: it stays `pending`, keeps its place in its partition's
     * order, and records why. `attempts` is deliberately NOT bumped, since it is the publish budget
     * and nothing was published; bumping it would dead-letter a whole incompatible event type within
     * minutes of a bad deploy. `next_attempt_at` is pushed by the stall cooldown, not the publish
     * back-off: re-decoding the row sooner cannot produce a different verdict, only load.
     *
     * Returns the digest it persisted, so the caller reports the same string it stored rather than
     * formatting the cause twice.
     *
     * @param  array<string, mixed>  $row
     *
     * @throws Exception on a DBAL failure of the stall update
     */
    private function stall(Connection $connection, array $row, Throwable $error): string
    {
        $digest = AuditDigest::digest($error);

        $connection->executeStatement(
            /** @lang PostgreSQL */
            <<<'SQL'
                UPDATE es_outbox
                SET last_error = :error,
                    next_attempt_at = clock_timestamp() + (:cooldown * interval '1 second')
                WHERE id = :id
                SQL,
            ['error' => $digest, 'cooldown' => $this->stalledCooldownSeconds, 'id' => $row['id']],
            ['cooldown' => ParameterType::INTEGER],
        );

        return $digest;
    }

    /**
     * Seconds to wait before the next attempt: exponential, `base * 2^(attempts - 1)` capped, so the
     * `maxAttempts` budget spans a real wall-clock window rather than burning on every drain tick,
     * then equal-jittered to a random point in the top half of that window, the same shape as
     * {@see \Storm\Chronicler\Store\RetryEventStore::sleepMicros()} and
     * {@see \Storm\Story\Middleware\RecoverConcurrencyConflict::backoffFor()}. A broker outage fails
     * every in-flight row at close to the same instant; without jitter every one of them computes the
     * SAME `next_attempt_at` and the relay re-floods the broker the moment it recovers, a herd this
     * relay caused rather than merely met.
     *
     * The floor is what makes the wall-clock promise hold whatever the base is: a configured zero
     * would return zero for every attempt, the row would fall due again on the very next tick, and
     * the budget would be spent at daemon speed on an outage the broker recovers from a second later.
     * The jitter's own lower bound is floored the same way, so halving a one-second window can never
     * hand back zero.
     *
     * @return int<1, max>
     */
    private function backoffSeconds(int $attempts): int
    {
        $seconds = $this->backoffBaseSeconds * (2 ** ($attempts - 1));

        // cap the FLOAT, then cast: a large attempt count overflows 2^(n-1) past what an int
        // represents, and casting first collapsed the capped exponential to the one-second floor
        $window = max(1, (int) min($this->backoffMaxSeconds, $seconds));

        return random_int(max(1, intdiv($window, 2)), $window);
    }
}
