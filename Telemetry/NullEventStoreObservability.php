<?php

declare(strict_types=1);

namespace Storm\Chronicler\Telemetry;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Default EventStoreObservability, a no-op. Ships with Chronicler, so the store has a real dependency
 * to wire even when no Telemetry package is installed. The opt-in `Storm\Telemetry` package decorates
 * or replaces this alias with its real implementation.
 */
#[AsAlias(EventStoreObservability::class)]
final readonly class NullEventStoreObservability implements EventStoreObservability
{
    public function recordAppend(AppendContext $ctx): void {}

    public function recordLoad(LoadContext $ctx): void {}

    public function recordOccConflict(OccConflictContext $ctx): void {}
}
