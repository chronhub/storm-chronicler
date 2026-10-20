<?php

declare(strict_types=1);

namespace Storm\Chronicler\Outbox;

/**
 * Writes outbox rows for just-appended events, atomically with them inside the append transaction.
 *
 * The default implementation runs on its own injected connection, the shared one, so when the event
 * store calls it inside `transactional()` the rows commit with the events. Disable the outbox by
 * aliasing to `NullOutboxWriter`.
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
