<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\RetryableException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Storm\Chronicler\Store\RetryEventStore;
use Storm\Chronicler\Tests\Fixture\FlakyEventStore;
use Storm\Stream\Stream;
use Storm\Stream\StreamName;

final class RetryEventStoreTest extends TestCase
{
    #[Test]
    public function retries_a_retryable_failure_then_succeeds(): void
    {
        $inner = new FlakyEventStore(failures: 2);
        $retry = new RetryEventStore($inner, $this->idleConnection(), maxAttempts: 3, backoffMicros: 1);

        $retry->appendTo(new Stream(new StreamName('order-1')), 0);

        $this->assertSame(3, $inner->attempts);
    }

    #[Test]
    public function rethrows_after_max_attempts(): void
    {
        $inner = new FlakyEventStore(failures: 10);
        $retry = new RetryEventStore($inner, $this->idleConnection(), maxAttempts: 3, backoffMicros: 1);

        $this->expectException(RetryableException::class);

        try {
            $retry->appendTo(new Stream(new StreamName('order-1')), 0);
        } finally {
            $this->assertSame(3, $inner->attempts);
        }
    }

    #[Test]
    public function a_first_try_success_leaves_the_loop_after_exactly_one_attempt(): void
    {
        // a success must RETURN, not fall through to the next iteration: the inner append has
        // committed, so a re-run would double-insert the whole stream slice
        $inner = new FlakyEventStore(failures: 0);
        $retry = new RetryEventStore($inner, $this->idleConnection(), maxAttempts: 3, backoffMicros: 1);

        $retry->appendTo(new Stream(new StreamName('order-1')), 0);

        $this->assertSame(1, $inner->attempts);
    }

    #[Test]
    public function defaults_to_three_attempts_and_fifty_thousand_micros(): void
    {
        $inner = new FlakyEventStore(failures: 0);
        $retry = new RetryEventStore($inner, $this->idleConnection());

        $refClass = new ReflectionClass($retry);
        $this->assertSame(3, $refClass->getProperty('maxAttempts')->getValue($retry));
        $this->assertSame(50_000, $refClass->getProperty('backoffMicros')->getValue($retry));
    }

    #[Test]
    #[Group('slow')]
    public function a_retry_actually_pauses_before_the_next_attempt(): void
    {
        // pins that the backoff CALL exists at all; without it, hot-stream losers re-collide on
        // the same tick. One-sided bound: usleep guarantees AT LEAST the floor (half the 100ms
        // window), so asserting elapsed >= 50ms cannot flake; only an upper bound could.
        $inner = new FlakyEventStore(failures: 1);
        $retry = new RetryEventStore($inner, $this->idleConnection(), maxAttempts: 2, backoffMicros: 100_000);

        $start = microtime(true);
        $retry->appendTo(new Stream(new StreamName('order-1')), 0);

        $this->assertGreaterThanOrEqual(0.05, microtime(true) - $start);
        $this->assertSame(2, $inner->attempts);
    }

    #[Test]
    public function the_default_retry_budget_is_three_attempts(): void
    {
        // defaults on purpose: the shipped budget is part of the contract, not test wiring
        $inner = new FlakyEventStore(failures: 10);
        $retry = new RetryEventStore($inner, $this->idleConnection());

        $this->expectException(RetryableException::class);

        try {
            $retry->appendTo(new Stream(new StreamName('order-1')), 0);
        } finally {
            $this->assertSame(3, $inner->attempts);
        }
    }

    #[Test]
    public function retries_a_retryable_fact_append_then_succeeds(): void
    {
        $inner = new FlakyEventStore(failures: 2);
        $retry = new RetryEventStore($inner, $this->idleConnection(), maxAttempts: 3, backoffMicros: 1);

        $retry->appendAny(new Stream(new StreamName('meter-1')));

        $this->assertSame(3, $inner->attempts);
    }

    #[Test]
    public function rethrows_a_fact_append_after_max_attempts(): void
    {
        $inner = new FlakyEventStore(failures: 10);
        $retry = new RetryEventStore($inner, $this->idleConnection(), maxAttempts: 3, backoffMicros: 1);

        $this->expectException(RetryableException::class);

        try {
            $retry->appendAny(new Stream(new StreamName('meter-1')));
        } finally {
            $this->assertSame(3, $inner->attempts);
        }
    }

    #[Test]
    public function stands_down_inside_a_caller_opened_transaction(): void
    {
        // an active transaction means the inner attempt is a savepoint, not its own transaction:
        // the retryable failure did not release the outer locks, so a re-run recurs against them;
        // exactly ONE attempt, the failure propagates for the transaction's owner to retry whole
        $inner = new FlakyEventStore(failures: 10);
        $retry = new RetryEventStore($inner, $this->activeConnection(), maxAttempts: 3, backoffMicros: 1);

        $this->expectException(RetryableException::class);

        try {
            $retry->appendTo(new Stream(new StreamName('order-1')), 0);
        } finally {
            $this->assertSame(1, $inner->attempts);
        }
    }

    #[Test]
    public function stands_down_on_the_fact_verb_inside_a_caller_opened_transaction(): void
    {
        $inner = new FlakyEventStore(failures: 10);
        $retry = new RetryEventStore($inner, $this->activeConnection(), maxAttempts: 3, backoffMicros: 1);

        $this->expectException(RetryableException::class);

        try {
            $retry->appendAny(new Stream(new StreamName('meter-1')));
        } finally {
            $this->assertSame(1, $inner->attempts);
        }
    }

    /** No transaction active: the retry owns the transaction and the full budget engages. */
    private function idleConnection(): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);

        return $connection;
    }

    /** A caller-opened transaction is active: the retry must stand down to a single attempt. */
    private function activeConnection(): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);

        return $connection;
    }
}
