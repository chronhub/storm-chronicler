<?php

declare(strict_types=1);

namespace Storm\Chronicler\Outbox;

/**
 * One outbox row to write atomically with its event: the event's position `sequence_no`, its
 * `partitionKey` which is the qualified stream, so the relay preserves order within a partition and a
 * stuck row only blocks its own aggregate, its stored type and schema version, and the serialized
 * header and content as JSON strings.
 */
final readonly class OutboxEntry
{
    public function __construct(
        public int $position,
        public string $partitionKey,
        public string $type,
        public int $eventVersion,
        public string $header,
        public string $content,
    ) {}
}
