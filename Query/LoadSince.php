<?php

declare(strict_types=1);

namespace Storm\Chronicler\Query;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Every event after a global position, across all categories, oldest first, capped.
 *
 * `WHERE sequence_no > :after ORDER BY sequence_no ASC LIMIT :limit`. The all-streams catch-up read:
 * pass the last position seen as `afterSequence` to drain the next slice.
 */
final readonly class LoadSince implements QueryFilter
{
    /**
     * @param  positive-int  $limit  a zero would reach the store as `LIMIT 0`, an empty page
     *                               indistinguishable from a drained store
     */
    public function __construct(
        public int $afterSequence,
        public int $limit,
    ) {}

    public function apply(QueryBuilder $qb): void
    {
        $qb->where('e.sequence_no > :afterSequence')
            ->orderBy('e.sequence_no', 'ASC')
            ->setMaxResults($this->limit)
            ->setParameter('afterSequence', $this->afterSequence, ParameterType::INTEGER);
    }
}
