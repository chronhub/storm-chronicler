<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests\Fixture;

use Storm\Contracts\Message\DomainEvent;
use Storm\Message\Attribute\Personal;
use Storm\Message\HasConstructablePayload;

#[Personal(subject: 'customer_id', keys: ['nickname', 'full_name'], fallback: ['nickname' => '', 'full_name' => ''])]
final class OptionalPersonalFieldsEvent implements DomainEvent
{
    use HasConstructablePayload;

    public function aggregateId(): string
    {
        return (string) $this->payload['customer_id'];
    }
}
