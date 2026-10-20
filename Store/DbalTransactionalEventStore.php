<?php

declare(strict_types=1);

namespace Storm\Chronicler\Store;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Storm\Stream\Stream;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Wraps each append, through either writer verb, in one DBAL transaction.
 *
 * It is the inner decorator of the writing chain, closest to the Postgres store; reads never pass
 * through here.
 *
 * A caller that needs several operations to commit atomically opens a transaction on the DBAL
 * `Connection` itself; the append then joins that transaction as it is, so the whole batch commits or
 * rolls back together. Inside such a caller-owned transaction this decorator stands down, like the
 * retry decorator, with no savepoint pair around the append. A pair would let a caller catch an append
 * that aborted the transaction, a `DuplicateVersion` on the stream version backstop or an outbox
 * identity collision, and continue in that same transaction; without savepoints, the caller owns
 * rollback after an abort. Inside a caller transaction:
 *
 * - A `StaleVersion` aborts nothing, and the caller may go on.
 *
 * - A `DuplicateVersion` leaves the transaction aborted: the caller can only roll it back, and the
 *   connection is clean afterwards.
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
     * @throws Exception on a DBAL failure of the wrapped append, the commit or the rollback itself
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
     * @throws Exception on a DBAL failure of the wrapped append, the commit or the rollback itself
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
