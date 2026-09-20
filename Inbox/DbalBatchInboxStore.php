<?php

declare(strict_types=1);

namespace Storm\Chronicler\Inbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Storm\Chronicler\Store\InsertChunk;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use function array_chunk;
use function array_fill;
use function array_values;
use function bin2hex;
use function count;
use function implode;
use function sort;

/**
 * Default BatchInboxStore: records every `(consumer, message_id)` and runs each handler inside one
 * DBAL transaction, with the whole batch's dedup rows written by a single multi-VALUES INSERT.
 *
 * The dedup gate is `INSERT … ON CONFLICT DO NOTHING RETURNING message_id`, the keys in hex both ways: the
 * RETURNING set contains exactly the rows that were actually inserted, the fresh messages. A key absent from the set was
 * already recorded, in an earlier batch or an earlier occurrence in THIS batch; `DO NOTHING` also
 * absorbs an intra-statement duplicate, unlike `DO UPDATE` which would refuse to affect a row twice.
 * Handlers then run in delivery order, never in RETURNING order; a fresh id is consumed on use so a
 * duplicate inside the batch runs its first occurrence only, the exact per-row behavior minus N-1
 * round-trips.
 *
 * All handlers run inside the shared transaction, so all N handlers' effects on the default connection
 * and all N inbox rows commit together in one COMMIT, one fsync on a durable cluster. A throw from any
 * handler rolls back the whole batch, including the dedup rows and the effects of the items that
 * already succeeded in it, so nothing is half-applied and the caller reprocesses on redelivery.
 *
 * Batches larger than {@see InsertChunk::ROWS} are split into several statements in the same
 * transaction: the chunk is a safety rail against the pdo_pgsql parameter ceiling, not a tuning knob,
 * and no real caller with prefetch-bounded batches comes near it.
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

        // resolved BEFORE the transaction, so a first registration autocommits and is cacheable
        $consumerId = $this->consumers->id($consumer);

        return $this->connection->transactional(
            function (Connection $tx) use ($consumerId, $items): array {
                [$fresh, $keys] = $this->recordFresh($tx, $consumerId, $items);

                $ran = [];

                foreach ($items as $item) {
                    $key = $keys[$item->messageId];
                    if (! isset($fresh[$key])) {
                        $ran[] = false;

                        continue;
                    }

                    // Consume on use, BY KEY: an intra-batch duplicate, or two spellings of one id
                    // that encode to one key, find it already spent and are reported false, exactly
                    // like the per-row ON CONFLICT gate would answer.
                    unset($fresh[$key]);

                    ($item->handler)();

                    $ran[] = true;
                }

                return $ran;
            },
        );
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
        $keys = [];
        foreach ($items as $item) {
            $keys[$item->messageId] ??= bin2hex(InboxKey::encode($item->messageId));
        }
        $sortedKeys = array_values($keys);
        sort($sortedKeys, SORT_STRING);

        $fresh = [];
        foreach (array_chunk($sortedKeys, InsertChunk::ROWS) as $chunk) {
            $params = [];

            foreach ($chunk as $key) {
                $params[] = $consumerId;
                $params[] = $key;
            }

            $placeholders = $chunk
                    |> count(...)
                    |> (static fn ($x) => array_fill(0, $x, "(?, decode(?, 'hex'))"))
                    |> (static fn ($x) => implode(', ', $x));

            $inserted = $tx->fetchFirstColumn(
                /** @lang PostgreSQL */
                // the conflict target is NAMED: only the dedup key may absorb a violation into
                // "already processed"; any future constraint on the table stays loud
                'INSERT INTO es_inbox (consumer_id, message_id) VALUES '.$placeholders.'
                 ON CONFLICT (consumer_id, message_id) DO NOTHING
                 RETURNING encode(message_id, \'hex\')',
                $params,
            );

            foreach ($inserted as $key) {
                $fresh[(string) $key] = true;
            }
        }

        return [$fresh, $keys];
    }
}
