<?php

declare(strict_types=1);

namespace Storm\Chronicler\Outbox;

/**
 * No-op `OutboxWriter` that disables the outbox: events are appended without enqueuing anything to publish.
 */
final class NullOutboxWriter implements OutboxWriter
{
    public function write(array $entries): void {}
}
