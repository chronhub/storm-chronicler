<?php

declare(strict_types=1);

namespace Storm\Chronicler\Outbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Storm\Chronicler\Store\InsertChunk;
use Storm\Support\Dbal\BatchedDelete;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use function array_chunk;
use function array_fill;
use function count;
use function implode;

/**
 * The default `OutboxWriter`, one multi-`VALUES` statement per `InsertChunk::ROWS` of entries.
 *
 * The relay's order is carried by the identity `id`, which it drains `ORDER BY id`, and insert order is
 * entry order, so the batch's rows take ids in event order. `position` is the event's `sequence_no`
 * captured inside the transaction: exact, carried for consumers and for the audit, and not the ordering
 * key. The two agree per partition anyway, since a stream's appends serialize on its head and each batch
 * is inserted in version order, but the relay reads only one of them, and a change to this insert must
 * keep the id order faithful to event order, not merely the `position` values.
 *
 * As the owner of the outbox tables, it also carries their storage retention: `pruneArchive()` prunes the
 * cold `es_outbox_archive` by age. The hot table needs no retention: a published row is disposed of at
 * drain time, deleted or moved to the archive, so `es_outbox` only ever holds in-flight work plus the
 * dead-letter, which the operator path `OutboxDeadLetter` owns and retention never touches.
 */
#[AsAlias(OutboxWriter::class)]
final readonly class DbalOutboxWriter implements OutboxWriter
{
    /**
     * The archive holds published rows only, by construction, so it is prunable by age alone; the BRIN
     * on `archived_at` serves this.
     */
    private const string ARCHIVE_PRUNABLE = "archived_at < now() - (CAST(:age AS bigint) * interval '1 second')";

    public function __construct(
        private Connection $connection,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws Exception on a DBAL failure inserting the outbox rows, which rolls back the append
     */
    public function write(array $entries): void
    {
        foreach (array_chunk($entries, InsertChunk::ROWS) as $chunk) {
            $params = [];

            foreach ($chunk as $entry) {
                $params[] = $entry->position;
                $params[] = $entry->partitionKey;
                $params[] = $entry->type;
                $params[] = $entry->eventVersion;
            }

            // The row names the event and carries no payload copy. The relay reads `header` and
            // `content` from `event_store` when claiming the publication work.
            $placeholders = $chunk
                    |> count(...)
                    |> (static fn ($x) => array_fill(0, $x, '(?, ?, ?, ?)'))
                    |> (static fn ($x) => implode(', ', $x));

            $this->connection->executeStatement(
                /* language=PostgreSQL */
                'INSERT INTO es_outbox (position, partition_key, type, event_version)
                 VALUES '.$placeholders,
                $params,
            );
        }
    }

    /**
     * Count the archived rows older than `$ageSeconds`, the dry-run preview of `pruneArchive()`.
     *
     * @throws Exception on a DBAL read failure
     */
    public function countArchivedOlderThan(int $ageSeconds): int
    {
        return (int) $this->connection->fetchOne(
            /* language=PostgreSQL */
            'SELECT count(*) FROM es_outbox_archive WHERE '.self::ARCHIVE_PRUNABLE,
            ['age' => $ageSeconds],
        );
    }

    /**
     * Prune the archive by age, the cold table's retention under `storm.outbox.disposal: archive`.
     *
     * Under `delete` disposal the archive is empty and this is a no-op. It cannot race the live relay,
     * which only ever inserts here through the atomic move and never reads.
     *
     * @param  positive-int  $batch
     * @return int rows pruned
     *
     * @throws Exception on a DBAL delete failure
     */
    public function pruneArchive(int $ageSeconds, int $batch): int
    {
        return BatchedDelete::run($this->connection, 'es_outbox_archive', self::ARCHIVE_PRUNABLE, ['age' => $ageSeconds], $batch);
    }
}
