<?php

declare(strict_types=1);

namespace Storm\Chronicler\Store;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\RetryableException;
use Storm\Chronicler\Exception\DuplicateVersion;
use Storm\Chronicler\Exception\StaleVersion;
use Storm\Contracts\Random\Jitter;
use Storm\Stream\Stream;
use Storm\Support\Random\NativeJitter;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Throwable;

/**
 * Retries both write verbs on transient DBAL failures, with exponential backoff and jitter.
 *
 * A transient failure is a deadlock, a serialization failure or a lock timeout, all
 * `RetryableException`. This is the outermost decorator of the writing chain: it reruns the whole
 * transactional append, so it must sit outside the transaction. Reads never pass through here: a
 * half-consumed generator cannot be replayed, and the store's readers carry their own retry loops, so
 * a projector polls again.
 *
 * The retry engages only when the append owns its transaction. Inside a caller-opened transaction on
 * the same connection, the append runs in that transaction with no savepoint of its own, so the
 * retryable failure rolled nothing back: the outer transaction still holds its locks, a deadlock it
 * caused recurs deterministically, and the failure has aborted the whole transaction. There the
 * append runs one attempt and the failure propagates for the outer transaction's owner to retry
 * whole.
 */
#[AsDecorator(DecisionAppend::class, priority: 0)]
final readonly class RetryEventStore implements DecisionAppend, FactAppend
{
    /**
     * @param  positive-int  $maxAttempts
     * @param  positive-int  $backoffMicros
     */
    public function __construct(
        #[AutowireDecorated]
        private DecisionAppend&FactAppend $eventStore,
        private Connection $connection,
        private int $maxAttempts = 3,
        private int $backoffMicros = 50_000,
        private Jitter $jitter = new NativeJitter,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws RetryableException re-thrown once `maxAttempts` is exhausted on transient DB failures
     * @throws StaleVersion when the wrapped store loses the OCC CAS; not retried in place, since a
     *                      re-append keeps the same expected-version and would only re-conflict, so it
     *                      is surfaced for the caller to replay from scratch by reloading at the new
     *                      version and re-deciding
     * @throws DuplicateVersion when the `UNIQUE` backstop fires; a true race a reload cannot fix, so
     *                          terminal, neither retried nor replayed
     */
    public function appendTo(Stream $stream, int $expectedVersion): void
    {
        // rerunning the whole append is safe only when each inner attempt is its own transaction,
        // fully rolled back on the retryable failure, so a retry never double-inserts nor leaves a
        // partial append; `retrying()` holds that premise by standing down under a caller's transaction
        $this->retrying(fn () => $this->eventStore->appendTo($stream, $expectedVersion));
    }

    /**
     * {@inheritDoc}
     *
     * @throws RetryableException re-thrown once `maxAttempts` is exhausted on transient DB failures
     * @throws DuplicateVersion when the `UNIQUE` backstop fires; on the fact path only reachable on a
     *                          stream mixing the CAS and fact verbs, forbidden by contract, so terminal
     */
    public function appendAny(Stream $stream): void
    {
        // no `StaleVersion` is possible here, since the bump never loses; only transient DBAL failures
        // are retried, each inner attempt being its own fully rolled-back transaction, bump included,
        // under the same ownership premise `retrying()` holds
        $this->retrying(fn () => $this->eventStore->appendAny($stream));
    }

    /**
     * @param  Closure(): void  $attempt
     *
     * @throws RetryableException re-thrown once `maxAttempts` is exhausted on transient DB failures
     * @throws Throwable every non-retryable failure of the attempt propagates unchanged; only
     *                   `RetryableException` is caught here, so `StaleVersion`, `DuplicateVersion` and
     *                   the rest pass through
     */
    private function retrying(Closure $attempt): void
    {
        if ($this->connection->isTransactionActive()) {
            // a caller-opened transaction is already active: the inner attempt runs inside it rather
            // than as its own transaction, so the rerun premise fails; one attempt, the failure propagates
            $attempt();

            return;
        }

        for ($try = 1; $try <= $this->maxAttempts; $try++) {
            try {
                $attempt();

                return;
            } catch (RetryableException $e) {
                if ($try >= $this->maxAttempts) {
                    throw $e;
                }

                $this->pause($try);
            }
        }
    }

    /**
     * Exponential backoff with equal jitter, capped at 2s: the window doubles per attempt from
     * `backoffMicros`, and the sleep lands in its upper half so simultaneous losers spread out.
     */
    private function pause(int $try): void
    {
        $cap = min($this->backoffMicros << ($try - 1), 2_000_000);
        usleep($this->jitter->between(intdiv($cap, 2), $cap));
    }
}
