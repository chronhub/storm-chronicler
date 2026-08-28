<?php

declare(strict_types=1);

namespace Storm\Chronicler\Telemetry;

/**
 * Carries the metrics-worthy data of a successful read via retrieveAll or retrieveByFilter.
 *
 * Source distinguishes the entry point, "retrieveAll" or "retrieveByFilter"; target is a
 * caller-supplied descriptor, a stream name, "category:<x>", or filter FQCN, kept as a free string so
 * the implementation can index whichever dimension it wants without the port over-modeling.
 *
 * eventCount reflects what the consumer actually iterated, not the full result set; an early break
 * lowers the count.
 */
final readonly class LoadContext
{
    public function __construct(
        /** Entry point, "retrieveAll" or "retrieveByFilter". */
        public string $source,
        /** Human-readable target: stream name, "category:<x>", or filter FQCN. */
        public string $target,
        /** Events yielded to the consumer, the post-iteration count. */
        public int $eventCount,
        /** Wall-clock duration, in milliseconds, until generator exhaustion or GC. */
        public float $durationMs,
    ) {}
}
