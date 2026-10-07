<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests;

use Doctrine\DBAL\Connection;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Inbox\InboxConsumers;

final class InboxConsumersTest extends TestCase
{
    #[Test]
    public function an_empty_answer_from_the_registry_is_refused_rather_than_read_as_id_zero(): void
    {
        // a registry that answers nothing to both the read and the insert: no committed row can do
        // that, and reading it as id 0 would write proofs under a consumer nothing names
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(false);
        $connection->method('getTransactionNestingLevel')->willReturn(0);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('answered no id for consumer "events"');

        new InboxConsumers($connection)->id('events');
    }

    #[Test]
    public function a_committed_id_is_cached_and_an_id_read_inside_a_transaction_is_not(): void
    {
        $reads = 0;
        $level = 1;
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(static function () use (&$reads): int {
            $reads++;

            return 7;
        });
        $connection->method('getTransactionNestingLevel')->willReturnCallback(static function () use (&$level): int {
            return $level;
        });
        $registry = new InboxConsumers($connection);

        self::assertSame(7, $registry->id('events'));
        self::assertSame(7, $registry->id('events'));
        self::assertSame(2, $reads, 'inside a transaction every lookup reads the table');

        $level = 0;
        self::assertSame(7, $registry->id('events'));
        self::assertSame(7, $registry->id('events'));
        self::assertSame(3, $reads, 'outside a transaction the first lookup is the last read');
    }

    #[Test]
    public function the_registry_is_read_then_written_under_the_consumer_s_name(): void
    {
        $calls = [];
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(static function (string $sql, array $params) use (&$calls): int|false {
            $calls[] = $params;

            return count($calls) === 1 ? false : 9;
        });
        $connection->method('getTransactionNestingLevel')->willReturn(1);

        self::assertSame(9, new InboxConsumers($connection)->id('events'));
        self::assertSame([['name' => 'events'], ['name' => 'events']], $calls);
    }

    #[Test]
    public function an_id_below_one_is_refused_like_no_id_at_all(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn('0');
        $connection->method('getTransactionNestingLevel')->willReturn(0);

        $this->expectException(LogicException::class);

        new InboxConsumers($connection)->id('events');
    }

    #[Test]
    public function an_id_the_driver_hands_back_as_text_is_cached_as_the_integer_it_is(): void
    {
        // a driver is free to answer a column as text; the cache keeps the integer, or the next
        // lookup would hand an int-typed caller the text
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn('7');
        $connection->method('getTransactionNestingLevel')->willReturn(0);
        $registry = new InboxConsumers($connection);

        self::assertSame(7, $registry->id('events'));
        self::assertSame(7, $registry->id('events'));
    }
}
