<?php

declare(strict_types=1);

namespace Storm\Chronicler\Store;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Storm\Stream\Stream;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Wraps each append, both writer verbs, in one DBAL transaction. The inner decorator of the
 * writing chain, closest to the Postgres store; reads never pass through here.
 *
 * A caller that needs several operations to commit atomically opens a transaction on the DBAL
 * `Connection` itself; the append then rides that transaction as it is, so the whole batch commits
 * or rolls back together. Inside such a caller-owned transaction this decorator stands down, like
 * the retry decorator: no savepoint pair around the append. What a pair would buy is a caller
 * that catches an append which ABORTED the transaction, `DuplicateVersion` on the stream version
 * backstop or an outbox identity collision, and goes on in that same transaction; no caller does
 * (storm-internal #589, the inventory), every one lets it roll back, and a savepoint cost a round
 * trip per append, 2.7 million per flood. The contract inside a caller transaction is therefore:
 * a `StaleVersion` aborts nothing and the caller may go on; a `DuplicateVersion` leaves the
 * transaction aborted and the caller can only roll it back, the connection clean afterwards.
 *
 * @see PostgresEventStore
 * @see RetryEventStore
 */
#[AsDecorator(DecisionAppend::class, priority: 10)]
final readonly class DbalTransactionalEventStore implements DecisionAppend, FactAppend
{
    public function __construct(
        #[AutowireDecorated]
        private DecisionAppend&FactAppend $eventStore,
        private Connection $connection,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws Exception on a DBAL failure of the wrapped append / the commit / rollback itself
     */
    public function appendTo(Stream $stream, int $expectedVersion): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->eventStore->appendTo($stream, $expectedVersion);

            return;
        }

        $this->connection->transactional(fn () => $this->eventStore->appendTo($stream, $expectedVersion));
    }

    /**
     * {@inheritDoc}
     *
     * @throws Exception on a DBAL failure of the wrapped append / the commit / rollback itself
     */
    public function appendAny(Stream $stream): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->eventStore->appendAny($stream);

            return;
        }

        $this->connection->transactional(fn () => $this->eventStore->appendAny($stream));
    }
}
