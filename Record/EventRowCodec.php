<?php

declare(strict_types=1);

namespace Storm\Chronicler\Record;

use JsonException;
use Storm\Chronicler\Evolution\UpcasterChain;
use Storm\Chronicler\Exception\NotADomainEvent;
use Storm\Clock\PointInTime;
use Storm\Contracts\Chronicler\EventTypeMapper;
use Storm\Contracts\Message\DomainEvent;
use Storm\Message\Header;
use Storm\Message\Message;
use Storm\Serializer\AliasBoundMessageSerializer;
use Storm\Serializer\Exception\SerializationException;
use Storm\Serializer\MessageSerializer;
use Storm\Serializer\SerializedMessage;
use Storm\Stream\StreamName;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * The default `StreamPersistence`.
 *
 * On write, the structural columns come from the stream and the message headers: `version` through
 * `Header::AggregateVersion`, `type` through the `EventTypeMapper`.
 *
 * On read, the shared `StoredEventReader` rebuilds the message from the row, resolves its type and
 * upcasts it; the codec wraps the result in an `EventRecord` with its `SequencePosition` and
 * `recorded_at`.
 *
 * @see \Storm\Message\Header::AggregateVersion
 */
#[AsAlias(StreamPersistence::class)]
final readonly class EventRowCodec implements StreamPersistence
{
    /** Shared read kernel: the decode, type-resolve, and upcast logic the outbox relay also uses. */
    private StoredEventReader $reader;

    public function __construct(
        private MessageSerializer $serializer,
        private EventTypeMapper $eventTypeMapper,
        // required, with no empty default: the chain defines what a stored event means, and a manual
        // composition that silently reads un-upcast rows is the wiring drift this refuses; a test
        // states its identity with an explicit `new UpcasterChain`
        UpcasterChain $upcasters,
    ) {
        $this->reader = new StoredEventReader($serializer, $eventTypeMapper, $upcasters);
    }

    public function toRow(StreamName $stream, Message $message): array
    {
        // a second gate behind `Stream`'s own: this public method accepts a bare `Message`, and a
        // persisted non-event breaks every later read of the stream, since `EventRecord` throws
        // `NotADomainEvent`; refuse at the write, symmetric with the read
        if (! $message->message() instanceof DomainEvent) {
            throw NotADomainEvent::got($message->message());
        }

        $type = $this->eventTypeMapper->toType($message->message()::class);

        // the codec binds the very type the row stores, the one `StoredEventReader` hands back from
        // the column, so a class renamed under that alias still opens the row's envelopes
        $data = $this->serializer instanceof AliasBoundMessageSerializer
            ? $this->serializer->serializeStored($message, $type)
            : $this->serializer->serialize($message);

        // The `type` column is the single source of truth for the event type, rebuilt into the
        // header on read via the mapper; the strip/inject rule is owned by `SerializedMessage`.
        $header = SerializedMessage::externalizeType($data[SerializedMessage::HEADER]);

        try {
            $encodedHeader = json_encode($header, JSON_THROW_ON_ERROR);
            $encodedContent = json_encode($data[SerializedMessage::CONTENT], JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw SerializationException::cannotEncode($e);
        }

        return [
            'category' => $stream->category->value,
            'stream' => $stream->toString(),
            'version' => $message->aggregateVersion(),
            SerializedMessage::TYPE => $type,
            'event_version' => $this->eventTypeMapper->versionOf($message->message()::class),
            SerializedMessage::HEADER => $encodedHeader,
            SerializedMessage::CONTENT => $encodedContent,
        ];
    }

    public function fromRow(array $row): EventRecord
    {
        [$message, $storedContent] = $this->reader->toMessageAndStored($row);

        return new EventRecord(
            $message,
            SequencePosition::fromString((string) $row['sequence_no']),
            PointInTime::fromStorage((string) $row['recorded_at']),
            $storedContent,
        );
    }
}
