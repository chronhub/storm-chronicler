<?php

declare(strict_types=1);

namespace Storm\Chronicler\Exception;

use LogicException;
use Storm\Chronicler\Evolution\UpcasterChain;
use Storm\Contracts\Chronicler\UndecodableRow;

/**
 * A stored event carries a schema version NEWER than the runtime knows: an older binary is reading
 * rows a newer one wrote, from a rollback or a mid-rolling-deployment read. The chain can upcast the
 * past, never downcast the future; handing the future payload to the codec unchanged would at best
 * explode late and at worst hydrate partially in silence. A deployment condition to resolve, by
 * rolling forward or stopping the old binary; hence a `LogicException` that fails fast and named.
 *
 * @see UpcasterChain
 */
final class UnsupportedFutureEventVersion extends LogicException implements UndecodableRow
{
    /**
     * {@inheritDoc}
     *
     * Always true: the release that wrote this row already decodes it.
     */
    public function resolvableByAnotherRuntime(): bool
    {
        return true;
    }

    public static function forEvent(string $alias, int $storedVersion, int $runtimeVersion): self
    {
        return new self(sprintf(
            'Event "%s" is stored at schema version %d but this runtime only knows version %d — a future row read by an older binary; roll the binary forward instead of letting a codec guess.',
            $alias,
            $storedVersion,
            $runtimeVersion,
        ));
    }
}
