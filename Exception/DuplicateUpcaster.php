<?php

declare(strict_types=1);

namespace Storm\Chronicler\Exception;

use LogicException;
use Storm\Chronicler\Evolution\Upcaster;
use Storm\Chronicler\Evolution\UpcasterChain;
use Storm\Contracts\Chronicler\UndecodableRow;

/**
 * Two upcasters claim the same version step of the same alias. The chain's invariant is one step =
 * one upcaster: it applies exactly one transformation per version, so two candidates make the
 * migrated payload depend on service discovery order, and the losing implementation is invisible.
 * On an immutable history that is not a preference to arbitrate but a wiring error to fix, hence a
 * `LogicException` that fails fast and named.
 *
 * @see UpcasterChain
 * @see Upcaster
 */
final class DuplicateUpcaster extends LogicException implements UndecodableRow
{
    /**
     * {@inheritDoc}
     *
     * Always true: a deployment that removes the second claimant decodes this row.
     */
    public function resolvableByAnotherRuntime(): bool
    {
        return true;
    }

    /**
     * @param  list<class-string<Upcaster>>  $claimants
     */
    public static function forStep(string $alias, int $fromVersion, array $claimants): self
    {
        return new self(sprintf(
            'Version step %d → %d of event "%s" is claimed by %d upcasters (%s); a step takes exactly one, or the payload depends on registration order.',
            $fromVersion,
            $fromVersion + 1,
            $alias,
            count($claimants),
            implode(', ', $claimants),
        ));
    }
}
