<?php

declare(strict_types=1);

namespace Storm\Chronicler\Inbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Storm\Chronicler\Store\InsertChunk;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use function array_chunk;
use function array_fill;
use function count;
use function implode;

/**
 * Default BatchInboxStore: records every `(consumer, message_id)` and runs each handler inside one
 * DBAL transaction, with the whole batch's dedup rows written by a single multi-VALUES INSERT.
 *
 * The dedup gate is `INSERT … ON CONFLICT DO NOTHING RETURNING message_id`: the RETURNING set contains
 * exactly the rows that were actually inserted, the fresh messages. An id absent from the set was
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

        return $this->connection->transactional(
            function (Connection $tx) use ($consumer, $items): array {
                $fresh = $this->recordFresh($tx, $consumer, $items);

                $ran = [];

                foreach ($items as $item) {
                    if (! isset($fresh[$item->messageId])) {
                        $ran[] = false;

                        continue;
                    }

                    // Consume on use: an intra-batch duplicate finds its id already spent and is
                    // reported false, exactly like the per-row ON CONFLICT gate would answer.
                    unset($fresh[$item->messageId]);

                    ($item->handler)();

                    $ran[] = true;
                }

                return $ran;
            },
        );
    }

    /**
     * One multi-VALUES dedup INSERT for the whole batch, chunked; the RETURNING set is the fresh ids.
     *
     * @param  list<InboxItem>  $items
     * @return array<string, true> the message ids actually inserted, as a set
     *
     * @throws Exception on a DBAL failure recording the inbox rows
     */
    private function recordFresh(Connection $tx, string $consumer, array $items): array
    {
        $fresh = [];
        $sortedItems = $items;
        usort($sortedItems, static fn (InboxItem $a, InboxItem $b): int => strcmp($a->messageId, $b->messageId));

        foreach (array_chunk($sortedItems, InsertChunk::ROWS) as $chunk) {
            $params = [];

            foreach ($chunk as $item) {
                $params[] = $consumer;
                $params[] = $item->messageId;
            }

            $placeholders = $chunk
                    |> count(...)
                    |> (static fn ($x) => array_fill(0, $x, '(?, ?)'))
                    |> (static fn ($x) => implode(', ', $x));

            $inserted = $tx->fetchFirstColumn(
                /** @lang PostgreSQL */
                // the conflict target is NAMED: only the dedup key may absorb a violation into
                // "already processed"; any future constraint on the table stays loud
                'INSERT INTO es_inbox (consumer, message_id) VALUES '.$placeholders.'
                 ON CONFLICT (consumer, message_id) DO NOTHING
                 RETURNING message_id',
                $params,
            );

            foreach ($inserted as $messageId) {
                $fresh[(string) $messageId] = true;
            }
        }

        return $fresh;
    }
}
