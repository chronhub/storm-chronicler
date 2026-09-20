<?php

declare(strict_types=1);

namespace Storm\Chronicler\Inbox;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use Storm\Support\Dbal\BatchedDelete;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Throwable;

use function count;
use function hex2bin;
use function sort;
use function sprintf;

/**
 * Default InboxStore: records `(consumer, message_id)` and runs the handler in one DBAL transaction.
 *
 * The row carries the consumer as the two-byte id `InboxConsumers` registered for its name, and the
 * message id in the binary form `InboxKey` gives it; the pair the contract names is what the caller
 * passes, the pair the table stores is what a proof costs.
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
    /**
     * A key whose first 48 bits are a minting instant: sixteen bytes carrying the version nibble 7. A
     * v4 uuid has the length and random leading bits, so the version is READ, never assumed.
     */
    private const string V7_KEY = 'octet_length(message_id) = 16 AND get_byte(message_id, 6) >> 4 = 7';

    /**
     * The distinct `consumer_id` values of the table, by a recursive skip along the leading column of
     * the primary index: `SELECT DISTINCT` would walk the whole table for a handful of values.
     */
    private const string CONSUMERS_OF_THE_TABLE = <<<'SQL'
        WITH RECURSIVE s AS (
            SELECT min(consumer_id) AS c FROM es_inbox
            UNION ALL
            SELECT (SELECT min(consumer_id) FROM es_inbox WHERE consumer_id > s.c) FROM s WHERE s.c IS NOT NULL
        )
        SELECT c FROM s WHERE c IS NOT NULL ORDER BY c
        SQL;

    /**
     * Every other key, `idem-` and hashed texts, non-v7 uuids, is prunable by age alone: its bytes say
     * nothing of its time.
     */
    private const string PRUNABLE_BY_AGE = "NOT (octet_length(message_id) = 16 AND get_byte(message_id, 6) >> 4 = 7) AND processed_at < now() - (CAST(:age AS bigint) * interval '1 second')";

    public function __construct(
        private Connection $connection,
        private InboxConsumers $consumers,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws Exception on a DBAL failure recording the inbox row
     */
    public function once(string $consumer, string $messageId, Closure $handler): bool
    {
        // resolved BEFORE the transaction: a first registration then autocommits and can be cached,
        // where one made inside the handler's transaction would vanish with its rollback
        $consumerId = $this->consumers->id($consumer);
        $key = InboxKey::encode($messageId);

        return $this->connection->transactional(
            function (Connection $tx) use ($consumerId, $key, $consumer, $messageId, $handler): bool {
                $inserted = $tx->executeStatement(
                    /** @lang PostgreSQL */
                    // the conflict target is NAMED: only the dedup key may absorb a violation into
                    // "already processed"; any future constraint on the table stays loud
                    'INSERT INTO es_inbox (consumer_id, message_id) VALUES (:consumer, :message_id)
                     ON CONFLICT (consumer_id, message_id) DO NOTHING',
                    ['consumer' => $consumerId, 'message_id' => $key],
                    ['message_id' => ParameterType::BINARY],
                );

                if ($inserted === 0) {
                    $duplicates = (int) $tx->fetchOne(
                        /* language=PostgreSQL */
                        'UPDATE es_inbox SET duplicates = duplicates + 1, last_duplicate_at = clock_timestamp()
                         WHERE consumer_id = :consumer AND message_id = :message_id
                         RETURNING duplicates',
                        ['consumer' => $consumerId, 'message_id' => $key],
                        ['message_id' => ParameterType::BINARY],
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
     * The dedup records older than `$ageSeconds`: the v7 keys below the bound of that instant, by the
     * primary index over every consumer the table holds, plus the other keys by their `processed_at`.
     *
     * @throws Exception on a DBAL failure
     */
    public function countPrunable(int $ageSeconds): int
    {
        // no consumer predicate: the count spans every consumer the table holds, and PostgreSQL
        // skips through the leading column of the primary index by itself
        $byKey = (int) $this->connection->fetchOne(
            /** @lang PostgreSQL */
            'SELECT count(*) FROM es_inbox WHERE '.self::V7_KEY.' AND message_id < :bound',
            ['bound' => $this->bound($ageSeconds)],
            ['bound' => ParameterType::BINARY],
        );
        $byAge = (int) $this->connection->fetchOne(
            /** @lang PostgreSQL */
            'SELECT count(*) FROM es_inbox WHERE '.self::PRUNABLE_BY_AGE,
            ['age' => $ageSeconds],
        );

        return $byKey + $byAge;
    }

    /**
     * Prune the dedup records older than `$ageSeconds`, in `$batch`-capped statements so it never holds
     * a long lock.
     *
     * A v7 key carries its minting instant in its first 48 bits, so the retention is a RANGE of the
     * primary index per consumer, walked with a cursor: each batch starts after the last key the
     * previous one deleted, at the frontier of what remains, rather than at the bound where the entries
     * of the rows already gone sit dead until vacuum. PostgreSQL marks a dead entry the first time a
     * scan meets it, so the cursor spares reads rather than a rising cost; a row that `SKIP LOCKED`
     * passed over and that sorts below the cursor waits for the next call, the purge being idempotent.
     * The consumers come from the table itself, read by a skip through the leading column of the
     * primary index, so a proof recorded under an id the registry lost is purged all the same and a
     * call that finds nothing to delete reads a few index pages, never the table. The keys of another
     * shape, few, go by age.
     *
     * The retention counts from the MINTING instant, not the consumption: a message consumed after a
     * queue lag is retired lag earlier than its `processed_at` says, so `$ageSeconds` must exceed the
     * redelivery window PLUS the longest queue lag.
     *
     * @param  positive-int  $batch
     * @return int rows pruned
     *
     * @throws Exception on a DBAL delete failure
     */
    public function prune(int $ageSeconds, int $batch): int
    {
        $bound = $this->bound($ageSeconds);
        $total = 0;
        foreach ($this->connection->fetchFirstColumn(self::CONSUMERS_OF_THE_TABLE) as $consumerId) {
            $cursor = '';
            do {
                $deleted = $this->connection->fetchFirstColumn(
                    /** @lang PostgreSQL */
                    sprintf(
                        'DELETE FROM es_inbox WHERE ctid IN (
                            SELECT ctid FROM es_inbox
                            WHERE consumer_id = :consumer AND %s AND message_id > :cursor AND message_id < :bound
                            ORDER BY message_id LIMIT %d FOR UPDATE SKIP LOCKED
                        ) RETURNING encode(message_id, \'hex\')',
                        self::V7_KEY,
                        $batch,
                    ),
                    ['consumer' => (int) $consumerId, 'cursor' => $cursor, 'bound' => $bound],
                    ['cursor' => ParameterType::BINARY, 'bound' => ParameterType::BINARY],
                );
                $total += count($deleted);
                if ($deleted !== []) {
                    // the hex of a key sorts as its bytes do: the largest hex is the largest key
                    sort($deleted, SORT_STRING);
                    $cursor = (string) hex2bin((string) $deleted[count($deleted) - 1]);
                }
            } while ($deleted !== []);
        }

        return $total + BatchedDelete::run($this->connection, 'es_inbox', self::PRUNABLE_BY_AGE, ['age' => $ageSeconds], $batch);
    }

    /**
     * The v7 floor of the instant `$ageSeconds` ago, read on the database clock so the retention and
     * the rows' own timestamps agree.
     *
     * @throws Exception on a DBAL failure
     */
    private function bound(int $ageSeconds): string
    {
        $milliseconds = (int) $this->connection->fetchOne(
            /* language=PostgreSQL */
            'SELECT ((EXTRACT(EPOCH FROM now()) - CAST(:age AS bigint)) * 1000)::bigint',
            ['age' => $ageSeconds],
        );

        return InboxKey::v7Floor($milliseconds);
    }
}
