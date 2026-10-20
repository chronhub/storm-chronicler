<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Query\QueryBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Query\EventFeedFilter;
use Storm\Stream\StreamName;

/**
 * Unit pin of the windowed feed read.
 *
 * The `QueryProjectionRunner` integration test covers the actual paging over a store. Here:
 *
 * - The window is stamped and honored
 *
 * - The scope derives its clauses from one `StreamName`: the category always, the stream when
 *   qualified or explicitly exact
 *
 * - The type clause is conditional and bound
 *
 * - The order is the catch-up order
 */
final class EventFeedFilterTest extends TestCase
{
    #[Test]
    public function stamps_the_window_the_runner_sets(): void
    {
        $qb = $this->queryBuilder();

        $filter = new EventFeedFilter;
        $filter->window(after: 10, head: 50, limit: 25);
        $filter->apply($qb);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('e.sequence_no > :feedAfter', $sql);
        $this->assertStringContainsString('e.sequence_no <= :feedHead', $sql);
        $this->assertStringContainsStringIgnoringCase('ORDER BY e.sequence_no asc', $sql);
        $this->assertStringContainsString('LIMIT 25', $sql);
        $this->assertSame(10, $qb->getParameter('feedAfter'));
        $this->assertSame(50, $qb->getParameter('feedHead'));
    }

    #[Test]
    public function defaults_to_an_unbounded_window_before_the_runner_stamps_one(): void
    {
        // a one-shot `apply()` without `window()` still reads `(0, PHP_INT_MAX]`, no undefined state.
        $qb = $this->queryBuilder();

        new EventFeedFilter()->apply($qb);

        $this->assertSame(0, $qb->getParameter('feedAfter'));
        $this->assertSame(PHP_INT_MAX, $qb->getParameter('feedHead'));
    }

    #[Test]
    public function scopes_to_a_whole_category_when_the_stream_name_is_bare(): void
    {
        // a bare `StreamName` carries only the category, so prune the partition, no exact-stream narrow.
        $qb = $this->queryBuilder();

        $filter = new EventFeedFilter(scope: new StreamName('account'));
        $filter->window(0, PHP_INT_MAX, 1000);
        $filter->apply($qb);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('e.category = :feedCategory', $sql);
        $this->assertStringNotContainsString('e.stream', $sql);
        $this->assertSame('account', $qb->getParameter('feedCategory'));
    }

    #[Test]
    public function exact_selection_can_narrow_a_bare_name_without_widening_to_its_category(): void
    {
        $qb = $this->queryBuilder();
        new EventFeedFilter(scope: new StreamName('account'), exactStream: true)->apply($qb);

        $this->assertStringContainsString('e.category = :feedCategory', $qb->getSQL());
        $this->assertStringContainsString('e.stream = :feedStream', $qb->getSQL());
        $this->assertSame('account', $qb->getParameter('feedCategory'));
        $this->assertSame('account', $qb->getParameter('feedStream'));
    }

    #[Test]
    public function narrows_to_one_stream_when_the_scope_is_qualified(): void
    {
        // a qualified `StreamName` emits both: the category, for partition prune or index lead, plus
        // the exact stream.
        $qb = $this->queryBuilder();

        $filter = new EventFeedFilter(scope: new StreamName('account')->withQualifier('1'));
        $filter->window(0, PHP_INT_MAX, 1000);
        $filter->apply($qb);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('e.category = :feedCategory', $sql);
        $this->assertStringContainsString('e.stream = :feedStream', $sql);
        $this->assertSame('account', $qb->getParameter('feedCategory'));
        $this->assertSame('account-1', $qb->getParameter('feedStream'));
    }

    #[Test]
    public function narrows_by_event_type_conditional_and_bound(): void
    {
        $qb = $this->queryBuilder();

        $filter = new EventFeedFilter(types: ['bank.deposited', 'bank.withdrawn']);
        $filter->window(0, PHP_INT_MAX, 1000);
        $filter->apply($qb);

        $this->assertStringContainsString('e.type IN (:feedTypes)', $qb->getSQL());
        $this->assertSame(['bank.deposited', 'bank.withdrawn'], $qb->getParameter('feedTypes'));
    }

    #[Test]
    public function omits_all_selection_clauses_when_none_are_set(): void
    {
        $qb = $this->queryBuilder();

        new EventFeedFilter()->apply($qb); // no scope, no types

        $sql = $qb->getSQL();
        $this->assertStringNotContainsString('e.category', $sql);
        $this->assertStringNotContainsString('e.stream', $sql);
        $this->assertStringNotContainsString('e.type', $sql);
    }

    #[Test]
    public function omits_the_type_clause_when_types_is_empty(): void
    {
        // an empty type set means "all types", the same as null, not an unsatisfiable `IN ()`.
        $qb = $this->queryBuilder();

        new EventFeedFilter(types: [])->apply($qb);

        $this->assertStringNotContainsString('e.type', $qb->getSQL());
    }

    private function queryBuilder(): QueryBuilder
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'serverVersion' => '16',
            'host' => '127.0.0.1',
            'dbname' => 'unused',
            'user' => 'unused',
            'password' => 'unused',
        ]);

        return $connection->createQueryBuilder()->select('e.*')->from('event_store', 'e');
    }
}
