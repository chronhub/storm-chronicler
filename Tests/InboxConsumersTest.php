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
}
