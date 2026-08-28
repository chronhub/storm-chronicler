<?php

declare(strict_types=1);

namespace Storm\Chronicler\Query;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * One aggregate stream's events after a version, the snapshot read path.
 *
 * `WHERE category = :category AND stream = :stream AND version > :afterVersion ORDER BY version ASC`.
 * The half-open lower bound `>` selects only the events recorded after the snapshot; ordering by
 * `version`, per-stream and contiguous, is the replay order and rides the `UNIQUE (category, stream,
 * version)` index, so there is no separate sort. `category` is included so the index is used from its
 * leading column; it equals `split_part(stream, '-', 1)`.
 */
final readonly class AggregateStreamSince implements QueryFilter
{
    public function __construct(
        public string $category,
        public string $stream,
        public int $afterVersion,
    ) {}

    public function apply(QueryBuilder $qb): void
    {
        $qb->where('e.category = :category')
            ->andWhere('e.stream = :stream')
            ->andWhere('e.version > :afterVersion')
            ->orderBy('e.version', 'ASC')
            ->setParameter('category', $this->category)
            ->setParameter('stream', $this->stream)
            ->setParameter('afterVersion', $this->afterVersion, ParameterType::INTEGER);
    }
}
