<?php

declare(strict_types=1);

namespace Storm\Chronicler\Outbox;

/**
 * Writes outbox rows for just-appended events, within the append transaction so they are atomic with
 * the events. The default impl runs on its own injected connection, the shared one, so when the event
 * store calls it inside `transactional()` the rows commit with the events. Disable the outbox by
 * aliasing to NullOutboxWriter.
 */
interface OutboxWriter
{
    /**
     * Write the outbox rows for the given entries.
     *
     * @param  list<OutboxEntry>  $entries
     */
    public function write(array $entries): void;
}
