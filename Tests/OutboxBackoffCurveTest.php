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
use Storm\Chronicler\Tests\Fixture\RecordingJitter;
use Storm\Serializer\DefaultMessageSerializer;

final class OutboxBackoffCurveTest extends TestCase
{
    #[Test]
    #[Group('adversarial')]
    #[DataProvider('curve')]
    public function the_backoff_curve_stays_capped_beyond_the_representable_range(int $attempts, int $window): void
    {
        // the saga relay's sibling carries the same arithmetic: the cap must apply to the float
        // before the cast, or a large yet accepted `max_attempts` collapses the capped exponential
        // to the one-second floor. Equal jitter on top lands the delay in the window's upper half,
        // never above it, so the curve gives a range per attempt rather than a single value.
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
    #[DataProvider('curve')]
    public function each_attempt_asks_the_jitter_for_the_upper_half_of_its_window(int $attempts, int $window): void
    {
        // equal jitter: the draw spans the upper half of the capped window, the lower end floored at
        // one second, and whichever end the jitter answers is the delay
        foreach ([[RecordingJitter::lowest(), max(1, intdiv($window, 2))], [RecordingJitter::highest(), $window]] as [$jitter, $delay]) {
            $relay = new OutboxRelay(
                $this->createStub(Connection::class),
                new StoredEventReader(new DefaultMessageSerializer, new IdentityEventTypeMapper, new UpcasterChain),
                $this->createStub(OutboxPublisher::class),
                maxAttempts: 5,
                backoffBaseSeconds: 1,
                backoffMaxSeconds: 60,
                jitter: $jitter,
            );

            self::assertSame($delay, new ReflectionMethod($relay, 'backoffSeconds')->invoke($relay, $attempts), "attempt {$attempts}");
            self::assertSame([[max(1, intdiv($window, 2)), $window]], $jitter->asked, "attempt {$attempts}");
        }
    }

    #[Test]
    public function the_floor_holds_a_degenerate_zero_base_at_one_second(): void
    {
        // the floor carries load: a zero base, constructible standalone, would otherwise compute a
        // zero delay and retry the downstream with no pause at all. The window is 1 here, jitter's
        // own degenerate case: halving it to 0 before the floor would let the draw land on zero half
        // the time, so the delay is sampled rather than asserted once, a single draw catching that
        // only when it lands on the wrong half.
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
        // the in-window bounds above cannot see a dead jitter, since a constant full-window delay
        // sits inside them: sample the delay and require a draw below the top. Thirty draws all on
        // the single top value of a 501-wide half-window have probability `(1/501)^30`, no flake.
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
        // inside the window most of the time, so a single draw sees it only when the draw happens to
        // fall in the widened part. Sixty draws all miss a quarter-sized gap with probability
        // `0.75^60`, about one in thirty million; the true floor holds on every draw, so no flake.
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
