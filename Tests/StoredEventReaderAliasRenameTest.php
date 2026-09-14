<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Evolution\MappedEventTypeMapper;
use Storm\Chronicler\Evolution\Upcaster;
use Storm\Chronicler\Evolution\UpcasterChain;
use Storm\Chronicler\Record\StoredEventReader;
use Storm\Chronicler\Tests\Fixture\RenamedAliasEvent;
use Storm\Message\Header;
use Storm\Message\Message;
use Storm\Serializer\DefaultMessageSerializer;

/**
 * Pins the upcaster lookup key at the real stored-row read boundary.
 */
final class StoredEventReaderAliasRenameTest extends TestCase
{
    #[Test]
    public function the_chain_is_polled_under_the_current_canonical_alias(): void
    {
        $upcaster = new class() implements Upcaster
        {
            /** @var list<array{string, int}> */
            public array $seen = [];

            public function supports(string $alias, int $fromVersion): bool
            {
                $this->seen[] = [$alias, $fromVersion];

                return $alias === 'test.renamed_alias' && $fromVersion === 1;
            }

            public function upcast(array $payload): array
            {
                return [...$payload, 'channel' => 'web'];
            }
        };
        $serializer = new DefaultMessageSerializer;
        $data = $serializer->serialize(
            new Message(RenamedAliasEvent::fromPayload(['id' => 'order-1']))
                ->withHeader(Header::MessageType, RenamedAliasEvent::class)
                ->withHeader(Header::MessageId, 'event-1'),
        );
        unset($data['header'][Header::MessageType->value]);
        $reader = new StoredEventReader(
            $serializer,
            new MappedEventTypeMapper([RenamedAliasEvent::class]),
            new UpcasterChain([$upcaster]),
        );
        $event = $reader->toMessage([
            'type' => 'test.original_alias',
            'event_version' => 1,
            'header' => json_encode($data['header'], JSON_THROW_ON_ERROR),
            'content' => json_encode($data['content'], JSON_THROW_ON_ERROR),
            'stream' => 'order-order-1',
        ])->message();

        self::assertInstanceOf(RenamedAliasEvent::class, $event);
        self::assertSame('web', $event->toPayload()['channel']);
        self::assertSame([['test.renamed_alias', 1]], $upcaster->seen);
    }
}
