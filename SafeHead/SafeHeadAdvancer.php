<?php

declare(strict_types=1);

namespace Storm\Chronicler\SafeHead;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Storm\Chronicler\Record\SequencePosition;
use Storm\Chronicler\Schema\EventStoreSchema;
use Storm\Chronicler\Store\PostgresEventStore;
use Storm\Contracts\Chronicler\Position;

/**
 * Computes the commit-order safe head, the highest position a filtered catch-up reader may pass
 * without risking a skip, and ratchets a persisted floor so the computation stays cheap.
 *
 * `sequence_no` is reserved at INSERT, but transactions commit out of order, so the head can show a
 * high position while a lower one is still in-flight. Instead of a snapshot-horizon watermark, which
 * missed invisible in-flight rows and skipped them, this scans the actual `sequence_no` gaps and
 * stops below the first young one: a gap is either an abort, stepped over, or an in-flight insert,
 * waited on, told apart by a grace window on the upper neighbor's `recorded_at`, stamped by the same
 * INSERT. The result is the lower edge of the lowest young gap, else the committed head.
 *
 * The scan is correct from any floor; `advance()` ratchets `event_store_high_water` forward,
 * monotone, only to keep the tail short, while `compute()` never writes, so the read stays pure and
 * always-correct even with no ratchet. Under N daemons `tick()` elects ONE writer per cycle via an
 * advisory lock: the winner scans and ratchets, the rest read the floor, which is conservative, at
 * or below the true head, so a reader never skips.
 *
 * Accepted residual, and the reason this is a grace-bounded watermark rather than an exact one: a
 * transaction that reserves a `sequence_no` then stays in-flight past the grace is presumed aborted
 * and stepped over, then commits below the cursor and is skipped, permanently and silently. Storm
 * appends commit in milliseconds, and four measured runs, chaos included, saw zero holes and zero
 * grace stall, so the residual does not bite at any load tested here; the grace, wired from
 * `storm.safe_head.grace_seconds`, is the knob.
 *
 * Making the presumption mechanically true takes a writer-side `transaction_timeout` strictly under
 * the grace, so no append can outlive its own presumption of abort. NOT
 * `idle_in_transaction_session_timeout`: that one terminates a session sitting IDLE inside a
 * transaction and does nothing about one that is busy, which a slow append is; it narrows the window
 * without closing it. Both are session/role settings PostgreSQL cannot be made to carry from here,
 * and `transaction_timeout` is connection-wide, so a value under the grace also bounds the projection
 * batches and the installer's DDL riding that connection: a deployment decision, not a boot guard.
 * {@see SafeHeadPrecondition} is what reports whether the deployment took it, and `storm:install`
 * surfaces that reading, so nobody learns it from a read-model that quietly lost an event.
 *
 * @see PostgresEventStore::safeHeadPosition()
 * @see EventStoreSchema  the `sequence_no` index this scan rides and the `event_store_high_water` cursor
 */
final readonly class SafeHeadAdvancer
{
    /**
     * The `WITH` body shared by compute and advance: derives `w.watermark` from the live gap scan
     * above `$floor`. `:grace` is the number of seconds an unfilled gap is treated as possibly
     * in-flight before being presumed aborted.
     *
     * The floor is inlined as a literal, not read via a `floor_pos` CTE, nor bound as a `:floor`
     * parameter. Both hide the value from the planner, which then falls back to the default `>`
     * selectivity, a third of the table, and estimates a huge `tail`: the cost crosses
     * `jit_above_cost`, so JIT compiles the query every cycle for what is, in a steady state,
     * near-zero work. A literal lets the planner read the histogram, estimate the real tiny tail, and
     * skip JIT, while still enabling it on a genuinely large backlog, where it earns its keep. A bound
     * parameter is worse than useless here: it estimates right under a custom plan but reverts to the
     * bad estimate once the statement goes generic after a few executions. `$floor` is the trusted
     * `event_store_high_water.position`, never user input.
     */
    private static function watermarkCtes(int $floor): string
    {
        /** @lang PostgreSQL */
        $template = <<<'SQL'
            tail AS (
                -- committed rows above the floor (an in-flight row is invisible, so its absence is the gap),
                -- plus a synthetic anchor at the floor so a gap right above the established region is seen
                SELECT e.sequence_no, e.recorded_at
                FROM event_store e
                WHERE e.sequence_no > %1$d
                UNION ALL
                SELECT %1$d, clock_timestamp()
            ),
            stepped AS (
                SELECT sequence_no, recorded_at,
                       lag(sequence_no) OVER (ORDER BY sequence_no) AS prev_seq
                FROM tail
            ),
            young_gap AS (
                -- lower edge of the lowest gap whose upper neighbor is still within the grace window
                SELECT min(prev_seq) AS wall
                FROM stepped
                WHERE prev_seq IS NOT NULL
                  AND sequence_no > prev_seq + 1
                  AND recorded_at >= clock_timestamp() - (CAST(:grace AS double precision) * interval '1 second')
            ),
            w AS (
                SELECT COALESCE(
                    (SELECT wall FROM young_gap),              -- hold below the first young gap
                    (SELECT max(sequence_no) FROM event_store) -- none: safe to the committed head
                ) AS watermark
            )
            SQL;

        return sprintf($template, $floor);
    }

    /**
     * The current ratcheted floor, 0 when never ratcheted or the store is empty, read on its own
     * query so it can be inlined into the gap scan as a planner-visible literal.
     *
     * Splitting the read from the scan opens a window the single-statement `floor_pos` CTE did not
     * have: a concurrent ratchet can lift the floor between this read and the scan. It is
     * correctness-safe on every path, since the scan yields the same watermark from any floor at or
     * below it; a lower floor only lengthens the scanned tail, never changes the result, so a stale
     * floor never causes a skip. On the `tick()` path the advisory-lock winner is the sole writer, so
     * the floor cannot move at all, and the tail is exactly minimal. On the unlocked `compute()` path,
     * for waiters and one-shot runners, the floor can be up to one ratchet stale, so the tail may be
     * marginally longer, still far below the JIT cost crossover, so the no-JIT win holds; it self-heals
     * on the next read. The worst case is a slightly longer scan, not a skip.
     *
     * @throws Exception on a DBAL failure of the singleton read
     */
    private function currentFloor(): int
    {
        return (int) $this->connection->fetchOne(
            /** @lang PostgreSQL */
            'SELECT COALESCE((SELECT position FROM event_store_high_water), 0)',
        );
    }

    /**
     * Advisory-lock key electing the single safe-head writer per catch-up cycle. Hashed into
     * Postgres's 64-bit advisory space by `hashtextextended`; a dedicated string keeps it distinct
     * from the saga fence and outbox partition keys that share that space.
     */
    private const string WRITER_LOCK_KEY = 'storm.safe_head.writer';

    public function __construct(
        private Connection $connection,
        private float $graceSeconds = 5.0,
    ) {}

    /**
     * The safe head right now: a pure read recomputed from the live store, never writing. Correct
     * regardless of the ratcheted floor.
     *
     * @throws Exception on a DBAL failure of the gap scan
     */
    public function compute(): ?Position
    {
        $value = $this->connection->fetchOne(
            /** @lang PostgreSQL */
            'WITH '.self::watermarkCtes($this->currentFloor()).' SELECT watermark FROM w',
            ['grace' => $this->graceSeconds],
        );

        return $this->toPosition($value);
    }

    /**
     * Compute the safe head and ratchet the persisted floor to it, returning the freshly computed
     * head. The upsert is conditional on `WHERE position < EXCLUDED.position`, so an idle cycle writes
     * nothing, no hot-row MVCC churn on the singleton; it is monotone, and a NULL watermark on an empty
     * store leaves the floor untouched. Runs inside the caller's batch transaction where one is
     * active on this connection, a rollback then undoing the ratchet; under a read-model store
     * split it runs in `tick()`'s own short transaction and survives a batch rollback, harmless
     * either way, since the floor is conservative and monotone, an optimization, never a
     * correctness input.
     *
     * @throws Exception on a DBAL failure of the gap scan / ratchet
     */
    public function advance(): ?Position
    {
        $value = $this->connection->fetchOne(
            /** @lang PostgreSQL */
            'WITH '.self::watermarkCtes($this->currentFloor()).', up AS ('
            .'INSERT INTO event_store_high_water (id, position) '
            .'SELECT 1, watermark FROM w WHERE watermark IS NOT NULL '
            .'ON CONFLICT (id) DO UPDATE SET '
            .'position = EXCLUDED.position, '
            .'updated_at = clock_timestamp() '
            .'WHERE event_store_high_water.position < EXCLUDED.position '
            .') SELECT watermark FROM w',
            ['grace' => $this->graceSeconds],
        );

        return $this->toPosition($value);
    }

    /**
     * One catch-up cycle's safe head with at most one daemon scanning. A transaction-scoped advisory
     * lock, `pg_try_advisory_xact_lock`, non-blocking, elects the writer: the winner runs `advance()`
     * to scan and ratchet, every other daemon runs `read()` for the O(1) floor with no scan,
     * collapsing both the N-way tuple lock on the singleton AND the N-way gap scan onto a single daemon
     * per cycle. The winner holds the lock until the caller's batch transaction settles, then the next
     * cycle re-elects. A loser never waits; it reads the conservative floor, at or below the true head
     * so it cannot skip, and is at most one cycle behind. Called once per catch-up cycle in place of a
     * bare `advance()`.
     *
     * @throws Exception on a DBAL failure of the lock probe / gap scan / floor read
     */
    public function tick(): ?Position
    {
        // Under a read-model store split the batch transaction lives on the OTHER connection and
        // this one sits in autocommit: the transaction-scoped advisory lock would acquire and
        // release inside its own statement, every daemon would win the election every cycle, and
        // the N-way scan contention the election exists to collapse would come back. A dedicated
        // short transaction restores the exclusion for the scan-and-ratchet; in the un-split
        // wiring a transaction is already active and this branch never runs, the lock holding to
        // the batch commit as before.
        if (! $this->connection->isTransactionActive()) {
            return $this->connection->transactional(fn (): ?Position => $this->winsWriterLock() ? $this->advance() : $this->read());
        }

        return $this->winsWriterLock() ? $this->advance() : $this->read();
    }

    /**
     * The last ratcheted floor: an O(1) singleton read with no gap scan, the watermark every
     * non-writer daemon consumes each cycle via `tick()`. Conservative, at or below the true safe head
     * so never a skip, at most one writer-cycle behind, and NULL until the first ratchet, since it
     * reflects the floor, not the live store. A fresh per-daemon scan stays available via `compute()`.
     *
     * @throws Exception on a DBAL failure of the singleton read
     */
    public function read(): ?Position
    {
        $value = $this->connection->fetchOne(
            /** @lang PostgreSQL */
            'SELECT position FROM event_store_high_water WHERE id = 1',
        );

        return $this->toPosition($value);
    }

    /**
     * True for the at-most-one daemon that may ratchet this cycle. `pg_try_advisory_xact_lock` is
     * non-blocking, so a loser returns false immediately with no wait, and transaction-scoped,
     * released when the caller's batch transaction commits or rolls back, including a deadlock-retry
     * rollback, after which the next attempt simply re-elects.
     *
     * @throws Exception on a DBAL failure of the lock probe
     */
    private function winsWriterLock(): bool
    {
        return (bool) $this->connection->fetchOne(
            /** @lang PostgreSQL */
            'SELECT pg_try_advisory_xact_lock(hashtextextended(:key, 0))',
            ['key' => self::WRITER_LOCK_KEY],
        );
    }

    private function toPosition(mixed $value): ?Position
    {
        if ($value === false || $value === null) {
            return null;
        }

        $position = (int) $value;

        return $position > 0 ? SequencePosition::fromInt($position) : null;
    }
}
