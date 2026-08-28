<?php

declare(strict_types=1);

namespace Storm\Chronicler\Record;

use Storm\Chronicler\Exception\NotADomainEvent;
use Storm\Contracts\Chronicler\InvalidPosition;
use Storm\Contracts\Chronicler\UndecodableRow;
use Storm\Contracts\Chronicler\UnknownEventType;
use Storm\Contracts\Clock\ClockExceptionContract;
use Storm\Contracts\Serializer\SerializationExceptionContract;
use Storm\Message\Message;
use Storm\Stream\StreamName;

/**
 * Maps between a neutral Message and an `event_store` row, and back to an EventRecord on read.
 *
 * Lives in the package rather than Contracts because it names concrete types: `Message`,
 * `EventRecord`, `StreamName`. It owns the structural columns `category`, `stream`, `version`,
 * and `type`; the header and content go through the `MessageSerializer` and are json-encoded
 * for the jsonb columns. `sequence_no` and `recorded_at` are DB-generated and never written.
 */
interface StreamPersistence
{
    /**
     * Serialize a message into an `event_store` row.
     *
     * @return array<string, mixed>
     *
     * @throws NotADomainEvent when the message does not wrap a domain event; the store holds domain
     *                         events exclusively, so the refusal belongs to the write and not only to
     *                         the read that would later choke on the row
     * @throws SerializationExceptionContract when the message is not a serializable payload
     */
    public function toRow(StreamName $stream, Message $message): array;

    /**
     * Rebuild an EventRecord from a stored `event_store` row.
     *
     * @param  array<string, mixed>  $row
     *
     * @throws SerializationExceptionContract when the stored event type is unknown / not deserializable
     * @throws UnknownEventType when the stored `type` resolves to no known class, or to a class
     *                          that is not a domain event
     * @throws UndecodableRow when this runtime cannot decode a stored row, a missing or duplicated
     *                        version step, an upcaster that threw, or a row written by a newer
     *                        release; `resolvableByAnotherRuntime()` separates a deployment state
     *                        from a row no release will decode
     * @throws InvalidPosition when `sequence_no` is not a positive integer
     * @throws ClockExceptionContract when a stored point in time failed to be parsed
     */
    public function fromRow(array $row): EventRecord;
}
