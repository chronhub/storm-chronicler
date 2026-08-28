<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests;

use Closure;
use Generator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\Chronicler\Evolution\Upcaster;
use Storm\Chronicler\Evolution\UpcasterChain;
use Storm\Chronicler\Exception\DuplicateUpcaster;
use Storm\Chronicler\Exception\MissingUpcaster;
use Storm\Chronicler\Exception\UnsupportedFutureEventVersion;
use Storm\Chronicler\Exception\UpcasterFailed;
use Storm\Chronicler\Tests\Fixture\ThrowingUpcaster;
use TypeError;

final class UpcasterChainTest extends TestCase
{
    #[Test]
    public function chains_each_version_step_in_order(): void
    {
        $chain = new UpcasterChain([
            $this->upcaster('order.placed', 1, fn (array $p): array => [...$p, 'currency' => 'EUR']),
            $this->upcaster('order.placed', 2, fn (array $p): array => [...$p, 'channel' => 'web']),
        ]);

        $result = $chain->upcast('order.placed', 1, 3, ['amount' => 100]);

        $this->assertSame(['amount' => 100, 'currency' => 'EUR', 'channel' => 'web'], $result);
    }

    #[Test]
    public function is_a_no_op_when_already_at_the_current_version(): void
    {
        $chain = new UpcasterChain([
            $this->upcaster('order.placed', 1, fn (array $p): array => [...$p, 'currency' => 'EUR']),
        ]);

        $this->assertSame(['amount' => 100], $chain->upcast('order.placed', 3, 3, ['amount' => 100]));
    }

    #[Test]
    public function audit_reports_a_clean_chain_as_ok(): void
    {
        $chain = new UpcasterChain([
            $this->upcaster('order.placed', 1, fn (array $p): array => $p),
            $this->upcaster('order.placed', 2, fn (array $p): array => $p),
        ]);

        $audit = $chain->audit('order.placed', 1, 3);

        $this->assertTrue($audit->ok());
        $this->assertSame([], $audit->missing);
        $this->assertSame([], $audit->duplicated);
    }

    #[Test]
    public function audit_names_the_missing_steps_without_reading_a_row(): void
    {
        // the deploy-time half of MissingUpcaster: the gap is named statically, before any read pays it
        $chain = new UpcasterChain([
            $this->upcaster('order.placed', 1, fn (array $p): array => $p), // only v1 to v2
        ]);

        $audit = $chain->audit('order.placed', 1, 5);

        $this->assertFalse($audit->ok());
        $this->assertSame([2, 3, 4], $audit->missing);
    }

    #[Test]
    public function audit_names_a_step_with_two_claimants(): void
    {
        $chain = new UpcasterChain([
            $this->upcaster('order.placed', 1, fn (array $p): array => $p),
            $this->upcaster('order.placed', 1, fn (array $p): array => $p),
        ]);

        $audit = $chain->audit('order.placed', 1, 2);

        $this->assertFalse($audit->ok());
        $this->assertSame([1], $audit->duplicated);
    }

    #[Test]
    public function throws_when_a_version_step_has_no_upcaster(): void
    {
        $chain = new UpcasterChain([
            $this->upcaster('order.placed', 1, fn (array $p): array => $p), // only v1 to v2
        ]);

        $this->expectException(MissingUpcaster::class);
        // the step, not just the type: the chain owns two steps here and only the second is the gap
        $this->expectExceptionMessageIsOrContains('from version 2 to 3');

        $chain->upcast('order.placed', 1, 3, []); // step v2 to v3 is missing
    }

    #[Test]
    public function ignores_upcasters_registered_for_other_aliases(): void
    {
        $chain = new UpcasterChain([
            $this->upcaster('other.event', 1, fn (array $p): array => [...$p, 'x' => 1]),
        ]);

        $this->expectException(MissingUpcaster::class);
        $this->expectExceptionMessageIsOrContains('No upcaster for event "order.placed" from version 1 to 2');

        $chain->upcast('order.placed', 1, 2, []);
    }

    /**
     * @param  Closure(array<string, mixed>): array<string, mixed>  $transform
     */
    private function upcaster(string $alias, int $from, Closure $transform): Upcaster
    {
        return new readonly class($alias, $from, $transform) implements Upcaster
        {
            public function __construct(
                private string $alias,
                private int $from,
                private Closure $transform,
            ) {}

            public function supports(string $alias, int $fromVersion): bool
            {
                return $alias === $this->alias && $fromVersion === $this->from;
            }

            public function upcast(array $payload): array
            {
                return ($this->transform)($payload);
            }
        };
    }

    #[Test]
    #[Group('adversarial')]
    public function two_upcasters_claiming_one_step_are_refused_instead_of_silently_ranked(): void
    {
        // Returning the first match would make the migrated payload depend on service discovery
        // order: the losing implementation is invisible, nothing fails at build or first read, and
        // an immutable history migrates one way today and the other way after a re-tag.
        $chain = new UpcasterChain([
            $this->upcaster('order.placed', 1, fn (array $p): array => [...$p, 'currency' => 'EUR']),
            $this->upcaster('order.placed', 1, fn (array $p): array => [...$p, 'currency' => 'USD']),
        ]);

        $this->expectException(DuplicateUpcaster::class);
        // the contested step is named, so an operator knows where to remove one of the two
        $this->expectExceptionMessageIsOrContains('Version step 1 → 2 of event "order.placed" is claimed by 2 upcasters');

        $chain->upcast('order.placed', 1, 2, ['amount' => 100]);
    }

    #[Test]
    #[Group('adversarial')]
    public function keeps_every_claimant_of_a_traversable_whose_keys_collide(): void
    {
        // The chain polls every registered upcaster, so it must not inherit the iterable's key
        // space. A Traversable is free to yield twice under one key, and collapsing on that key
        // drops a claimant silently: the contested step below would read as cleanly owned, and the
        // payload would migrate on discovery order, which is the one thing this class refuses.
        $colliding = (function (): Generator {
            yield 'upcaster' => $this->upcaster('order.placed', 1, fn (array $p): array => [...$p, 'currency' => 'EUR']);
            yield 'upcaster' => $this->upcaster('order.placed', 1, fn (array $p): array => [...$p, 'currency' => 'USD']);
        })();

        $chain = new UpcasterChain($colliding);

        $this->expectException(DuplicateUpcaster::class);
        $this->expectExceptionMessageIsOrContains('claimed by 2 upcasters');

        $chain->upcast('order.placed', 1, 2, ['amount' => 100]);
    }

    #[Test]
    public function a_second_upcaster_on_another_step_of_the_same_alias_is_not_a_duplicate(): void
    {
        // the guard must catch a contested STEP, not a busy alias: a chain of consecutive steps is
        // the normal shape of an event migrated more than once
        $chain = new UpcasterChain([
            $this->upcaster('order.placed', 1, fn (array $p): array => [...$p, 'currency' => 'EUR']),
            $this->upcaster('order.placed', 2, fn (array $p): array => [...$p, 'channel' => 'web']),
        ]);

        $this->assertSame(
            ['amount' => 100, 'currency' => 'EUR', 'channel' => 'web'],
            $chain->upcast('order.placed', 1, 3, ['amount' => 100]),
        );
    }

    #[Test]
    #[Group('adversarial')]
    public function an_upcaster_that_throws_is_wrapped_naming_the_service_and_the_step(): void
    {
        // A user upcaster's own TypeError must not escape raw: raw, the outbox relay cannot tell it
        // from an infrastructure crash, so it would roll back the whole batch and crash-loop the
        // daemon instead of stalling the one row. And the bare "null given" names neither the
        // service that is wrong nor the event it choked on; the wrap does both, cause preserved.
        $chain = new UpcasterChain([new ThrowingUpcaster]);

        try {
            $chain->upcast('test.upcast', 1, 2, ['amount' => 100]);
            $this->fail('expected the throwing upcaster to be wrapped');
        } catch (UpcasterFailed $e) {
            $this->assertStringContainsString(ThrowingUpcaster::class, $e->getMessage()); // WHICH service
            $this->assertStringContainsString('test.upcast', $e->getMessage());           // WHICH event
            $this->assertStringContainsString('from version 1 to 2', $e->getMessage());   // WHICH step
            $previous = $e->getPrevious();
            $this->assertInstanceOf(TypeError::class, $previous); // the original fault, for the audit digest
            $this->assertStringContainsString('null given', $previous->getMessage());
        }
    }

    #[Test]
    #[Group('adversarial')]
    public function a_stored_version_newer_than_the_runtime_is_refused_loud(): void
    {
        // the mirror asymmetry would be silent: loud on the past, MissingUpcaster, zero
        // iterations on the FUTURE; a rolled-back binary reading a newer row would get the payload
        // unchanged, handed to a codec that may hydrate it partially
        $this->expectException(UnsupportedFutureEventVersion::class);

        new UpcasterChain([])->upcast('account.opened', 5, 2, ['v' => 5]);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_probe_that_throws_is_wrapped_naming_the_service_and_the_step(): void
    {
        // supports() runs user code too: a probe that throws must join the same typed lane as a
        // failed migration, or the relay reads it as an infrastructure crash and rolls back the
        // whole batch over one broken service
        $probe = $this->throwingProbe();
        $chain = new UpcasterChain([$probe]);

        try {
            $chain->upcast('test.probe', 1, 2, ['amount' => 100]);
            $this->fail('expected the throwing probe to be wrapped');
        } catch (UpcasterFailed $e) {
            $this->assertStringContainsString($probe::class, $e->getMessage());   // WHICH service
            $this->assertStringContainsString('test.probe', $e->getMessage());    // WHICH event
            $this->assertStringContainsString('version 1', $e->getMessage());     // WHICH step
            $this->assertStringContainsString('probe exploded', (string) $e->getPrevious()?->getMessage());
        }
    }

    #[Test]
    public function the_audit_wraps_a_probe_that_throws_the_same_way(): void
    {
        // the deploy-time half polls the same probes: both halves of the invariant fail with the
        // same typed exception, so a caller classifies one lane, not two
        $this->expectException(UpcasterFailed::class);

        new UpcasterChain([$this->throwingProbe()])->audit('test.probe', 1, 2);
    }

    /** An upcaster whose probe itself fails: `supports()` throws before answering. */
    private function throwingProbe(): Upcaster
    {
        return new class() implements Upcaster
        {
            public function supports(string $alias, int $fromVersion): bool
            {
                throw new RuntimeException('probe exploded');
            }

            public function upcast(array $payload): array
            {
                return $payload;
            }
        };
    }
}
