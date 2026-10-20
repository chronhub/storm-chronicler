<?php

declare(strict_types=1);

namespace Storm\Chronicler\Inbox;

use Throwable;

/**
 * Batched consumer-side idempotency: N handlers, each at most once per `(consumer, messageId)`.
 *
 * They run inside one transaction and one `COMMIT`. This is the throughput counterpart of the
 * per-message `InboxStore::once()`, which takes one transaction per message. Amortizing the per-event
 * commit over N messages is the standard consume-side batching technique, as in Akka `groupedWithin`,
 * Axon `batchSize` and Kafka `max.poll.records`: a commit costs an fsync on a durable cluster, plus the
 * WAL record, the round trip, the lock release and the syscall in every case.
 *
 * The exactly-once-effect window widens from one message to one batch: the whole batch commits or rolls
 * back together. At-least-once still holds: a rolled-back batch is redelivered, and the `ON CONFLICT`
 * deduplication skips again the rows already recorded.
 *
 * @see InboxStore the per-message sibling
 */
interface BatchInboxStore
{
    /**
     * Record each item's `(consumer, messageId)` and run its handler, all in one transaction.
     *
     * A duplicate item is a no-op, and the handlers run in array order.
     *
     * @param  list<InboxItem>  $items
     * @return list<bool> one entry per item, in order: true if the handler ran on first delivery, false
     *                    if skipped as already processed
     *
     * @throws Throwable propagated from any handler; the whole batch is rolled back with it, so every
     *                   message in the batch is reprocessed on redelivery. The caller isolates the poison
     *                   by replaying the batch one message at a time on the per-message path.
     */
    public function onceBatch(string $consumer, array $items): array;
}
