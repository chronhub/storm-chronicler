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
use Storm\Serializer\AliasBoundMessageSerializer;
use Storm\Serializer\Exception\SerializationException;
use Storm\Serializer\MessageSerializer;
use Storm\Serializer\SerializedMessage;

/**
 * Rebuilds a `Message` from a stored event row, the single source of truth for upcast-on-read.
 *
 * The read path, `EventRowCodec::fromRow()`, wraps the message in an `EventRecord`; the republish path,
 * `OutboxRelay`, publishes the bare message. Both must decode, resolve the type and upcast identically,
 * and one shared place keeps them from drifting, such as a fix to the upcast applied on one side only.
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
         * Required, with no empty default: the chain defines what a stored event means, and a
         * composition that silently reads un-upcast rows is the drift this refuses. A caller states
         * its identity.
         */
        private UpcasterChain $upcasters,
    ) {}

    /**
     * @param  array<string, mixed>  $row  a stored row exposing `type`, `event_version`, `header`, `content`
     *
     * @throws SerializationException when the stored row is malformed, or the event type is not
     *                                deserializable
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
     * The rebuilt message, paired with the stored payload as written.
     *
     * The stored payload is pre-upcast, with personal keys still ciphered. The read path threads the
     * pair into `EventRecord::$storedContent`, so the inspection surfaces render personal keys as
     * stored; the payload references the already-decoded content and is never a copy.
     *
     * Same contract and same throws as `toMessage()`.
     *
     * @param  array<string, mixed>  $row  a stored row exposing `type`, `event_version`, `header`, `content`
     * @return array{Message, array<string, mixed>}
     *
     * @throws SerializationException when the stored row is malformed, or the event type is not
     *                                deserializable
     * @throws UnknownEventType when the stored `type` resolves to no known class, or to a class
     *                          that is not a domain event
     * @throws UndecodableRow when this runtime cannot decode a stored row, a missing or duplicated
     *                        version step, an upcaster that threw, or a row written by a newer
     *                        release; `resolvableByAnotherRuntime()` separates a deployment state
     *                        from a row no release will decode
     */
    public function toMessageAndStored(array $row): array
    {
        // validated once: the type, and the decoded header and content
        $serialized = SerializedMessage::fromRow($row);

        // the `type` column, an `EventType` alias, is the source of truth for the event class; it goes
        // back in as the `MessageType` header so the serializer can rebuild the object, a rule
        // `SerializedMessage` owns
        $class = $this->eventTypeMapper->toClass($serialized->requireType());

        if (! is_a($class, DomainEvent::class, true)) {
            // the store holds domain events only: any other loadable class is a restore or import
            // artifact, refused before its payload is hydrated. The mapper cannot hold this gate, since
            // the neutral wire resolves commands through the same `toClass()`, and the
            // `UnknownEventType` lane keeps the relay dead-lettering the row rather than a foreign
            // type escaping the drain.
            throw UnknownEventType::notAnEvent($serialized->requireType(), $class);
        }

        $header = SerializedMessage::internalizeType($serialized->header, $class);

        // restore the source stream on the read side, so a read event knows its stream and so its
        // category, split on the first `-`. Both durable reads provide it, the event-store `stream`
        // column and the relay's `partition_key AS stream` alias, the same value under its ordering
        // name; the guard stays for a caller feeding rows without it.
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

        // the stored `type` column, not an alias recomputed from the class: it is the value the row was
        // written with, so an envelope bound to it opens even after the alias map moved on
        $data = ['header' => $header, 'content' => $content];
        $message = $this->serializer instanceof AliasBoundMessageSerializer
            ? $this->serializer->deserializeStored($data, $serialized->requireType())
            : $this->serializer->deserialize($data);

        return [$message, $serialized->content];
    }
}
