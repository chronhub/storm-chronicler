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
 * The verdicts are asserted on a pair of catalogs rather than on one, because the check is a diff.
 * A rule tested against a single catalog would pin what the rule reads, never what it compares,
 * and every one of these verdicts lives in the comparison.
 *
 * The passing cases carry as much as the failing ones. A team routes around a gate within a week
 * when it refuses a non-personal class rename under a stable alias, or a version bump that did ship
 * its upcaster: the alias exists to make the first free, and the second is the idiom the matrix
 * prescribes.
 */
final class CatalogueCompatibilityTest extends TestCase
{
    #[Test]
    public function a_class_rename_under_a_stable_alias_is_refused_when_the_current_class_is_personal(): void
    {
        $compatibility = new CatalogueCompatibility(new UpcasterChain, ['App\\CurrentEvent' => ['subject' => 'id', 'keys' => ['name'], 'fallbacks' => ['name' => null]]]);
        $problems = $compatibility->verdicts(
            ['customer.created' => $this->entry(class: 'App\\OldEvent')],
            ['customer.created' => $this->entry(class: 'App\\CurrentEvent')],
        );
        self::assertCount(1, $problems);
        self::assertStringContainsString('customer.created', $problems[0]);
        self::assertStringContainsString('App\\OldEvent', $problems[0]);
        self::assertStringContainsString('App\\CurrentEvent', $problems[0]);
    }

    #[Test]
    public function a_class_rename_under_a_stable_alias_stays_unreported_for_a_non_personal_class(): void
    {
        $compatibility = new CatalogueCompatibility(new UpcasterChain, ['App\\PersonalEvent' => ['subject' => 'id', 'keys' => ['name'], 'fallbacks' => ['name' => null]]]);
        $problems = $compatibility->verdicts(
            ['customer.created' => $this->entry(class: 'App\\OldEvent')],
            ['customer.created' => $this->entry(class: 'App\\CurrentEvent')],
        );
        self::assertSame([], $problems);
    }

    #[Test]
    public function a_personal_class_rename_is_refused_through_replaces(): void
    {
        $compatibility = new CatalogueCompatibility(new UpcasterChain, ['App\\CurrentEvent' => ['subject' => 'id', 'keys' => ['name'], 'fallbacks' => ['name' => null]]]);
        $problems = $compatibility->verdicts(
            ['customer.created' => $this->entry(class: 'App\\OldEvent')],
            ['customer.registered' => $this->entry(class: 'App\\CurrentEvent', replaces: ['customer.created'])],
        );
        self::assertCount(1, $problems);
        self::assertStringContainsString('customer.created', $problems[0]);
        self::assertStringContainsString('customer.registered', $problems[0]);
    }

    #[Test]
    public function an_unchanged_personal_class_is_not_reported(): void
    {
        $compatibility = new CatalogueCompatibility(new UpcasterChain, ['App\\CurrentEvent' => ['subject' => 'id', 'keys' => ['name'], 'fallbacks' => ['name' => null]]]);
        $catalogue = ['customer.created' => $this->entry(class: 'App\\CurrentEvent')];
        self::assertSame([], $compatibility->verdicts($catalogue, $catalogue));
    }

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
        // alias stability preserves type resolution; this verdict does not assess personal cipher context
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
        // the two look nothing alike in a diff and are the same loss to a store: rows carrying the
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
        // an operator fixes a contract in a single pass, so a verdict naming one problem when two
        // exist sends them back for a second round; the loop must run to the end and the list must
        // carry every finding, not the first
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
        // last would forget the others. The two catalogs differ because comparing one to itself
        // hides every symmetric bug: both sides forget the same aliases and the diff comes out
        // empty for the wrong reason
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
        // coverage cannot be established does not count as covered
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

    #[Test]
    public function an_upcaster_left_on_the_replaced_alias_is_not_the_chain_the_runtime_calls(): void
    {
        $verdicts = $this->compatibility($this->upcaster('order.placed', 1))->verdicts(
            ['order.placed' => $this->entry()],
            ['ordering.placed' => $this->entry(version: 2, replaces: ['order.placed'])],
        );

        self::assertCount(1, $verdicts);
        self::assertStringContainsString('order.placed', $verdicts[0]);
        self::assertStringContainsString('canonical "ordering.placed"', $verdicts[0]);
        self::assertStringContainsString('v1 to v2', $verdicts[0]);
    }

    #[Test]
    public function a_rename_at_a_stable_version_still_needs_the_whole_chain_under_the_new_alias(): void
    {
        $compatibility = new CatalogueCompatibility(new UpcasterChain([
            $this->upcaster('order.placed', 1),
            $this->upcaster('order.placed', 2),
        ]));
        $verdicts = $compatibility->verdicts(
            ['order.placed' => $this->entry(version: 3)],
            ['ordering.placed' => $this->entry(version: 3, replaces: ['order.placed'])],
        );

        self::assertCount(2, $verdicts);
        self::assertStringContainsString('v1 to v2', $verdicts[0]);
        self::assertStringContainsString('v2 to v3', $verdicts[1]);
        self::assertStringNotContainsString('moved from schema version 3 to 3', implode(' ', $verdicts));
        foreach ($verdicts as $verdict) {
            self::assertStringContainsString('canonical "ordering.placed"', $verdict);
        }
    }

    #[Test]
    public function a_replaced_alias_cannot_hide_a_version_downgrade(): void
    {
        $verdicts = $this->compatibility()->verdicts(
            ['order.placed' => $this->entry(version: 3)],
            ['ordering.placed' => $this->entry(version: 2, replaces: ['order.placed'])],
        );

        self::assertCount(1, $verdicts);
        self::assertStringContainsString('down to 2', $verdicts[0]);
        self::assertStringContainsString('order.placed', $verdicts[0]);
        self::assertStringContainsString('canonical "ordering.placed"', $verdicts[0]);
    }

    #[Test]
    public function a_historical_replaces_alias_is_checked_against_its_current_destination(): void
    {
        $verdicts = $this->compatibility()->verdicts(
            ['ordering.placed' => $this->entry(replaces: ['order.placed'])],
            [
                'ordering.placed' => $this->entry(),
                'billing.placed' => $this->entry(version: 2, replaces: ['order.placed']),
            ],
        );

        self::assertCount(1, $verdicts);
        self::assertStringContainsString('order.placed', $verdicts[0]);
        self::assertStringContainsString('canonical "billing.placed"', $verdicts[0]);
        self::assertStringContainsString('v1 to v2', $verdicts[0]);
    }

    #[Test]
    public function a_historical_alias_claimed_by_two_current_entries_is_refused_and_not_resolved_by_order(): void
    {
        $baseline = ['order.placed' => $this->entry()];
        $current = [
            'a.placed' => $this->entry(replaces: ['order.placed']),
            'b.placed' => $this->entry(replaces: ['order.placed']),
        ];
        $verdicts = $this->compatibility()->verdicts($baseline, $current);

        self::assertCount(1, $verdicts);
        foreach (['order.placed', 'a.placed', 'b.placed'] as $alias) {
            self::assertStringContainsString($alias, $verdicts[0]);
        }
        self::assertSame($verdicts, $this->compatibility()->verdicts($baseline, array_reverse($current, true)));
    }

    #[Test]
    public function a_complete_chain_under_the_new_canonical_alias_passes(): void
    {
        $compatibility = new CatalogueCompatibility(new UpcasterChain([
            $this->upcaster('ordering.placed', 1),
            $this->upcaster('ordering.placed', 2),
        ]));
        foreach ([1, 3] as $baselineVersion) {
            self::assertSame([], $compatibility->verdicts(
                ['order.placed' => $this->entry(version: $baselineVersion)],
                ['ordering.placed' => $this->entry(version: 3, replaces: ['order.placed'])],
            ));
        }
    }

    #[Test]
    public function a_stable_canonical_bump_with_replaces_reports_each_step_once(): void
    {
        $verdicts = $this->compatibility()->verdicts(
            ['ordering.placed' => $this->entry(replaces: ['order.placed'])],
            ['ordering.placed' => $this->entry(version: 2, replaces: ['order.placed'])],
        );

        self::assertCount(1, $verdicts);
        self::assertStringContainsString('alias "ordering.placed"', $verdicts[0]);
        self::assertStringContainsString('v1 to v2', $verdicts[0]);
    }

    #[Test]
    public function historical_aliases_can_split_between_unique_current_destinations(): void
    {
        self::assertSame([], $this->compatibility()->verdicts(
            ['old.entry' => $this->entry(replaces: ['x', 'y'])],
            [
                'a.entry' => $this->entry(replaces: ['x', 'old.entry']),
                'b.entry' => $this->entry(replaces: ['y']),
            ],
        ));
    }

    #[Test]
    public function a_shared_claim_does_not_end_the_judgement_of_the_aliases_after_it(): void
    {
        $problems = $this->compatibility()->verdicts(
            ['a' => $this->entry(), 'z' => $this->entry(version: 2)],
            ['a1' => $this->entry(replaces: ['a']), 'a2' => $this->entry(class: 'App\Other', replaces: ['a']), 'z' => $this->entry()],
        );

        self::assertCount(2, $problems, implode("\n", $problems));
        self::assertStringContainsString('"a" is claimed by more than one current event', $problems[0]);
        self::assertStringContainsString('"z" went from schema version 2 down to 1', $problems[1]);
    }

    #[Test]
    public function an_unchanged_historical_alias_does_not_end_the_judgement_of_the_aliases_after_it(): void
    {
        // `old1` still resolves where it did and is skipped; `old2` moved to a new canonical whose
        // chain lacks the step, and must still be judged
        $problems = $this->compatibility()->verdicts(
            ['x' => $this->entry(version: 2, replaces: ['old1']), 'y' => $this->entry(version: 2, replaces: ['old2'])],
            ['x' => $this->entry(version: 2, replaces: ['old1']), 'y' => $this->entry(version: 2), 'w' => $this->entry(class: 'App\W', version: 2, replaces: ['old2'])],
        );

        self::assertCount(1, $problems, implode("\n", $problems));
        self::assertStringContainsString('"old2" (canonical "w") requires an upcaster for the step v1 to v2', $problems[0]);
    }

    #[Test]
    public function every_alias_that_went_down_is_reported_not_only_the_first(): void
    {
        $problems = $this->compatibility()->verdicts(
            ['a' => $this->entry(version: 2), 'z' => $this->entry(version: 2)],
            ['a' => $this->entry(), 'z' => $this->entry()],
        );

        self::assertCount(2, $problems, implode("\n", $problems));
        self::assertStringContainsString('"a" went from schema version 2 down to 1', $problems[0]);
        self::assertStringContainsString('"z" went from schema version 2 down to 1', $problems[1]);
    }

    #[Test]
    public function an_upcaster_that_cannot_be_probed_does_not_end_the_judgement_of_the_aliases_after_it(): void
    {
        $refusing = new readonly class() implements Upcaster
        {
            public function supports(string $alias, int $fromVersion): bool
            {
                return $alias === 'a' ? throw new RuntimeException('cannot say') : false;
            }

            public function upcast(array $payload): array
            {
                return $payload;
            }
        };

        $problems = new CatalogueCompatibility(new UpcasterChain([$refusing]))->verdicts(
            ['a' => $this->entry(), 'z' => $this->entry(version: 2)],
            ['a' => $this->entry(version: 2), 'z' => $this->entry()],
        );

        self::assertCount(2, $problems, implode("\n", $problems));
        self::assertStringStartsWith('alias "a": ', $problems[0]);
        self::assertStringContainsString('"z" went from schema version 2 down to 1', $problems[1]);
    }

    #[Test]
    public function a_historical_personal_declaration_under_an_unchanged_class_is_no_class_change(): void
    {
        $entry = [...$this->entry(class: 'App\C'), 'personal' => ['subject' => 'id', 'keys' => ['name']]];

        self::assertSame([], $this->compatibility()->verdicts(['c' => $entry], ['c' => $entry]));
    }

    #[Test]
    public function an_alias_the_runtime_agrees_with_does_not_end_the_runtime_check(): void
    {
        // one alias with no personal data on either side, one matching the runtime exactly, and
        // only the two that disagree are named, each of them
        $personal = ['App\P' => ['subject' => 'id', 'keys' => ['name', 'email'], 'fallbacks' => ['name' => null, 'email' => null]]];
        $problems = new CatalogueCompatibility(new UpcasterChain, $personal)->currentPersonalMismatches([
            'plain' => $this->entry(class: 'App\Plain'),
            'good' => [...$this->entry(class: 'App\P'), 'personal' => ['subject' => 'id', 'keys' => ['email', 'name']]],
            'bad' => [...$this->entry(class: 'App\P'), 'personal' => ['subject' => 'other', 'keys' => ['name', 'email']]],
            'worse' => [...$this->entry(class: 'App\P'), 'personal' => ['subject' => 'id', 'keys' => ['name']]],
        ]);

        self::assertSame([
            'alias "bad" does not match the current runtime personal declaration; the message contract was not judged',
            'alias "worse" does not match the current runtime personal declaration; the message contract was not judged',
        ], $problems);
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

    /**
     * @param  list<string>  $replaces
     * @return array{class: string, version: int, replaces: list<string>}
     */
    private function entry(string $class = 'App\Order\OrderPlaced', int $version = 1, array $replaces = []): array
    {
        return ['class' => $class, 'version' => $version, 'replaces' => $replaces];
    }
}
