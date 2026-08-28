<?php

declare(strict_types=1);

namespace Storm\Chronicler\Query;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Events of one category within a sequence range, oldest first, capped.
 *
 * `WHERE category = :c AND sequence_no > :after AND sequence_no <= :max ORDER BY sequence_no ASC
 * LIMIT :limit`. The half-open lower bound `>` makes it resumable: pass the last position seen as
 * `afterSequence` to read the next slice; `maxSequence` is the inclusive upper watermark of the
 * batch.
 */
final readonly class LoadRange implements QueryFilter
{
    /**
     * @param  positive-int  $limit  a zero would reach the store as `LIMIT 0`, an empty page
     *                               indistinguishable from a drained range
     */
    public function __construct(
        public string $category,
        public int $afterSequence,
        public int $maxSequence,
        public int $limit,
    ) {}

    public function apply(QueryBuilder $qb): void
    {
        $qb->where('e.category = :category')
            ->andWhere('e.sequence_no > :afterSequence')
            ->andWhere('e.sequence_no <= :maxSequence')
            ->orderBy('e.sequence_no', 'ASC')
            ->setMaxResults($this->limit)
            ->setParameter('category', $this->category)
            ->setParameter('afterSequence', $this->afterSequence, ParameterType::INTEGER)
            ->setParameter('maxSequence', $this->maxSequence, ParameterType::INTEGER);
    }
}
