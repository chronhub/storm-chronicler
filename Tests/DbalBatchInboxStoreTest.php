<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests;

use Closure;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\Chronicler\Inbox\DbalBatchInboxStore;
use Storm\Chronicler\Inbox\InboxConsumers;
use Storm\Chronicler\Inbox\InboxItem;
use Storm\Chronicler\Inbox\InboxKey;

use function array_key_exists;
use function array_map;
use function bin2hex;
use function count;
use function in_array;
use function range;
use function sort;
use function sprintf;
use function strcmp;

/**
 * The batch store's contract, unit-pinned with the `Connection` mocked:
 *
 * - One multi-VALUES dedup `INSERT`, whose `RETURNING` set is the fresh ids, then N handlers inside
 *   one `transactional()` call
 *
 * - A duplicate, an id absent from the `RETURNING` set or already consumed within the batch, skips
 *   its handler
 *
 * - A throwing handler propagates so the real transaction rolls the whole batch back; the
 *   integration test covers the rollback itself
 *
 * - An oversized batch chunks into several statements
 *
 * The mock mirrors PostgreSQL's `ON CONFLICT DO NOTHING`: a conflicting row, including an
 * intra-statement duplicate, is simply absent from `RETURNING`.
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

        $ran = new DbalBatchInboxStore($connection, new InboxConsumers($connection))->onceBatch('events', [
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
        $connection = $this->connection(insertedFor: static fn (string $key): bool => $key !== bin2hex(InboxKey::encode('b')));

        $ran = new DbalBatchInboxStore($connection, new InboxConsumers($connection))->onceBatch('events', [
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

        $ran = new DbalBatchInboxStore($connection, new InboxConsumers($connection))->onceBatch('events', [
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

        $this->assertSame([], new DbalBatchInboxStore($connection, new InboxConsumers($connection))->onceBatch('events', []));
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
            new DbalBatchInboxStore($connection, new InboxConsumers($connection))->onceBatch('events', [
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

        new DbalBatchInboxStore($connection, new InboxConsumers($connection))->onceBatch('events', $this->items(256));

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

        $ran = new DbalBatchInboxStore($connection, new InboxConsumers($connection))->onceBatch('events', $this->items(1001));

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
        $connection->method('fetchOne')->willReturn(1);
        $connection->method('fetchFirstColumn')
            ->willReturnCallback(static function (string $sql, array $params) use (&$capturedParams): array {
                $capturedParams = $params;

                return ['msg-a', 'msg-b', 'msg-c'];
            });

        $store = new DbalBatchInboxStore($connection, new InboxConsumers($connection));
        $store->onceBatch('events', [
            new InboxItem('msg-c', static function (): void {}),
            new InboxItem('msg-a', static function (): void {}),
            new InboxItem('msg-b', static function (): void {}),
        ]);

        // the keys travel in hex, sorted as the index sorts them, and the consumer as its registry id
        $keys = array_map(static fn (string $id): string => bin2hex(InboxKey::encode($id)), ['msg-a', 'msg-b', 'msg-c']);
        sort($keys, SORT_STRING);
        $this->assertSame([1, $keys[0], 1, $keys[1], 1, $keys[2]], $capturedParams);
    }

    #[Test]
    public function records_each_skipped_key_once_with_its_occurrences_in_key_order(): void
    {
        // two ids an earlier batch recorded, skipped three times in all: one UPDATE carries each key
        // once with how often it was skipped, sorted on the key whatever order the batch skipped
        // them in, then the consumer. The batch skips the higher key first, so the order is the sort's
        [$low, $high] = strcmp(bin2hex(InboxKey::encode('a')), bin2hex(InboxKey::encode('b'))) < 0 ? ['a', 'b'] : ['b', 'a'];
        $recorded = [bin2hex(InboxKey::encode('a')), bin2hex(InboxKey::encode('b'))];
        $connection = $this->connection(insertedFor: static fn (string $key): bool => ! in_array($key, $recorded, true));
        $updates = [];
        $connection->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $params) use (&$updates): int {
                $updates[] = [$sql, $params];

                return count($params);
            });

        $ran = new DbalBatchInboxStore($connection, new InboxConsumers($connection))->onceBatch('events', [
            new InboxItem($high, static function (): void {}),
            new InboxItem('fresh', static function (): void {}),
            new InboxItem($low, static function (): void {}),
            new InboxItem($high, static function (): void {}),
        ]);

        $this->assertSame([false, true, false, false], $ran);
        $this->assertCount(1, $updates);
        $this->assertStringContainsString("VALUES (decode(?, 'hex'), CAST(? AS bigint)), (decode(?, 'hex'), CAST(? AS bigint)))", $updates[0][0]);
        $this->assertSame([bin2hex(InboxKey::encode($low)), 1, bin2hex(InboxKey::encode($high)), 2, 1], $updates[0][1]);
    }

    #[Test]
    public function a_batch_without_a_skipped_item_records_no_duplicate(): void
    {
        $connection = $this->connection(insertedFor: static fn (string $key): bool => true);
        $connection->method('executeStatement')->willThrowException(new RuntimeException('no duplicate to record'));

        $ran = new DbalBatchInboxStore($connection, new InboxConsumers($connection))->onceBatch('events', $this->items(3));

        $this->assertSame([true, true, true], $ran);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * A `Connection` whose `transactional()` runs the closure inline and whose `fetchFirstColumn()`
     * mirrors the multi-VALUES `ON CONFLICT DO NOTHING RETURNING message_id`.
     *
     * Positional params come as `[consumer, key, consumer, key, …]`, the keys in hex as the store
     * sends them. A key is returned only when `$insertedFor` says fresh and it was not already
     * inserted earlier in the same statement or batch, PostgreSQL's intra-statement `DO NOTHING`.
     *
     * @param  Closure(string): bool  $insertedFor
     * @return Connection&Stub
     */
    private function connection(Closure $insertedFor, int &$statements = 0): Connection
    {
        $recorded = [];

        $connection = $this->createStub(Connection::class);
        $connection->method('transactional')
            ->willReturnCallback(static fn (Closure $work): mixed => $work($connection));
        // the registry's one read: the consumer 'events' is id 1 in every fake
        $connection->method('fetchOne')->willReturn(1);
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
