<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests\Fixture;

use Storm\Contracts\Message\DomainEvent;

final class SomethingHappened implements DomainEvent
{
    public function __construct(
        public string $what,
    ) {}

    public function aggregateId(): string
    {
        return 'something';
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
