<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Outbox\OutboxDeadLetter;

final class OutboxDeadLetterPageTest extends TestCase
{
    #[Test]
    public function the_page_bounds_itself_in_sql_rather_than_after_the_read(): void
    {
        // the whole point of the paged read: the cap and the cursor ride the statement, so a
        // dead-letter that grew unattended can never be loaded whole and sliced afterwards.
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->with(
                self::stringContains('LIMIT :limit'),
                ['after' => 12, 'limit' => 50],
                ['after' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER],
            )
            ->willReturn([]);

        self::assertSame([], new OutboxDeadLetter($connection)->listFailedPage(12, 50));
    }

    #[Test]
    #[Group('adversarial')]
    public function the_cursor_is_strict_and_the_order_is_the_drain_order(): void
    {
        // `>` and not `>=`: a cursor echoed back from the last row of a page must not re-serve that
        // row. And the order is the relay's, so a page reads in the order a requeue would republish.
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->with(self::callback(static function (string $sql): bool {
                self::assertStringContainsString('id > :after', $sql);
                self::assertStringNotContainsString('id >= :after', $sql);
                self::assertStringContainsString('ORDER BY id', $sql);
                self::assertStringContainsString("status = 'failed'", $sql);

                return true;
            }))
            ->willReturn([]);

        new OutboxDeadLetter($connection)->listFailedPage(0, 10);
    }

    #[Test]
    public function every_row_of_the_page_is_decoded_not_just_the_first(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([
            ['id' => '7', 'position' => '700', 'type' => 'a.one', 'attempts' => '3', 'last_error' => 'boom', 'failed_at' => '2026-08-23T10:00:00Z'],
            ['id' => '9', 'position' => '900', 'type' => 'a.two', 'attempts' => '1', 'last_error' => null, 'failed_at' => null],
        ]);

        $page = new OutboxDeadLetter($connection)->listFailedPage(0, 10);

        self::assertCount(2, $page);
        // the string-to-int coercion is the gateway's job, pdo_pgsql handing back bigint as string
        self::assertSame(7, $page[0]->id);
        self::assertSame(700, $page[0]->position);
        self::assertSame('a.one', $page[0]->type);
        self::assertSame(3, $page[0]->attempts);
        self::assertSame('boom', $page[0]->lastError);
        self::assertSame('2026-08-23T10:00:00Z', $page[0]->failedAt);
        // a row given up on without a recorded cause keeps both nulls, never an empty string
        self::assertSame(9, $page[1]->id);
        self::assertNull($page[1]->lastError);
        self::assertNull($page[1]->failedAt);
    }
}
