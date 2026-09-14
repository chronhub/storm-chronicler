<?php

declare(strict_types=1);

namespace Storm\Chronicler\Review361\Fixture\BeforeV1;

use Storm\Contracts\Message\DomainEvent;
use Storm\Message\EventType;
use Storm\Message\HasConstructablePayload;

#[EventType('review361.original', version: 1, replaces: [])]
final class Event implements DomainEvent
{
    use HasConstructablePayload;

    public function aggregateId(): string
    {
        return $this->toPayload()['id'];
    }
}
