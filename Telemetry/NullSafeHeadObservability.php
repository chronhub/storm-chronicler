<?php

declare(strict_types=1);

namespace Storm\Chronicler\Telemetry;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(SafeHeadObservability::class)]
final readonly class NullSafeHeadObservability implements SafeHeadObservability
{
    public function recordCrossing(SafeHeadCrossingContext $context): void {}
}
