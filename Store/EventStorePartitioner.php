<?php

declare(strict_types=1);

namespace Storm\Chronicler\Store;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use InvalidArgumentException;
use RuntimeException;
use Storm\Chronicler\Schema\EventStoreSchema;
use Storm\Stream\StreamName;

/**
 * Provisions per-category LIST partitions of `event_store`, the ops side of the partitioned event
 * store.
 *
 * The schema ships `event_store PARTITION BY LIST (category)` with a single `event_store_default`
 * catch-all, so any category writes out of the box. This splits a known category out of the DEFAULT
 * into its own child so its rows no longer share the catch-all. Short names use
 * `event_store_p_<category>`; longer names include a digest within the server identifier limit.
 *
 * It creates only the child table: the parent's PK `(category, sequence_no)`, the UNIQUE
 * `(category, stream, version)` and the `sequence_no` index are PARTITIONED indexes, so Postgres
 * propagates them to every child automatically and there is nothing per-child to recreate; a
 * child-local unique without the partition key is exactly the trap to avoid.
 *
 * Warning: provision EARLY, while the DEFAULT is empty for the category. Postgres does NOT migrate
 * rows out of the DEFAULT: creating a partition for a category that already has rows there is
 * simply REJECTED, since the new partition's constraint would contradict the DEFAULT's contents. The
 * split is then a manual recipe: detach, create, `INSERT ... SELECT`, delete, under an ACCESS
 * EXCLUSIVE window that grows with volume. `defaultVolumeFor()` surfaces the volume so a caller can
 * refuse before hitting the raw Postgres error; note that this class's direct API performs NO
 * volume guard itself. The `storm:event-store:partition` command does, and prints the recipe
 * instead of attempting the doomed split. In a design that provisioned its categories up front, the
 * DEFAULT stays empty and none of this arises.
 *
 * @see EventStoreSchema
 */
final readonly class EventStorePartitioner
{
    public function __construct(
        private Connection $connection,
    ) {}

    /**
     * Create missing category partitions; existing children are recognized by their LIST bound.
     *
     * @param  list<string>  $categories
     *
     * @throws InvalidArgumentException when a category is not a valid stream category matching `StreamName::CATEGORY_REGEX`, a config error
     * @throws RuntimeException when the generated name is occupied or the identifier budget is too small
     * @throws Exception on a DBAL failure
     */
    public function createPartitions(array $categories): void
    {
        foreach ($categories as $category) {
            $category = $this->assertValidCategory($category);

            if ($this->existingPartition($category) !== false) {
                continue;
            }

            $name = $this->partitionName($category);
            $occupant = $this->connection->fetchOne(
                "SELECT c.relkind::text || ' ' || COALESCE(pg_get_expr(c.relpartbound, c.oid), 'unpartitioned')
                   FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                  WHERE n.nspname = current_schema() AND c.relname = :name",
                ['name' => $name],
            );
            if ($occupant !== false) {
                throw new RuntimeException(sprintf('Partition name "%s" is occupied by %s; expected category "%s".', $name, $occupant, $category));
            }

            $this->connection->executeStatement(sprintf(
                "CREATE TABLE %s PARTITION OF event_store FOR VALUES IN ('%s')",
                $name,
                $category,
            ));
        }
    }

    /**
     * Whether the category's child exists and is attached to `event_store` with the matching LIST bound.
     *
     * @throws InvalidArgumentException when the category is not a bare identifier
     * @throws Exception on a DBAL failure
     */
    public function verify(string $category): bool
    {
        $category = $this->assertValidCategory($category);

        return $this->existingPartition($category) !== false;
    }

    /**
     * Return the existing child name or the bounded name used for creation and manual migration.
     *
     * @throws InvalidArgumentException when the category is invalid
     * @throws RuntimeException when the server identifier budget cannot hold the generated name
     * @throws Exception on a DBAL failure
     */
    public function partitionName(string $category): string
    {
        $category = $this->assertValidCategory($category);
        $existing = $this->existingPartition($category);
        if ($existing !== false) {
            return $existing;
        }

        $limit = (int) $this->connection->fetchOne("SELECT current_setting('max_identifier_length')::int");
        $prefix = 'event_store_p_';
        if (strlen($prefix.$category) <= $limit) {
            return $prefix.$category;
        }

        $budget = $limit - strlen($prefix) - 9;
        if ($budget < 1) {
            throw new RuntimeException(sprintf('PostgreSQL identifier limit %d is too small for a category partition name.', $limit));
        }

        return $prefix.substr($category, 0, $budget).'_'.substr(hash('sha256', $category), 0, 8);
    }

    private function existingPartition(string $category): string|false
    {
        $name = $this->connection->fetchOne(
            'SELECT c.relname::text
               FROM pg_class c
               JOIN pg_namespace n ON n.oid = c.relnamespace
               JOIN pg_inherits i ON i.inhrelid = c.oid
               JOIN pg_class p ON p.oid = i.inhparent
               JOIN pg_namespace pn ON pn.oid = p.relnamespace
              WHERE n.nspname = current_schema()
                AND pn.nspname = current_schema() AND p.relname = :parent
                AND pg_get_expr(c.relpartbound, c.oid) = :bound',
            ['parent' => 'event_store', 'bound' => sprintf("FOR VALUES IN ('%s')", $category)],
        );

        return is_string($name) ? $name : false;
    }

    /**
     * How many rows of this category still sit in `event_store_default`, the volume a split would
     * migrate under an ACCESS EXCLUSIVE lock. Zero once the category has its own partition, or was
     * never written.
     *
     * @throws InvalidArgumentException when the category is not a bare identifier
     * @throws Exception on a DBAL failure
     */
    public function defaultVolumeFor(string $category): int
    {
        $category = $this->assertValidCategory($category);

        return (int) $this->connection->fetchOne(
            /** @lang PostgreSQL */
            'SELECT count(*) FROM event_store_default WHERE category = :category',
            ['category' => $category],
        );
    }

    /**
     * @throws InvalidArgumentException when the category is not a valid stream category matching `StreamName::CATEGORY_REGEX`
     */
    private function assertValidCategory(string $category): string
    {
        if (preg_match(StreamName::CATEGORY_REGEX, $category) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid event-store partition category [%s]: expected a valid stream category matching %s.',
                $category,
                StreamName::CATEGORY_REGEX,
            ));
        }

        return $category;
    }
}
