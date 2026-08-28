<?php

declare(strict_types=1);

namespace Storm\Chronicler\Telemetry;

/**
 * Carries the metrics-worthy data of a successful append, either write verb, to the observability
 * port. A pure value object, built at the call site, consumed by whatever observability
 * implementation is wired.
 *
 * @see EventStoreObservability
 */
final readonly class AppendContext
{
    public function __construct(
        /** The full qualified stream, or just the category for an anonymous append. */
        public string $streamName,
        /** The stream's category, materialized here, so implementations don't need to split the stream name. */
        public string $category,
        /** Number of events written by this append, at least 1; the empty case short-circuits before this fires. */
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
