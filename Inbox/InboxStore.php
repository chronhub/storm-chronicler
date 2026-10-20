<?php

declare(strict_types=1);

namespace Storm\Chronicler\Inbox;

use Closure;
use Throwable;

/**
 * Consumer-side idempotency: run a handler at most once per `(consumer, messageId)`.
 *
 * Records the pair and runs the handler atomically; if the pair was already recorded, the handler is
 * skipped. This is the safety net for at-least-once delivery, where the outbox can re-drain and
 * Messenger retries: a stateful reaction stays correct across duplicates. The `es_inbox` table backs it.
 */
interface InboxStore
{
    /**
     * Run `$handler` once per `(consumer, messageId)`, recording the pair in the same transaction.
     *
     * A duplicate is a no-op.
     *
     * @return bool true if the handler ran on first delivery, false if skipped as already processed
     *
     * @throws Throwable propagated from `$handler`; the recording is rolled back with it, so the
     *                   message is reprocessed on redelivery
     */
    public function once(string $consumer, string $messageId, Closure $handler): bool;
}
