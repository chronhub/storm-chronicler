<?php

declare(strict_types=1);

namespace Storm\Chronicler\Console;

use Doctrine\DBAL\Exception;
use Override;
use RuntimeException;
use Storm\Chronicler\Store\EventStorePartitioner;
use Storm\Contracts\Aggregate\AggregateIdentity;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Creates and verifies the per-category `LIST` partitions of `event_store`.
 *
 * It splits a known category out of the `event_store_default` catch-all into its own child, with a
 * bounded physical name. Categories come from config, never the CLI: `storm.event_store_partition_list`,
 * the explicit set the domain knows, and, when `storm.autodiscover_partition_per_category` is on, the
 * categories of the registered aggregates, a convenience rather than best practice, since a partition is
 * not necessarily an aggregate.
 *
 * `--verify-only` checks that each child exists and is attached with the right `LIST` bound, creating
 * nothing. Otherwise the command adopts correctly bound children or creates missing partitions, then
 * verifies.
 *
 * Postgres rejects a simple split when `event_store_default` still holds the category's rows, and does
 * not migrate them. So a category with rows in the default partition is refused and marked
 * `NEEDS MIGRATION` rather than migrated: the command prints the manual recipe the operator runs, which
 * creates a standalone table, moves the rows, then attaches it under an `ACCESS EXCLUSIVE` lock.
 * Provision early, while the default is empty; when the categories are provisioned up front, this never
 * fires.
 *
 * Examples:
 *
 * ```bash
 * bin/console storm:event-store:partition
 * bin/console storm:event-store:partition --verify-only
 * ```
 */
#[AsCommand(name: 'storm:event-store:partition', description: 'Create/verify per-category event_store partitions from config.')]
final class EventStorePartitionCommand extends Command
{
    /**
     * @param  list<string>  $partitionList
     * @param  array<class-string, array{
     *     id: class-string<AggregateIdentity>,
     *     category: string,
     *     snapshot?: array{threshold: int, max_age_seconds?: int|null, min_interval_seconds?: int|null},
     * }>  $aggregates
     */
    public function __construct(
        private readonly EventStorePartitioner $partitioner,
        #[Autowire('%storm.event_store_partition_list%')]
        private readonly array $partitionList,
        #[Autowire('%storm.autodiscover_partition_per_category%')]
        private readonly bool $autodiscover,
        #[Autowire('%storm.aggregates%')]
        private readonly array $aggregates,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption('verify-only', null, InputOption::VALUE_NONE, 'Verify the partitions/bounds exist; create nothing');
    }

    /**
     * {@inheritDoc}
     *
     * @throws RuntimeException when a partition name is occupied or cannot fit the server limit
     * @throws Exception on a DBAL failure of a create, a verify or a count
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $verifyOnly = $input->getOption('verify-only') === true;

        $categories = $this->resolveCategories();

        if ($categories === []) {
            // Partitioning is opt-in: with nothing configured, every category stays in
            // `event_store_default`, a no-op rather than an error, so the command is safe to call
            // unconditionally from an installation step.
            $io->info('No partition categories configured (storm.event_store_partition_list / autodiscover_partition_per_category) — every category uses event_store_default.');

            return Command::SUCCESS;
        }

        $io->title(sprintf('Event-store partitions%s', $verifyOnly ? ' (verify only)' : ''));

        $allOk = true;

        foreach ($categories as $category) {
            if (! $verifyOnly && ! $this->provision($io, $category)) {
                $io->writeln(sprintf('  %-30s <error>NEEDS MIGRATION</error>', $category));
                $allOk = false;

                continue;
            }

            $ok = $this->partitioner->verify($category);
            $io->writeln(sprintf('  %-30s %s', $category, $ok ? '<info>OK</info>' : '<error>FAILED</error>'));
            $allOk = $allOk && $ok;
        }

        if (! $allOk) {
            $io->error('One or more partitions are missing, not attached, or need a manual migration (see above).');

            return Command::FAILURE;
        }

        $io->success('Event-store partitions are ready.');

        return Command::SUCCESS;
    }

    /**
     * Create the category's partition with a simple `CREATE TABLE … PARTITION OF`.
     *
     * Returns false when the default partition still holds the category's rows, since Postgres rejects
     * the split there, so the command prints the manual migration recipe instead of attempting a doomed
     * creation.
     *
     * @throws RuntimeException when a partition name is occupied or cannot fit the server limit
     * @throws Exception on a DBAL failure
     */
    private function provision(SymfonyStyle $io, string $category): bool
    {
        $volume = $this->partitioner->defaultVolumeFor($category);

        if ($volume > 0) {
            $volume
                |> number_format(...)
                |> (static fn ($x) => sprintf("Category '%s' has %s row(s) in event_store_default — Postgres rejects a simple split (it does not migrate them). ".'Migrate manually (under an ACCESS EXCLUSIVE lock — appends blocked — preferably off-peak), then re-run:', $category, $x))
                |> $io->warning(...);
            $io->writeln($this->migrationRecipe($category));

            return false;
        }

        $this->partitioner->createPartitions([$category]);

        return true;
    }

    /**
     * The manual split the operator runs when a category already has rows in the default partition.
     *
     * It stages a standalone table, moves the rows out of the default, then attaches it as the partition,
     * in one transaction with `ACCESS EXCLUSIVE` on the parent at `ATTACH`; the parent's partitioned
     * primary key, unique constraint and index attach with it.
     *
     * @throws RuntimeException when the partition name cannot fit the server limit
     * @throws Exception on a DBAL failure
     */
    private function migrationRecipe(string $category): string
    {
        $name = $this->partitioner->partitionName($category);

        return implode("\n", [
            '  BEGIN;',
            // `INCLUDING CONSTRAINTS` is required: without it the child misses the parent's `CHECK`
            // constraint `event_store_cat_stream_chk` and the `ATTACH` is refused.
            sprintf('  CREATE TABLE %s (LIKE event_store INCLUDING DEFAULTS INCLUDING CONSTRAINTS);', $name),
            sprintf("  WITH moved AS (DELETE FROM event_store_default WHERE category = '%s' RETURNING *)", $category),
            sprintf('  INSERT INTO %s SELECT * FROM moved;', $name),
            sprintf("  ALTER TABLE event_store ATTACH PARTITION %s FOR VALUES IN ('%s');", $name, $category),
            '  COMMIT;',
        ]);
    }

    /**
     * @return list<string>
     */
    private function resolveCategories(): array
    {
        $categories = $this->partitionList;

        if ($this->autodiscover) {
            $categories = array_merge($categories, array_column($this->aggregates, 'category'));
        }

        return $categories
            |> array_unique(...)
            |> array_values(...);
    }
}
