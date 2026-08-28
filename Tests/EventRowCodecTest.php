<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests;

use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Chronicler\Evolution\IdentityEventTypeMapper;
use Storm\Chronicler\Evolution\UpcasterChain;
use Storm\Chronicler\Exception\NotADomainEvent;
use Storm\Chronicler\Exception\UnknownEventType;
use Storm\Chronicler\Record\EventRowCodec;
use Storm\Chronicler\Tests\Fixture\NotAnEventPayload;
use Storm\Chronicler\Tests\Fixture\SomethingHappened;
use Storm\Message\Header;
use Storm\Message\Message;
use Storm\Serializer\DefaultMessageSerializer;
use Storm\Serializer\Exception\SerializationException;
use Storm\Stream\StreamName;

final class EventRowCodecTest extends TestCase
{
    private EventRowCodec $persistence;

    #[Override]
    protected function setUp(): void
    {
        $this->persistence = new EventRowCodec(
            new DefaultMessageSerializer,
            new IdentityEventTypeMapper,
            new UpcasterChain,
        );
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    #[Test]
    public function maps_a_message_to_a_row(): void
    {
        $row = $this->persistence->toRow(new StreamName('order-1'), $this->message());

        $this->assertSame('order', $row['category']);
        $this->assertSame('order-1', $row['stream']);
        $this->assertSame(3, $row['version']);
        $this->assertSame(SomethingHappened::class, $row['type']);
        $this->assertIsString($row['header']);
        $this->assertIsString($row['content']);
    }

    #[Test]
    public function wraps_an_unencodable_payload_as_a_serialization_failure(): void
    {
        // a content value json_encode rejects, invalid UTF-8, crosses as the contracted SerializationException
        // from cannotEncode, never a raw JsonException; the write boundary owes the same translation as the read.
        $message = new Message(
            new SomethingHappened("\xB1\x31"),
            [
                Header::MessageId->value => 'id-1',
                Header::MessageType->value => SomethingHappened::class,
                Header::AggregateVersion->value => 3,
            ],
        );

        $this->expectException(SerializationException::class);

        $this->persistence->toRow(new StreamName('order-1'), $message);
    }

    #[Test]
    public function refuses_to_persist_a_non_event_message(): void
    {
        // redundant defense behind Stream's own gate, but toRow is publicly callable with a bare Message:
        // a persisted non-event poisons every later read, EventRecord throwing NotADomainEvent, so it is
        // refused at the WRITE, symmetric with the read.
        $this->expectException(NotADomainEvent::class);

        $this->persistence->toRow(new StreamName('order-1'), new Message(new stdClass));
    }

    // -------------------------------------------------------------------------
    // Round-trip
    // -------------------------------------------------------------------------

    #[Test]
    public function round_trips_a_row_back_to_an_event_record(): void
    {
        $row = $this->persistence->toRow(new StreamName('order-1'), $this->message());

        // DB-generated columns, simulated
        $row['sequence_no'] = '7';
        $row['recorded_at'] = '2026-05-20 10:00:00.000000+00';

        $record = $this->persistence->fromRow($row);

        $event = $record->message->message();
        $this->assertInstanceOf(SomethingHappened::class, $event);
        $this->assertSame('hello', $event->what);
        $this->assertSame(3, $record->message->aggregateVersion());
        $this->assertSame('7', $record->position->toString());
        $this->assertSame('2026-05-20T10:00:00.000000+00:00', $record->recordedAt->toString());
    }

    #[Test]
    public function refuses_a_stored_type_resolving_to_a_loadable_non_event_before_hydration(): void
    {
        // the symmetric read-side gate of refuses_to_persist_a_non_event_message: a row whose `type`
        // names a loadable SerializablePayload that is NOT a DomainEvent, the shape a restore or an
        // import could smuggle in, is refused BEFORE fromPayload runs; the fixture throws on
        // hydration, so reaching it would fail this test with the wrong exception
        $row = $this->persistence->toRow(new StreamName('order-1'), $this->message());
        $row['type'] = NotAnEventPayload::class;
        $row['sequence_no'] = '7';
        $row['recorded_at'] = '2026-05-20 10:00:00.000000+00';

        $this->expectException(UnknownEventType::class);

        $this->persistence->fromRow($row);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function message(): Message
    {
        return new Message(
            new SomethingHappened('hello'),
            [
                Header::MessageId->value => 'id-1',
                Header::MessageType->value => SomethingHappened::class,
                Header::AggregateVersion->value => 3,
            ],
        );
    }
}
