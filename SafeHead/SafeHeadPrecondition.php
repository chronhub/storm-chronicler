<?php

declare(strict_types=1);

namespace Storm\Chronicler\SafeHead;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;

/**
 * Reports whether the deployment actually holds the assumption {@see SafeHeadAdvancer} rests on: that
 * no append transaction can stay in-flight longer than the grace.
 *
 * The advancer steps over a gap whose upper neighbor is older than the grace, presuming the missing
 * `sequence_no` was aborted. A writer that reserves a position, outlives the grace, then commits, is
 * skipped by every filtered catch-up already past it; permanent read-model incompleteness, not lag.
 * The presumption must be made mechanically true by a writer-side timeout, and a precondition nobody
 * verifies is a wish.
 *
 * What it can and cannot conclude, stated because the asymmetry decides how the caller must use it:
 *
 * - It can prove the precondition FALSE. `transaction_timeout = 0` means no writer is bounded, full
 *   stop, whatever the role or session.
 *
 * - It cannot prove it TRUE. These are session/role settings; this connection's value is what THIS
 *   connection's writers get, strong evidence when the app appends through the same DSN, and no
 *   promise at all about a writer connecting differently.
 *
 * So it reports and never reassures: silence here means "nothing observed", not "verified". For the
 * same reason nothing in Storm refuses to run on the strength of it; the scope note below says what
 * enforcing it would cost.
 *
 * `idle_in_transaction_session_timeout` is deliberately NOT accepted as the guard. It terminates a
 * session sitting IDLE inside a transaction; it does not touch a transaction that is busy, and a slow
 * append, a lock wait, a long handler on the write connection, is busy. It narrows the window, it
 * does not close it, so an operator who set only that one is told exactly that rather than left
 * believing they are covered.
 *
 * Deliberately NOT enforced by Storm itself, weighed and rejected for now:
 * `SET LOCAL transaction_timeout` inside the append transaction would make the presumption true from
 * the writer side, scoped exactly to the transaction it is about. Three costs stopped it: it is one
 * extra round trip on every append, on a hot path this project benches hard; `transaction_timeout`
 * TERMINATES the session rather than canceling the statement, so an overrun kills a worker's
 * connection; and appends nest inside a caller's application-level transaction, where the setting
 * would bound that whole outer unit of work, not just the append. Worth revisiting as an opt-in, not
 * worth smuggling in as a default. The connection-wide alternative, `ALTER ROLE … SET
 * transaction_timeout`, has the mirror problem: it bounds EVERY transaction on that connection, the
 * projection batches and the installer's own DDL included, so it is only sound where writers have a
 * connection of their own.
 */
final readonly class SafeHeadPrecondition
{
    public function __construct(
        private Connection $connection,
        private float $graceSeconds = 5.0,
    ) {}

    /**
     * Everything this connection can see that contradicts the presumption, most load-bearing first.
     *
     * @return list<string> human-readable findings; empty means nothing was observed, which is not a
     *                      certificate
     *
     * @throws Exception on a DBAL failure interrogating the settings / activity views
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
     * A timeout GUC in seconds. Read from `pg_settings`, whose `setting` is the raw value in the
     * GUC's base unit, milliseconds here; `current_setting()` returns a display string, '4s' or
     * '250ms', that would have to be parsed back.
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
     * Age of the longest transaction currently open on this database, this backend excluded since the
     * installer runs inside one of its own.
     *
     * Evidence, never reassurance: a non-superuser without `pg_read_all_stats` gets `xact_start`
     * nulled for other users' backends, so this can read 0 while a foreign writer sits there. It can
     * therefore raise a finding but never retire one, which is why a zero is not reported at all.
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
