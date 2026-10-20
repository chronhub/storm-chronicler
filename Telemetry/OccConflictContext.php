<?php

declare(strict_types=1);

namespace Storm\Chronicler\Telemetry;

/**
 * The metrics-worthy data of an OCC conflict caught at append time.
 */
final readonly class OccConflictContext
{
    public function __construct(
        /** The fully qualified stream that lost the race. */
        public string $streamName,
        public string $category,
        /** The version the caller passed in. */
        public int $expectedVersion,
        /** The current stream-head version observed by the engine. */
        public int $actualVersion,
        /**
         * "stale" when the pre-check on `stream_heads` lost the CAS and `StaleVersion` is thrown, or
         * "duplicate" when the `UNIQUE (category, stream, version)` backstop caught a true race past the
         * CAS and `DuplicateVersion` is thrown.
         */
        public string $kind,
    ) {}
}
