<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\Chronicler\Evolution\CatalogueCompatibility;
use Storm\Chronicler\Evolution\Upcaster;
use Storm\Chronicler\Evolution\UpcasterChain;

use function implode;

/**
 * One verdict family per way a declaration can move, judged against the event-change matrix.
 *
 * The verdicts are asserted on a PAIR of catalogues rather than on one, because that is what the
 * check is: a diff. A rule tested against a single catalogue would pin what the rule reads, never
 * what it compares, and the comparison is where every one of these lives.
 *
 * The passing cases carry as much as the failing ones. A gate that refuses a class rename under a
 * stable alias, or a version bump that DID ship its upcaster, is a gate a team routes around within
 * a week; the alias exists precisely to make the first free, and the second is the idiom the matrix
 * prescribes.
 */
final class CatalogueCompatibilityTest extends TestCase
{
    #[Test]
    public function a_new_alias_is_additive_and_passes(): void
    {
        $verdicts = $this->compatibility()->verdicts(
            ['order.placed' => $this->entry()],
            ['order.placed' => $this->entry(), 'order.shipped' => $this->entry()],
        );

        self::assertSame([], $verdicts, 'no stored row can carry an alias that did not exist');
    }

    #[Test]
    public function a_class_renamed_under_a_stable_alias_passes(): void
    {
        // the case the alias exists to make free; a gate refusing it would teach people to avoid it
        $verdicts = $this->compatibility()->verdicts(
            ['order.placed' => $this->entry(class: 'App\Old\OrderPlaced')],
            ['order.placed' => $this->entry(class: 'App\New\OrderWasPlaced')],
        );

        self::assertSame([], $verdicts);
    }

    #[Test]
    #[Group('adversarial')]
    public function an_alias_that_vanishes_leaves_its_rows_unreadable(): void
    {
        $verdicts = $this->compatibility()->verdicts(
            ['order.placed' => $this->entry()],
            [],
        );

        self::assertCount(1, $verdicts);
        self::assertStringContainsString('order.placed', $verdicts[0]);
        self::assertStringContainsString('no longer resolves', $verdicts[0]);
    }

    #[Test]
    public function an_alias_renamed_with_replaces_passes(): void
    {
        // the matrix's own escape for a deliberate rename: the old name stays resolvable
        $verdicts = $this->compatibility()->verdicts(
            ['order.placed' => $this->entry()],
            ['ordering.placed' => $this->entry(replaces: ['order.placed'])],
        );

        self::assertSame([], $verdicts);
    }

    #[Test]
    #[Group('adversarial')]
    public function dropping_a_replaces_entry_loses_the_same_rows_as_deleting_the_alias(): void
    {
        // the two look nothing alike in a diff and are the SAME loss to a store: rows carrying the
        // former name resolve to nothing either way
        $verdicts = $this->compatibility()->verdicts(
            ['ordering.placed' => $this->entry(replaces: ['order.placed'])],
            ['ordering.placed' => $this->entry()],
        );

        self::assertCount(1, $verdicts);
        self::assertStringContainsString('order.placed', $verdicts[0]);
    }

    #[Test]
    public function a_version_bump_that_ships_its_upcaster_passes(): void
    {
        $verdicts = $this->compatibility($this->upcasterFor('order.placed', 1))->verdicts(
            ['order.placed' => $this->entry(version: 1)],
            ['order.placed' => $this->entry(version: 2)],
        );

        self::assertSame([], $verdicts);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_version_bump_without_its_upcaster_names_the_missing_step(): void
    {
        $verdicts = $this->compatibility()->verdicts(
            ['order.placed' => $this->entry(version: 1)],
            ['order.placed' => $this->entry(version: 2)],
        );

        self::assertCount(1, $verdicts);
        self::assertStringContainsString('v1 to v2', $verdicts[0]);
        self::assertStringContainsString('must ship its upcaster', $verdicts[0]);
    }

    #[Test]
    public function a_two_step_bump_names_every_step_it_cannot_climb(): void
    {
        // one verdict per gap, not one per alias: an operator fixes them in a single pass and needs
        // to see all of them, which is the same liberty the chain audit takes
        $verdicts = $this->compatibility($this->upcasterFor('order.placed', 1))->verdicts(
            ['order.placed' => $this->entry(version: 1)],
            ['order.placed' => $this->entry(version: 3)],
        );

        self::assertCount(1, $verdicts);
        self::assertStringContainsString('v2 to v3', $verdicts[0]);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_version_that_goes_backwards_is_refused(): void
    {
        $verdicts = $this->compatibility()->verdicts(
            ['order.placed' => $this->entry(version: 3)],
            ['order.placed' => $this->entry(version: 2)],
        );

        self::assertCount(1, $verdicts);
        self::assertStringContainsString('down to 2', $verdicts[0]);
    }

    #[Test]
    #[Group('adversarial')]
    public function two_upcasters_claiming_one_step_is_a_migration_that_depends_on_discovery_order(): void
    {
        $chain = new UpcasterChain([$this->upcaster('order.placed', 1), $this->upcaster('order.placed', 1)]);

        $verdicts = new CatalogueCompatibility($chain)->verdicts(
            ['order.placed' => $this->entry(version: 1)],
            ['order.placed' => $this->entry(version: 2)],
        );

        self::assertCount(1, $verdicts);
        self::assertStringContainsString('more than one upcaster', $verdicts[0]);
        self::assertStringContainsString('v1 to v2', $verdicts[0], 'which step is contested is half the report');
    }

    #[Test]
    #[Group('adversarial')]
    public function a_second_incompatibility_is_reported_too_and_not_stopped_at_the_first(): void
    {
        // an operator fixes a contract in ONE pass, so a verdict naming one problem when two exist
        // sends them back for a second round; the loop must run to the end and the list must carry
        // every finding, not the first
        $verdicts = $this->compatibility()->verdicts(
            ['order.placed' => $this->entry(), 'order.shipped' => $this->entry(version: 1)],
            ['order.shipped' => $this->entry(version: 2)],
        );

        self::assertCount(2, $verdicts);
        self::assertStringContainsString('order.placed', implode(' ', $verdicts), 'the vanished alias');
        self::assertStringContainsString('v1 to v2', implode(' ', $verdicts), 'and the bump that shipped no upcaster');
    }

    #[Test]
    #[Group('adversarial')]
    public function a_former_name_of_every_entry_is_resolvable_and_not_only_of_the_last(): void
    {
        // `resolvable()` merges each entry's `replaces` into one set, and a merge that kept only the
        // last would forget the others. The catalogues are DIFFERENT on purpose: comparing one to
        // itself hides every symmetric bug, both sides forgetting the same aliases and the diff
        // coming out empty for the wrong reason
        $verdicts = $this->compatibility()->verdicts(
            [
                'ordering.placed' => $this->entry(replaces: ['order.placed']),
                'ordering.shipped' => $this->entry(replaces: ['order.shipped']),
            ],
            [
                'ordering.placed' => $this->entry(replaces: ['order.placed']),
                'ordering.shipped' => $this->entry(replaces: ['order.shipped']),
                'ordering.settled' => $this->entry(replaces: ['order.settled']),
            ],
        );

        self::assertSame([], $verdicts, 'a purely additive revision loses nothing');
    }

    #[Test]
    #[Group('adversarial')]
    public function two_aliases_lost_at_once_are_both_named(): void
    {
        $verdicts = $this->compatibility()->verdicts(
            ['order.placed' => $this->entry(), 'order.shipped' => $this->entry()],
            [],
        );

        self::assertCount(2, $verdicts, 'a list that stopped at the first would send an operator back for a second round');
    }

    #[Test]
    public function every_step_a_bump_cannot_climb_is_named_and_not_only_the_first(): void
    {
        $verdicts = $this->compatibility($this->upcasterFor('order.placed', 1))->verdicts(
            ['order.placed' => $this->entry(version: 1)],
            ['order.placed' => $this->entry(version: 4)],
        );

        self::assertCount(2, $verdicts);
        self::assertStringContainsString('v2 to v3', implode(' ', $verdicts));
        self::assertStringContainsString('v3 to v4', implode(' ', $verdicts));
    }

    #[Test]
    #[Group('adversarial')]
    public function an_upcaster_that_cannot_say_what_it_owns_is_reported_and_not_swallowed(): void
    {
        // the chain refuses to guess when a `supports()` probe throws, and so must this: a bump whose
        // coverage cannot be established is not a bump that has been established
        $blind = new class() implements Upcaster
        {
            public function supports(string $alias, int $fromVersion): bool
            {
                throw new RuntimeException('the probe itself is broken');
            }

            public function upcast(array $payload): array
            {
                return $payload;
            }
        };

        $verdicts = $this->compatibility($blind)->verdicts(
            ['order.placed' => $this->entry(version: 1)],
            ['order.placed' => $this->entry(version: 2)],
        );

        self::assertCount(1, $verdicts);
        self::assertStringContainsString('order.placed', $verdicts[0]);
    }

    private function compatibility(?Upcaster $upcaster = null): CatalogueCompatibility
    {
        return new CatalogueCompatibility(new UpcasterChain($upcaster === null ? [] : [$upcaster]));
    }

    private function upcasterFor(string $alias, int $fromVersion): Upcaster
    {
        return $this->upcaster($alias, $fromVersion);
    }

    private function upcaster(string $alias, int $fromVersion): Upcaster
    {
        return new class($alias, $fromVersion) implements Upcaster
        {
            public function __construct(private readonly string $alias, private readonly int $fromVersion) {}

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

    /**
     * @param  list<string>  $replaces
     * @return array{class: string, version: int, replaces: list<string>}
     */
    private function entry(string $class = 'App\Order\OrderPlaced', int $version = 1, array $replaces = []): array
    {
        return ['class' => $class, 'version' => $version, 'replaces' => $replaces];
    }
}
