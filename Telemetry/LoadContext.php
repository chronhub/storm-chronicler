<?php

declare(strict_types=1);

namespace Storm\Chronicler\Telemetry;

/**
 * The metrics-worthy data of a successful read through `retrieveAll()` or `retrieveByFilter()`.
 */
final readonly class LoadContext
{
    public function __construct(
        /** Entry point, "retrieveAll" or "retrieveByFilter". */
        public string $source,
        /**
         * Caller-supplied, human-readable target: a stream name, "category:<x>", or a filter FQCN.
         *
         * A free string, so an implementation indexes whichever dimension it wants without the port
         * over-modeling it.
         */
        public string $target,
        /**
         * Events the consumer actually iterated, not the full result set; an early break lowers the count.
         */
        public int $eventCount,
        /** Wall-clock duration, in milliseconds, until generator exhaustion or GC. */
        public float $durationMs,
    ) {}
}
