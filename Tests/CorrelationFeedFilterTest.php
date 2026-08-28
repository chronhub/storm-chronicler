<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Query\QueryBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Query\CorrelationFeedFilter;

final class CorrelationFeedFilterTest extends TestCase
{
    #[Test]
    public function the_predicate_is_spelled_the_way_the_shipped_index_is_built(): void
    {
        // the one assertion this filter exists for. `event_store_correlation_idx` is built on
        // `header #>> '{__correlation_id}'`, and only the path form and this bound
        // `string_to_array` form resolve to it; the `->>` key form returns the same text, matches
        // nothing, and scans the whole store while every row still looks right.
        $sql = $this->render(new CorrelationFeedFilter(['corr-9'], 1000));

        self::assertStringContainsString("#>> string_to_array(:correlationPath, ',')", $sql);
        self::assertStringNotContainsString('->>', $sql);
    }

    #[Test]
    public function the_bound_path_is_the_promoted_header_wire_key(): void
    {
        $qb = $this->builder();
        new CorrelationFeedFilter(['corr-9'], 1000)->apply($qb);

        self::assertSame('__correlation_id', $qb->getParameter('correlationPath'));
    }

    #[Test]
    public function one_id_and_several_render_the_same_set_predicate(): void
    {
        // a set of one is still a set: a second spelling for the common case would be a second
        // thing to keep matching the index.
        $one = $this->render(new CorrelationFeedFilter(['corr-9'], 1000));
        $many = $this->render(new CorrelationFeedFilter(['corr-9', 'corr-9\x1fkyc'], 1000));

        self::assertSame($one, $many);
    }

    #[Test]
    public function the_ids_reach_the_query_whole_and_in_order(): void
    {
        $qb = $this->builder();
        new CorrelationFeedFilter(['corr-9', 'corr-9\x1fkyc', 'corr-9\x1fdocs'], 1000)->apply($qb);

        self::assertSame(['corr-9', 'corr-9\x1fkyc', 'corr-9\x1fdocs'], $qb->getParameter('correlationIds'));
    }

    #[Test]
    public function the_read_is_ordered_and_capped_for_a_catch_up_reader(): void
    {
        $qb = $this->builder();
        new CorrelationFeedFilter(['corr-9'], 250)->apply($qb);

        self::assertStringContainsString('ORDER BY e.sequence_no ASC', $this->render(new CorrelationFeedFilter(['corr-9'], 250)));
        self::assertSame(250, $qb->getMaxResults());
    }

    #[Test]
    #[Group('adversarial')]
    public function an_empty_set_is_refused_rather_than_matching_nothing(): void
    {
        // an empty set renders a predicate that matches nothing while reading as a filter that was
        // merely not narrow: the caller would get an empty trace and no reason for it.
        $this->expectException(InvalidArgumentException::class);

        new CorrelationFeedFilter([], 1000);
    }

    private function render(CorrelationFeedFilter $filter): string
    {
        $qb = $this->builder();
        $filter->apply($qb);

        return $qb->getSQL();
    }

    private function builder(): QueryBuilder
    {
        // an unconnected pgsql connection: the filter is judged on the SQL it renders, never on a read
        return DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'serverVersion' => '16',
            'host' => '127.0.0.1',
            'dbname' => 'unused',
            'user' => 'unused',
            'password' => 'unused',
        ])->createQueryBuilder()->select('e.*')->from('event_store', 'e');
    }
}
