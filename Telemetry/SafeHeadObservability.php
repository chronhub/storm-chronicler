<?php

declare(strict_types=1);

namespace Storm\Chronicler\Telemetry;

/** Local, non-blocking observation of committed ratchets; sink failures must not escape. */
interface SafeHeadObservability
{
    /** Reports absent positions presumed aborted, never a proof of writer termination. */
    public function recordCrossing(SafeHeadCrossingContext $context): void;
}
