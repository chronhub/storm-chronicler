<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests\Fixture;

use RuntimeException;
use Storm\Contracts\Message\SerializablePayload;

/**
 * Test double: a loadable `SerializablePayload` that is not a `DomainEvent`.
 *
 * It is the shape a restored or imported row could smuggle into the store. Hydrating it is the
 * failure the read gate refuses, so `fromPayload()` throws to prove the gate fired first.
 */
final class NotAnEventPayload implements SerializablePayload
{
    public function toPayload(): array
    {
        return [];
    }

    public static function fromPayload(array $payload): static
    {
        throw new RuntimeException('a non-event payload must be refused before hydration');
    }
}
