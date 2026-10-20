<?php

declare(strict_types=1);

namespace Storm\Chronicler\Inbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Storm\Chronicler\Store\InsertChunk;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use function array_chunk;
use function array_column;
use function array_fill;
use function array_fill_keys;
use function array_keys;
use function array_map;
use function array_merge;
use function array_values;
use function bin2hex;
use function count;
use function implode;
use function sort;

/**
 * The default `BatchInboxStore`, recording and running a whole batch in one DBAL transaction.
 *
 * The batch's deduplication rows go in by a single multi-`VALUES` `INSERT`, and the gate is
 * `INSERT … ON CONFLICT DO NOTHING RETURNING message_id`, the keys in hex both ways:
 *
 * - The `RETURNING` set holds exactly the rows actually inserted, the fresh messages; a key absent from
 *   it was already recorded, in an earlier batch or earlier in this batch.
 *
 * - `DO NOTHING` also absorbs a duplicate within the statement, where `DO UPDATE` would refuse to affect
 *   a row twice.
 *
 * - Handlers run in delivery order, never in `RETURNING` order. A fresh id is consumed on use, so a
 *   duplicate inside the batch runs its first occurrence only, the exact per-row behavior minus N-1
 *   round trips.
 *
 * Skipped occurrences update `duplicates` and `last_duplicate_at` by encoded key in bounded statements
 * within the same transaction; a failing handler rolls those observations back too.
 *
 * All handlers share the transaction, so their effects on the default connection commit with all N
 * inbox rows, and a throw from any handler also rolls back the effects of the items that already
 * succeeded: nothing is half-applied.
 *
 * Batches larger than `InsertChunk::ROWS` split into several statements in the same transaction: the
 * chunk is a safety rail against the pdo_pgsql parameter ceiling, not a tuning knob, and no real caller
 * with prefetch-bounded batches comes near it.
 *
 * @see DbalInboxStore the per-message sibling
 */
#[AsAlias(BatchInboxStore::class)]
final readonly class DbalBatchInboxStore implements BatchInboxStore
{
    public function __construct(
        private Connection $connection,
        private InboxConsumers $consumers,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws Exception on a DBAL failure recording the inbox rows
     */
    public function onceBatch(string $consumer, array $items): array
    {
        if ($items === []) {
            return [];
        }

        // resolved before the transaction, so a first registration autocommits and is cacheable
        $consumerId = $this->consumers->id($consumer);

        return $this->connection->transactional(
            function (Connection $tx) use ($consumerId, $items): array {
                [$fresh, $keys] = $this->recordFresh($tx, $consumerId, $items);

                $ran = [];
                $duplicates = [];

                foreach ($items as $item) {
                    $key = $keys[$item->messageId];
                    if (! isset($fresh[$key])) {
                        $duplicates[$key] = ($duplicates[$key] ?? 0) + 1;
                        $ran[] = false;

                        continue;
                    }

                    // Consume on use, by key: an intra-batch duplicate, or two spellings of one id
                    // that encode to one key, find it already spent and are reported false, exactly
                    // like the per-row `ON CONFLICT` gate would answer.
                    unset($fresh[$key]);

                    ($item->handler)();

                    $ran[] = true;
                }

                $this->recordDuplicates($tx, $consumerId, $duplicates);

                return $ran;
            },
        );
    }

    /**
     * @param  array<string, positive-int>  $duplicates  skipped occurrences by encoded key
     *
     * @throws Exception on a DBAL failure updating the duplicate observations
     */
    private function recordDuplicates(Connection $tx, int $consumerId, array $duplicates): void
    {
        ksort($duplicates, SORT_STRING);
        foreach (array_chunk($duplicates, InsertChunk::ROWS, true) as $chunk) {
            // the keys stay strings only because of their length: an encoded key is 32 or 64 hex
            // digits, past `PHP_INT_MAX` even when every digit is decimal, so PHP never stores one as
            // an integer; a shorter encoding would hand `array_keys` an integer for a digit-only key
            $params = array_merge(...array_map(static fn (string $key, int $count): array => [$key, $count], array_keys($chunk), $chunk));
            $params[] = $consumerId;
            $values = implode(', ', array_fill(0, count($chunk), "(decode(?, 'hex'), CAST(? AS bigint))"));

            $tx->executeStatement(
                /* language=PostgreSQL */
                'WITH skipped(message_id, duplicates) AS (VALUES '.$values.'),
                 locked AS MATERIALIZED (
                     SELECT i.consumer_id, i.message_id, s.duplicates
                     FROM es_inbox i JOIN skipped s ON s.message_id = i.message_id
                     WHERE i.consumer_id = ?
                     ORDER BY i.message_id
                     FOR UPDATE OF i
                 )
                 UPDATE es_inbox i
                 SET duplicates = i.duplicates + l.duplicates, last_duplicate_at = clock_timestamp()
                 FROM locked l
                 WHERE i.consumer_id = l.consumer_id AND i.message_id = l.message_id',
                $params,
            );
        }
    }

    /**
     * One multi-VALUES dedup INSERT for the whole batch, chunked; the RETURNING set is the fresh keys.
     *
     * @param  list<InboxItem>  $items
     * @return array{array<string, true>, array<string, string>} the keys actually inserted, as a set of
     *                                                           hex, and every item's key by message id
     *
     * @throws Exception on a DBAL failure recording the inbox rows
     */
    private function recordFresh(Connection $tx, int $consumerId, array $items): array
    {
        // the binary keys travel as hex and come back as hex: one text placeholder per key, so the
        // multi-VALUES statement needs no binary parameter typing, and the RETURNING set reads as
        // plain strings; sorted on the key, the index order, so two batches lock rows the same way
        $keys = array_map(
            static fn (string $messageId): string => bin2hex(InboxKey::encode($messageId)),
            array_column($items, 'messageId', 'messageId'),
        );
        // equivalent: `sort()` below reindexes the list itself
        // @infection-ignore-all
        $sortedKeys = array_values($keys);
        sort($sortedKeys, SORT_STRING);

        $fresh = [];
        foreach (array_chunk($sortedKeys, InsertChunk::ROWS) as $chunk) {
            $params = array_merge(...array_map(static fn (string $key): array => [$consumerId, $key], $chunk));

            // equivalent: `implode()` joins by value, so the start index of the
            // filled list, the only mutable number of this statement, changes nothing it renders
            // @infection-ignore-all
            $placeholders = $chunk
                    |> count(...)
                    |> (static fn ($x) => array_fill(0, $x, "(?, decode(?, 'hex'))"))
                    |> (static fn ($x) => implode(', ', $x));

            $inserted = $tx->fetchFirstColumn(
                // the conflict target is named: only the dedup key may absorb a violation into
                // "already processed"; any future constraint on the table stays loud
                /* language=PostgreSQL */
                'INSERT INTO es_inbox (consumer_id, message_id) VALUES '.$placeholders.'
                 ON CONFLICT (consumer_id, message_id) DO NOTHING
                 RETURNING encode(message_id, \'hex\')',
                $params,
            );

            // equivalent: a set of the hex keys `RETURNING` hands back as text,
            // read by `isset()` alone, so neither the cast nor the value is observable
            // @infection-ignore-all
            $fresh += array_fill_keys(array_map(static fn (mixed $key): string => (string) $key, $inserted), true);
        }

        return [$fresh, $keys];
    }
}
