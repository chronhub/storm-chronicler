<?php

declare(strict_types=1);

namespace Storm\Chronicler\Outbox;

/**
 * One outbox row to write atomically with its event.
 *
 * - `position`, the event's `sequence_no`
 *
 * - `partitionKey`, the qualified stream, so the relay preserves order within a partition and a stuck
 *   row only blocks its own aggregate
 *
 * - `type` and `eventVersion`, the stored type and schema version
 *
 * The row is a publication index: the store holds the payload, and the relay reads it there in its
 * claim, so the row copies neither header nor content.
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
