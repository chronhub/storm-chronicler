<?php

declare(strict_types=1);

namespace Storm\Chronicler\Query;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Storm\Stream\StreamCategory;

/**
 * One aggregate stream's events after a version, the snapshot read path.
 *
 * `WHERE category = :category AND stream = :stream AND version > :afterVersion ORDER BY version ASC`.
 *
 * - The half-open lower bound `>` selects only the events recorded after the snapshot.
 *
 * - Ordering by `version`, per stream and contiguous, is the replay order and uses the
 *   `UNIQUE (category, stream, version)` index, so there is no separate sort.
 *
 * - `category`, equal to `split_part(stream, '-', 1)`, lets the index be used from its leading column.
 */
final readonly class AggregateStreamSince implements QueryFilter
{
    public function __construct(
        public StreamCategory $category,
        public string $stream,
        public int $afterVersion,
    ) {}

    public function apply(QueryBuilder $qb): void
    {
        $qb->where('e.category = :category')
            ->andWhere('e.stream = :stream')
            ->andWhere('e.version > :afterVersion')
            ->orderBy('e.version', 'ASC')
            ->setParameter('category', $this->category->value)
            ->setParameter('stream', $this->stream)
            ->setParameter('afterVersion', $this->afterVersion, ParameterType::INTEGER);
    }
}
