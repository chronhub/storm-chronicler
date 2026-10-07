<?php

declare(strict_types=1);

namespace Storm\Chronicler\Console;

use Closure;
use Override;
use Storm\Chronicler\Outbox\OutboxDrainIncomplete;
use Storm\Chronicler\Outbox\OutboxDrainResult;
use Storm\Chronicler\Outbox\OutboxRelay;
use Storm\Support\Console\DaemonLoop;
use Storm\Support\Console\PositiveIntOption;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Drains the event-store outbox: publishes pending messages, then disposes of the rows, deleted or
 * moved to es_outbox_archive per `storm.outbox.disposal`. Stays transport-neutral: it only knows
 * OutboxRelay; the actual publishing target, the event bus, is the relay's injected `OutboxPublisher`.
 *
 * One-shot by default, draining once then exiting so a scheduler re-runs it; `--daemon` keeps it up
 * and loops the drain in-process, so the framework bootstrap is paid once, not per tick, via
 * {@see \Storm\Support\Console\DaemonLoop}.
 *
 * Examples:
 *
 * ```bash
 * bin/console storm:outbox:relay
 * bin/console storm:outbox:relay --batch=500
 * bin/console storm:outbox:relay --daemon --sleep=800 --time-limit=3600
 * ```
 */
#[AsCommand(
    name: 'storm:outbox:relay',
    description: 'Drain the event-store outbox: publish pending messages, then dispose of the rows (delete or archive).',
)]
final class RelayOutboxCommand extends Command
{
    use DaemonLoop;

    public function __construct(
        private readonly OutboxRelay $relay,
        /** Runs after a worker iteration; callback failures do not change delivery outcomes. */
        private readonly ?Closure $afterIteration = null,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Max messages to drain in one run', '100');
        $this->configureDaemon(800);
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $batch = PositiveIntOption::parse($input->getOption('batch'));
        if ($batch === null) {
            $io->error('--batch must be a positive integer (max messages per drain), e.g. --batch=500.');

            return Command::INVALID;
        }

        if ((bool) $input->getOption('daemon')) {
            return $this->daemon($io, $input, $batch);
        }

        try {
            try {
                $result = $this->relay->drain($batch);
            } finally {
                $this->drainTelemetry();
            }
        } catch (OutboxDrainIncomplete $e) {
            // A transient publishing failure: the relay fails FORWARD; what it reports here is
            // committed, namely published rows, dead-letters, the bumped back-off; the tail stays
            // pending. Show the durable progress, then signal the scheduler to retry.
            //
            // Including the stall, which a drain can carry alongside a transient failure: they happen
            // on different partitions and neither cancels the other. Reporting only the broker outage
            // would send the operator to fix the broker and call it done, while a partition stays
            // frozen on a deployment fault that no retry can clear.
            $io->error(sprintf(
                'Relayed %d message(s), %d dead-lettered%s, then stopped on a publish error: %s',
                $e->progress->published,
                $e->progress->failed,
                $this->stallClause($e->progress),
                $e->getPrevious()?->getMessage() ?? $e->getMessage(),
            ));

            return Command::FAILURE;
        } catch (Throwable $e) {
            // The drain's own statements failed; nothing to report as progress, rolled back.
            $io->error(sprintf('Outbox relay failed: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        if ($result->stalled > 0) {
            // A stalled row is the one outcome that does NOT resolve itself: the rows are intact and
            // still `pending`, but their partitions stay frozen until a deployment ships the missing
            // upcaster or rolls this binary forward. Reporting SUCCESS here, as a dead-letter does,
            // would tell a scheduler the egress is fine while an aggregate's is stopped, so it exits
            // non-zero: the alert must keep firing until someone deploys.
            $io->error(sprintf(
                'Relayed %d outbox message(s), %d dead-lettered%s.',
                $result->published,
                $result->failed,
                $this->stallClause($result),
            ));

            return Command::FAILURE;
        }

        if ($result->failed > 0) {
            $io->warning(sprintf(
                'Relayed %d outbox message(s); %d dead-lettered (status=failed).',
                $result->published,
                $result->failed,
            ));
        } else {
            // the phrase is kept intact: the runbook and its tests read it
            $io->success(sprintf('Relayed %d outbox message(s).', $result->published));
        }

        $this->reportWhatIsLeft($io, $result->moreDue);

        return Command::SUCCESS;
    }

    /**
     * What the drain leaves behind, on EVERY path that succeeds.
     *
     * Every succeeding path reaches it, the dead-letter one included: a bad deployment that
     * dead-letters a full batch relays 0, and a gate that loops until a run reports 0 would read that
     * as an empty queue, counting what LEFT and never what remains.
     *
     * The two halves answer different questions. A full batch means the cap cut the run, so re-running
     * moves more immediately. A short batch means only that the cap did not cut it, and a table can
     * still hold rows no claim would take: backing off from an earlier run, or held by another worker.
     * Those need waiting, not looping, so the line says so rather than inviting a hot loop.
     */
    private function reportWhatIsLeft(SymfonyStyle $io, bool $moreDue): void
    {
        if ($moreDue) {
            $io->warning('The batch was full, so more messages are pending — re-run until this reports 0.');

            return;
        }

        try {
            $pending = $this->relay->countPending();
        } catch (Throwable $e) {
            // the drain itself succeeded and is committed; a failed count must not restate that as an
            // error, it must only stop claiming the queue is empty
            $io->warning(sprintf('Could not read what is left pending, so the outbox is NOT proven drained: %s', $e->getMessage()));

            return;
        }

        if ($pending > 0) {
            $io->warning(sprintf(
                'The batch was not full, yet %d message(s) are still pending: backing off after a failed attempt, or held by another worker. The outbox is NOT drained; wait out the back-off and re-run.',
                $pending,
            ));
        }
    }

    /**
     * The stall fragment of a drain report, empty when nothing stalled, so one sentence serves the
     * clean outcome and the interrupted one. Naming the cause and the frozen partitions is the
     * point: a count that says neither WHICH schema step is missing nor WHOSE egress stopped tells
     * an operator nothing they can act on without querying `es_outbox`.
     */
    private function stallClause(OutboxDrainResult $result): string
    {
        return $result->stalled === 0 ? '' : sprintf(
            '; %d stalled on a runtime incompatibility (rows kept pending, no attempt burnt): %s [frozen: %s]',
            $result->stalled,
            $result->stalledCause ?? 'see es_outbox.last_error',
            $this->frozenPartitions($result->stalledPartitions),
        );
    }

    /**
     * The frozen partition names, capped at five so the alert stays one line; the full set stays
     * recoverable from `es_outbox` itself.
     *
     * @param  list<string>  $partitions
     */
    private function frozenPartitions(array $partitions): string
    {
        $named = array_slice($partitions, 0, 5);
        $more = count($partitions) - count($named);

        return implode(', ', $named).($more > 0 ? sprintf(' and %d more', $more) : '');
    }

    /**
     * Daemon mode: boot once, loop the drain in-process until SIGTERM/SIGINT or `--time-limit`. An
     * {@see OutboxDrainIncomplete}, a transient publish failure, is NOT fatal here: its progress is
     * committed and the relay's back-off is bumped, so the loop counts it as idle and polls before
     * retrying the tail. Any other Throwable propagates, so the process exits non-zero and the
     * supervisor respawns with back-off.
     *
     * A stall, a row this runtime cannot decode, is not fatal either, since the other partitions must
     * keep draining, but it is the one condition that never clears on its own: it is warned about on
     * the tick it happens and makes the daemon's final exit non-zero, so a supervisor's restart log
     * carries the incident instead of a clean stop.
     *
     * @param  int<1, max>  $batch  positive, since execute() guards it before the daemon branch
     */
    private function daemon(SymfonyStyle $io, InputInterface $input, int $batch): int
    {
        $published = 0;
        $failed = 0;
        $stalled = 0;

        $this->daemonLoop(function () use ($io, $batch, &$published, &$failed, &$stalled): int {
            try {
                try {
                    $result = $this->relay->drain($batch);
                } finally {
                    $this->drainTelemetry();
                }
                // Stalled rows are NOT work: nothing moved, and counting them as a busy tick would
                // spin the loop instead of polling.
                $work = $result->published + $result->failed;
            } catch (OutboxDrainIncomplete $e) {
                // The progress is committed and the back-off bumped; treat the tick as idle so the
                // poll honors it. Its counts are accounted exactly like a clean drain's, the stall
                // included: a drain can carry both, on different partitions, and dropping the stall
                // here would hide the one that no retry clears behind the one that heals itself.
                $result = $e->progress;
                $work = 0;
            }

            $published += $result->published;
            $failed += $result->failed;
            $stalled += $result->stalled;

            if ($result->stalled > 0) {
                // Say it on the tick, not only at exit: a daemon that logs a frozen partition an hour
                // later, when its time-limit recycles it, has kept the one condition needing a human
                // to itself for that hour. The relay's stall cooldown bounds the repetition, so this
                // fires about once per cooldown per stuck partition, which is the alert cadence.
                $io->warning(sprintf(
                    '%d outbox row(s) stalled on a runtime incompatibility — kept pending, egress frozen for %s until a deployment fixes it: %s',
                    $result->stalled,
                    $this->frozenPartitions($result->stalledPartitions),
                    $result->stalledCause ?? 'see es_outbox.last_error',
                ));
            }

            return $work;
        }, $this->daemonSleepMs($input), $this->daemonTimeLimit($input));

        if ($stalled > 0) {
            $io->error(sprintf(
                'Outbox relay daemon stopped — relayed %d message(s), %d dead-lettered, %d stall(s) on a runtime incompatibility.',
                $published,
                $failed,
                $stalled,
            ));

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Outbox relay daemon stopped — relayed %d message(s), %d dead-lettered.',
            $published,
            $failed,
        ));

        return Command::SUCCESS;
    }

    private function drainTelemetry(): void
    {
        try {
            ($this->afterIteration)?->__invoke();
        } catch (Throwable) {
        }
    }
}
