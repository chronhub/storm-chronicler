<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\RetryableException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Random\RandomException;
use ReflectionClass;
use Storm\Chronicler\Store\RetryEventStore;
use Storm\Chronicler\Tests\Fixture\FlakyEventStore;
use Storm\Chronicler\Tests\Fixture\RecordingJitter;
use Storm\Contracts\Random\Jitter;
use Storm\Stream\Stream;
use Storm\Stream\StreamName;
use Storm\Support\Random\NativeJitter;

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
        // a success must `return`, not fall through to the next iteration: the inner append has
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
        $this->assertInstanceOf(NativeJitter::class, $refClass->getProperty('jitter')->getValue($retry));
    }

    #[Test]
    #[Group('slow')]
    public function a_retry_actually_pauses_before_the_next_attempt(): void
    {
        // pins that the backoff call exists at all and sleeps what the port answered; without it,
        // hot-stream losers re-collide on the same tick. The port answers the floor of the 100ms
        // window, and `usleep()` sleeps at least that long, so asserting elapsed >= 50ms cannot
        // flake; only an upper bound could.
        $inner = new FlakyEventStore(failures: 1);
        $jitter = RecordingJitter::lowest();
        $retry = new RetryEventStore($inner, $this->idleConnection(), maxAttempts: 2, backoffMicros: 100_000, jitter: $jitter);

        $start = microtime(true);
        $retry->appendTo(new Stream(new StreamName('order-1')), 0);

        $this->assertGreaterThanOrEqual(0.05, microtime(true) - $start);
        $this->assertSame([[50_000, 100_000]], $jitter->asked);
        $this->assertSame(2, $inner->attempts);
    }

    #[Test]
    public function the_pause_draws_its_window_from_the_jitter_port(): void
    {
        // the window doubles per attempt from `backoffMicros` and the port is asked for its upper half,
        // both bounds included; the fake answers the low end, then the high end, and the store goes on
        // to its next attempt after each
        $inner = new FlakyEventStore(failures: 2);
        $jitter = RecordingJitter::lowestThenHighest();
        $retry = new RetryEventStore($inner, $this->idleConnection(), maxAttempts: 3, backoffMicros: 1_000, jitter: $jitter);

        $retry->appendTo(new Stream(new StreamName('order-1')), 0);

        $this->assertSame([[500, 1_000], [1_000, 2_000]], $jitter->asked);
        $this->assertSame(3, $inner->attempts);
    }

    #[Test]
    public function the_window_never_exceeds_two_seconds(): void
    {
        // a refusing port ends the run at its first draw, so the cap is read without sleeping a whole
        // second; the refusal propagates, a RandomException being no retryable failure
        $inner = new FlakyEventStore(failures: 1);
        $jitter = new class() implements Jitter
        {
            /** @var list<array{int, int}> */
            public array $asked = [];

            public function between(int $min, int $max): int
            {
                $this->asked[] = [$min, $max];

                throw new RandomException('no source of randomness');
            }
        };
        $retry = new RetryEventStore($inner, $this->idleConnection(), maxAttempts: 2, backoffMicros: 2_000_001, jitter: $jitter);

        try {
            $retry->appendTo(new Stream(new StreamName('order-1')), 0);
            $this->fail('expected the refused draw to propagate');
        } catch (RandomException) {
        }

        $this->assertSame([[1_000_000, 2_000_000]], $jitter->asked);
        $this->assertSame(1, $inner->attempts);
    }

    #[Test]
    public function the_default_retry_budget_is_three_attempts(): void
    {
        // the shipped defaults: the budget is part of the contract, not test wiring
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
        // exactly one attempt, then the failure propagates for the transaction's owner to retry whole
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

    /**
     * No transaction active: the retry owns the transaction and the full budget engages.
     */
    private function idleConnection(): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);

        return $connection;
    }

    /**
     * A caller-opened transaction is active: the retry must stand down to a single attempt.
     */
    private function activeConnection(): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);

        return $connection;
    }
}
