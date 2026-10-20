<?php

declare(strict_types=1);

namespace Storm\Chronicler\Exception;

use LogicException;
use Storm\Chronicler\Evolution\UpcasterChain;
use Storm\Contracts\Chronicler\UndecodableRow;

/**
 * A stored event carries a schema version newer than the runtime knows.
 *
 * An older binary is reading rows a newer one wrote, after a rollback or during a rolling deployment.
 * The chain can upcast the past, never downcast the future; handing the future payload to the codec
 * unchanged would at best fail late and at worst hydrate partially in silence.
 *
 * It is a deployment condition, resolved by rolling forward or stopping the old binary, so a
 * `LogicException` fails fast and names both versions.
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
