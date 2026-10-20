<?php

declare(strict_types=1);

namespace Storm\Chronicler\Outbox;

use RuntimeException;
use Throwable;

/**
 * The shipped concrete `UnrecoverablePublishFailure`: an outbox message that can never be delivered.
 *
 * It covers three causes:
 *
 * - The consumer rejected it
 *
 * - The destination is unroutable
 *
 * - The publisher meets the same refusal on every attempt, as when the transport cannot encode the
 *   event or the stored headers cannot be stamped as its context
 *
 * Throw it from a publisher, or from a handler in sync dispatch, passing the cause as `$previous`: the
 * relay records the whole chain in `last_error`.
 *
 * It extends `RuntimeException` per the exception policy, since a delivery rejection is a runtime
 * condition, not a bug.
 *
 * @see OutboxRelay
 */
final class UnroutableMessage extends RuntimeException implements UnrecoverablePublishFailure
{
    public static function reason(string $why, ?Throwable $previous = null): self
    {
        return new self($why, previous: $previous);
    }
}
