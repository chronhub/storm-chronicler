<?php

declare(strict_types=1);

namespace Storm\Chronicler\Console;

use Doctrine\DBAL\Exception;
use JsonException;
use Override;
use Storm\Chronicler\Outbox\FailedOutboxMessage;
use Storm\Chronicler\Outbox\OutboxDeadLetter;
use Storm\Support\Console\PositiveIntOption;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Inspect / recover the outbox dead-letter, `status = 'failed'`; the operator counterpart to
 * RelayOutboxCommand, like `messenger:failed:show|retry`.
 *
 * - Show, the default: list the dead-lettered rows with id, position, type, attempts, and error.
 *
 * - `--retry`: requeue them to `pending`, clearing `attempts`, `next_attempt_at`, `last_error`, and
 *   `failed_at`, so the relay republishes them. The relay drains `ORDER BY id`, so re-queued rows go
 *   out in their original order, but events published after the row was dead-lettered already went
 *   out, so a replayed event is re-emitted late, recovery not time travel. Fix the root cause first.
 *
 * - `--purge`: delete them for good. Without an id it shows the count and asks, since these rows are
 *   the only record of why the publication failed and a requeue after the fix is the normal ending;
 *   `--force` answers for a non-interactive session. An id-scoped purge names its target, so it asks
 *   nothing.
 *
 * An optional `id` argument scopes `--retry` / `--purge` to a single row; omit it to act on all failed
 * rows.
 *
 * Examples:
 *
 * ```bash
 * bin/console storm:outbox:failed
 * bin/console storm:outbox:failed --retry
 * bin/console storm:outbox:failed 42 --purge
 * bin/console storm:outbox:failed --purge --force
 * ```
 *
 * @see RelayOutboxCommand
 */
#[AsCommand(
    name: 'storm:outbox:failed',
    description: 'Inspect or recover dead-lettered outbox messages (--retry to requeue, --purge to delete).',
)]
final class OutboxFailedCommand extends Command
{
    public function __construct(
        private readonly OutboxDeadLetter $deadLetter,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::OPTIONAL, 'A single failed row id (omit to act on all)');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the machine-readable dead-letter rows, the error untruncated');
        $this->addOption('retry', null, InputOption::VALUE_NONE, 'Requeue the failed row(s) to pending');
        $this->addOption('purge', null, InputOption::VALUE_NONE, 'Delete the failed row(s)');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Confirm a --purge of ALL failed rows without asking (required when the session is non-interactive)');
    }

    /**
     * {@inheritDoc}
     *
     * @throws JsonException when a machine document cannot be encoded
     * @throws Exception on a DBAL failure of the inspect / requeue / purge query
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $retry = $input->getOption('retry') === true;
        $purge = $input->getOption('purge') === true;

        $json = $input->getOption('json') === true;

        if ($retry && $purge) {
            // stderr, and on every refusal below: this command has a machine channel, and a refusal
            // printed on stdout lands INSIDE the document a caller is piping into a parser
            $io->getErrorStyle()->error('Use either --retry or --purge, not both.');

            return Command::INVALID;
        }

        $idArg = $input->getArgument('id');

        // Reject anything that is not a positive integer loudly: a silent `(int)` cast would target
        // id 0, match nothing, and report a success-shaped "nothing to do" to an operator who
        // believes they aimed at a row. The shared parser also refuses "0" itself, which
        // `ctype_digit` accepts while the error text promises a positive integer, and a digit string
        // too long to represent, which PHP saturates at PHP_INT_MAX instead of rejecting.
        $id = null;

        if ($idArg !== null) {
            $id = PositiveIntOption::parse($idArg);

            if ($id === null) {
                $io->getErrorStyle()->error(sprintf('Invalid id "%s" — it must be a positive integer.', is_scalar($idArg) ? (string) $idArg : get_debug_type($idArg)));

                return Command::INVALID;
            }
        }

        if ($retry) {
            return $this->requeue($io, $output, $id, $json);
        }

        if ($purge) {
            return $this->purge($io, $input, $output, $id, $json);
        }

        return $this->show($io, $output, $id, $json);
    }

    /**
     * @throws JsonException when the machine document cannot be encoded
     * @throws Exception on a DBAL failure of the dead-letter read
     */
    private function show(SymfonyStyle $io, OutputInterface $output, ?int $id, bool $json): int
    {
        $rows = $this->deadLetter->listFailed($id);

        if ($rows === []) {
            if ($json) {
                $output->writeln(json_encode(['messages' => []], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

                return Command::SUCCESS;
            }

            $io->success('No dead-lettered outbox messages.');

            return Command::SUCCESS;
        }

        if ($json) {
            // the ONLY channel this command had truncated the one thing it exists to deliver: the
            // cause of a publication failure, cut at sixty characters, leaving an operator to query
            // `es_outbox` by hand mid-incident. Here the error travels whole
            $output->writeln(json_encode([
                'messages' => array_map(static fn (FailedOutboxMessage $row): array => [
                    'id' => $row->id,
                    'position' => $row->position,
                    'type' => $row->type,
                    'attempts' => $row->attempts,
                    'failed_at' => $row->failedAt,
                    'last_error' => $row->lastError,
                ], $rows),
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $io->table(
            ['Id', 'Position', 'Type', 'Attempts', 'Failed at', 'Error'],
            array_map(static fn (FailedOutboxMessage $row): array => [
                (string) $row->id,
                (string) $row->position,
                $row->type,
                (string) $row->attempts,
                $row->failedAt ?? '—',
                self::shorten($row->lastError),
            ], $rows),
        );
        $rows
            |> count(...)
            |> (static fn ($x) => sprintf(' <comment>%d dead-lettered message(s).</comment>', $x))
            |> $io->writeln(...);

        return Command::SUCCESS;
    }

    /**
     * Requeue, with no interlock, unlike its `purge()` sibling.
     *
     * The asymmetry is a difference in kind, not an oversight. A purge discards the only record of why
     * a publication failed, while a requeue fulfills the intent the outbox already held, and
     * republishing once the root cause is fixed is this command's designed ending. The count an
     * operator wants before a bulk requeue sits one keystroke away in the default form, which lists
     * the rows and totals them, so a prompt here would tax the normal gesture to restate a number the
     * previous command already printed.
     *
     * @throws JsonException when the machine document cannot be encoded
     * @throws Exception on a DBAL failure of the requeue update
     */
    private function requeue(SymfonyStyle $io, OutputInterface $output, ?int $id, bool $json): int
    {
        $count = $this->deadLetter->requeue($id);

        if ($json) {
            $output->writeln(json_encode(['requeued' => $count], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return Command::SUCCESS;
        }

        $count > 0
            ? $io->success(sprintf('Re-queued %d message(s) to pending — run storm:outbox:relay to republish.', $count))
            : $io->warning('No dead-lettered message to requeue.');

        return Command::SUCCESS;
    }

    /**
     * Purge, with an interlock on the bulk form only.
     *
     * These rows are the ONLY built-in record of why a publication failed, and a requeue after the
     * root cause is fixed is the normal ending, so deleting the whole dead-letter is not a cleanup,
     * it is discarding the evidence and the pending recovery in one keystroke. An id-scoped purge
     * names what it kills and stays friction-free; the bulk form states the count first, then asks,
     * or takes `--force` when nobody is there to answer.
     *
     * @throws JsonException when the machine document cannot be encoded
     * @throws Exception on a DBAL failure of the count / delete
     */
    private function purge(SymfonyStyle $io, InputInterface $input, OutputInterface $output, ?int $id, bool $json): int
    {
        $count = $this->deadLetter->countFailed($id);

        if ($count === 0) {
            if ($json) {
                $output->writeln(json_encode(['purged' => 0], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

                return Command::SUCCESS;
            }

            $io->warning('No dead-lettered message to purge.');

            return Command::SUCCESS;
        }

        if ($id === null && $input->getOption('force') !== true) {
            // a caller asking for a document is driving this from a script, so the interlock takes
            // `--force` there as it does with no terminal: a prompt would block a pipeline forever,
            // and answering it would print a question into the document
            if ($json || ! $input->isInteractive()) {
                $io->getErrorStyle()->error(sprintf(
                    '--purge with no id deletes all %d dead-lettered row(s), the only record of their failure, and the session is non-interactive: pass --force to confirm, or an id to purge one row.',
                    $count,
                ));

                return Command::INVALID;
            }

            if (! $io->confirm(sprintf('Delete all %d dead-lettered message(s)? They cannot be requeued afterwards.', $count), false)) {
                $io->warning('Aborted — nothing was purged.');

                return Command::SUCCESS;
            }
        }

        $purged = $this->deadLetter->purge($id);

        if ($json) {
            $output->writeln(json_encode(['purged' => $purged], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return Command::SUCCESS;
        }

        $io->success(sprintf('Purged %d dead-lettered message(s).', $purged));

        return Command::SUCCESS;
    }

    private static function shorten(?string $error): string
    {
        if ($error === null || $error === '') {
            return '—';
        }

        // `last_error` can be multi-line, a class plus its cause chain, so collapse to keep the table one row tall;
        // the full value stays in the column for whoever queries it.
        $oneLine = preg_replace('/\s+/', ' ', $error) ?? $error; // null only on a PCRE error, so keep the raw text

        return mb_strlen($oneLine) > 60 ? mb_substr($oneLine, 0, 57).'…' : $oneLine;
    }
}
