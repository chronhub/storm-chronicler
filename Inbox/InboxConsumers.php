<?php

declare(strict_types=1);

namespace Storm\Chronicler\Inbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use LogicException;

use function is_numeric;
use function sprintf;

/**
 * The registry of consumer names behind `es_inbox.consumer_id`.
 *
 * A transport name is registered the first time a proof is recorded under it and never again, so each
 * proof carries two bytes of consumer rather than the name repeated on every row.
 *
 * The lookup runs once per name per process: the id is read from the cache, then from the table, and
 * an unknown name is inserted. The insert is `ON CONFLICT DO UPDATE ... RETURNING`, so two processes
 * registering the same name at once both get the one id.
 *
 * The cache holds committed ids only. A lookup made while a transaction is open on the connection
 * returns the id but does not cache it: had the insert joined that transaction and the transaction
 * rolled back, the cached id would name a row that no longer exists, the next proofs would be written
 * under it, and a later registration would mint a second id for the same name, splitting the
 * deduplication in two. The stores therefore resolve the consumer before opening their transaction,
 * where the insert autocommits, and a caller that is itself inside a transaction pays one read per
 * proof until it commits.
 */
final class InboxConsumers
{
    /** @var array<string, int> */
    private array $ids = [];

    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * @throws Exception on a DBAL failure reading or registering the name
     * @throws LogicException when the registry answers no id, which no committed row can do
     */
    public function id(string $consumer): int
    {
        if (isset($this->ids[$consumer])) {
            return $this->ids[$consumer];
        }

        $connection = $this->connection;
        $id = $connection->fetchOne(
            /* language=PostgreSQL */
            'SELECT id FROM es_inbox_consumers WHERE name = :name',
            ['name' => $consumer],
        );
        if ($id === false) {
            $id = $connection->fetchOne(
                /* language=PostgreSQL */
                'INSERT INTO es_inbox_consumers (name) VALUES (:name)
                 ON CONFLICT (name) DO UPDATE SET name = EXCLUDED.name
                 RETURNING id',
                ['name' => $consumer],
            );
        }

        // `$id` is numeric past the first test, and a numeric string compares with 1 as its number, so
        // dropping the `(int)` here is an equivalent mutant, left without an ignore: the comparison and
        // the disjunction on this line are killed, and an ignore would mask them
        if (! is_numeric($id) || (int) $id < 1) {
            throw new LogicException(sprintf('The inbox registry answered no id for consumer "%s".', $consumer));
        }

        if ($connection->getTransactionNestingLevel() === 0) {
            $this->ids[$consumer] = (int) $id;
        }

        return (int) $id;
    }
}
