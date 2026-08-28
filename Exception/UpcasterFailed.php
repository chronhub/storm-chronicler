<?php

declare(strict_types=1);

namespace Storm\Chronicler\Exception;

use LogicException;
use Storm\Chronicler\Evolution\Upcaster;
use Storm\Chronicler\Evolution\UpcasterChain;
use Storm\Contracts\Chronicler\UndecodableRow;
use Throwable;

/**
 * An upcaster's own code threw, either while migrating a payload or while being probed through
 * `supports()`: a TypeError on a field the payload does not carry, a failed assertion, a service
 * that cannot even answer which steps it owns. The chain wraps both moments instead of letting
 * them escape raw, because a raw Throwable is indistinguishable from an infrastructure crash: on
 * the outbox relay it would roll back the whole drained batch and crash-loop the daemon, when the
 * condition is the same runtime incompatibility as a missing step, THIS binary cannot decode the
 * row, a corrected deployment can, so it belongs to the same stalled disposition. The wrap names
 * the upcaster class and the step, since the underlying "undefined array key" says neither which
 * service is wrong nor which event it choked on; the original failure is preserved as `previous`.
 * A coding error to fix, hence a `LogicException`, loud and named.
 *
 * @see UpcasterChain
 */
final class UpcasterFailed extends LogicException implements UndecodableRow
{
    /**
     * {@inheritDoc}
     *
     * Always true: a deployment that fixes the upcaster decodes this row.
     */
    public function resolvableByAnotherRuntime(): bool
    {
        return true;
    }

    /**
     * @param  class-string<Upcaster>  $upcaster
     */
    public static function forStep(string $alias, int $fromVersion, string $upcaster, Throwable $cause): self
    {
        return new self(sprintf(
            'Upcaster %s failed migrating event "%s" from version %d to %d: %s',
            $upcaster,
            $alias,
            $fromVersion,
            $fromVersion + 1,
            $cause->getMessage(),
        ), 0, $cause);
    }

    /**
     * @param  class-string<Upcaster>  $upcaster
     */
    public static function whileProbing(string $alias, int $version, string $upcaster, Throwable $cause): self
    {
        return new self(sprintf(
            'Upcaster %s failed answering supports() for event "%s" at version %d: %s',
            $upcaster,
            $alias,
            $version,
            $cause->getMessage(),
        ), 0, $cause);
    }
}
