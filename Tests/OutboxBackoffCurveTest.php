<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Storm\Chronicler\Evolution\IdentityEventTypeMapper;
use Storm\Chronicler\Evolution\UpcasterChain;
use Storm\Chronicler\Outbox\OutboxPublisher;
use Storm\Chronicler\Outbox\OutboxRelay;
use Storm\Chronicler\Record\StoredEventReader;
use Storm\Serializer\DefaultMessageSerializer;

final class OutboxBackoffCurveTest extends TestCase
{
    #[Test]
    #[Group('adversarial')]
    #[DataProvider('curve')]
    public function the_backoff_curve_stays_capped_beyond_the_representable_range(int $attempts, int $window): void
    {
        // the saga relay's sibling carries the same arithmetic: the cap must apply to the FLOAT
        // before the cast, or a large yet accepted max_attempts collapses the capped exponential
        // to the one-second floor. Equal-jittered on top: the delay lands in the window's upper
        // half, never above it, so the curve's shape is a RANGE per attempt, not a single value.
        $relay = new OutboxRelay(
            $this->createStub(Connection::class),
            new StoredEventReader(new DefaultMessageSerializer, new IdentityEventTypeMapper, new UpcasterChain),
            $this->createStub(OutboxPublisher::class),
            maxAttempts: 5,
            backoffBaseSeconds: 1,
            backoffMaxSeconds: 60,
        );

        $delay = new ReflectionMethod($relay, 'backoffSeconds')->invoke($relay, $attempts);
        self::assertGreaterThanOrEqual(max(1, intdiv($window, 2)), $delay, "attempt {$attempts}: at least half the window");
        self::assertLessThanOrEqual($window, $delay, "attempt {$attempts}: capped by the window");
    }

    #[Test]
    public function the_floor_holds_a_degenerate_zero_base_at_one_second(): void
    {
        // the floor is not decoration: a zero base, constructible standalone, would otherwise
        // compute a zero delay and hammer the downstream with no pause at all. The window is 1
        // here, jitter's own degenerate case, halving it to 0 before the floor would let the draw
        // land on zero half the time; sampled, not asserted once, since a single draw only catches
        // that the half the time it lands on the wrong half.
        $relay = new OutboxRelay(
            $this->createStub(Connection::class),
            new StoredEventReader(new DefaultMessageSerializer, new IdentityEventTypeMapper, new UpcasterChain),
            $this->createStub(OutboxPublisher::class),
            maxAttempts: 5,
            backoffBaseSeconds: 0,
            backoffMaxSeconds: 60,
        );
        $method = new ReflectionMethod($relay, 'backoffSeconds');

        for ($i = 0; $i < 30; $i++) {
            self::assertSame(1, $method->invoke($relay, 1));
        }
    }

    #[Test]
    public function the_jittered_delay_actually_jitters_below_the_window_top(): void
    {
        // the in-window bounds above cannot see a dead jitter (a constant full-window delay sits
        // inside them): sample the delay and require a draw BELOW the top. P(30 draws all landing
        // on the single top value of a 501-wide half-window) ≈ (1/501)^30, not a flake source.
        $relay = new OutboxRelay(
            $this->createStub(Connection::class),
            new StoredEventReader(new DefaultMessageSerializer, new IdentityEventTypeMapper, new UpcasterChain),
            $this->createStub(OutboxPublisher::class),
            maxAttempts: 5,
            backoffBaseSeconds: 1000,
            backoffMaxSeconds: 1000,
        );
        $method = new ReflectionMethod($relay, 'backoffSeconds');

        $delays = [];
        for ($i = 0; $i < 30; $i++) {
            $delays[] = $method->invoke($relay, 1);
        }

        self::assertLessThan(1000, min($delays), 'a dead jitter would always return the window top');
    }

    #[Test]
    public function the_jittered_delay_never_falls_below_the_window_half(): void
    {
        // the floor needs sampling too, for the mirror reason: a widened lower bound still lands
        // inside the window most of the time, so a single draw sees it only when the draw happens
        // to fall in the widened part. P(60 draws all missing a quarter-sized gap) = 0.75^60,
        // about one in thirty million; the true floor holds on EVERY draw, so this cannot flake.
        $relay = new OutboxRelay(
            $this->createStub(Connection::class),
            new StoredEventReader(new DefaultMessageSerializer, new IdentityEventTypeMapper, new UpcasterChain),
            $this->createStub(OutboxPublisher::class),
            maxAttempts: 5,
            backoffBaseSeconds: 1000,
            backoffMaxSeconds: 1000,
        );
        $method = new ReflectionMethod($relay, 'backoffSeconds');

        $delays = [];
        for ($i = 0; $i < 60; $i++) {
            $delays[] = $method->invoke($relay, 1);
        }

        self::assertGreaterThanOrEqual(500, min($delays), 'the delay must sit in the window UPPER half');
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function curve(): iterable
    {
        yield 'first attempt is the base' => [1, 1];

        yield 'doubling below the cap' => [5, 16];

        yield 'the cap engages' => [7, 60];

        yield 'well past the cap' => [32, 60];

        yield 'past the int64 range' => [70, 60];

        yield 'absurd but accepted configuration' => [200, 60];
    }
}
