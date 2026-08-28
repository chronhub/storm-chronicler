<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\Chronicler\Evolution\IdentityEventTypeMapper;
use Storm\Chronicler\Evolution\Upcaster;
use Storm\Chronicler\Evolution\UpcasterChain;
use Storm\Chronicler\Evolution\UpcasterConformance;
use Storm\Chronicler\Exception\UnknownEventType;
use Storm\Chronicler\Record\StoredEventReader;
use Storm\Chronicler\Tests\Fixture\NotAnEventPayload;
use Storm\Chronicler\Tests\Fixture\SomethingHappened;
use Storm\Contracts\Chronicler\EventTypeMapper;
use Storm\Serializer\DefaultMessageSerializer;
use Throwable;

/**
 * The pure half of the readability conformance, one verdict family per stored-type shape: an
 * undeclared stored type, a stored type resolving to a loadable class that is not a domain event,
 * rows above the runtime's version, a chain gap between the data-computed floor and the current
 * version, a duplicate step owner, and the clean pass, including the reset-posture case where the
 * floor equals the current version and no upcaster is needed at all.
 *
 * The parity case at the bottom is what makes the rest mean anything: a verdict is a claim ABOUT the
 * read path, made by a class that does not call it, so the two agreeing is a coincidence until
 * something holds it. The store's rows are built from a real domain event for the same reason; the
 * placeholder they carried resolved to a class the read path refuses outright, which pinned the one
 * verdict this check exists to prevent, a store full of unreadable rows reported conformant.
 */
final class UpcasterConformanceTest extends TestCase
{
    #[Test]
    public function passes_a_store_whose_floor_is_the_current_version(): void
    {
        // the reset posture: a version bump on a fresh schema leaves no old rows, so no upcaster is owed
        $conformance = new UpcasterConformance($this->mapperAt(5), new UpcasterChain);

        $this->assertSame([], $conformance->verdicts([
            ['type' => SomethingHappened::class, 'floor' => 5, 'ceiling' => 5, 'n' => 100],
        ]));
    }

    #[Test]
    public function passes_an_old_floor_whose_chain_is_continuous(): void
    {
        $conformance = new UpcasterConformance($this->mapperAt(3), new UpcasterChain([
            $this->claiming(SomethingHappened::class, 1),
            $this->claiming(SomethingHappened::class, 2),
        ]));

        $this->assertSame([], $conformance->verdicts([
            ['type' => SomethingHappened::class, 'floor' => 1, 'ceiling' => 3, 'n' => 7],
        ]));
    }

    #[Test]
    public function names_the_missing_steps_between_the_stored_floor_and_the_current_version(): void
    {
        $conformance = new UpcasterConformance($this->mapperAt(3), new UpcasterChain([
            $this->claiming(SomethingHappened::class, 1), // v1 to v2 exists; v2 to v3 is the gap
        ]));

        // the shape the query really hands back: pdo_pgsql returns the aggregates as strings, and the
        // chain audit takes ints, so the row is fed here exactly as it arrives
        $problems = $conformance->verdicts([
            ['type' => SomethingHappened::class, 'floor' => '1', 'ceiling' => '3', 'n' => '7'],
        ]);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('no upcaster for the step v2 to v3', $problems[0]);
    }

    #[Test]
    public function keeps_judging_the_later_types_after_an_undeclared_one(): void
    {
        // an undeclared type ends its own verdict, not the audit: the store holds one row set per
        // type, and stopping at the first unreadable one would hide every problem behind it, which
        // is the opposite of what an operator runs this for
        $conformance = new UpcasterConformance($this->mapperAt(2), new UpcasterChain);

        $problems = $conformance->verdicts([
            ['type' => 'Not\\A\\Real\\Class', 'floor' => 1, 'ceiling' => 1, 'n' => 3],
            ['type' => SomethingHappened::class, 'floor' => 2, 'ceiling' => 4, 'n' => 9],
        ]);

        $this->assertCount(2, $problems);
        $this->assertStringContainsString('resolves to no declared event class', $problems[0]);
        $this->assertStringContainsString('rows at schema version 4', $problems[1]);
    }

    #[Test]
    public function names_an_undeclared_stored_type_as_unreadable(): void
    {
        $conformance = new UpcasterConformance(new IdentityEventTypeMapper, new UpcasterChain);

        $problems = $conformance->verdicts([
            ['type' => 'Not\\A\\Real\\Class', 'floor' => 1, 'ceiling' => 1, 'n' => 3],
        ]);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('resolves to no declared event class', $problems[0]);
    }

    #[Test]
    public function names_rows_stored_above_the_runtime_version(): void
    {
        // a rolled-back binary reading a future schema: the ceiling outruns what this runtime knows
        $conformance = new UpcasterConformance($this->mapperAt(2), new UpcasterChain);

        $problems = $conformance->verdicts([
            ['type' => SomethingHappened::class, 'floor' => 2, 'ceiling' => 4, 'n' => 9],
        ]);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('rows at schema version 4 but this runtime only knows version 2', $problems[0]);
    }

    #[Test]
    public function names_a_duplicated_step_owner(): void
    {
        $conformance = new UpcasterConformance($this->mapperAt(2), new UpcasterChain([
            $this->claiming(SomethingHappened::class, 1),
            $this->claiming(SomethingHappened::class, 1),
        ]));

        $problems = $conformance->verdicts([
            ['type' => SomethingHappened::class, 'floor' => 1, 'ceiling' => 2, 'n' => 1],
        ]);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('more than one upcaster claims the step v1 to v2', $problems[0]);
    }

    #[Test]
    public function a_renamed_alias_audits_the_canonical_chain_the_runtime_will_call(): void
    {
        // rows stored under a replaced alias resolve to the class whose CANONICAL alias the read
        // path hands to the chain; the audit must poll that same alias or its verdict lies
        $conformance = new UpcasterConformance($this->renamedMapper('order.placed.legacy', 'order.placed', 2), new UpcasterChain([
            $this->claiming('order.placed', 1),
        ]));

        $this->assertSame([], $conformance->verdicts([
            ['type' => 'order.placed.legacy', 'floor' => 1, 'ceiling' => 2, 'n' => 4],
        ]));
    }

    #[Test]
    public function an_upcaster_left_on_the_replaced_alias_is_reported_missing(): void
    {
        // the mirror lie: a chain claiming only the OLD alias would be approved while the runtime,
        // which polls the canonical one, never calls it; the verdict names both aliases
        $conformance = new UpcasterConformance($this->renamedMapper('order.placed.legacy', 'order.placed', 2), new UpcasterChain([
            $this->claiming('order.placed.legacy', 1),
        ]));

        $problems = $conformance->verdicts([
            ['type' => 'order.placed.legacy', 'floor' => 1, 'ceiling' => 2, 'n' => 4],
        ]);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('no upcaster for the step v1 to v2', $problems[0]);
        $this->assertStringContainsString('order.placed.legacy', $problems[0]);
        $this->assertStringContainsString('canonical "order.placed"', $problems[0]);
    }

    #[Test]
    public function a_probe_that_throws_becomes_an_actionable_problem_not_a_crash(): void
    {
        // the deploy-time verdict must survive a broken upcaster: the operator needs the problem
        // list, not a stack trace that hides every type behind the faulty service
        $conformance = new UpcasterConformance($this->mapperAt(2), new UpcasterChain([
            $this->throwingProbe(),
        ]));

        $problems = $conformance->verdicts([
            ['type' => SomethingHappened::class, 'floor' => 1, 'ceiling' => 2, 'n' => 1],
            // a second stored type AFTER the faulty one: its verdict must still be judged, the
            // broken probe ends one type's verdict, never the audit
            ['type' => 'Not\\A\\Real\\Class', 'floor' => 1, 'ceiling' => 1, 'n' => 3],
        ]);

        $this->assertCount(2, $problems);
        $this->assertStringContainsString('supports()', $problems[0]);
        $this->assertStringContainsString('Upcaster', $problems[0]);
        $this->assertStringContainsString('resolves to no declared event class', $problems[1]);
    }

    #[Test]
    public function names_a_stored_type_resolving_to_a_loadable_class_that_is_not_a_domain_event(): void
    {
        // a restore or import artifact: the class LOADS, so the resolution succeeds and every
        // version rule below it reads clean, while the read path refuses the row before hydrating it
        $conformance = new UpcasterConformance($this->mapperAt(1), new UpcasterChain);

        $problems = $conformance->verdicts([
            ['type' => NotAnEventPayload::class, 'floor' => 1, 'ceiling' => 1, 'n' => 40],
        ]);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('is not a domain event', $problems[0]);
        $this->assertStringContainsString('40 rows', $problems[0]);
    }

    #[Test]
    public function a_foreign_class_does_not_stop_the_remaining_stored_types_from_being_audited(): void
    {
        $conformance = new UpcasterConformance($this->mapperAt(2), new UpcasterChain);

        $problems = $conformance->verdicts([
            ['type' => NotAnEventPayload::class, 'floor' => 1, 'ceiling' => 1, 'n' => 40],
            ['type' => SomethingHappened::class, 'floor' => 1, 'ceiling' => 1, 'n' => 2],
        ]);

        $this->assertCount(2, $problems);
        $this->assertStringContainsString('is not a domain event', $problems[0]);
        $this->assertStringContainsString('no upcaster for the step v1 to v2', $problems[1]);
    }

    /**
     * Every stored-row shape whose verdict is expressible with a mapper of one version step: the
     * stored type, the version the row carries, the version the runtime knows, and how many
     * upcasters claim the single step between them, zero being a gap and two a duplicate.
     *
     * @return iterable<string, array{string, int, positive-int, int}>
     */
    public static function storedShapes(): iterable
    {
        yield 'an undeclared stored type' => ['Not\\A\\Real\\Class', 1, 1, 0];
        yield 'a loadable class that is not a domain event' => [NotAnEventPayload::class, 1, 1, 0];
        yield 'a row written above the runtime version' => [SomethingHappened::class, 2, 1, 0];
        yield 'a gap between the stored floor and the current version' => [SomethingHappened::class, 1, 2, 0];
        yield 'two upcasters claiming the one step' => [SomethingHappened::class, 1, 2, 2];
        yield 'a row already at the current version' => [SomethingHappened::class, 1, 1, 0];
        yield 'a row upcast across a continuous chain' => [SomethingHappened::class, 1, 2, 1];
    }

    /**
     * @param  positive-int  $current
     */
    #[Test]
    #[Group('adversarial')]
    #[DataProvider('storedShapes')]
    public function the_verdict_agrees_with_what_the_read_path_does_to_the_same_row(string $type, int $stored, int $current, int $claimants): void
    {
        // the promise of a deploy-time verdict, stated as an equality: a store this calls conformant
        // is a store the read path can drain, and a store it condemns is one that would really fail.
        // Neither class calls the other, so nothing but this holds the two sets equal, and they had
        // already diverged on the one shape whose class loads and is refused anyway.
        $mapper = $this->mapperAt($current);
        $chain = new UpcasterChain(array_fill(0, $claimants, $this->claiming(SomethingHappened::class, 1)));

        $reported = new UpcasterConformance($mapper, $chain)->verdicts([
            ['type' => $type, 'floor' => $stored, 'ceiling' => $stored, 'n' => 1],
        ]) !== [];

        $refused = false;

        try {
            new StoredEventReader(new DefaultMessageSerializer, $mapper, $chain)->toMessage([
                'type' => $type,
                'event_version' => $stored,
                'header' => '{}',
                'content' => '{"what":"hello"}',
            ]);
        } catch (Throwable) {
            $refused = true;
        }

        $this->assertSame($refused, $reported, $refused
            ? 'the read path refuses this row and the deploy-time verdict calls the store conformant'
            : 'the deploy-time verdict condemns a row the read path reads without complaint');
    }

    /**
     * An identity-shaped mapper whose types are all at `$version`.
     *
     * @param  positive-int  $version
     */
    private function mapperAt(int $version): EventTypeMapper
    {
        return new readonly class($version) implements EventTypeMapper
        {
            /** @param positive-int $version */
            public function __construct(private int $version) {}

            public function toType(string $class): string
            {
                return $class;
            }

            public function toClass(string $type): string
            {
                if (! class_exists($type)) {
                    throw UnknownEventType::cannotResolve($type);
                }

                return $type;
            }

            public function versionOf(string $class): int
            {
                return $this->version;
            }

            public function storedTypesOf(string $class): array
            {
                return [$class];
            }
        };
    }

    /**
     * A mapper with one renamed alias: `$legacy` still resolves to `SomethingHappened`, whose canonical
     * alias is `$canonical` at `$version`.
     *
     * @param  positive-int  $version
     */
    private function renamedMapper(string $legacy, string $canonical, int $version): EventTypeMapper
    {
        return new readonly class($legacy, $canonical, $version) implements EventTypeMapper
        {
            /** @param positive-int $version */
            public function __construct(
                private string $legacy,
                private string $canonical,
                private int $version,
            ) {}

            public function toType(string $class): string
            {
                return $this->canonical;
            }

            public function toClass(string $type): string
            {
                if ($type !== $this->legacy && $type !== $this->canonical) {
                    throw UnknownEventType::cannotResolve($type);
                }

                return SomethingHappened::class;
            }

            public function versionOf(string $class): int
            {
                return $this->version;
            }

            public function storedTypesOf(string $class): array
            {
                return [$this->canonical, $this->legacy];
            }
        };
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

    /** An upcaster claiming exactly one `(alias, fromVersion)` step, payload untouched. */
    private function claiming(string $alias, int $fromVersion): Upcaster
    {
        return new readonly class($alias, $fromVersion) implements Upcaster
        {
            public function __construct(private string $alias, private int $fromVersion) {}

            public function supports(string $alias, int $fromVersion): bool
            {
                return $alias === $this->alias && $fromVersion === $this->fromVersion;
            }

            public function upcast(array $payload): array
            {
                return $payload;
            }
        };
    }
}
