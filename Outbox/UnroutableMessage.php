<?php

declare(strict_types=1);

namespace Storm\Chronicler\Outbox;

use RuntimeException;
use Throwable;

/**
 * The shipped concrete UnrecoverablePublishFailure: an outbox message that can never be delivered,
 * because the consumer rejected it or the destination is unroutable. Throw it from a publisher, or in
 * sync dispatch a handler, so OutboxRelay dead-letters the row immediately instead of spending the
 * retry budget. Pass the cause as `$previous`; the relay records the whole chain in `last_error`.
 *
 * `RuntimeException` per the exception policy, since a delivery rejection is a runtime condition, not a
 * bug. For an exception that already has its own hierarchy, implement UnrecoverablePublishFailure on it
 * directly rather than throwing this.
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
