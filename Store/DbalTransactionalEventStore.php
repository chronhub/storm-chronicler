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
 * `Connection` itself; the per-append transaction then nests within it, DBAL nesting it as a
 * savepoint, so the whole batch commits or rolls back together. Inside such a caller-owned
 * transaction the retry decorator stands down.
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
        $this->connection->transactional(fn () => $this->eventStore->appendTo($stream, $expectedVersion));
    }

    /**
     * {@inheritDoc}
     *
     * @throws Exception on a DBAL failure of the wrapped append / the commit / rollback itself
     */
    public function appendAny(Stream $stream): void
    {
        $this->connection->transactional(fn () => $this->eventStore->appendAny($stream));
    }
}
