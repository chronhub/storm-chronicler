<?php

declare(strict_types=1);

namespace Storm\Chronicler\Record;

use Storm\Chronicler\Evolution\UpcasterChain;
use Storm\Chronicler\Exception\UnknownEventType;
use Storm\Chronicler\Outbox\OutboxRelay;
use Storm\Contracts\Chronicler\EventTypeMapper;
use Storm\Contracts\Chronicler\UndecodableRow;
use Storm\Contracts\Message\DomainEvent;
use Storm\Message\Header;
use Storm\Message\Message;
use Storm\Serializer\Exception\SerializationException;
use Storm\Serializer\MessageSerializer;
use Storm\Serializer\SerializedMessage;

/**
 * Rebuilds a Message from a stored event row, the single source of truth for upcast-on-read.
 *
 * Shared by the read path, EventRowCodec::fromRow, which then wraps the message in an
 * EventRecord, and the republished path, OutboxRelay, which publishes the bare message. Both MUST
 * decode and resolve the type and upcast identically; keeping it in one place stops the two from
 * drifting, for example a fix to the upcast applied on only one side.
 *
 * @see EventRowCodec::fromRow()
 * @see EventRecord
 * @see OutboxRelay
 */
final readonly class StoredEventReader
{
    public function __construct(
        private MessageSerializer $serializer,
        private EventTypeMapper $eventTypeMapper,
        /**
         * REQUIRED, no empty default: the chain is stored-event MEANING, and a composition that
         * silently reads un-upcast rows is the drift this refuses; a caller states its identity.
         */
        private UpcasterChain $upcasters,
    ) {}

    /**
     * @param  array<string, mixed>  $row  a stored row exposing `type`, `event_version`, `header`, `content`
     *
     * @throws SerializationException when the stored row is malformed, or the event type is not deserializable
     * @throws UnknownEventType when the stored `type` resolves to no known class, or to a class
     *                          that is not a domain event
     * @throws UndecodableRow when this runtime cannot decode a stored row, a missing or duplicated
     *                        version step, an upcaster that threw, or a row written by a newer
     *                        release; `resolvableByAnotherRuntime()` separates a deployment state
     *                        from a row no release will decode
     */
    public function toMessage(array $row): Message
    {
        return $this->toMessageAndStored($row)[0];
    }

    /**
     * The rebuilt message TOGETHER with the stored payload as written, pre-upcast, personal keys
     * still ciphered. The read path threads the pair into `EventRecord::$storedContent` so the
     * inspection surfaces can render personal keys as stored; a reference to the already-decoded
     * content, never a copy.
     *
     * Same contract as {@see toMessage()}, same throws.
     *
     * @param  array<string, mixed>  $row  a stored row exposing `type`, `event_version`, `header`, `content`
     * @return array{Message, array<string, mixed>}
     *
     * @throws SerializationException when the stored row is malformed, or the event type is not deserializable
     * @throws UnknownEventType when the stored `type` resolves to no known class, or to a class
     *                          that is not a domain event
     * @throws UndecodableRow when this runtime cannot decode a stored row, a missing or duplicated
     *                        version step, an upcaster that threw, or a row written by a newer
     *                        release; `resolvableByAnotherRuntime()` separates a deployment state
     *                        from a row no release will decode
     */
    public function toMessageAndStored(array $row): array
    {
        $serialized = SerializedMessage::fromRow($row); // validated once: type + decoded header/content

        // The `type` column, an EventType alias, is the source of truth for the event class; feed it back
        // as the `MessageType` header so the serializer can rebuild the object; SerializedMessage owns that rule.
        $class = $this->eventTypeMapper->toClass($serialized->requireType());

        if (! is_a($class, DomainEvent::class, true)) {
            // the store holds domain events exclusively; a stored type resolving to any OTHER loadable
            // class, a restore or import artifact, is refused BEFORE its payload is hydrated. The
            // mapper cannot carry this gate: the neutral wire resolves commands through the same
            // toClass(). Riding the UnknownEventType lane keeps the relay dead-lettering the row
            // instead of letting a foreign type escape the drain.
            throw UnknownEventType::notAnEvent($serialized->requireType(), $class);
        }

        $header = SerializedMessage::internalizeType($serialized->header, $class);

        // Restore the source stream id on the read side so a read event knows its own stream, and hence its
        // category, split on the first `-`. Both durable reads provide it: the event-store `stream`
        // column, and the outbox relay's `partition_key AS stream` alias, the same value under its ordering name;
        // the guard stays for any caller feeding rows without it.
        if (isset($row['stream'])) {
            $header[Header::StreamName->value] = (string) $row['stream'];
        }

        // Upcast the payload from its stored schema version up to the type's current version, so the
        // serializer always rebuilds a current-shape event, whatever the row's age.
        $content = $this->upcasters->upcast(
            $this->eventTypeMapper->toType($class),
            (int) $row['event_version'],
            $this->eventTypeMapper->versionOf($class),
            $serialized->content,
        );

        return [$this->serializer->deserialize(['header' => $header, 'content' => $content]), $serialized->content];
    }
}
