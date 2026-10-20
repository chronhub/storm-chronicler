<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests\Fixture;

use Storm\Chronicler\Telemetry\SafeHeadCrossingContext;
use Storm\Chronicler\Telemetry\SafeHeadObservability;

final class RecordingSafeHeadObserver implements SafeHeadObservability
{
    /** @var list<SafeHeadCrossingContext> */
    public array $seen = [];

    /**
     * @return list<SafeHeadCrossingContext>
     */
    public function observations(): array
    {
        return $this->seen;
    }

    public function recordCrossing(SafeHeadCrossingContext $context): void
    {
        $this->seen[] = $context;
    }
}
