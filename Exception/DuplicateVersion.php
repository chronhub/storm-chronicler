<?php

declare(strict_types=1);

namespace Storm\Chronicler\Exception;

/**
 * The database's unique `(stream, version)` rejected the append: a race that slipped past the
 * pre-check, or a redelivery of the same message. Do not blind-retry; it may already be done, so
 * treat it idempotently, best deduped upstream on message-id. See ConcurrencyException for the
 * rationale.
 */
final class DuplicateVersion extends ConcurrencyException
{
    public static function forStream(string $stream): self
    {
        return new self(sprintf(
            'Concurrency conflict on stream "%s": a duplicate (stream, version) was rejected.',
            $stream,
        ));
    }
}
