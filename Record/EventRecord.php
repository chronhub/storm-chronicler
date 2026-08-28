<?php

declare(strict_types=1);

namespace Storm\Chronicler\Record;

use Storm\Chronicler\Exception\NotADomainEvent;
use Storm\Clock\PointInTime;
use Storm\Contracts\Chronicler\Position;
use Storm\Contracts\Message\DomainEvent;
use Storm\Message\Message;

/**
 * A single stored event read back from the event store: the neutral Message envelope, header bag
 * and the deserialized domain object, plus its assigned Position and the DB-generated
 * `recorded_at`.
 *
 * A plain data carrier, yielded on read, never built by hand in the domain. Per-event metadata
 * such as aggregate version, type, and stream lives in the Message header, not as separate fields
 * here: the header bag is the extension point.
 */
final readonly class EventRecord
{
    public function __construct(
        public Message $message,
        public Position $position,
        public PointInTime $recordedAt,
        /**
         * The stored payload AS WRITTEN, pre-upcast, personal keys still ciphered. A reference to
         * the already-decoded row content, so it costs one array slot, never a copy; the fold path
         * ignores it. It exists for the inspection surfaces: `PersonalDataVeil` renders personal
         * keys from HERE, as stored, instead of the rebuilt event's decrypted `toPayload()`.
         *
         * @var array<string, mixed>
         */
        public array $storedContent = [],
    ) {}

    /**
     * The stored domain event. A record only ever comes from reading the event store, which stores
     * domain events exclusively, so the envelope always wraps a DomainEvent.
     *
     * @throws NotADomainEvent when the envelope wraps a non-event, a corrupt read or a misuse
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
