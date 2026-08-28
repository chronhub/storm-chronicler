<?php

declare(strict_types=1);

namespace Storm\Chronicler\Console;

use Doctrine\DBAL\Exception;
use Override;
use Storm\Chronicler\Outbox\DbalOutboxWriter;
use Storm\Clock\Duration;
use Storm\Support\Console\PositiveIntOption;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prunes the outbox archive, `es_outbox_archive` under `storm.outbox.disposal: archive`, older than
 * --before, purely by `archived_at` age; the cold table's retention, a no-op under `delete` disposal.
 * The HOT table needs no retention: a published row is disposed of at drain time, so `es_outbox` only
 * holds in-flight work plus the dead-letter, which `storm:outbox:failed` owns.
 *
 * Batched: LIMIT-capped DELETEs so it never holds a long lock. Cannot race the live relay, which only
 * ever INSERTs into the archive via the atomic move. Idempotent.
 *
 * --before <30d|48h|90m> is required, so there is no accidental prune-all; --dry-run counts without
 * deleting; --batch caps each statement. Overlap prevention is the scheduler's job, backup the
 * operator's. The events themselves live in event_store and are never touched.
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
     * @throws Exception on a DBAL failure of the count / delete
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $before = $input->getOption('before');
        $duration = Duration::fromString(is_string($before) ? $before : '');
        if ($duration === null) {
            $io->error('Specify --before with an age, e.g. --before 7d (suffixes: d=days, h=hours, m=minutes).');

            return Command::INVALID;
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
