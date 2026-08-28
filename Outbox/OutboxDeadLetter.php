<?php

declare(strict_types=1);

namespace Storm\Chronicler\Outbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use Storm\Chronicler\Console\OutboxFailedCommand;

/**
 * Inspect and recover the outbox dead-letter, `status = 'failed'`: list the dead-lettered rows,
 * requeue them to `pending` for the relay to republish, or purge them. Owns the `es_outbox`
 * dead-letter SQL so the {@see OutboxFailedCommand} stays pure presentation. This is the operator
 * path, off the hot relay loop, a separate seam from {@see DbalOutboxWriter}, which only ever appends.
 *
 * Every operation is optionally scoped to a single row id; with no id it acts on the whole dead-letter.
 */
final readonly class OutboxDeadLetter
{
    public function __construct(
        private Connection $connection,
    ) {}

    /**
     * The dead-lettered rows, optionally one id, ordered by id, the relay's drain order, so the view
     * matches the order a requeue would republish in.
     *
     * @return list<FailedOutboxMessage>
     *
     * @throws Exception on a DBAL read failure
     */
    public function listFailed(?int $id): array
    {
        [$where, $params, $types] = $this->scope($id);

        /** @var list<array{id: int|string, position: int|string, type: string, attempts: int|string, last_error: ?string, failed_at: ?string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            /** @lang PostgreSQL */
            'SELECT id, position, type, attempts, last_error, failed_at FROM es_outbox '.$where.' ORDER BY id',
            $params,
            $types,
        );

        return $this->toMessages($rows);
    }

    /**
     * One keyset page of the dead-letter, the rows whose id is strictly greater than `$afterId`,
     * in the same drain order.
     *
     * `listFailed()` answers a console that renders whatever it is handed; a page answers a caller
     * that cannot be trusted to ask for little, so the cap rides the SQL instead of a slice taken
     * once the whole dead-letter is already in memory.
     *
     * @param  non-negative-int  $afterId  zero starts at the first row, ids being one-based
     * @param  positive-int  $limit  rows at most, the caller having already clamped it
     * @return list<FailedOutboxMessage>
     *
     * @throws Exception on a DBAL read failure
     */
    public function listFailedPage(int $afterId, int $limit): array
    {
        /** @var list<array{id: int|string, position: int|string, type: string, attempts: int|string, last_error: ?string, failed_at: ?string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            /** @lang PostgreSQL */
            "SELECT id, position, type, attempts, last_error, failed_at FROM es_outbox WHERE status = 'failed' AND id > :after ORDER BY id LIMIT :limit",
            ['after' => $afterId, 'limit' => $limit],
            ['after' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER],
        );

        return $this->toMessages($rows);
    }

    /**
     * Requeue the dead-letter to `pending` so the relay republishes it, clearing every terminal marker,
     * `attempts`, `last_error`, `next_attempt_at`, and `failed_at`, so the row looks pristine.
     *
     * @return int rows requeued
     *
     * @throws Exception on a DBAL update failure
     */
    public function requeue(?int $id): int
    {
        [$where, $params, $types] = $this->scope($id);

        return (int) $this->connection->executeStatement(
            /** @lang PostgreSQL */
            "UPDATE es_outbox SET status = 'pending', attempts = 0, last_error = NULL, "
            .'next_attempt_at = NULL, failed_at = NULL '.$where,
            $params,
            $types,
        );
    }

    /**
     * How many rows the same scope would act on, without loading them: what a destructive command
     * must be able to state BEFORE it asks for confirmation, since "purge?" and "purge 12 000 rows,
     * the only record of why they failed?" are not the same question.
     *
     * @throws Exception on a DBAL read failure
     */
    public function countFailed(?int $id): int
    {
        [$where, $params, $types] = $this->scope($id);

        return (int) $this->connection->fetchOne(
            /** @lang PostgreSQL */
            'SELECT count(*) FROM es_outbox '.$where,
            $params,
            $types,
        );
    }

    /**
     * Delete the dead-lettered rows for good.
     *
     * @return int rows purged
     *
     * @throws Exception on a DBAL delete failure
     */
    public function purge(?int $id): int
    {
        [$where, $params, $types] = $this->scope($id);

        return (int) $this->connection->executeStatement(
            /** @lang PostgreSQL */
            'DELETE FROM es_outbox '.$where,
            $params,
            $types,
        );
    }

    /**
     * @param  list<array{id: int|string, position: int|string, type: string, attempts: int|string, last_error: ?string, failed_at: ?string}>  $rows
     * @return list<FailedOutboxMessage>
     */
    private function toMessages(array $rows): array
    {
        return array_map(static fn (array $row): FailedOutboxMessage => new FailedOutboxMessage(
            id: (int) $row['id'],
            position: (int) $row['position'],
            type: $row['type'],
            attempts: (int) $row['attempts'],
            lastError: $row['last_error'],
            failedAt: $row['failed_at'],
        ), $rows);
    }

    /**
     * The `WHERE status = 'failed'` clause, optionally narrowed to one id.
     *
     * @return array{0: string, 1: array<string, int>, 2: array<string, ParameterType>}
     */
    private function scope(?int $id): array
    {
        if ($id !== null) {
            return ["WHERE status = 'failed' AND id = :id", ['id' => $id], ['id' => ParameterType::INTEGER]];
        }

        return ["WHERE status = 'failed'", [], []];
    }
}
