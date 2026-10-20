<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Evolution\MappedEventTypeMapper;
use Storm\Chronicler\Evolution\UpcasterChain;
use Storm\Chronicler\Record\EventRowCodec;
use Storm\Chronicler\Tests\Fixture\RenamedAliasEvent;
use Storm\Message\Header;
use Storm\Message\Message;
use Storm\Serializer\AliasBoundMessageSerializer;
use Storm\Serializer\DefaultMessageSerializer;
use Storm\Serializer\MessageSerializer;
use Storm\Stream\StreamName;

/**
 * The row write hands the codec the very `type` it stores.
 *
 * It goes through the `AliasBoundMessageSerializer` capability when the codec has it and through
 * `serialize()` when it does not, so an envelope sealed here binds the alias `StoredEventReader`
 * hands back from the column.
 */
final class EventRowCodecStoredTypeTest extends TestCase
{
    #[Test]
    public function an_alias_bound_serializer_seals_under_the_type_the_row_stores(): void
    {
        $sealedUnder = null;
        $serializer = $this->createMock(AliasBoundMessageSerializer::class);
        $serializer->expects($this->never())->method('serialize');
        $serializer->expects($this->once())
            ->method('serializeStored')
            ->willReturnCallback(static function (Message $message, string $storedType) use (&$sealedUnder): array {
                $sealedUnder = $storedType;

                return new DefaultMessageSerializer()->serialize($message);
            });

        $row = $this->codec($serializer)->toRow(new StreamName('order-order-1'), $this->message());

        self::assertSame('test.renamed_alias', $row['type']);
        self::assertSame($row['type'], $sealedUnder);
    }

    #[Test]
    public function a_serializer_without_the_capability_writes_through_serialize(): void
    {
        $serializer = $this->createMock(MessageSerializer::class);
        $serializer->expects($this->once())
            ->method('serialize')
            ->willReturnCallback(new DefaultMessageSerializer()->serialize(...));

        $row = $this->codec($serializer)->toRow(new StreamName('order-order-1'), $this->message());

        self::assertSame('test.renamed_alias', $row['type']);
    }

    private function codec(MessageSerializer $serializer): EventRowCodec
    {
        return new EventRowCodec($serializer, new MappedEventTypeMapper([RenamedAliasEvent::class]), new UpcasterChain([]));
    }

    private function message(): Message
    {
        return new Message(RenamedAliasEvent::fromPayload(['id' => 'order-1']))
            ->withHeader(Header::MessageType, RenamedAliasEvent::class)
            ->withHeader(Header::MessageId, 'event-1')
            ->withHeader(Header::AggregateVersion, 1);
    }
}
