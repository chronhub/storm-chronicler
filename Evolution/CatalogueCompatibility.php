<?php

declare(strict_types=1);

namespace Storm\Chronicler\Evolution;

use Storm\Chronicler\Exception\UpcasterFailed;

use function array_column;
use function array_diff;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_merge;
use function array_values;
use function count;
use function implode;
use function sort;
use function sprintf;

/**
 * The declaration-level compatibility verdict between two revisions' published message declarations.
 *
 * Given the declarations a revision published and those this revision publishes, it reports
 * alias-resolution and event-version incompatibilities, upcaster failures and incompatible declared
 * personal contexts included. Commands are judged on their aliases alone: a command has no version and
 * nothing upcasts it.
 *
 * It is the upstream face of {@see UpcasterConformance}, and the two do not overlap. Conformance asks
 * whether this binary can read the store in front of it, computed from the rows, which ties it to a
 * store and so to a deployment. This verdict asks whether the change would break a store that holds the
 * previous shapes, computed from two declarations, with no store at all. Only this one can speak before
 * the row exists, which is what lets it run on a pull request.
 *
 * The rules are the event-change matrix and nothing invented here:
 *
 * - A PHP class renamed under a stable alias is refused when its current destination is declared
 *   personal.
 *
 * - Other class renames pass at the type-resolution boundary unless the historical declaration is
 *   personal.
 *
 * - Enriched entries also preserve the historical subject and keys.
 *
 * Declarations do not establish that every stored field remains readable, and alias stability alone
 * does not migrate encrypted data.
 *
 * Its blind spots are named here, because a gate trusted beyond its reach is worse than none. It reads
 * declarations, so an undeclared payload key renamed at a constant version passes it: payload shapes
 * stay out of the declarations, since a verdict derived from reading accessors would be a heuristic
 * where these are exact. A change of meaning under a stable alias and version is invisible to any
 * tool; the matrix routes it to a new alias, and only a reviewer enforces that.
 *
 * @phpstan-type CatalogueEntry array{
 *     class: string,
 *     version: int,
 *     replaces: list<string>,
 *     personal?: array{subject: string, keys: list<string>}|null,
 * }
 * @phpstan-type CommandEntry array{class: string, replaces: list<string>}
 */
final readonly class CatalogueCompatibility
{
    /**
     * The format the `MessageCatalogue` writer emits; reading a format does not enable its emission.
     */
    public const int SCHEMA_VERSION = 1;

    public const array READABLE_SCHEMA_VERSIONS = [1, 2];

    /**
     * @param  array<string, array{
     *     subject: string,
     *     keys: list<string>,
     *     fallbacks: array<string, mixed>,
     * }>  $personalData
     */
    public function __construct(
        private UpcasterChain $upcasters,
        private array $personalData = [],
    ) {}

    /**
     * One line per detected incompatibility, empty when all checked declarations are compatible.
     *
     * @param  array<string, CatalogueEntry>  $baseline  the declarations of the revision compared against
     * @param  array<string, CatalogueEntry>  $current  the declarations this revision publishes
     * @return list<string>
     */
    public function verdicts(array $baseline, array $current): array
    {
        $problems = $this->unresolvable($baseline, $current);

        $claimants = [];
        foreach ($current as $currCanonical => $currEntry) {
            $claimants[$currCanonical][] = $currCanonical;
            foreach ($currEntry['replaces'] as $replaced) {
                $claimants[$replaced][] = $currCanonical;
            }
        }

        $historical = [];
        foreach ($baseline as $baseCanonical => $baseEntry) {
            $historical[$baseCanonical] = [
                'destination' => $baseCanonical,
                'version' => $baseEntry['version'],
            ];
        }
        foreach ($baseline as $baseCanonical => $baseEntry) {
            foreach ($baseEntry['replaces'] as $replaced) {
                if (! isset($historical[$replaced])) {
                    $historical[$replaced] = [
                        'destination' => $baseCanonical,
                        'version' => $baseEntry['version'],
                    ];
                }
            }
        }

        $auditCache = [];

        foreach ($historical as $alias => $source) {
            $claims = $claimants[$alias] ?? [];

            if ($claims === []) {
                continue;
            }

            if (count($claims) > 1) {
                sort($claims);
                $problems[] = sprintf(
                    'alias "%s" is claimed by more than one current event ("%s") — resolution would depend on discovery order',
                    $alias,
                    implode('", "', $claims),
                );

                continue;
            }

            $currentDestination = $claims[0];
            $baseDestination = $source['destination'];
            $canonicalChanged = $currentDestination !== $baseDestination;

            if (! $canonicalChanged && $alias !== $baseDestination) {
                continue;
            }

            $label = $currentDestination === $alias
                ? sprintf('"%s"', $alias)
                : sprintf('"%s" (canonical "%s")', $alias, $currentDestination);

            $previousClass = $baseline[$baseDestination]['class'];
            $currentClass = $current[$currentDestination]['class'];

            if ($previousClass !== $currentClass && isset($this->personalData[$currentClass])) {
                $problems[] = sprintf(
                    'alias %s changed PHP class from "%s" to "%s", whose current declaration is personal; encrypted historical fields bind the original class, so keeping the alias does not preserve decryption',
                    $label,
                    $previousClass,
                    $currentClass,
                );
            }

            $historicalPersonal = $baseline[$baseDestination]['personal'] ?? null;
            $currentPersonal = $current[$currentDestination]['personal'] ?? null;
            if ($historicalPersonal !== null) {
                if ($previousClass !== $currentClass && ! isset($this->personalData[$currentClass])) {
                    $problems[] = sprintf('alias %s changed the PHP class of a historical personal declaration from "%s" to "%s"; encrypted fields bind the original class', $label, $previousClass, $currentClass);
                }
                if ($currentPersonal === null) {
                    $problems[] = sprintf('alias %s removed its personal declaration; historical encrypted fields still require their original context', $label);
                } else {
                    if ($historicalPersonal['subject'] !== $currentPersonal['subject']) {
                        $problems[] = sprintf('alias %s changed its personal subject; historical encrypted fields bind the original subject', $label);
                    }
                    $problems = [
                        ...$problems,
                        ...array_map(
                            static fn (string $key): string => sprintf('alias %s removed personal key "%s"; historical encrypted fields bind their original key', $label, $key),
                            array_diff($historicalPersonal['keys'], $currentPersonal['keys']),
                        ),
                    ];
                }
            }

            $was = $source['version'];
            $is = $current[$currentDestination]['version'];

            if ($is < $was) {
                $problems[] = sprintf(
                    'alias %s went from schema version %d down to %d — a binary that reads a shape it no longer declares refuses those rows',
                    $label,
                    $was,
                    $is,
                );

                continue;
            }

            // A new canonical alias invalidates earlier chain proofs, so every historical step needs
            // checking.
            $from = $canonicalChanged ? 1 : $was;
            $to = $is;

            $cacheKey = "$currentDestination:$from:$to";
            if (! array_key_exists($cacheKey, $auditCache)) {
                try {
                    $auditCache[$cacheKey] = $this->upcasters->audit($currentDestination, $from, $to);
                } catch (UpcasterFailed $e) {
                    $auditCache[$cacheKey] = $e;
                }
            }

            $audit = $auditCache[$cacheKey];
            if ($audit instanceof UpcasterFailed) {
                $problems[] = sprintf('alias %s: %s', $label, $audit->getMessage());

                continue;
            }

            $problems = [
                ...$problems,
                ...array_map(static fn (int $step): string => $canonicalChanged
                    ? sprintf(
                        'alias %s requires an upcaster for the step v%d to v%d under the current canonical alias — moving an alias must preserve its entire upcaster chain',
                        $label,
                        $step,
                        $step + 1,
                    )
                    : sprintf(
                        'alias %s moved from schema version %d to %d with no upcaster for the step v%d to v%d — a version bump must ship its upcaster',
                        $label,
                        $was,
                        $is,
                        $step,
                        $step + 1,
                    ), $audit->missing),
                ...array_map(static fn (int $step): string => sprintf(
                    'alias %s: more than one upcaster claims the step v%d to v%d — the migration would depend on discovery order',
                    $label,
                    $step,
                    $step + 1,
                ), $audit->duplicated),
            ];
        }

        return $problems;
    }

    /**
     * One line per command alias the baseline resolved and this revision no longer declares, empty when
     * there is none.
     *
     * The alias is the whole contract of a command: a pending outbox row stored under a lost alias
     * resolves no class at the relay and is dead-lettered.
     *
     * @param  array<string, CommandEntry>  $baseline  the commands of the revision compared against
     * @param  array<string, CommandEntry>  $current  the commands this revision publishes
     * @return list<string>
     */
    public function commandVerdicts(array $baseline, array $current): array
    {
        $lost = array_diff(self::resolvable($baseline), self::resolvable($current)) |> array_values(...);

        return array_map(
            static fn (int|string $alias): string => sprintf(
                'command alias "%s" no longer resolves to any declared command — pending outbox rows stored under it are dead-lettered; keep it as a `replaces` entry if the rename is deliberate',
                $alias,
            ),
            $lost,
        );
    }

    /**
     * Rejects a current document whose personal contexts disagree with the running container.
     *
     * @param  array<string, CatalogueEntry>  $current
     * @return list<string>
     */
    public function currentPersonalMismatches(array $current): array
    {
        $mismatched = array_filter($current, fn (array $entry): bool => ! $this->matchesRuntime($entry)) |> array_keys(...);

        return array_map(
            static fn (int|string $alias): string => sprintf('alias "%s" does not match the current runtime personal declaration; the message contract was not judged', $alias),
            $mismatched,
        );
    }

    /**
     * Whether an entry's personal declaration agrees with the running container.
     *
     * A declaration absent on both sides counts as agreement.
     *
     * @param  CatalogueEntry  $entry
     */
    private function matchesRuntime(array $entry): bool
    {
        $declared = $entry['personal'] ?? null;
        $runtime = $this->personalData[$entry['class']] ?? null;
        if ($declared === null || $runtime === null) {
            return $declared === $runtime;
        }

        $declaredKeys = $declared['keys'];
        $runtimeKeys = $runtime['keys'];
        sort($declaredKeys);
        sort($runtimeKeys);

        return $declared['subject'] === $runtime['subject'] && $declaredKeys === $runtimeKeys;
    }

    /**
     * Every alias the baseline could resolve and this revision cannot, canonical or replaced alike.
     *
     * The two cases collapse into one check because the store cannot tell them apart: a
     * canonical alias deleted and a `replaces` entry dropped both leave rows carrying a type that
     * now resolves to nothing, and both are readable again only by putting the name back.
     *
     * @param  array<string, CatalogueEntry>  $baseline
     * @param  array<string, CatalogueEntry>  $current
     * @return list<string>
     */
    private function unresolvable(array $baseline, array $current): array
    {
        $lost = array_diff(self::resolvable($baseline), self::resolvable($current)) |> array_values(...);

        return array_map(
            static fn (int|string $alias): string => sprintf(
                'alias "%s" no longer resolves to any declared event — rows already stored under it become unreadable; keep it as a `replaces` entry if the rename is deliberate',
                $alias,
            ),
            $lost,
        );
    }

    /**
     * Every alias the declarations resolve: each canonical name plus the former names it still answers to.
     *
     * @param  array<string, CatalogueEntry|CommandEntry>  $catalogue
     * @return list<string>
     */
    private static function resolvable(array $catalogue): array
    {
        return array_merge(array_keys($catalogue), ...array_column($catalogue, 'replaces'));
    }
}
