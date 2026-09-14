<?php

declare(strict_types=1);

namespace Storm\Chronicler\Inbox;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Psr\Log\LoggerInterface;
use Storm\Support\Dbal\BatchedDelete;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Throwable;

/**
 * Default InboxStore: records `(consumer, message_id)` and runs the handler in one DBAL transaction.
 *
 * `INSERT … ON CONFLICT DO NOTHING` is the dedup gate: 0 rows affected means already processed, so it
 * skips and the handler never runs. Otherwise, the handler runs inside the same transaction, so its
 * effects on the default connection and the inbox row commit together; a throw rolls both back and
 * the message is reprocessed on redelivery. Atomicity covers DB effects on this connection, not
 * external side effects like HTTP or mail, which stay at-least-once.
 *
 * A skip is counted where it happens: the row keeps how many redeliveries it absorbed in
 * `duplicates` and the last one in `last_duplicate_at`, and a logger, when one is given, gets
 * `storm.chronicler.inbox_duplicate` at info. Without them a working inbox is silent, and nothing
 * outside it says a duplicate ever came.
 */
#[AsAlias(InboxStore::class)]
final readonly class DbalInboxStore implements InboxStore
{
    /** A dedup record is prunable once redelivery is no longer possible; purely age-based. */
    private const string PRUNABLE = "processed_at < now() - (CAST(:age AS bigint) * interval '1 second')";

    public function __construct(
        private Connection $connection,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws Exception on a DBAL failure recording the inbox row
     */
    public function once(string $consumer, string $messageId, Closure $handler): bool
    {
        return $this->connection->transactional(
            function (Connection $tx) use ($consumer, $messageId, $handler): bool {
                $inserted = $tx->executeStatement(
                    /** @lang PostgreSQL */
                    // the conflict target is NAMED: only the dedup key may absorb a violation into
                    // "already processed"; any future constraint on the table stays loud
                    'INSERT INTO es_inbox (consumer, message_id) VALUES (:consumer, :message_id)
                     ON CONFLICT (consumer, message_id) DO NOTHING',
                    ['consumer' => $consumer, 'message_id' => $messageId],
                );

                if ($inserted === 0) {
                    $duplicates = (int) $tx->fetchOne(
                        /* language=PostgreSQL */
                        'UPDATE es_inbox SET duplicates = duplicates + 1, last_duplicate_at = clock_timestamp()
                         WHERE consumer = :consumer AND message_id = :message_id
                         RETURNING duplicates',
                        ['consumer' => $consumer, 'message_id' => $messageId],
                    );
                    $this->logDuplicate($consumer, $messageId, $duplicates);

                    return false;
                }

                $handler();

                return true;
            },
        );
    }

    // fail-open, as every observation around a commit: a logger that throws must not undo the skip
    private function logDuplicate(string $consumer, string $messageId, int $duplicates): void
    {
        if ($this->logger === null) {
            return;
        }

        try {
            $this->logger->info('storm.chronicler.inbox_duplicate', [
                'consumer' => $consumer,
                'message_id' => $messageId,
                'duplicates' => $duplicates,
            ]);
        } catch (Throwable) {
            // the observation may lose its line; it may never cost the inbox its answer
        }
    }

    /**
     * Count the dedup records older than `$ageSeconds`, the dry-run preview of `prune()`.
     *
     * @throws Exception on a DBAL read failure
     */
    public function countPrunable(int $ageSeconds): int
    {
        return (int) $this->connection->fetchOne(
            /** @lang PostgreSQL */
            'SELECT count(*) FROM es_inbox WHERE '.self::PRUNABLE,
            ['age' => $ageSeconds],
        );
    }

    /**
     * Prune the dedup records older than `$ageSeconds`, in `$batch`-capped statements so it never holds
     * a long lock.
     *
     * @param  positive-int  $batch
     * @return int rows pruned
     *
     * @throws Exception on a DBAL delete failure
     */
    public function prune(int $ageSeconds, int $batch): int
    {
        return BatchedDelete::run($this->connection, 'es_inbox', self::PRUNABLE, ['age' => $ageSeconds], $batch);
    }
}
