<?php

declare(strict_types=1);

namespace Storm\Chronicler\Exception;

use LogicException;
use Storm\Chronicler\Evolution\Upcaster;
use Storm\Contracts\Chronicler\UndecodableRow;

/**
 * The upcaster chain has a gap: a stored event must move past a version step for which no
 * Upcaster is registered. Bumping a version requires shipping its upcaster, so this is a wiring
 * or deployment error to fix, not a runtime condition to handle, hence a `LogicException` that
 * fails fast and is never caught in the business flow.
 *
 * @see Upcaster
 */
final class MissingUpcaster extends LogicException implements UndecodableRow
{
    /**
     * {@inheritDoc}
     *
     * Always true: a deployment that ships the missing step decodes this row.
     */
    public function resolvableByAnotherRuntime(): bool
    {
        return true;
    }

    public static function forStep(string $alias, int $fromVersion): self
    {
        return new self(sprintf(
            'No upcaster for event "%s" from version %d to %d — a version bump needs its upcaster.',
            $alias,
            $fromVersion,
            $fromVersion + 1,
        ));
    }
}
