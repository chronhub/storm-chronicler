<?php

declare(strict_types=1);

namespace Storm\Chronicler\Erasure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Storm\Chronicler\Directory\StreamHeadStore;
use Storm\Chronicler\Exception\StorageFailure;
use Storm\Stream\StreamName;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * DBAL StreamEraser: deletes the stream's `event_store` rows and its undelivered `es_outbox` rows,
 * and delegates the stream-head removal to StreamHeadStore, all inside one transaction fenced on the
 * stream's head.
 *
 * The transaction alone gives atomicity, not exclusion: it stops a HALF-DONE erase, not an erase
 * INTERLEAVED with a writer. Since every append verb writes the head first, taking that row before
 * the first delete makes the two orders mutually exclusive; see the fence note in `erase()` for the
 * interleaving it closes.
 */
#[AsAlias(StreamEraser::class)]
final readonly class DbalStreamEraser implements StreamEraser
{
    public function __construct(
        private Connection $connection,
        private StreamHeadStore $streamHeads,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws Exception on a DBAL failure of the deletion
     * @throws StorageFailure on a store failure of the stream-head deletion
     */
    public function erase(StreamName $streamName): int
    {
        $stream = $streamName->toString();
        $category = $streamName->category;
        $removed = 0;

        $this->connection->transactional(function () use ($stream, $category, &$removed): void {
            // FIRST, before deleting anything: hold the stream's head, the row every append verb
            // writes as ITS first statement. One transaction is not enough on its own: it makes the
            // erase atomic, not exclusive. Without the fence, a concurrent append slips between the
            // event delete and the head delete: its rows are invisible to the delete's snapshot and
            // survive, then the head delete removes the head it just wrote, leaving durable events
            // and outbox rows with NO head. `hasStream()` then answers false while the rows are
            // there, and no decision append can recreate the head, since new-stream creation only
            // accepts `expectedVersion = 0`; the stream is unrecoverable. With the fence, the writer
            // either lands entirely before the erase and is swept with the rest, or entirely after it
            // and starts a fresh, coherent stream.
            $this->streamHeads->lockForErase($stream);

            // `category` is redundant with the CHECK (category = split_part(stream, '-', 1)) but it is
            // what lets the planner prune to ONE partition and ride the (category, stream, version)
            // index; without it, an erase seq-scans every partition of the store.
            $removed = (int) $this->connection->executeStatement(
                /* language=PostgreSQL */
                'DELETE FROM event_store WHERE category = :category AND stream = :stream',
                ['category' => $category, 'stream' => $stream],
            );

            // The stream's publication-index rows die with it in the SAME transaction, so no work
            // remains for events whose payload has been erased. Every row still in this table is undelivered by
            // construction, pending or dead-lettered, since a delivered one has already left it,
            // deleted or moved to the archive. That archive is the delivery audit and is NOT the
            // stream's to erase.
            $this->connection->executeStatement(
                /* language=PostgreSQL */
                'DELETE FROM es_outbox WHERE partition_key = :stream',
                ['stream' => $stream],
            );

            $this->streamHeads->delete($stream);
        });

        return $removed;
    }
}
