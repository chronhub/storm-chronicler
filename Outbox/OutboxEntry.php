<?php

declare(strict_types=1);

namespace Storm\Chronicler\Outbox;

/**
 * One outbox row to write atomically with its event: the event's position `sequence_no`, its
 * `partitionKey` which is the qualified stream, so the relay preserves order within a partition and a
 * stuck row only blocks its own aggregate, and its stored type and schema version. No copy of the
 * header or content: the row is a publication index, the store holds the payload and the relay
 * reads it there in its claim.
 */
final readonly class OutboxEntry
{
    public function __construct(
        public int $position,
        public string $partitionKey,
        public string $type,
        public int $eventVersion,
    ) {}
}
