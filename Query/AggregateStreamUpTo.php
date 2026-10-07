<?php

declare(strict_types=1);

namespace Storm\Chronicler\Query;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Storm\Stream\StreamCategory;

/**
 * One aggregate stream's events from its first version up to an inclusive target, the historical
 * inspection read path.
 *
 * `WHERE category = :category AND stream = :stream AND version <= :upToVersion ORDER BY version ASC
 * LIMIT :upToVersion`. Versions within a stream are unique and contiguous from 1, so the target is
 * also the row count and the LIMIT bounds the result set in SQL, not in PHP: an event appended past
 * the target after the caller observed the head can never enter the read. Ordering by `version`
 * rides the `UNIQUE (category, stream, version)` index, and `category` keeps its leading column and
 * partition pruning, as in `AggregateStreamSince`.
 */
final readonly class AggregateStreamUpTo implements QueryFilter
{
    /**
     * @param  positive-int  $upToVersion  a zero would reach the store as `LIMIT 0`, an empty read
     *                                     indistinguishable from an absent stream
     */
    public function __construct(
        public StreamCategory $category,
        public string $stream,
        public int $upToVersion,
    ) {}

    public function apply(QueryBuilder $qb): void
    {
        $qb->where('e.category = :category')
            ->andWhere('e.stream = :stream')
            ->andWhere('e.version <= :upToVersion')
            ->orderBy('e.version', 'ASC')
            ->setMaxResults($this->upToVersion)
            ->setParameter('category', $this->category->value)
            ->setParameter('stream', $this->stream)
            ->setParameter('upToVersion', $this->upToVersion, ParameterType::INTEGER);
    }
}
