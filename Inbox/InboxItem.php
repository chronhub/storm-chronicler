<?php

declare(strict_types=1);

namespace Storm\Chronicler\Inbox;

use Closure;

/**
 * One unit of work for a batched inbox run: the message id to deduplicate on and the handler to run if it
 * is the first delivery.
 *
 * @see BatchInboxStore the store that consumes these
 */
final readonly class InboxItem
{
    public function __construct(
        public string $messageId,
        public Closure $handler,
    ) {}
}
