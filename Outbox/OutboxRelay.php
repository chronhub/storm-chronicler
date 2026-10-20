<?php

declare(strict_types=1);

namespace Storm\Chronicler\Outbox;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use JsonException;
use Psr\Log\LoggerInterface;
use Storm\Chronicler\Console\RelayOutboxCommand;
use Storm\Chronicler\Record\StoredEventReader;
use Storm\Chronicler\Store\RetryEventStore;
use Storm\Contracts\Chronicler\UndecodableRow;
use Storm\Contracts\Random\Jitter;
use Storm\Contracts\Serializer\SerializationExceptionContract;
use Storm\Message\Message;
use Storm\Support\Error\AuditDigest;
use Storm\Support\Error\TransientFailure;
use Storm\Support\OutboxDisposal;
use Storm\Support\Random\NativeJitter;
use Throwable;

/**
 * Drains the outbox: publishes each due `pending` row, then disposes of it, in one drain transaction.
 *
 * The claim reads the `pending` rows that are due, their payload joined from `event_store` since the row
 * names the event and carries no copy of it. It locks them with `SKIP LOCKED`, so parallel relays do not
 * collide, in `ORDER BY id`, so insertion order, and so per-aggregate version order, is kept. Each row is
 * rebuilt into a `Message` and published, and the batch's rows are disposed of at the end in one
 * statement, inside the same drain transaction. Disposal deletes by default, since a queue is not a
 * ledger, or moves the row to the append-only `es_outbox_archive` under
 * `storm.outbox.disposal: archive`; see {@see \Storm\Support\OutboxDisposal}.
 *
 * Ordering is scoped per partition, where `partition_key` is the qualified stream: the relay keeps order
 * within a partition, so a stuck row blocks only its own aggregate while other partitions keep draining,
 * which removes head-of-line blocking.
 *
 * Safe parallel draining by N workers takes three guards, because the claim is rows-first. Each one
 * covers what the previous one leaves open:
 *
 * - The relay claims the partition with a Postgres transaction-scoped advisory lock,
 *   `pg_try_advisory_xact_lock`, before touching it. Row-level `SKIP LOCKED` alone would not do, since
 *   it locks the rows a worker already read, not the partition, so a sibling grabs the tail and races.
 *
 * - It verifies it holds the partition's pending head, because the advisory lock alone would not do
 *   either: the rows were locked at the `SELECT`, before any claim, so the advisory winner may hold the
 *   tail while the sibling row-locked the head, and publishing past it would put v2 on the wire before
 *   v1.
 *
 * - It verifies the claimed window has no hole, because holding the head is not holding the range. A
 *   sibling's row lock, still held or lingering on a transaction rolling back after a crash, may hide a
 *   due row in the middle of the window, so the relay would publish around it. The probe counts the
 *   partition's due pending rows up to the highest claimed id and refuses the partition when the claim
 *   holds fewer.
 *
 * Together the three make the promise hold: a partition's rows are only published by a relay that holds
 * every due pending row up to the last one it publishes. The lock releases itself at commit or rollback,
 * so a crashed relay frees its partitions with no stale-lease window, which is why it was chosen over a
 * `lease_until` column and its bookkeeping.
 *
 * `publish()` runs inside the drain transaction because a row may be disposed of only after a successful
 * publication, at-least-once, so the two share one unit of work. The publisher must respect two
 * consequences, per the {@see OutboxPublisher} contract: it must be a fast, durable handoff, since the
 * batch's row locks, the partition advisory locks and the transaction are all held for its whole
 * duration, and it must not assume its own writes are durable when it returns, since they commit with
 * the drain. Each publish is fenced by a savepoint, so a publisher that fails, even one that aborts the
 * transaction or half-writes first from an in-process handler chain on this connection, leaves zero
 * trace, and the failure handling below always gets to commit: a poison row cannot disarm its own
 * remedy.
 *
 * Failure handling is per row, so a failure of one row never re-emits the others:
 *
 * - Success: the row is disposed of, deleted or archived, in the same transaction. Disposal only follows
 *   a successful publication, so a crash before commit redelivers the whole batch and the inbox
 *   deduplicates, at-least-once.
 *
 * - Permanent failure: the row can never decode or deserialize, from a corrupt payload or an unknown
 *   event type, or `publish()` threw an `UnrecoverablePublishFailure` because the consumer rejected it,
 *   it is unroutable, or the publisher meets the same refusal on every attempt. It is dead-lettered at
 *   once with `status = 'failed'` and `last_error`, since retrying could never help; the partition is
 *   not blocked, the bad row leaving `pending`.
 *
 * - Runtime incompatibility: the row decodes for some binary but not this one, through a missing,
 *   duplicated or itself-crashing upcaster on a version step, or a row written by a newer runtime. It is
 *   stalled, since neither lane above fits. Dead-lettering would be wrong: the row is not poison and a
 *   corrected deployment makes it publishable, so parking it in `failed` would demand a manual requeue
 *   for a fault the deploy already fixed, and break its aggregate's order meanwhile. Spending `attempts`
 *   would be wrong too, since that budget measures the broker's health, and a five-attempt cap would
 *   dead-letter the whole incompatible type within minutes, long before an operator can ship the
 *   upcaster. So the row stays `pending`, `attempts` untouched, `last_error` recorded, and
 *   `next_attempt_at` set to a cooldown of its own, so the relay stops re-decoding a row whose verdict
 *   cannot change until a deployment does; its partition is held so the tail keeps order. The refused
 *   tail is realigned onto the stall cooldown, so it stops refilling the claim window while nothing can
 *   move, and the whole partition wakes with its head. Unlike the two lanes above, this one never
 *   resolves itself, so the drain still succeeds but carries the count and the first cause out in
 *   {@see OutboxDrainResult}.
 *
 * - Transient failure: publish threw because brokers are down or a handler errored. `attempts` is bumped,
 *   `next_attempt_at` is set on exponential back-off, and the row stays `pending`; its partition is
 *   blocked for the rest of the pass so its tail keeps order, and the outage surfaces as
 *   {@see OutboxDrainIncomplete}, with the first failure as cause and the committed progress carried, so
 *   the caller and its scheduler retry without losing sight of the work that did land. While the row
 *   cools down it is out of the claim entirely, so it spends no `attempts` and takes no slot from the
 *   other partitions; it still gates its own tail through the probe's `cooling_floor`, and that due tail,
 *   refused behind it, is lazily realigned onto its `next_attempt_at`, so the tail leaves the claim too
 *   and the partition wakes as one. Once `attempts` reaches `maxAttempts` the row is dead-lettered
 *   instead, so a genuine poison message cannot block its partition indefinitely, unless
 *   {@see \Storm\Support\Error\TransientFailure} finds in the cause chain an outage that clears without the
 *   message changing, from its own list. The budget measures the broker's health, so spending it on an
 *   outage would dead-letter every partition head within minutes of a broker going away, out of reach of
 *   every lag signal and replayable only by hand; such an outage keeps its row `pending` whatever the
 *   attempt count, and the drain keeps reporting it until someone fixes it.
 *
 * A dead-lettered row leaves the `pending` index, so its partition unblocks; it stays for inspection or
 * replay through `storm:outbox:failed`. Dead-lettering a row breaks ordering for its aggregate, since
 * later events are published while it sits in `failed`: an operational incident to alert, fix and
 * replay, strictly better than freezing the partition.
 *
 * The command-side counterpart is {@see \Storm\Saga\Outbox\SagaOutboxRelay}, which drains the saga
 * command outbox under its own invariants, with no ordering and no event upcasting.
 *
 * @see \Storm\Message\Message
 */
final readonly class OutboxRelay
{
    /**
     * Fences each publish inside the drain transaction.
     *
     * `publish()` may run arbitrary code on this very connection, since a synchronously routed event bus
     * executes handlers in-process, per the `OutboxPublisher` contract. If that code aborts the
     * transaction, a failed raw statement leaving 25P02 on everything after, or half-writes before
     * failing, `ROLLBACK TO SAVEPOINT` un-aborts the drain and erases the attempt's traces. Without it,
     * the failure handling below would be lost with the failure it handles: the attempts bump or the
     * dead-letter `UPDATE` could never commit, so the next tick replays the identical crash forever in a
     * hot loop and `maxAttempts` never engages.
     */
    private const string PUBLISH_SAVEPOINT = 'storm_outbox_publish';

    /** The heartbeat row's key: the event relay, as opposed to the saga command relay on its own table. */
    private const string RELAY = 'event';

    public function __construct(
        private Connection $connection,
        private StoredEventReader $reader,
        private OutboxPublisher $publisher,
        // equivalent: the bundle always sets this argument, so the default serves a standalone construction
        // no production wiring takes
        // @infection-ignore-all
        private int $maxAttempts = 5,
        /**
         * Exponential back-off between retries, `base * 2^(attempts - 1)` capped, so a broker outage
         * spans a real wall-clock window instead of burning `maxAttempts` on every drain tick.
         */
        // equivalent: the bundle always sets this argument, so the default serves a standalone construction
        // no production wiring takes
        // @infection-ignore-all
        private int $backoffBaseSeconds = 1,
        // equivalent: the bundle always sets this argument, so the default serves a standalone construction
        // no production wiring takes
        // @infection-ignore-all
        private int $backoffMaxSeconds = 60,
        private OutboxDisposal $disposal = OutboxDisposal::Delete,
        /**
         * How long a row this runtime cannot decode is left alone before the relay tries it again.
         * Its own knob, not the publish back-off: the verdict cannot change until a deployment does,
         * so the useful scale is a deploy, minutes, not a broker hiccup, seconds.
         */
        // equivalent: the bundle always sets this argument, so the default serves a standalone construction
        // no production wiring takes
        // @infection-ignore-all
        private int $stalledCooldownSeconds = 300,
        /**
         * Where a failed publish leaves its line, `storm.outbox.publish_failed` at warning with the
         * partition held, the attempt spent and the budget: the outage is otherwise a non-zero exit
         * and two columns on a row. Null logs nothing, for standalone use.
         */
        private ?LoggerInterface $logger = null,
        private Jitter $jitter = new NativeJitter,
    ) {}

    /**
     * Drains one batch; a stalled row, one this runtime cannot decode, does not throw.
     *
     * The drain did all the work it could and committed it, so the outcome carries the count and the
     * first cause instead. Reusing `OutboxDrainIncomplete` would say "transient publish failure", which
     * this is not, and its daemon handling counts the tick as idle, which would bury exactly the
     * condition that never resolves itself. Turning that outcome into an alert belongs to the caller;
     * {@see RelayOutboxCommand} exits non-zero on it.
     *
     * @param  positive-int  $batch  a throughput knob, not a fairness one: the claim takes the
     *                               `batch` oldest pending rows that are due, and a due tail refused
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

            /** @var list<int> $publishedIds disposed of in one statement at the end of the batch */
            $publishedIds = [];

            // Per-partition ordering, that is per-aggregate stream: a stuck row blocks only its partition,
            // not the whole log. Once a partition is blocked, its later rows are skipped, order kept;
            // other partitions keep draining, head-of-line elimination.
            $blocked = [];

            // Among the blocked, the partitions whose blocker is cold: a cooling or stalled row,
            // `next_attempt_at` in the future, as opposed to one merely row-locked by a sibling
            // relay. The distinction decides what happens to the refused rows below: a cold
            // blocker's due tail would otherwise refill the claim window on every tick, only to be
            // refused again, starving foreign partitions for as long as the blocker cools, so
            // those rows are realigned onto their blocker at the end of the pass, lazy cooling. A
            // hot refusal is left alone: the next tick, lock released, is the right retry.
            /** @var array<string, true> $coldBlocked */
            $coldBlocked = [];

            /** @var list<int> $refusedCold ids refused behind a cold blocker, realigned in one statement */
            $refusedCold = [];

            // Safe N-worker draining, the first two guards of the class block: $owned caches, per
            // partition, whether this relay holds its advisory lock, taken once at first sight, and only
            // the owner drains a partition; the pending-head check below the claim covers the tail an
            // owner may hold while a sibling row-locked the head. The locks and the probes go to the
            // server once per tick, whatever the partition count: a batch spans hundreds of aggregate
            // streams, and a round trip per partition would make the probe the first cost of a flood.
            // Locks are taken in first-seen order, the order the loop walks, and a partition locked here
            // then found blocked holds its lock to the commit, the lock preceding the probe.
            // the owned partitions come from the claim's own strings, never from the keys of
            // `$ownership`, which PHP turns into integers for a key like `42`
            $partitions = $claim->partitions();
            $ownership = $this->ownPartitions($connection, $partitions);
            $probes = $this->probePartitions(
                $connection,
                $claim,
                array_values(array_filter($partitions, static fn (string $partition): bool => $ownership[$partition])),
            );
            $owned = [];

            // Per partition, the lowest pending row that is not due yet, or null. Read with the head
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
                    $owned[$partition] = $ownership[$partition] ?? false;

                    if (! $owned[$partition]) {
                        $blocked[$partition] = true; // another relay owns this partition this tick

                        continue;
                    }

                    $probe = $probes[$partition] ?? new PartitionProbe(null, null, 0);
                    $coolingFloor[$partition] = $probe->coolingFloor;

                    if (! $probe->headIsOurs($row->id)) {
                        // an older pending row exists that the claim did not hand us; never publish
                        // past it. Two distinct causes, and the probe already tells them apart:
                        $blocked[$partition] = true;

                        if ($probe->headIsCooling()) {
                            // the head is cooling: the due-filter kept it out of the claim, no
                            // sibling holds it. Cold blocker: realign the refused tail onto it,
                            // or the same due tail refills the window every tick until a deploy.
                            $coldBlocked[$partition] = true;
                            $refusedCold[] = $row->id;
                        }
                        // else the head is due but row-locked by a sibling relay: hot contention,
                        // left uncooled, since the next tick is the right retry.

                        continue;
                    }

                    if ($probe->dueInWindow !== $claim->countFor($partition)) {
                        // The window has a hole: a due pending row at or below our highest claimed id
                        // that the claim did not hand us. The head check above cannot see it, since we
                        // may well hold the head, and the cooling floor cannot either, since the row is
                        // due: it is hidden behind a sibling's row lock, held or lingering on a
                        // transaction rolling back after a crash, or its cooldown lapsed between the
                        // claim's snapshot and this probe's. Either way, publishing our rows would
                        // jump over it. Hot refusal, left uncooled: the adverse lock resolves by the
                        // next tick, whose claim then takes the full window.
                        $blocked[$partition] = true;

                        continue;
                    }
                }

                if ($coolingFloor[$partition] !== null && $row->id > $coolingFloor[$partition]) {
                    // Behind a cooling row of this partition: the floor gates its tail even though the
                    // due-filter hides it from the claim. A row can never be the floor, since a not-due
                    // row is filtered out of the claim. The floor is cold by definition: realign.
                    $blocked[$partition] = true;
                    $coldBlocked[$partition] = true;
                    $refusedCold[] = $row->id;

                    continue;
                }

                if ($row->vanished) {
                    // The row outlived its event: a storage fault to name, never a message to publish
                    // empty, never a head to leave in place; the partition keeps draining behind it.
                    $this->deadLetter($connection, $row->raw, OutboxEventVanished::at($partition, $row->raw['position']));
                    $failed++;

                    continue;
                }

                try {
                    $message = $this->reader->toMessage($row->raw);
                } catch (UndecodableRow $e) {
                    if (! $e->resolvableByAnotherRuntime()) {
                        // no release will ever decode it: a type that loads and is not a domain event.
                        // Poison, so dead-letter now, and the partition is not blocked, the bad row
                        // leaving `pending` while the tail proceeds.
                        $this->deadLetter($connection, $row->raw, $e);
                        $failed++;

                        continue;
                    }

                    // Runtime incompatibility, not poison: this binary lacks the version step, wires two
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
                    // dead-letter now. The partition is not blocked; the bad row leaves `pending`.
                    // Caught at the contract the serializer port promises, so a conforming alternative
                    // serializer dead-letters its poison row too instead of aborting the whole drain.
                    $this->deadLetter($connection, $row->raw, $e);
                    $failed++;

                    continue;
                }

                // The fence: a failed publish must leave zero trace, an in-process handler's partial
                // writes evaporating, and must not take the drain transaction down with it;
                // otherwise bump/dead-letter below could never commit.
                $connection->createSavepoint(self::PUBLISH_SAVEPOINT);

                try {
                    $this->publisher->publish($message);
                    $connection->releaseSavepoint(self::PUBLISH_SAVEPOINT);
                } catch (UnrecoverablePublishFailure $e) {
                    $this->erasePublishAttempt($connection);

                    // Permanent publish failure, consumer rejected, unroutable or refused the same way on
                    // every attempt, so dead-letter now; partition not blocked, no point spending the retry
                    // budget.
                    $this->deadLetter($connection, $row->raw, $e, $row->attempts + 1);
                    $failed++;

                    continue;
                } catch (Throwable $e) {
                    $this->erasePublishAttempt($connection);

                    $attempts = $row->attempts + 1;

                    if ($attempts >= $this->maxAttempts && ! TransientFailure::behind($e)) {
                        // Out of attempts, and nothing in the cause chain is an outage that clears
                        // without the message changing, by TransientFailure's own list: the failure
                        // is the message's own, so dead-letter; partition not blocked, keep going. An
                        // outage takes the lane below however long it lasts, since the budget measures
                        // the broker and spending it on a broker that is merely down would turn a wait
                        // into an operator's replay.
                        $this->deadLetter($connection, $row->raw, $e, $attempts);
                        $failed++;

                        continue;
                    }

                    // Transient: bump attempts, schedule the next attempt with back-off, leave pending,
                    // block this partition since its tail must keep order, but other partitions drain on.
                    $connection->executeStatement(
                        /* language=PostgreSQL */
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
                    $this->logPublishFailure($partition, $attempts, $e);

                    continue;
                }

                $publishedIds[] = $row->id;
            }

            $this->realignRefused($connection, $refusedCold);
            $this->dispose($connection, $publishedIds);

            // A full batch means the cap cut the drain, not that the queue is empty. Read from the
            // claim's own size rather than a second probe: over-reading one row would lock it under
            // `FOR UPDATE` and hold it from another worker, and a probe after the batch cannot see past
            // our own locks. It can say "more" over a queue that ended exactly on the cap, which costs
            // one extra run; the other direction costs a deploy over events still in flight.
            return new OutboxDrainResult(
                count($publishedIds),
                $failed,
                $stalled,
                $stalledCause,
                // back to strings: a partition key like `42` was stored as an integer key above
                array_map(strval(...), array_keys($stalledPartitions)),
                count($rows) === $batch,
            );
        });

        // The heartbeat, after the drain committed and before any outage is surfaced: a tick that
        // found nothing, or that failed forward, is still a relay alive, and the liveness check reads
        // nothing else on a quiet system. Its own statement, outside the drain transaction, so a
        // beat can never hold the claim's row locks and the drain can never roll a beat back.
        $this->beat($result->published);

        if ($transient !== null) {
            // Progress and the bumped attempts are committed; now surface the outage so the run
            // retries, carrying the committed counts; fail forward, the work done must show.
            throw OutboxDrainIncomplete::after($result, $transient);
        }

        return $result;
    }

    /**
     * Upserts this relay's row in `es_outbox_relay`: `ticked_at` to now, `relayed_total` up by what the
     * drain disposed of. One row per relay name, the event relay's being `event`; the freshest row
     * speaks for the fleet.
     *
     * A missing table is not swallowed: `es_outbox_relay` is core schema created with `es_outbox`, so
     * its absence is an incomplete install, the same stance the liveness checks take, and a relay
     * that beat into the void would restore the very blindness the row exists to end. The install
     * precedes the binary, as for every core table.
     */
    private function beat(int $relayed): void
    {
        $this->connection->executeStatement(
            /* language=PostgreSQL */
            'INSERT INTO es_outbox_relay (relay, ticked_at, relayed_total) VALUES (:relay, clock_timestamp(), :relayed)
             ON CONFLICT (relay) DO UPDATE SET ticked_at = EXCLUDED.ticked_at, relayed_total = es_outbox_relay.relayed_total + EXCLUDED.relayed_total',
            ['relay' => self::RELAY, 'relayed' => $relayed],
            ['relayed' => ParameterType::INTEGER],
        );
    }

    /**
     * How many rows are still `pending`, whatever the reason this drain did not take them.
     *
     * The batch signal answers one question only, whether the cap cut the run short, and a short batch
     * is not the same fact as an empty table: a row backing off from an earlier run is not due, so no
     * claim takes it and no full batch reports it, and the deployment gate that loops until a run
     * relays 0 reads that silence as a drained queue. Rows another worker holds under `SKIP LOCKED`
     * count here too: to the operator asking whether the outbox is empty, held and cooling are the same
     * answer.
     *
     * It runs outside the drain's transaction as a plain count: it claims no row and holds no lock, so
     * it cannot withhold work from another worker the way an over-read of the claim would.
     *
     * @throws Exception on a DBAL failure of the count
     */
    public function countPending(): int
    {
        return (int) $this->connection->fetchOne(
            /* language=PostgreSQL */
            "SELECT count(*) FROM es_outbox WHERE status = 'pending'",
        );
    }

    /**
     * The claim: the `batch` oldest pending rows that are due, in global id order.
     *
     * The rows are row-locked with `SKIP LOCKED` so parallel relays do not collide, and the id order
     * keeps per-partition insertion order. The due-filter is what makes the batch fair. Its cost,
     * measured in shape rather than numbers: `next_attempt_at` is not in
     * `es_outbox_pending_idx (id) WHERE status='pending'`, so the scan pays a heap fetch per row it
     * skips. In the steady state nothing cools, the first `batch` entries pass, and that fetch is one
     * the query owed anyway to return the payload; the cost only appears with a cooling backlog, where
     * it buys the fairness it pays for.
     *
     * The filter is not pre-optimized into the index: an `INCLUDE` column serves index-only scans, not
     * the filter of an ordinary index scan, so the fix would mean a key column and a different scan
     * order. Measure the tick before touching the schema. The filter's blind spot, a frozen
     * partition's due tail passing it every tick, is closed by `realignRefused()`.
     *
     * @return list<OutboxRow>
     *
     * @throws Exception on a DBAL failure of the claim
     */
    private function claimDueRows(Connection $connection, int $batch): array
    {
        $rows = $connection->fetchAllAssociative(
            /* language=PostgreSQL */
            <<<'SQL'
                SELECT o.id, o.position, o.partition_key, o.partition_key AS stream, o.type, o.event_version, o.attempts,
                       e.header, e.content, CAST(e.sequence_no IS NULL AS integer) AS vanished
                FROM es_outbox o
                LEFT JOIN event_store e ON e.category = split_part(o.partition_key, '-', 1) AND e.sequence_no = o.position
                WHERE o.status = 'pending'
                  AND (o.next_attempt_at IS NULL OR o.next_attempt_at <= clock_timestamp())
                ORDER BY o.id
                LIMIT :batch
                FOR UPDATE OF o SKIP LOCKED
                SQL,
            ['batch' => $batch],
            ['batch' => ParameterType::INTEGER],
        );

        return array_map(OutboxRow::of(...), $rows);
    }

    /**
     * Claim the tick's partitions with transaction-scoped advisory locks, one statement for all of
     * them, in the order given, which is the order the drain walks. A `try` lock never waits, so
     * taking many in one statement cannot deadlock; a partition another relay owns this tick reads
     * false and is skipped, its rows left for that relay or the next tick.
     *
     * @param  list<string>  $partitions
     * @return array<string, bool> owned, keyed by partition
     *
     * @throws Exception on a DBAL failure of the lock attempt
     * @throws JsonException when a partition key cannot be encoded
     */
    private function ownPartitions(Connection $connection, array $partitions): array
    {
        if ($partitions === []) {
            return [];
        }

        $rows = $connection->fetchAllKeyValue(
            /* language=PostgreSQL */
            'SELECT p.partition_key, pg_try_advisory_xact_lock(hashtextextended(p.partition_key, 0))
             FROM jsonb_array_elements_text(CAST(:partitions AS jsonb)) WITH ORDINALITY AS p(partition_key, n)
             ORDER BY p.n',
            ['partitions' => json_encode($partitions, JSON_THROW_ON_ERROR)],
        );

        return array_map(boolval(...), $rows);
    }

    /**
     * One probe, three answers: owning a partition is neither owning its head nor seeing its holes.
     *
     * Either gap would put a later version on the wire first. Each partition's aggregates read pending
     * rows at or below its highest claimed id; a later suffix cannot block this window and is excluded
     * from the scan. An old or sparse pending prefix can still exceed the batch. The exact claim count
     * bounds the JSON relation's estimate without truncating its input, and two bound parameters serve
     * every batch size.
     *
     * - `head`: the claim is rows-first, since the `SELECT` row-locked a batch before any partition
     *   claim, so a sibling may hold the row lock on an older pending row of this very partition, which
     *   our claim's `SKIP LOCKED` skipped. A pending row older than the first one we hold belongs to
     *   someone else's batch, or to the next tick, and order defers to it.
     *
     * - `cooling_floor`: the lowest pending row in the prefix that is not due. The claim filters those
     *   out, so they are invisible to the loop, and that invisibility is the danger: a cooling row in the
     *   middle of a partition would let the rows behind it pass. The state is reachable: dead-letter a
     *   row, let a later one cool on a transient failure, then `storm:outbox:failed <id> --retry`
     *   requeues the first one due under the cooling one. Nothing may be published at or above this
     *   floor.
     *
     * - `due_in_window`: how many due pending rows sit at or below the highest id the claim handed us for
     *   this partition. The head answers "is there a hole before my first row"; this answers "is there a
     *   hole anywhere in my window", the hidden row being older than rows we hold but newer than our
     *   first. More due rows in the window than the claim holds means one is not ours, and publishing the
     *   window would jump over it.
     *
     * One statement serves every partition the tick owns, each answered by its own row; a partition with
     * no pending row in its prefix answers null extrema and a zero due count.
     *
     * @param  list<string>  $partitions  the owned partitions, in claim order
     * @return array<string, PartitionProbe> one probe per owned partition
     *
     * @throws Exception on a DBAL failure of the probe
     * @throws JsonException when a claim cannot be encoded
     */
    private function probePartitions(Connection $connection, ClaimSummary $claim, array $partitions): array
    {
        if ($partitions === []) {
            return [];
        }

        $claims = array_map(
            static fn (string $partition): array => ['partition_key' => $partition, 'max_claimed' => $claim->maxFor($partition)],
            $partitions,
        );

        $rows = $connection->fetchAllAssociative(
            /* language=PostgreSQL */
            <<<'SQL'
                SELECT c.partition_key, p.head, p.cooling_floor, p.due_in_window
                FROM (
                    SELECT *
                    FROM jsonb_to_recordset(CAST(:claims AS jsonb)) AS claims(partition_key text, max_claimed bigint)
                    LIMIT :claim_count
                ) AS c
                CROSS JOIN LATERAL (
                    SELECT min(o.id) AS head,
                           min(o.id) FILTER (WHERE o.next_attempt_at > clock_timestamp()) AS cooling_floor,
                           count(*) FILTER (WHERE o.next_attempt_at IS NULL OR o.next_attempt_at <= clock_timestamp()) AS due_in_window
                    FROM es_outbox o
                    WHERE o.partition_key = c.partition_key AND o.status = 'pending' AND o.id <= c.max_claimed
                ) AS p
                SQL,
            ['claims' => json_encode($claims, JSON_THROW_ON_ERROR), 'claim_count' => count($claims)],
            ['claim_count' => ParameterType::INTEGER],
        );

        $probes = [];
        foreach ($rows as $row) {
            $probes[(string) $row['partition_key']] = new PartitionProbe(
                $row['head'] === null ? null : (int) $row['head'],
                $row['cooling_floor'] === null ? null : (int) $row['cooling_floor'],
                (int) $row['due_in_window'],
            );
        }

        return $probes;
    }

    /**
     * Dispose of the batch's published rows in one statement, atomic with the publications.
     *
     * It runs inside the drain transaction: a plain `DELETE`, or the atomic move with
     * `DELETE … RETURNING` piped into the archive `INSERT`, so a row is always in exactly one table.
     * `archived_at` defaults to now, the publication instant. Failed, pending and blocked rows are
     * untouched by construction, since only successfully published ids reach this list.
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
            OutboxDisposal::Delete =>
                /* language=PostgreSQL */
                'DELETE FROM es_outbox WHERE id IN (:ids)',
            OutboxDisposal::Archive =>
                /* language=PostgreSQL */
                <<<'SQL'
                WITH moved AS (
                    DELETE FROM es_outbox WHERE id IN (:ids)
                    RETURNING id, position, partition_key, type, event_version, attempts, occurred_at
                )
                INSERT INTO es_outbox_archive (id, position, partition_key, type, event_version, header, content, attempts, occurred_at)
                SELECT m.id, m.position, m.partition_key, m.type, m.event_version,
                       COALESCE(e.header, '{}'::jsonb), COALESCE(e.content, '{}'::jsonb), m.attempts, m.occurred_at
                FROM moved m
                LEFT JOIN event_store e ON e.category = split_part(m.partition_key, '-', 1) AND e.sequence_no = m.position
                SQL,
        };

        $connection->executeStatement($sql, ['ids' => $publishedIds], ['ids' => ArrayParameterType::INTEGER]);
    }

    /**
     * Lazy cooling, the fairness backstop: realigns the rows refused behind a cold blocker onto it.
     *
     * A cold blocker is a head or an older row, cooling or stalled, with `next_attempt_at` in the
     * future; the rows refused behind it in this pass take its `next_attempt_at`, in one statement over
     * rows the claim already row-locked, so no new locks and no query outside the drain's own pass.
     * Without it, a frozen partition's due tail passes the due-filter on every tick, fills the claim
     * window, gets refused by the very guards that hold its order, and starves foreign partitions for
     * as long as the blocker cools, until a deployment for a stalled head. With it, a frozen partition
     * costs one wasted window per row, the next tick's claim excludes the whole tail, and the partition
     * wakes together with its blocker. Ordering is untouched: publication stays gated by the head check
     * and the cooling floor, and this only schedules the next look.
     *
     * The blocker is resolved inside the statement, per row, as the lowest still-cooling pending row
     * below it in its partition, rather than carried over from the probe: a blocker whose cooldown
     * lapsed mid-pass then resolves to the next cold row or to `NULL`, leaving the refused row due, the
     * safe direction, since an extra look costs a window and a lost one costs latency a deploy would
     * have to notice. Rows refused behind a hot blocker, one merely row-locked by a sibling relay, are
     * never in this list: that contention resolves by the next tick, and cooling a healthy partition
     * would trade a tick of delay for a whole cooldown.
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
            /* language=PostgreSQL */
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
     * Un-aborts the drain transaction after a failed publish.
     *
     * It rolls back to the pre-publish savepoint, one of the few statements Postgres allows in an aborted
     * transaction, then releases it so retries within the same drain do not stack shadowed savepoints.
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
            /* language=PostgreSQL */
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
     * Freeze a row this runtime cannot decode, keeping it `pending` and in its partition's order.
     *
     * It records why. `attempts` is not bumped, since it is the publish budget and nothing was
     * published; bumping it would dead-letter a whole incompatible event type within minutes of a bad
     * deploy. `next_attempt_at` is pushed by the stall cooldown, not the publish back-off: re-decoding
     * the row sooner cannot produce a different verdict, only load.
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
            /* language=PostgreSQL */
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
     * Seconds to wait before the next attempt: exponential, capped, then jittered.
     *
     * The wait is `base * 2^(attempts - 1)` capped, so the `maxAttempts` budget spans a real wall-clock
     * window rather than burning on every drain tick, then equal-jittered to a random point in the top
     * half of that window, the same shape as {@see RetryEventStore::pause()} and
     * {@see \Storm\Story\Middleware\RecoverConcurrencyConflict::retryDelay()}. A broker outage fails
     * every in-flight row at close to the same instant; without jitter every one of them computes the
     * same `next_attempt_at` and the relay re-floods the broker the moment it recovers, a herd this
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

        // cap the float, then cast: a large attempt count overflows 2^(n-1) past what an int
        // represents, and casting first would collapse the capped exponential to the one-second floor
        $window = max(1, (int) min($this->backoffMaxSeconds, $seconds));

        // the port draws inside the range it is asked for, and this range never starts below one
        /** @var int<1, max> $seconds */
        $seconds = $this->jitter->between(max(1, intdiv($window, 2)), $window);

        return $seconds;
    }

    // fail-open, as every observation around a commit: a logger that throws must not undo the back-off
    private function logPublishFailure(string $partition, int $attempts, Throwable $failure): void
    {
        if ($this->logger === null) {
            return;
        }

        try {
            $this->logger->warning('storm.outbox.publish_failed', [
                'relay' => self::RELAY,
                'partition' => $partition,
                'attempts' => $attempts,
                'max_attempts' => $this->maxAttempts,
                'error' => AuditDigest::digest($failure),
            ]);
        } catch (Throwable) {
            // the observation may lose its line; it may never cost the relay its progress
        }
    }
}
