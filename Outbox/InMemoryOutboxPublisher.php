<?php

declare(strict_types=1);

namespace Storm\Chronicler\Outbox;

use Storm\Message\Message;

/**
 * In-memory `OutboxPublisher` that records what it published, for tests and local dev.
 */
final class InMemoryOutboxPublisher implements OutboxPublisher
{
    /** @var list<Message> */
    private array $published = [];

    public function publish(Message $message): void
    {
        $this->published[] = $message;
    }

    /**
     * @return list<Message>
     */
    public function published(): array
    {
        return $this->published;
    }
}
