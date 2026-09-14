<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Store\EventStorePartitioner;

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
    public function it_rejects_a_category_that_is_not_a_valid_stream_category_before_touching_the_db(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('executeStatement');

        $this->expectException(InvalidArgumentException::class);

        // 'Bad-Category' breaks StreamName::CATEGORY_REGEX, uppercase and hyphen, a config error.
        new EventStorePartitioner($connection)->createPartitions(['Bad-Category']);
    }

    #[Test]
    #[Group('adversarial')]
    public function it_rejects_every_metacharacter_class_a_category_could_smuggle_into_the_ddl(): void
    {
        // the module's ONLY string-into-SQL path: the category is interpolated into DDL as both the
        // child identifier and the LIST bound, so each hostile class must die on the allowlist, one
        // named case per class rather than one generic invalid input
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
            } catch (InvalidArgumentException) {
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
        // the right NAME and the wrong bound is attached, is a partition, and accepts none of this
        // category's rows. The class documents the hand recipe that produces it, a detach and
        // reattach where the FOR VALUES clause is typed by an operator mid-incident.
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(false);

        self::assertFalse(new EventStorePartitioner($connection)->verify('account'));
    }
}
