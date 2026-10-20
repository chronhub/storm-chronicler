<?php

declare(strict_types=1);

namespace Storm\Chronicler\Store;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use RuntimeException;
use Storm\Chronicler\Schema\EventStoreSchema;
use Storm\Stream\Exception\InvalidStreamException;
use Storm\Stream\StreamCategory;

/**
 * Provisions per-category `LIST` partitions of `event_store`, the ops side of the partitioned store.
 *
 * The schema ships `event_store PARTITION BY LIST (category)` with a single `event_store_default`
 * catch-all, so any category writes out of the box. This splits a known category out of the default
 * partition into its own child, so its rows no longer share the catch-all. Short names use
 * `event_store_p_<category>`; longer names include a digest within the server identifier limit.
 *
 * It creates only the child table: the parent's primary key `(category, sequence_no)`, the unique
 * `(category, stream, version)` and the `sequence_no` index are partitioned indexes, so Postgres
 * propagates them to every child and nothing per child needs recreating; a child-local unique without
 * the partition key is the trap to avoid.
 *
 * Warning: provision early, while the default partition holds no row of the category. Postgres does
 * not migrate rows out of the default: creating a partition for a category that already has rows
 * there is rejected, since the new partition's constraint would contradict the default's contents.
 * The split is then a manual recipe, under an `ACCESS EXCLUSIVE` window that grows with volume:
 *
 * - Detach
 * - Create
 * - `INSERT ... SELECT`
 * - Delete
 *
 * `defaultVolumeFor()` surfaces the volume so a caller can refuse before hitting the raw Postgres
 * error, and this class's direct API performs no volume guard itself. The `storm:event-store:partition`
 * command does, and prints the recipe instead of attempting the doomed split. When the categories are
 * provisioned up front, the default stays empty and none of this arises.
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
     * @param  list<string>  $categories  the configured values, each read under the `StreamCategory` rule
     *
     * @throws InvalidStreamException when a category is not a bare, well-formed stream category, a
     *                                config error
     * @throws RuntimeException when the generated name is occupied or the identifier budget is too small
     * @throws Exception on a DBAL failure
     */
    public function createPartitions(array $categories): void
    {
        foreach ($categories as $configured) {
            $category = $this->declared($configured);

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
     * @throws InvalidStreamException when the category is not a bare, well-formed stream category
     * @throws Exception on a DBAL failure
     */
    public function verify(string $category): bool
    {
        return $this->existingPartition($this->declared($category)) !== false;
    }

    /**
     * Return the existing child name or the bounded name used for creation and manual migration.
     *
     * @throws InvalidStreamException when the category is not a bare, well-formed stream category
     * @throws RuntimeException when the server identifier budget cannot hold the generated name
     * @throws Exception on a DBAL failure
     */
    public function partitionName(string $category): string
    {
        $category = $this->declared($category);
        $existing = $this->existingPartition($category);
        if ($existing !== false) {
            return $existing;
        }

        // equivalent: the arithmetic and the comparison below read the numeric
        // answer as its integer, and `%d` formats it the same, so the cast answers the analyzer alone
        // @infection-ignore-all
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
     * How many rows of this category still sit in `event_store_default`.
     *
     * It is the volume a split would migrate under an `ACCESS EXCLUSIVE` lock, and zero once the
     * category has its own partition or was never written.
     *
     * @throws InvalidStreamException when the category is not a bare, well-formed stream category
     * @throws Exception on a DBAL failure
     */
    public function defaultVolumeFor(string $category): int
    {
        return (int) $this->connection->fetchOne(
            /* language=PostgreSQL */
            'SELECT count(*) FROM event_store_default WHERE category = :category',
            ['category' => $this->declared($category)],
        );
    }

    /**
     * A partition category is written in configuration, so it is held to the canonical form rather
     * than normalized: a value that `StreamCategory` would fold is refused, since the LIST bound and
     * the child identifier must read exactly what the store writes.
     *
     * @throws InvalidStreamException when the value is not a bare, well-formed, canonical stream category
     */
    private function declared(string $category): string
    {
        if (new StreamCategory($category)->value !== $category) {
            throw InvalidStreamException::invalidCategory($category);
        }

        return $category;
    }
}
