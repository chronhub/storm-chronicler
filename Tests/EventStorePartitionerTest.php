<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\Chronicler\Store\EventStorePartitioner;
use Storm\Stream\Exception\InvalidStreamException;

use function hash;
use function str_contains;
use function str_repeat;
use function substr;

final class EventStorePartitionerTest extends TestCase
{
    #[Test]
    public function the_physical_name_is_bounded_by_the_server_identifier_length(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(static fn (string $sql) => str_contains($sql, 'max_identifier_length') ? '32' : false);
        $sql = '';
        $connection->expects($this->once())->method('executeStatement')->willReturnCallback(static function (string $statement) use (&$sql): int {
            $sql = $statement;

            return 0;
        });
        new EventStorePartitioner($connection)->createPartitions([str_repeat('a', 80)]);
        self::assertSame(1, preg_match('/CREATE TABLE (?:IF NOT EXISTS )?([a-z0-9_]+)/', $sql, $matches));
        $name = $matches[1] ?? '';
        self::assertNotSame('', $name);
        self::assertLessThanOrEqual(32, strlen($name));
    }

    #[Test]
    public function it_creates_one_idempotent_list_partition_for_a_category_and_nothing_else(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(static fn (string $sql) => str_contains($sql, 'max_identifier_length') ? '63' : false);
        // Only the child table, no per-child index, since the parent's partitioned indexes propagate.
        $connection->expects($this->once())
            ->method('executeStatement')
            ->with("CREATE TABLE event_store_p_account PARTITION OF event_store FOR VALUES IN ('account')")
            ->willReturn(0);

        new EventStorePartitioner($connection)->createPartitions(['account']);
    }

    #[Test]
    public function test_an_invalid_category_raises_the_contracted_stream_exception(): void
    {
        // the same rule as `StreamName`, so the same contracted exception; an SPL
        // `InvalidArgumentException` escapes the package's exception surface for a value every other
        // entry refuses as a stream fault
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('executeStatement');

        $this->expectException(InvalidStreamException::class);

        new EventStorePartitioner($connection)->createPartitions(['Bad-Category']);
    }

    #[Test]
    public function it_rejects_a_category_that_is_not_a_valid_stream_category_before_touching_the_db(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('executeStatement');

        $this->expectException(InvalidStreamException::class);

        // 'Bad-Category' breaks `StreamName::CATEGORY_REGEX`, uppercase and hyphen, a config error.
        new EventStorePartitioner($connection)->createPartitions(['Bad-Category']);
    }

    #[Test]
    #[Group('adversarial')]
    public function it_rejects_every_metacharacter_class_a_category_could_smuggle_into_the_ddl(): void
    {
        // the module's only string-into-SQL path: the category is interpolated into DDL as both the
        // child identifier and the `LIST` bound, so each hostile class must be refused by the
        // allowlist, one named case per class rather than one generic invalid input
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('executeStatement');

        $partitioner = new EventStorePartitioner($connection);

        $hostile = [
            "pay'ment; drop table event_store; --", // single quote plus statement splice
            'pay"ment',                             // double quote, the identifier escape
            'pay ment',                             // whitespace
            'pay-ment',                             // hyphen, breaks the child identifier
            'payment)',                             // paren, closes the FOR VALUES list
            'Payment',                              // uppercase
            '1payment',                             // leading digit
            '_payment',                             // leading underscore
            '',                                     // empty
        ];

        foreach ($hostile as $category) {
            try {
                $partitioner->createPartitions([$category]);
                $this->fail(sprintf('expected the refusal of "%s"', $category));
            } catch (InvalidStreamException) {
                // refused before any statement, the mock above asserts none ever ran
            }
        }
    }

    #[Test]
    public function default_volume_for_counts_the_category_rows_still_in_the_default_partition(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('fetchOne')
            ->with($this->anything(), ['category' => 'account']) // bound param, pinned
            ->willReturn('42'); // count(*) comes back as a numeric string

        self::assertSame(42, new EventStorePartitioner($connection)->defaultVolumeFor('account'));
    }

    #[Test]
    public function verify_is_true_only_when_the_child_is_attached_with_the_matching_list_bound(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('fetchOne')
            // Parent and full bound identify the child independently of its physical name.
            ->with($this->anything(), ['parent' => 'event_store', 'bound' => "FOR VALUES IN ('account')"])
            ->willReturn('event_store_p_account');

        self::assertTrue(new EventStorePartitioner($connection)->verify('account'));
    }

    #[Test]
    public function verify_is_false_when_the_child_is_missing(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(false); // no row, so not a partition

        self::assertFalse(new EventStorePartitioner($connection)->verify('account'));
    }

    #[Test]
    #[Group('adversarial')]
    public function verify_is_false_when_the_child_exists_under_a_bound_that_admits_another_category(): void
    {
        // the arm the name above promises and the presence check alone would miss: a child carrying
        // the right name and the wrong bound is attached, is a partition, and accepts none of this
        // category's rows. The class documents the hand recipe that produces it, a detach and
        // reattach where the `FOR VALUES` clause is typed by an operator mid-incident.
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(false);

        self::assertFalse(new EventStorePartitioner($connection)->verify('account'));
    }

    #[Test]
    public function a_long_category_keeps_a_bounded_prefix_and_a_hash_of_its_whole_name(): void
    {
        // the budget is the limit minus the prefix and the nine characters of `_` and eight hex:
        // what survives of the category and which part of its hash follow from that arithmetic alone
        $category = 'x'.str_repeat('a', 39).'z';

        self::assertSame(
            'event_store_p_xaaaaaaaa_'.substr(hash('sha256', $category), 0, 8),
            $this->partitioner('32')->partitionName($category),
        );
    }

    #[Test]
    public function a_category_whose_prefixed_name_fits_exactly_keeps_it_whole(): void
    {
        $category = str_repeat('b', 18);

        self::assertSame('event_store_p_'.$category, $this->partitioner('32')->partitionName($category));
    }

    #[Test]
    public function the_smallest_budget_still_names_the_partition_and_a_smaller_one_is_refused(): void
    {
        $category = str_repeat('c', 12);

        self::assertSame('event_store_p_c_'.substr(hash('sha256', $category), 0, 8), $this->partitioner('24')->partitionName($category));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('limit 23 is too small');

        $this->partitioner('23')->partitionName($category);
    }

    #[Test]
    public function a_generated_name_another_relation_already_holds_is_refused(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(static fn (string $sql, array $params = []): string|false => match (true) {
            str_contains($sql, 'max_identifier_length') => '63',
            ($params['name'] ?? null) === 'event_store_p_account' => 'r unpartitioned',
            default => false,
        });
        $connection->expects($this->never())->method('executeStatement');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"event_store_p_account" is occupied by r unpartitioned');

        new EventStorePartitioner($connection)->createPartitions(['account']);
    }

    private function partitioner(string $limit): EventStorePartitioner
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(static fn (string $sql): string|false => str_contains($sql, 'max_identifier_length') ? $limit : false);

        return new EventStorePartitioner($connection);
    }
}
