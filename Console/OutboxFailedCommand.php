<?php

declare(strict_types=1);

namespace Storm\Chronicler\Console;

use Doctrine\DBAL\Exception;
use JsonException;
use Override;
use Storm\Chronicler\Outbox\FailedOutboxMessage;
use Storm\Chronicler\Outbox\OutboxDeadLetter;
use Storm\Support\Console\DestructiveConfirmation;
use Storm\Support\Console\PositiveIntOption;
use Storm\Text\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Inspects and recovers the outbox dead-letter, `status = 'failed'`.
 *
 * It is the operator counterpart of `RelayOutboxCommand`, like `messenger:failed:show|retry`.
 *
 * - Show, the default: lists one page of dead-lettered rows with id, position, type, attempts and
 *   error. `--limit` bounds the page and `--after` resumes after an id; the same options bound
 *   collective retry.
 *
 * - `--retry --limit=N`: requeues one page to `pending`, clearing `attempts`, `next_attempt_at`,
 *   `last_error` and `failed_at`, so the relay republishes them. The relay drains `ORDER BY id`, so
 *   requeued rows go out in their original order, but events published after the row was dead-lettered
 *   already went out, so a replayed event is re-emitted late, after events that followed it. Fix the
 *   root cause first.
 *
 * - `--purge`: deletes them for good. Without an id it shows the count and asks, since these rows are
 *   the only record of why the publication failed and a requeue after the fix is the normal ending;
 *   `--force` answers for a non-interactive session. An id-scoped purge names its target, so it asks
 *   nothing.
 *
 * An optional `id` argument scopes `--retry` or `--purge` to a single row; omit it to inspect or retry a
 * page, or to purge all failed rows. Collective retry requires an explicit limit and accepts an exact
 * `--type` filter.
 *
 * Examples:
 *
 * ```bash
 * bin/console storm:outbox:failed
 * bin/console storm:outbox:failed --limit=50 --after=42 --json
 * bin/console storm:outbox:failed --retry --limit=50
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
    use DestructiveConfirmation;

    public function __construct(
        private readonly OutboxDeadLetter $deadLetter,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::OPTIONAL, 'A single failed row id; omit to inspect or retry a page, or purge all failed rows');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Page size from 1 to 1000; defaults to 100 for inspection, required for collective retry');
        $this->addOption('after', null, InputOption::VALUE_REQUIRED, 'Inspect or retry rows after this positive id');
        $this->addOption('type', null, InputOption::VALUE_REQUIRED, 'Exact event type alias for a collective retry');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the machine-readable dead-letter rows, the error untruncated');
        $this->addOption('retry', null, InputOption::VALUE_NONE, 'Requeue the failed row(s) to pending');
        $this->addOption('purge', null, InputOption::VALUE_NONE, 'Delete the failed row(s)');
        $this->configureForce('a --purge with no id deletes every dead-lettered row, the only record of their failure');
    }

    /**
     * {@inheritDoc}
     *
     * @throws JsonException when a machine document cannot be encoded
     * @throws Exception on a DBAL failure of the inspect, requeue or purge query
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
            // printed on stdout lands inside the document a caller is piping into a parser
            $io->getErrorStyle()->error('Use either --retry or --purge, not both.');

            return Command::INVALID;
        }

        $idArg = $input->getArgument('id');

        // Reject anything that is not a positive integer loudly: a silent `(int)` cast would target
        // id 0, match nothing, and report a success-shaped "nothing to do" to an operator who
        // believes they aimed at a row. The shared parser also refuses "0" itself, which
        // `ctype_digit` accepts while the error text promises a positive integer, and a digit string
        // too long to represent, which PHP saturates at `PHP_INT_MAX` instead of rejecting.
        $id = null;

        if ($idArg !== null) {
            $id = PositiveIntOption::parse($idArg);

            if ($id === null) {
                $io->getErrorStyle()->error(sprintf('Invalid id "%s" — it must be a positive integer.', is_scalar($idArg) ? (string) $idArg : get_debug_type($idArg)));

                return Command::INVALID;
            }
        }

        $rawLimit = $input->getOption('limit');
        $rawAfter = $input->getOption('after');
        $type = $input->getOption('type');
        if (($rawLimit !== null || $rawAfter !== null) && ($id !== null || $purge)) {
            $io->getErrorStyle()->error('Use --limit and --after only for inspection or collective --retry, without an id or --purge.');

            return Command::INVALID;
        }

        if ($type !== null && (! $retry || $id !== null || ! is_string($type) || trim($type) === '')) {
            $io->getErrorStyle()->error('Use a non-empty --type only with collective --retry.');

            return Command::INVALID;
        }

        if ($retry && $id === null && $rawLimit === null) {
            $io->getErrorStyle()->error('Collective --retry requires an explicit --limit from 1 to 1000. Repeat with the returned --after cursor to continue.');

            return Command::INVALID;
        }

        $limit = $rawLimit === null ? 100 : PositiveIntOption::parse($rawLimit);
        $after = $rawAfter === null ? 0 : PositiveIntOption::parse($rawAfter);
        if ($limit === null || $limit > 1000 || $after === null) {
            $io->getErrorStyle()->error('The limit must be an integer from 1 to 1000; after must be a representable positive integer.');

            return Command::INVALID;
        }

        if ($retry) {
            return $id === null
                ? $this->requeuePage($io, $output, $json, $limit, $after, $type)
                : $this->requeue($io, $output, $id, $json);
        }

        if ($purge) {
            return $this->purge($io, $input, $output, $id, $json);
        }

        return $this->show($io, $output, $id, $json, $limit, $after);
    }

    /**
     * @param  positive-int  $limit
     * @param  non-negative-int  $after
     *
     * @throws JsonException when the machine document cannot be encoded
     * @throws Exception on a DBAL failure of the dead-letter read
     */
    private function show(SymfonyStyle $io, OutputInterface $output, ?int $id, bool $json, int $limit, int $after): int
    {
        $rows = $id === null ? $this->deadLetter->listFailedPage($after, $limit) : $this->deadLetter->listFailed($id);
        $nextAfter = $id === null && count($rows) === $limit ? array_last($rows)->id : null;

        if ($json) {
            $document = [
                'messages' => array_map(static fn (FailedOutboxMessage $row): array => [
                    'id' => $row->id,
                    'position' => $row->position,
                    'type' => $row->type,
                    'attempts' => $row->attempts,
                    'failed_at' => $row->failedAt,
                    'last_error' => $row->lastError,
                ], $rows),
            ];
            if ($id === null) {
                $document['pagination'] = ['limit' => $limit, 'after' => $after, 'next_after' => $nextAfter];
            }
            $output->writeln(json_encode($document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        if ($rows === []) {
            $io->success($after === 0 ? 'No dead-lettered outbox messages.' : sprintf('No dead-lettered outbox messages after id %d.', $after));

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
        $io->writeln(sprintf(' <comment>%d dead-lettered message(s)%s.</comment>', count($rows), $id === null ? ' on this page' : ''));
        if ($nextAfter !== null) {
            $io->text(sprintf('Continue inspection with --limit=%d --after=%d; the next page may be empty.', $limit, $nextAfter));
        }

        return Command::SUCCESS;
    }

    /**
     * Requeue a single explicitly identified row without a confirmation prompt.
     *
     * @throws JsonException when the machine document cannot be encoded
     * @throws Exception on a DBAL failure of the requeue update
     */
    private function requeue(SymfonyStyle $io, OutputInterface $output, int $id, bool $json): int
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
     * @param  positive-int  $limit
     * @param  non-negative-int  $after
     * @param  non-empty-string|null  $type
     *
     * @throws JsonException when the machine document cannot be encoded
     * @throws Exception on a DBAL failure of the requeue update
     */
    private function requeuePage(SymfonyStyle $io, OutputInterface $output, bool $json, int $limit, int $after, ?string $type): int
    {
        $ids = $this->deadLetter->requeuePage($after, $limit, $type);
        $count = count($ids);
        $nextAfter = $ids === [] ? null : max($ids);
        if ($json) {
            $output->writeln(json_encode([
                'requeued' => $count,
                'type' => $type,
                'pagination' => ['limit' => $limit, 'after' => $after, 'next_after' => $nextAfter],
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return Command::SUCCESS;
        }

        if ($nextAfter === null) {
            $io->warning('No dead-lettered message to requeue. Nothing matched when candidates were locked; repeat the same scope before concluding recovery is complete.');

            return Command::SUCCESS;
        }

        $io->success(sprintf('Re-queued %d message(s) to pending; run storm:outbox:relay to republish.', $count));
        $io->text(sprintf('Continue with --retry --limit=%d --after=%d%s; the next page may be empty.', $limit, $nextAfter, $type === null ? '' : ', keeping the same --type filter'));

        return Command::SUCCESS;
    }

    /**
     * Purge, with an interlock on the bulk form only.
     *
     * Deleting the whole dead-letter discards the evidence and the pending recovery in one keystroke, so
     * the bulk form states the count first, then asks, or takes `--force` when nobody is there to answer.
     *
     * @throws JsonException when the machine document cannot be encoded
     * @throws Exception on a DBAL failure of the count or the delete
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

        if ($id === null) {
            // the gate reads a --json run as non-interactive: a prompt would block the script that
            // asked for a document, and an answered one would print a question into it
            $status = $this->confirmDestructive(
                $io,
                $input,
                sprintf('Delete all %d dead-lettered message(s)?', $count),
                sprintf('--purge with no id deletes all %d dead-lettered row(s), the only record of their failure, and none can be requeued afterwards; an id purges one row.', $count),
            );
            if ($status !== null) {
                return $status;
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

        // `last_error` can be multi-line, a class plus its cause chain, so collapse it to keep the table
        // one row tall; the full value stays in the column for whoever queries it.
        return $error |> Str::oneLine(...) |> Str::excerpt(60);
    }
}
