<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests\Fixture;

use Storm\Contracts\Message\DomainEvent;
use Storm\Message\EventType;
use Storm\Message\HasConstructablePayload;

/**
 * Current v2 shape of an event whose v1 payload had no `currency`; exercises upcast-on-read.
 */
#[EventType('test.upcast', version: 2)]
final class TestUpcastEvent implements DomainEvent
{
    use HasConstructablePayload;

    public int $amount {
        get => (int) $this->payload['amount'];
    }

    public string $currency {
        get => (string) $this->payload['currency'];
    }

    public function aggregateId(): string
    {
        return (string) $this->payload['accountId'];
    }
}
