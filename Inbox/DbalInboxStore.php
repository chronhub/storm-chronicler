<?php

declare(strict_types=1);

namespace Storm\Chronicler\Inbox;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Storm\Support\Dbal\BatchedDelete;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Default InboxStore: records `(consumer, message_id)` and runs the handler in one DBAL transaction.
 *
 * `INSERT … ON CONFLICT DO NOTHING` is the dedup gate: 0 rows affected means already processed, so it
 * skips and the handler never runs. Otherwise, the handler runs inside the same transaction, so its
 * effects on the default connection and the inbox row commit together; a throw rolls both back and
 * the message is reprocessed on redelivery. Atomicity covers DB effects on this connection, not
 * external side effects like HTTP or mail, which stay at-least-once.
 */
#[AsAlias(InboxStore::class)]
final readonly class DbalInboxStore implements InboxStore
{
    /** A dedup record is prunable once redelivery is no longer possible; purely age-based. */
    private const string PRUNABLE = "processed_at < now() - (CAST(:age AS bigint) * interval '1 second')";

    public function __construct(
        private Connection $connection,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws Exception on a DBAL failure recording the inbox row
     */
    public function once(string $consumer, string $messageId, Closure $handler): bool
    {
        return $this->connection->transactional(
            static function (Connection $tx) use ($consumer, $messageId, $handler): bool {
                $inserted = $tx->executeStatement(
                    /** @lang PostgreSQL */
                    // the conflict target is NAMED: only the dedup key may absorb a violation into
                    // "already processed"; any future constraint on the table stays loud
                    'INSERT INTO es_inbox (consumer, message_id) VALUES (:consumer, :message_id)
                     ON CONFLICT (consumer, message_id) DO NOTHING',
                    ['consumer' => $consumer, 'message_id' => $messageId],
                );

                if ($inserted === 0) {
                    return false;
                }

                $handler();

                return true;
            },
        );
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
