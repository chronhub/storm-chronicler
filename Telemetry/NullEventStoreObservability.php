<?php

declare(strict_types=1);

namespace Storm\Chronicler\Telemetry;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * The default `EventStoreObservability`, a no-op.
 *
 * The opt-in `Storm\Telemetry` package decorates or replaces this alias with its real implementation.
 */
#[AsAlias(EventStoreObservability::class)]
final readonly class NullEventStoreObservability implements EventStoreObservability
{
    public function recordAppend(AppendContext $ctx): void {}

    public function recordLoad(LoadContext $ctx): void {}

    public function recordOccConflict(OccConflictContext $ctx): void {}
}
