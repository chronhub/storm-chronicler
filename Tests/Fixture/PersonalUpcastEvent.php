<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests\Fixture;

use Storm\Contracts\Message\DomainEvent;
use Storm\Message\Attribute\Personal;
use Storm\Message\EventType;
use Storm\Message\HasConstructablePayload;

/**
 * Current v2 shape of a MARKED event whose v1 payload had no `channel`; exercises the whole read
 * chain of crypto-shredding: the upcaster migrates the shape while `full_name` rides through as an
 * opaque envelope, and the ciphering codec renders it, or its fallback, only at deserialize.
 */
#[EventType('test.personal_upcast', version: 2)]
#[Personal(subject: 'customer_id', keys: ['full_name'], fallback: ['full_name' => '⌫'])]
final class PersonalUpcastEvent implements DomainEvent
{
    use HasConstructablePayload;

    public string $fullName {
        get => (string) $this->payload['full_name'];
    }

    public string $channel {
        get => (string) $this->payload['channel'];
    }

    public function aggregateId(): string
    {
        return (string) $this->payload['customer_id'];
    }
}
