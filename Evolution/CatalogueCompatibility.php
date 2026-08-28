<?php

declare(strict_types=1);

namespace Storm\Chronicler\Evolution;

use Storm\Chronicler\Exception\UpcasterFailed;

use function array_diff;
use function array_keys;
use function sprintf;

/**
 * The declaration-level compatibility verdict: given the message catalogue a revision published and
 * the one this revision publishes, reports every change that would make an existing store
 * unreadable.
 *
 * The upstream face of {@see UpcasterConformance}, and the two do not overlap. Conformance answers
 * "can this binary read the store in front of me", computed FROM the rows, which ties it to a store
 * and therefore to a deployment. This one answers "would this change break a store that holds the
 * previous shapes", computed from two DECLARATIONS, which needs no store at all. Only one of the two
 * can speak before the row exists, and that is the one that can run on a pull request.
 *
 * The rules are the event-change matrix and nothing invented here. A PHP class renamed under a
 * stable alias is not reported, that being the very case the alias exists to make free.
 *
 * What it cannot see is worth naming, because a gate trusted beyond its reach is worse than none. It
 * reads declarations, so a payload key renamed at a constant version passes it: the shapes are not
 * catalogued, deliberately, a verdict derived from reading accessors being a heuristic where these
 * are exact. And a change of MEANING under a stable alias and version is invisible to any tool; the
 * matrix routes it to a new alias, and only a reviewer enforces that.
 */
final readonly class CatalogueCompatibility
{
    /**
     * The catalogue FORMAT's version, not any event's, and it lives here rather than with the
     * renderer because this is the side that must REFUSE a document it cannot read. A reader that
     * judged an unknown shape would return a verdict about fields it guessed at; refusing is the only
     * honest answer, and the constant belongs to whoever gives it.
     *
     * It moves when the document gains or loses a field, the payload shapes a later revision may
     * carry being the case it is reserved for.
     */
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        private UpcasterChain $upcasters,
    ) {}

    /**
     * One line per incompatibility, empty when the change is safe for any existing store.
     *
     * @param  array<string, array{class: string, version: int, replaces: list<string>}>  $baseline  the catalogue of the revision being compared against
     * @param  array<string, array{class: string, version: int, replaces: list<string>}>  $current  the catalogue this revision publishes
     * @return list<string>
     */
    public function verdicts(array $baseline, array $current): array
    {
        $problems = $this->unresolvable($baseline, $current);

        foreach ($baseline as $alias => $was) {
            $is = $current[$alias] ?? null;

            if ($is === null) {
                continue; // the alias left the canonical set; whether that is a loss is the resolvability check's to say
            }

            $problems = [...$problems, ...$this->versionMove($alias, $was['version'], $is['version'])];
        }

        return $problems;
    }

    /**
     * Every alias the baseline could resolve and this revision cannot, canonical or replaced alike.
     *
     * The two cases collapse into one check on purpose, because the store cannot tell them apart: a
     * canonical alias deleted and a `replaces` entry dropped both leave rows carrying a type that
     * now resolves to nothing, and both are readable again only by putting the name back.
     *
     * @param  array<string, array{class: string, version: int, replaces: list<string>}>  $baseline
     * @param  array<string, array{class: string, version: int, replaces: list<string>}>  $current
     * @return list<string>
     */
    private function unresolvable(array $baseline, array $current): array
    {
        $lost = array_diff(self::resolvable($baseline), self::resolvable($current));

        $problems = [];

        foreach ($lost as $alias) {
            $problems[] = sprintf(
                'alias "%s" no longer resolves to any declared event — rows already stored under it become unreadable; keep it as a `replaces` entry if the rename is deliberate',
                $alias,
            );
        }

        return $problems;
    }

    /**
     * The verdict on one alias whose version moved, which is where a bump meets its upcaster.
     *
     * @return list<string>
     */
    private function versionMove(string $alias, int $was, int $is): array
    {
        if ($is < $was) {
            return [sprintf(
                'alias "%s" went from schema version %d down to %d — a binary that reads a shape it no longer declares refuses those rows',
                $alias, $was, $is,
            )];
        }

        // @infection-ignore-all
        // Removing this return is an EQUIVALENT, and the pin sits here rather than in the config
        // because the line carries no other mutant to smother: `audit($alias, $v, $v)` walks the
        // steps of an empty range, so it reports neither a gap nor a duplicate and the fall-through
        // returns the same empty list. The early return says the intent, that an unmoved version
        // asks the chain nothing.
        if ($is === $was) {
            return [];
        }

        try {
            $audit = $this->upcasters->audit($alias, $was, $is);
        } catch (UpcasterFailed $e) {
            return [sprintf('alias "%s": %s', $alias, $e->getMessage())];
        }

        $problems = [];

        foreach ($audit->missing as $step) {
            $problems[] = sprintf(
                'alias "%s" moved from schema version %d to %d with no upcaster for the step v%d to v%d — a version bump must ship its upcaster',
                $alias, $was, $is, $step, $step + 1,
            );
        }

        foreach ($audit->duplicated as $step) {
            $problems[] = sprintf(
                'alias "%s": more than one upcaster claims the step v%d to v%d — the migration would depend on discovery order',
                $alias, $step, $step + 1,
            );
        }

        return $problems;
    }

    /**
     * Every alias a catalogue can resolve: each canonical name plus the former names it still answers to.
     *
     * @param  array<string, array{class: string, version: int, replaces: list<string>}>  $catalogue
     * @return list<string>
     */
    private static function resolvable(array $catalogue): array
    {
        $aliases = array_keys($catalogue);

        foreach ($catalogue as $entry) {
            $aliases = [...$aliases, ...$entry['replaces']];
        }

        return $aliases;
    }
}
