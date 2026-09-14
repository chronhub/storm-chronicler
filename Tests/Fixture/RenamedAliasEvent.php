<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests\Fixture;

use Storm\Contracts\Message\DomainEvent;
use Storm\Message\EventType;
use Storm\Message\HasConstructablePayload;

/**
 * A current event whose canonical alias differs from the alias carried by historical rows.
 */
#[EventType('test.renamed_alias', version: 2, replaces: ['test.original_alias'])]
final class RenamedAliasEvent implements DomainEvent
{
    use HasConstructablePayload;

    public function aggregateId(): string
    {
        return (string) $this->payload['id'];
    }
}
