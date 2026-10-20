<?php

declare(strict_types=1);

namespace Storm\Chronicler\Telemetry;

/**
 * The metrics-worthy data of a successful append, through either write verb.
 *
 * A pure value object, built at the call site and consumed by whichever observability implementation
 * is wired.
 *
 * @see EventStoreObservability
 */
final readonly class AppendContext
{
    public function __construct(
        /** The fully qualified stream, or just the category for an anonymous append. */
        public string $streamName,
        /**
         * The stream's category, materialized here so implementations need not split the stream name.
         */
        public string $category,
        /**
         * Number of events this append wrote, at least 1: the empty case short-circuits before this fires.
         */
        public int $eventCount,
        /**
         * The new stream version after this append: the expected version plus the event count on the
         * decision path, the upper bound of the allocated range on the fact path.
         */
        public int $newVersion,
        /** Wall-clock duration of the public method, in milliseconds. */
        public float $durationMs,
    ) {}
}
