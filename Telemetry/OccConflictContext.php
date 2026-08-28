<?php

declare(strict_types=1);

namespace Storm\Chronicler\Telemetry;

/**
 * Carries the metrics-worthy data of an OCC conflict caught at append time.
 *
 * Kind distinguishes the two paths: "stale" means the pre-check on stream_heads lost the CAS, so
 * StaleVersion will be thrown. "Duplicate" means the `UNIQUE (category, stream, version)` backstop
 * fired, so DuplicateVersion will be thrown, a true race past the CAS.
 *
 * Both paths fire before the exception is re-thrown to the caller.
 */
final readonly class OccConflictContext
{
    public function __construct(
        /** The full qualified stream that lost the race. */
        public string $streamName,
        /** The stream's category. */
        public string $category,
        /** The version the caller passed in. */
        public int $expectedVersion,
        /** The current stream-head version observed by the engine. */
        public int $actualVersion,
        /** "stale" for the pre-check, or "duplicate" for the UNIQUE backstop. */
        public string $kind,
    ) {}
}
