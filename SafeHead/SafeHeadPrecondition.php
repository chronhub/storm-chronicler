<?php

declare(strict_types=1);

namespace Storm\Chronicler\SafeHead;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;

/**
 * Reports whether the deployment holds the assumption the safe-head advancer rests on.
 *
 * {@see SafeHeadAdvancer} assumes no append transaction stays in flight longer than the grace: it steps
 * over a gap whose upper neighbor is older than the grace, presuming the missing `sequence_no` aborted.
 * A writer that reserves a position, outlives the grace and then commits is skipped by every filtered
 * catch-up already past it: permanent read-model incompleteness, not lag. A writer-side timeout must
 * make the presumption mechanically true, and a precondition nobody verifies is a wish.
 *
 * The check is asymmetric, and the asymmetry decides how a caller must use it:
 *
 * - It can prove the precondition false: `transaction_timeout = 0` means no writer is bounded, whatever
 *   the role or session.
 *
 * - It cannot prove it true. These are session or role settings: this connection's value is what this
 *   connection's writers get, strong evidence when the app appends through the same DSN, and no promise
 *   at all about a writer that connects differently.
 *
 * So it reports and never reassures: silence means "nothing observed", not "verified". For the same
 * reason nothing in Storm refuses to run on the strength of it; the last paragraph says what enforcing
 * it would cost.
 *
 * The check rejects `idle_in_transaction_session_timeout` as the guard. That setting ends a session
 * sitting idle inside a transaction and leaves a busy transaction alone, and a slow append, a lock wait
 * or a long handler on the write connection is busy. It narrows the window without closing it, so an
 * operator who set only that one is told so rather than left believing they are covered.
 *
 * Storm does not enforce the timeout itself. `SET LOCAL transaction_timeout` inside the append
 * transaction would make the presumption true from the writer side, scoped to that transaction, at
 * three costs:
 *
 * - One extra round trip on every append, on a hot path.
 *
 * - `transaction_timeout` terminates the session rather than canceling the statement, so an overrun
 *   kills a worker's connection.
 *
 * - Appends nest inside a caller's application-level transaction, where the setting would bound the
 *   whole outer unit of work, not just the append.
 *
 * It fits as an opt-in rather than a default. The connection-wide alternative,
 * `ALTER ROLE … SET transaction_timeout`, has the mirror problem: it bounds every transaction on that
 * connection, the projection batches and the installer's own DDL included, so it is sound only where
 * writers have a connection of their own.
 */
final readonly class SafeHeadPrecondition
{
    public function __construct(
        private Connection $connection,
        private float $graceSeconds = 5.0,
    ) {}

    /**
     * Everything this connection can see that contradicts the presumption, most serious first.
     *
     * @return list<string> human-readable findings; empty means nothing was observed, which is not a
     *                      certificate
     *
     * @throws Exception on a DBAL failure interrogating the settings or activity views
     */
    public function problems(): array
    {
        $problems = [];

        $transaction = $this->timeoutSeconds('transaction_timeout');
        $idle = $this->timeoutSeconds('idle_in_transaction_session_timeout');

        if ($transaction === 0.0) {
            $problems[] = sprintf(
                'transaction_timeout is 0 on this connection: nothing bounds an append below the %s s safe-head grace, so a writer can outlive its presumption of abort and commit under a projection that already passed it — a permanently skipped event.%s',
                $this->format($this->graceSeconds),
                $idle > 0.0
                    ? sprintf(' idle_in_transaction_session_timeout (%s s) does not cover it: it terminates a session sitting IDLE in a transaction, never one that is busy.', $this->format($idle))
                    : '',
            );
        } elseif ($transaction >= $this->graceSeconds) {
            $problems[] = sprintf(
                'transaction_timeout (%s s) is not below the %s s safe-head grace: it permits exactly the transactions the watermark already presumes dead. Set it under the grace, or raise storm.safe_head.grace_seconds above it.',
                $this->format($transaction),
                $this->format($this->graceSeconds),
            );
        }

        $oldest = $this->oldestInFlightSeconds();

        if ($oldest > $this->graceSeconds) {
            $problems[] = sprintf(
                'a transaction on this database has been open for %s s, past the %s s grace: if it holds a reserved sequence_no, the watermark has already stepped over it.',
                $this->format($oldest),
                $this->format($this->graceSeconds),
            );
        }

        return $problems;
    }

    /**
     * A timeout GUC in seconds.
     *
     * Read from `pg_settings`, whose `setting` is the raw value in the GUC's base unit, milliseconds
     * here; `current_setting()` returns a display string, '4s' or '250ms', that would have to be parsed
     * back.
     *
     * @throws Exception on a DBAL failure of the settings read
     */
    private function timeoutSeconds(string $name): float
    {
        $milliseconds = $this->connection->fetchOne(
            /* language=PostgreSQL */
            'SELECT setting FROM pg_settings WHERE name = :name',
            ['name' => $name],
        );

        // Absent, so a PostgreSQL without that GUC: unbounded, which is what 0 already means here.
        return $milliseconds === false ? 0.0 : (float) $milliseconds / 1000;
    }

    /**
     * Age of the longest transaction currently open on this database, this backend excluded.
     *
     * This backend is left out because the installer runs inside a transaction of its own.
     *
     * The reading is evidence, never reassurance: a non-superuser without `pg_read_all_stats` gets
     * `xact_start` nulled for other users' backends, so this can read 0 while a foreign writer sits
     * there. It can raise a finding but never retire one, which is why a zero is not reported at all.
     *
     * @throws Exception on a DBAL failure of the activity read
     */
    private function oldestInFlightSeconds(): float
    {
        return (float) $this->connection->fetchOne(
            /* language=PostgreSQL */
            "SELECT COALESCE(max(EXTRACT(EPOCH FROM (clock_timestamp() - xact_start))), 0)
             FROM pg_stat_activity
             WHERE datname = current_database()
               AND xact_start IS NOT NULL
               AND pid <> pg_backend_pid()
               AND backend_type = 'client backend'",
        );
    }

    private function format(float $seconds): string
    {
        return number_format($seconds, 3, '.', '')
                |> (static fn ($x) => rtrim($x, '0'))
                |> (static fn ($x) => rtrim($x, '.'));
    }
}
