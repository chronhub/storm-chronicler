<?php

declare(strict_types=1);

namespace Storm\Chronicler\Console;

use Doctrine\DBAL\Exception;
use Override;
use Storm\Chronicler\Outbox\DbalOutboxWriter;
use Storm\Support\Console\DurationOption;
use Storm\Support\Console\PositiveIntOption;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prunes the outbox archive, `es_outbox_archive` under `storm.outbox.disposal: archive`, by age.
 *
 * Rows older than `--before` go, by `archived_at` alone: the cold table's retention, a no-op under
 * `delete` disposal. The hot table needs no retention: a published row is disposed of at drain time, so
 * `es_outbox` only holds in-flight work plus the dead-letter, which `storm:outbox:failed` owns.
 *
 * The prune runs in `LIMIT`-capped deletes so it never holds a long lock, and is idempotent. It cannot
 * race the live relay, which only ever inserts into the archive through the atomic move.
 *
 * `--before <30d|48h|90m>` is required, so there is no accidental prune-all; `--dry-run` counts without
 * deleting; `--batch` caps each statement. Overlap prevention is the scheduler's job, backup the
 * operator's. The events themselves live in `event_store` and are never touched.
 *
 * Examples:
 *
 * ```bash
 * bin/console storm:outbox:prune --before 7d
 * bin/console storm:outbox:prune --before 7d --dry-run
 * ```
 */
#[AsCommand(name: 'storm:outbox:prune', description: 'Prune es_outbox_archive rows older than --before (storage retention of the delivery archive).')]
final class OutboxPruneCommand extends Command
{
    public function __construct(
        private readonly DbalOutboxWriter $outbox,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption('before', null, InputOption::VALUE_REQUIRED, 'Prune archived rows older than this (e.g. 7d, 48h) — required');
        $this->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Rows deleted per batch', '1000');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count what would be pruned, delete nothing');
    }

    /**
     * {@inheritDoc}
     *
     * @throws Exception on a DBAL failure of the count or the delete
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $duration = DurationOption::required($input, $io, 'before');
        if (is_int($duration)) {
            return $duration;
        }
        $age = $duration->seconds;

        $batch = PositiveIntOption::parse($input->getOption('batch'));
        if ($batch === null) {
            $io->error('--batch must be a positive integer (rows per delete batch), e.g. --batch=1000.');

            return Command::INVALID;
        }

        $dryRun = $input->getOption('dry-run') === true;

        $io->title(sprintf('Outbox prune — archived rows older than %s%s', $input->getOption('before'), $dryRun ? ' (DRY RUN)' : ''));

        if ($dryRun) {
            $io->success(sprintf(
                'Would prune %d archived row(s). Nothing deleted.',
                $this->outbox->countArchivedOlderThan($age),
            ));

            return Command::SUCCESS;
        }

        $io->success(sprintf('Pruned %d archived row(s).', $this->outbox->pruneArchive($age, $batch)));

        return Command::SUCCESS;
    }
}
