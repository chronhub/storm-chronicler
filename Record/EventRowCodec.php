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
use Storm\Serializer\Exception\SerializationException;
use Storm\Serializer\MessageSerializer;
use Storm\Serializer\SerializedMessage;
use Storm\Stream\StreamName;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Default StreamPersistence.
 *
 * Write: the structural columns come from the stream and the message headers, `version` via
 * Header::AggregateVersion and `type` via the EventTypeMapper.
 *
 * Read: delegates the row-to-Message rebuild, type resolution, and upcast-on-read to the shared
 * StoredEventReader, then wraps it in an EventRecord with its SequencePosition and `recorded_at`.
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
        // REQUIRED, no empty default: a chain is stored-event MEANING, and a manual composition
        // that silently reads un-upcast rows is exactly the wiring drift this refuses; a test
        // states its identity with an explicit `new UpcasterChain`
        UpcasterChain $upcasters,
    ) {
        $this->reader = new StoredEventReader($serializer, $eventTypeMapper, $upcasters);
    }

    public function toRow(StreamName $stream, Message $message): array
    {
        // Redundant defense behind Stream's own validating gate: this method is publicly callable
        // with a bare Message, and a persisted non-event poisons every later read of the stream
        // since EventRecord throws NotADomainEvent; refuse at the WRITE, symmetric with the read.
        if (! $message->message() instanceof DomainEvent) {
            throw NotADomainEvent::got($message->message());
        }

        $data = $this->serializer->serialize($message);

        // The `type` column is the single source of truth for the event type, rebuilt into the
        // header on read via the mapper; the strip/inject rule is owned by SerializedMessage.
        $header = SerializedMessage::externalizeType($data[SerializedMessage::HEADER]);

        try {
            $encodedHeader = json_encode($header, JSON_THROW_ON_ERROR);
            $encodedContent = json_encode($data[SerializedMessage::CONTENT], JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw SerializationException::cannotEncode($e);
        }

        return [
            'category' => $stream->category,
            'stream' => $stream->toString(),
            'version' => $message->aggregateVersion(),
            SerializedMessage::TYPE => $this->eventTypeMapper->toType($message->message()::class),
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
