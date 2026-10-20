<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests\Fixture;

use Storm\Contracts\Message\DomainEvent;

/**
 * A second fixture event type, alongside `SomethingHappened`, so tests can exercise type filtering.
 *
 * The stored `type` column differs by event class.
 *
 * @see SomethingHappened
 */
final class OtherThingHappened implements DomainEvent
{
    public function __construct(
        public string $what,
    ) {}

    public function aggregateId(): string
    {
        return 'other';
    }

    public function toPayload(): array
    {
        return ['what' => $this->what];
    }

    public static function fromPayload(array $payload): static
    {
        return new self((string) $payload['what']);
    }
}
