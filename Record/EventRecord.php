<?php

declare(strict_types=1);

namespace Storm\Chronicler\Record;

use Storm\Chronicler\Exception\NotADomainEvent;
use Storm\Clock\PointInTime;
use Storm\Contracts\Chronicler\Position;
use Storm\Contracts\Message\DomainEvent;
use Storm\Message\Message;

/**
 * A stored event read back from the event store.
 *
 * It carries the neutral `Message` envelope, with its header bag and the deserialized domain object,
 * plus the assigned `Position` and the database-generated `recorded_at`. A plain data carrier, yielded
 * on read and never built by hand in the domain.
 *
 * Per-event metadata such as the aggregate version, the type and the stream lives in the message
 * header rather than in separate fields: the header bag is the extension point.
 */
final readonly class EventRecord
{
    public function __construct(
        public Message $message,
        public Position $position,
        public PointInTime $recordedAt,
        /**
         * The stored payload as written: pre-upcast, personal keys still ciphered.
         *
         * It references the already-decoded row content, so it costs one array slot and never a copy,
         * and the fold path ignores it. It serves the inspection surfaces: `PersonalDataVeil` renders
         * personal keys from this stored form instead of the rebuilt event's decrypted `toPayload()`.
         *
         * @var array<string, mixed>
         */
        public array $storedContent = [],
    ) {}

    /**
     * The stored domain event.
     *
     * A record only ever comes from reading the event store, which stores domain events exclusively,
     * so the envelope always wraps a `DomainEvent`.
     *
     * @throws NotADomainEvent when the envelope wraps a non-event, after a corrupt read or a misuse
     */
    public function event(): DomainEvent
    {
        $event = $this->message->message();

        if (! $event instanceof DomainEvent) {
            throw NotADomainEvent::got($event);
        }

        return $event;
    }
}
