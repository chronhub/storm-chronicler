<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests;

use Closure;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\Chronicler\Inbox\DbalBatchInboxStore;
use Storm\Chronicler\Inbox\InboxItem;

use function array_key_exists;
use function count;
use function range;
use function sprintf;

/**
 * The batch store's contract, unit-pinned with the Connection mocked: ONE multi-VALUES dedup INSERT,
 * whose RETURNING set is the fresh ids, then N handlers inside ONE transactional() call; a duplicate,
 * an id absent from the RETURNING set or already consumed within the batch, skips its handler; a
 * throwing handler propagates so the real transaction rolls the whole batch back, though the rollback
 * itself rides the integration test; and an oversized batch chunks into several statements. The mock
 * mirrors PostgreSQL's ON CONFLICT DO NOTHING: a conflicting row, including an intra-statement
 * duplicate, is simply absent from RETURNING.
 */
final class DbalBatchInboxStoreTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Fresh / duplicate gating
    // -------------------------------------------------------------------------

    #[Test]
    public function runs_every_fresh_handler_in_order_inside_one_transaction(): void
    {
        $order = [];
        $connection = $this->connection(insertedFor: static fn (string $id): bool => true);

        $ran = new DbalBatchInboxStore($connection)->onceBatch('events', [
            new InboxItem('a', function () use (&$order): void {
                $order[] = 'a';
            }),
            new InboxItem('b', function () use (&$order): void {
                $order[] = 'b';
            }),
            new InboxItem('c', function () use (&$order): void {
                $order[] = 'c';
            }),
        ]);

        $this->assertSame([true, true, true], $ran);
        $this->assertSame(['a', 'b', 'c'], $order); // ran, and in delivery order, never RETURNING order
    }

    #[Test]
    public function skips_a_duplicate_without_running_its_handler(): void
    {
        $order = [];
        // 'b' is already recorded, so absent from the RETURNING set.
        $connection = $this->connection(insertedFor: static fn (string $id): bool => $id !== 'b');

        $ran = new DbalBatchInboxStore($connection)->onceBatch('events', [
            new InboxItem('a', function () use (&$order): void {
                $order[] = 'a';
            }),
            new InboxItem('b', function () use (&$order): void {
                $order[] = 'b';
            }),
            new InboxItem('c', function () use (&$order): void {
                $order[] = 'c';
            }),
        ]);

        $this->assertSame([true, false, true], $ran);
        $this->assertSame(['a', 'c'], $order); // the duplicate's handler never ran
    }

    #[Test]
    public function an_intra_batch_duplicate_runs_its_first_occurrence_only(): void
    {
        $runs = 0;
        $connection = $this->connection(insertedFor: static fn (string $id): bool => true);

        $ran = new DbalBatchInboxStore($connection)->onceBatch('events', [
            new InboxItem('x', function () use (&$runs): void {
                $runs++;
            }),
            new InboxItem('y', static function (): void {}),
            new InboxItem('x', function () use (&$runs): void {
                $runs++;
            }),
        ]);

        $this->assertSame([true, true, false], $ran); // the second 'x' is a duplicate of the first
        $this->assertSame(1, $runs);                  // its handler ran exactly once
    }

    #[Test]
    public function an_empty_batch_is_a_no_op(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('transactional');

        $this->assertSame([], new DbalBatchInboxStore($connection)->onceBatch('events', []));
    }

    // -------------------------------------------------------------------------
    // Poison propagation
    // -------------------------------------------------------------------------

    #[Test]
    public function a_throwing_handler_propagates_and_stops_the_batch(): void
    {
        $order = [];
        $connection = $this->connection(insertedFor: static fn (string $id): bool => true);

        try {
            new DbalBatchInboxStore($connection)->onceBatch('events', [
                new InboxItem('a', function () use (&$order): void {
                    $order[] = 'a';
                }),
                new InboxItem('b', static function (): void {
                    throw new RuntimeException('poison');
                }),
                new InboxItem('c', function () use (&$order): void {
                    $order[] = 'c';
                }),
            ]);
            $this->fail('a poison handler must propagate so the transaction rolls the batch back');
        } catch (RuntimeException $e) {
            $this->assertSame('poison', $e->getMessage());
            $this->assertSame(['a'], $order); // 'c' never ran; the batch stopped at the poison
        }
    }

    // -------------------------------------------------------------------------
    // Wire shape: one statement per chunk
    // -------------------------------------------------------------------------

    #[Test]
    public function a_batch_within_the_chunk_size_is_one_dedup_statement(): void
    {
        $statements = 0;
        $connection = $this->connection(
            insertedFor: static fn (string $id): bool => true,
            statements: $statements,
        );

        new DbalBatchInboxStore($connection)->onceBatch('events', $this->items(256));

        $this->assertSame(1, $statements);
    }

    #[Test]
    public function a_batch_beyond_the_chunk_size_splits_into_several_statements(): void
    {
        $statements = 0;
        $connection = $this->connection(
            insertedFor: static fn (string $id): bool => true,
            statements: $statements,
        );

        $ran = new DbalBatchInboxStore($connection)->onceBatch('events', $this->items(1001));

        $this->assertSame(2, $statements);       // 1000 + 1
        $this->assertCount(1001, $ran);          // every item still answered
        $this->assertNotContains(false, $ran);   // all fresh
    }

    #[Test]
    public function sorts_items_by_message_id_to_enforce_deterministic_insert_order(): void
    {
        $capturedParams = [];
        $connection = $this->createStub(Connection::class);
        $connection->method('transactional')
            ->willReturnCallback(static fn (Closure $work): mixed => $work($connection));
        $connection->method('fetchFirstColumn')
            ->willReturnCallback(static function (string $sql, array $params) use (&$capturedParams): array {
                $capturedParams = $params;

                return ['msg-a', 'msg-b', 'msg-c'];
            });

        $store = new DbalBatchInboxStore($connection);
        $store->onceBatch('events', [
            new InboxItem('msg-c', static function (): void {}),
            new InboxItem('msg-a', static function (): void {}),
            new InboxItem('msg-b', static function (): void {}),
        ]);

        $this->assertSame(['events', 'msg-a', 'events', 'msg-b', 'events', 'msg-c'], $capturedParams);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * A Connection whose transactional() runs the closure inline and whose fetchFirstColumn mirrors the
     * multi-VALUES `ON CONFLICT DO NOTHING RETURNING message_id`: positional params come as
     * [consumer, id, consumer, id, …]; an id is returned iff $insertedFor says fresh AND it was not already
     * inserted earlier in the same statement or batch, PostgreSQL's intra-statement DO NOTHING.
     *
     * @param  Closure(string): bool  $insertedFor
     */
    private function connection(Closure $insertedFor, int &$statements = 0): Connection
    {
        $recorded = [];

        $connection = $this->createStub(Connection::class);
        $connection->method('transactional')
            ->willReturnCallback(static fn (Closure $work): mixed => $work($connection));
        $connection->method('fetchFirstColumn')
            ->willReturnCallback(static function (string $sql, array $params) use ($insertedFor, &$recorded, &$statements): array {
                $statements++;
                $fresh = [];

                for ($i = 1; $i < count($params); $i += 2) {
                    $id = $params[$i];

                    if (array_key_exists($id, $recorded) || ! $insertedFor($id)) {
                        continue;
                    }

                    $recorded[$id] = true;
                    $fresh[] = $id;
                }

                return $fresh;
            });

        return $connection;
    }

    /**
     * @return list<InboxItem>
     */
    private function items(int $count): array
    {
        $items = [];

        foreach (range(1, $count) as $i) {
            $items[] = new InboxItem(sprintf('m-%04d', $i), static function (): void {});
        }

        return $items;
    }
}
