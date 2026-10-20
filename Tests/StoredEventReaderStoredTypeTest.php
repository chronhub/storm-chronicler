<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Evolution\MappedEventTypeMapper;
use Storm\Chronicler\Evolution\UpcasterChain;
use Storm\Chronicler\Record\StoredEventReader;
use Storm\Chronicler\Tests\Fixture\RenamedAliasEvent;
use Storm\Message\Header;
use Storm\Message\Message;
use Storm\Serializer\AliasBoundMessageSerializer;
use Storm\Serializer\DefaultMessageSerializer;
use Storm\Serializer\MessageSerializer;

/**
 * The stored-row read hands the codec the `type` column exactly as stored.
 *
 * It goes through the `AliasBoundMessageSerializer` capability when the codec has it and through
 * `deserialize()` when it does not. The row below is stored under the alias its class replaces, so a
 * reader recomputing the alias from the class would hand over the current one instead.
 */
final class StoredEventReaderStoredTypeTest extends TestCase
{
    #[Test]
    public function an_alias_bound_serializer_receives_the_stored_type_column(): void
    {
        $serializer = $this->createMock(AliasBoundMessageSerializer::class);
        $serializer->expects($this->never())->method('deserialize');
        $serializer->expects($this->once())
            ->method('deserializeStored')
            ->with($this->anything(), 'test.original_alias')
            ->willReturnCallback(new DefaultMessageSerializer()->deserialize(...));

        $event = $this->reader($serializer)->toMessage($this->row())->message();

        self::assertInstanceOf(RenamedAliasEvent::class, $event);
    }

    #[Test]
    public function a_serializer_without_the_capability_reads_through_deserialize(): void
    {
        $serializer = $this->createMock(MessageSerializer::class);
        $serializer->expects($this->once())
            ->method('deserialize')
            ->willReturnCallback(new DefaultMessageSerializer()->deserialize(...));

        $event = $this->reader($serializer)->toMessage($this->row())->message();

        self::assertInstanceOf(RenamedAliasEvent::class, $event);
    }

    private function reader(MessageSerializer $serializer): StoredEventReader
    {
        return new StoredEventReader($serializer, new MappedEventTypeMapper([RenamedAliasEvent::class]), new UpcasterChain([]));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(): array
    {
        $data = new DefaultMessageSerializer()->serialize(
            new Message(RenamedAliasEvent::fromPayload(['id' => 'order-1']))
                ->withHeader(Header::MessageType, RenamedAliasEvent::class)
                ->withHeader(Header::MessageId, 'event-1'),
        );
        unset($data['header'][Header::MessageType->value]);

        return [
            'type' => 'test.original_alias',
            'event_version' => 2,
            'header' => json_encode($data['header'], JSON_THROW_ON_ERROR),
            'content' => json_encode($data['content'], JSON_THROW_ON_ERROR),
            'stream' => 'order-order-1',
        ];
    }
}
