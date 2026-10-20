<?php

declare(strict_types=1);

namespace Storm\Chronicler\Console;

use Doctrine\DBAL\Exception;
use Override;
use Storm\Chronicler\Exception\InvalidInboxRetention;
use Storm\Chronicler\Inbox\DbalInboxStore;
use Storm\Support\Console\DurationOption;
use Storm\Support\Console\PositiveIntOption;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prunes the `es_inbox` deduplication records older than `--before`, the retention of that window.
 *
 * Each row marks a `(consumer, message-id)` already processed; its value expires once redelivery is no
 * longer possible, past the broker's retry or visibility horizon, so old rows are safe to drop.
 *
 * The prune runs in `LIMIT`-capped statements so it never holds a long lock, and is idempotent. A v7 key
 * carries its minting instant, so its age is read from the key and the rows go by a range of the primary
 * index; any other key goes by `processed_at`. The age therefore counts from the minting of the
 * message, not its consumption: a message consumed after a queue lag retires that lag earlier than
 * `processed_at` says. Pick `--before` beyond your broker's maximum redelivery window plus the longest
 * queue lag you accept.
 *
 * `--before <7d|48h|90m>` is required, so there is no accidental prune-all; `--dry-run` counts without
 * deleting; `--batch` caps each statement. A retention cutoff before the Unix epoch is rejected before
 * counting or deleting records. The configured request idempotency window is also a minimum, and
 * deployments must add their queue and redelivery horizons to it.
 *
 * Examples:
 *
 * ```bash
 * bin/console storm:inbox:prune --before 7d
 * bin/console storm:inbox:prune --before 7d --dry-run
 * ```
 */
#[AsCommand(name: 'storm:inbox:prune', description: 'Prune es_inbox dedup records older than --before (consumer-dedup retention).')]
final class InboxPruneCommand extends Command
{
    public function __construct(
        private readonly DbalInboxStore $inbox,
        private readonly int $idempotencyLifetimeSeconds = 86400,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption('before', null, InputOption::VALUE_REQUIRED, 'Prune dedup records older than this (e.g. 7d, 48h), the age of a v7 message id being its minting instant: exceed the redelivery window plus the longest queue lag — required');
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

        $before = $input->getOption('before');
        $duration = DurationOption::required($input, $io, 'before');
        if (is_int($duration)) {
            return $duration;
        }
        $age = $duration->seconds;
        if ($age < $this->idempotencyLifetimeSeconds) {
            $io->error(sprintf('--before must cover the configured idempotency retry window of %d seconds; also allow for queue lag and redelivery.', $this->idempotencyLifetimeSeconds));

            return Command::INVALID;
        }

        $batch = PositiveIntOption::parse($input->getOption('batch'));
        if ($batch === null) {
            $io->error('--batch must be a positive integer (rows per delete batch), e.g. --batch=1000.');

            return Command::INVALID;
        }

        $dryRun = $input->getOption('dry-run') === true;

        $io->title(sprintf('Inbox prune — dedup records older than %s%s', $before, $dryRun ? ' (DRY RUN)' : ''));

        try {
            $count = $dryRun ? $this->inbox->countPrunable($age) : $this->inbox->prune($age, $batch);
        } catch (InvalidInboxRetention $error) {
            $io->error($error->getMessage());

            return Command::INVALID;
        }

        $io->success($dryRun
            ? sprintf('Would prune %d dedup record(s). Nothing deleted.', $count)
            : sprintf('Pruned %d dedup record(s).', $count));

        return Command::SUCCESS;
    }
}
