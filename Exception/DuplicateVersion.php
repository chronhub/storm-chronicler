<?php

declare(strict_types=1);

namespace Storm\Chronicler\Exception;

/**
 * The database's unique `(stream, version)` rejected the append.
 *
 * The cause is a race that slipped past the pre-check, or a redelivery of the same message replaying
 * the same events. Do not retry it blindly: the work may already be done, so treat it idempotently and
 * ack, at best deduplicated upstream on message id.
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
