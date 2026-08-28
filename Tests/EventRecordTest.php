<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Chronicler\Exception\NotADomainEvent;
use Storm\Chronicler\Record\EventRecord;
use Storm\Chronicler\Record\SequencePosition;
use Storm\Clock\PointInTime;
use Storm\Message\Message;

final class EventRecordTest extends TestCase
{
    #[Test]
    public function carries_message_position_and_recorded_at(): void
    {
        $message = new Message(new stdClass);
        $position = SequencePosition::fromInt(10);
        $recordedAt = PointInTime::from('2026-05-20T10:00:00.000000+00:00');

        $record = new EventRecord($message, $position, $recordedAt);

        $this->assertSame($message, $record->message);
        $this->assertSame($position, $record->position);
        $this->assertSame($recordedAt, $record->recordedAt);
    }

    #[Test]
    public function event_refuses_an_envelope_that_does_not_wrap_a_domain_event(): void
    {
        // the @throws every reader declares, renderers and projections: a corrupt read or a misuse
        // surfaces loud, never a TypeError deep in a fold
        $record = new EventRecord(
            new Message(new stdClass),
            SequencePosition::fromInt(1),
            PointInTime::from('2026-05-20T10:00:00.000000+00:00'),
        );

        $this->expectException(NotADomainEvent::class);

        $record->event();
    }
}
