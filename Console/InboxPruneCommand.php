<?php

declare(strict_types=1);

namespace Storm\Chronicler\Console;

use Doctrine\DBAL\Exception;
use Override;
use Storm\Chronicler\Inbox\DbalInboxStore;
use Storm\Clock\Duration;
use Storm\Support\Console\PositiveIntOption;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prunes es_inbox dedup records older than --before, the consumer-dedup window's retention. Each row
 * marks a `(consumer, message-id)` already processed; its value expires once redelivery is no longer
 * possible, past the broker's retry or visibility horizon, so old rows are safe to drop.
 *
 * Batched, in LIMIT-capped statements so it never holds a long lock; idempotent. A v7 key carries its
 * minting instant, so its age is read from the key and the rows go by a range of the primary index;
 * any other key goes by `processed_at`. The age therefore counts from the MINTING of the message, not
 * its consumption: a message consumed after a queue lag retires lag earlier than `processed_at` says.
 * Pick --before beyond your broker's max redelivery window PLUS the longest queue lag you accept.
 *
 * --before <7d|48h|90m> is required, so there is no accidental prune-all; --dry-run counts without
 * deleting; --batch caps each statement.
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

        $io->title(sprintf('Inbox prune — dedup records older than %s%s', $before, $dryRun ? ' (DRY RUN)' : ''));

        if ($dryRun) {
            $io->success(sprintf('Would prune %d dedup record(s). Nothing deleted.', $this->inbox->countPrunable($age)));

            return Command::SUCCESS;
        }

        $io->success(sprintf('Pruned %d dedup record(s).', $this->inbox->prune($age, $batch)));

        return Command::SUCCESS;
    }
}
