<?php

declare(strict_types=1);

namespace Storm\Chronicler\Review361\Fixture\AfterV1;

use Storm\Contracts\Message\DomainEvent;
use Storm\Message\EventType;
use Storm\Message\HasConstructablePayload;

#[EventType('review361.renamed', version: 1, replaces: ['review361.original'])]
final class Event implements DomainEvent
{
    use HasConstructablePayload;

    public function aggregateId(): string
    {
        return $this->toPayload()['id'];
    }
}
