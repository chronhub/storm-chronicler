<?php

declare(strict_types=1);

namespace Storm\Chronicler\SafeHead;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Storm\Chronicler\Record\SequencePosition;
use Storm\Chronicler\Schema\EventStoreSchema;
use Storm\Chronicler\Store\PostgresEventStore;
use Storm\Chronicler\Telemetry\NullSafeHeadObservability;
use Storm\Chronicler\Telemetry\SafeHeadCrossingContext;
use Storm\Chronicler\Telemetry\SafeHeadObservability;
use Storm\Contracts\Chronicler\Position;
use Throwable;

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
 * The grace test has no upper time bound. Normal appends omit `recorded_at`, leaving it to the
 * database default `clock_timestamp()`. A backward server clock step can make a neighbor appear
 * future-dated; explicit SQL outside the append contract can supply such a timestamp too. The gap
 * stays young until the neighbor has aged beyond the grace relative to the server clock. An upper
 * bound would exclude that neighbor and permit a skip over positions still in flight. `compute()`
 * and `advance()` share this conservative predicate.
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
final class SafeHeadAdvancer
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
    private static function watermarkCtes(int $floor, ?int $window = null): string
    {
        // The bound, when the caller sets one, closes BOTH the tail and the fallback: a tail read up
        // to floor + W with a fallback still reading the global head would step over a young gap
        // sitting above the window, for good. With the fallback capped at floor + W the watermark
        // never passes what the scan has seen, and a gap above the window is found by a later ratchet.
        $ceiling = $window === null ? '' : sprintf(' AND e.sequence_no <= %d', $floor + $window);
        // A gap is judged young by its UPPER neighbor, and a gap straddling the top of the window has
        // that neighbor above it. Without it the scan ends on the last row of the window, or on the
        // anchor alone when the window holds no row, sees no gap, and the fallback lifts the floor
        // over positions nothing examined. The first committed row above the window joins `tail`,
        // one index probe: a young straddling gap holds the watermark below it, an old one is
        // stepped over by the capped fallback.
        $beyond = $window === null ? '' : sprintf(
            ' UNION ALL (SELECT e.sequence_no, e.recorded_at FROM event_store e WHERE e.sequence_no > %d ORDER BY e.sequence_no LIMIT 1)',
            $floor + $window,
        );
        // `LEAST` ignores a NULL argument, so capping the head of an EMPTY store would answer the
        // cap itself: a watermark over no row, and a floor climbing one window per ratchet above the
        // positions the first events are about to take. The cap applies to a head that exists.
        $fallback = $window === null
            ? '(SELECT max(sequence_no) FROM event_store)'
            : sprintf('(SELECT LEAST(%d, head.top) FROM (SELECT max(sequence_no) AS top FROM event_store) head WHERE head.top IS NOT NULL)', $floor + $window);

        /* language=PostgreSQL */
        $template = <<<'SQL'
            tail AS (
                -- committed rows above the floor (an in-flight row is invisible, so its absence is the gap),
                -- plus a synthetic anchor at the floor so a gap right above the established region is seen
                SELECT e.sequence_no, e.recorded_at
                FROM event_store e
                WHERE e.sequence_no > %1$d%2$s
                UNION ALL
                SELECT %1$d, clock_timestamp()%4$s
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
                    (SELECT wall FROM young_gap), -- hold below the first young gap
                    %3$s                          -- none: safe to the committed head, or to the window's top
                ) AS watermark
            )
            SQL;

        return sprintf($template, $floor, $ceiling, $fallback, $beyond);
    }

    /**
     * The current ratcheted floor, 0 when never ratcheted or the store is empty, read on its own
     * query so it can be inlined into the gap scan as a planner-visible literal.
     *
     * Splitting the read from the scan opens a window the single-statement `floor_pos` CTE did not
     * have: a concurrent ratchet can lift the floor between this read and the scan. It is
     * correctness-safe on every path. The unbounded scan of `compute()` yields the same watermark
     * from any floor at or below it; a lower floor only lengthens the scanned tail, never changes
     * the result. The bounded scan of `ratchet()` yields a watermark that depends on the floor, at
     * most `RATCHET_WINDOW` above it: a lower floor gives a LOWER watermark, conservative, so a stale
     * floor never causes a skip there either, it costs one more ratchet. On the `tick()` path the
     * advisory-lock winner is the sole writer, so the floor cannot move at all. On the unlocked
     * `compute()` path, for waiters and one-shot runners, the floor can be up to one ratchet stale,
     * so the tail may be marginally longer; it self-heals on the next read.
     *
     * @throws Exception on a DBAL failure of the singleton read
     */
    private function currentFloor(): int
    {
        return (int) $this->connection->fetchOne(
            /* language=PostgreSQL */
            'SELECT COALESCE((SELECT position FROM event_store_high_water), 0)',
        );
    }

    /**
     * Advisory-lock key electing the single safe-head writer per catch-up cycle. Hashed into
     * Postgres's 64-bit advisory space by `hashtextextended`; a dedicated string keeps it distinct
     * from the saga fence and outbox partition keys that share that space.
     */
    private const string WRITER_LOCK_KEY = 'storm.safe_head.writer';

    /**
     * How many rows above the floor one ratchet reads at most. Every row of the tail costs a heap
     * block, `recorded_at` living in no index, so an unbounded ratchet under a flood paid the whole
     * distance floor to head on every tick, thirteen thousand blocks a call. The bound caps the
     * call; it also caps how far one ratchet can advance, so the floor keeps up with the writers
     * only while `RATCHET_WINDOW × ratchets per second` exceeds the append rate: the measured flood
     * appends 6 500 rows a second under 21 elected ratchets a second, and 2 000 leaves a sixfold
     * margin. A floor that stops keeping up shows as a growing lag of the catch-up watermark, never
     * as a skip. The pure read never bounds itself: it has no floor to advance and returns the head.
     */
    public const int RATCHET_WINDOW = 2000;

    private ?Closure $observation = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly float $graceSeconds = 5.0,
        private readonly SafeHeadObservability $obs = new NullSafeHeadObservability,
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
            /* language=PostgreSQL */
            'WITH '.self::watermarkCtes($this->currentFloor()).' SELECT watermark FROM w',
            ['grace' => $this->graceSeconds],
        );

        return $this->toPosition($value);
    }

    /**
     * Compute the safe head over at most `RATCHET_WINDOW` rows above the floor and ratchet the
     * persisted floor to it, returning the freshly computed head; a tail longer than the window is
     * climbed one window per ratchet. The upsert is conditional on `WHERE position < EXCLUDED.position`, so an idle cycle writes
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
        return $this->ratchet(false);
    }

    private function ratchet(bool $observe): ?Position
    {
        $floor = $this->currentFloor();
        // Crossed gap portions stay inside `]floor, watermark]`; a ratchet may cross several holes.
        // `prev_seq < watermark` excludes the young gap that holds the watermark at its lower edge
        // and every gap above it. Observation delivery still depends on movement and commit.
        $row = $this->connection->fetchAllAssociative(
            'WITH '.self::watermarkCtes($floor, self::RATCHET_WINDOW).', crossed AS ('
            .'SELECT prev_seq + 1 AS first, LEAST(sequence_no - 1, (SELECT watermark FROM w)) AS last FROM stepped '
            .'WHERE prev_seq IS NOT NULL AND sequence_no > prev_seq + 1 '
            .'AND prev_seq < (SELECT watermark FROM w)'
            .'), up AS ('
            .'INSERT INTO event_store_high_water (id, position) '
            .'SELECT 1, watermark FROM w WHERE watermark IS NOT NULL '
            .'ON CONFLICT (id) DO UPDATE SET position = EXCLUDED.position, updated_at = clock_timestamp() '
            .'WHERE event_store_high_water.position < EXCLUDED.position RETURNING position'
            .') SELECT watermark, EXISTS(SELECT 1 FROM up) AS moved, '
            .'(SELECT count(*) FROM crossed) AS gap_count, '
            .'(SELECT COALESCE(sum(last - first + 1), 0) FROM crossed) AS missing_positions, '
            ."(SELECT COALESCE(json_agg(json_build_object('from', first, 'to', last)), '[]'::json) "
            .'FROM (SELECT first, last FROM crossed ORDER BY first LIMIT 5) sample) AS ranges FROM w',
            ['grace' => $this->graceSeconds],
        )[0];
        if ($observe && $row['moved'] && (int) $row['gap_count'] > 0) {
            $context = new SafeHeadCrossingContext(
                $floor, (int) $row['watermark'], $this->graceSeconds,
                (int) $row['gap_count'], (int) $row['missing_positions'],
                json_decode((string) $row['ranges'], true, flags: JSON_THROW_ON_ERROR),
                (int) $row['gap_count'] > 5,
            );
            $this->observation = function () use ($context): void {
                if ($this->connection->isTransactionActive()) {
                    return;
                }
                try {
                    $this->obs->recordCrossing($context);
                } catch (Throwable) {
                }
            };
        }

        return $this->toPosition($row['watermark']);
    }

    /**
     * Transfers the notification to its batch attempt; callers must discard it on rollback.
     *
     * @return Closure(): void|null
     */
    public function takeObservation(): ?Closure
    {
        $observation = $this->observation;
        $this->observation = null;

        return $observation;
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
        $this->observation = null;
        if (! $this->connection->isTransactionActive()) {
            $position = $this->connection->transactional(fn (): ?Position => $this->winsWriterLock() ? $this->ratchet(true) : $this->read());
            $observation = $this->takeObservation();
            $observation?->__invoke();

            return $position;
        }

        return $this->winsWriterLock() ? $this->ratchet(true) : $this->read();
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
            /* language=PostgreSQL */
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
            /* language=PostgreSQL */
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
