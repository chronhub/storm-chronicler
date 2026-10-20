<?php

declare(strict_types=1);

namespace Storm\Chronicler\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Storm\Chronicler\Store\StreamReader;

/**
 * The projector's per-cycle read: events after the checkpoint up to the safe head, oldest first, capped.
 *
 * `WHERE sequence_no > :after AND sequence_no <= :max [AND category IN (…)] [AND type IN (…)]
 * ORDER BY sequence_no ASC LIMIT :limit`.
 *
 * - `afterPosition` is the last applied checkpoint; the half-open `>` makes the read resumable.
 *
 * - `maxPosition` is `StreamReader::safeHeadPosition()`, so a read never passes an in-flight position;
 *   a filtered projection has no gap net, so the watermark is mandatory.
 *
 * - `limit` is the read cap: the persistent runner passes its batch size, and ad-hoc callers get
 *   `DEFAULT_LIMIT`. An ad-hoc read over this filter returns at most `limit` rows, so set it to cover
 *   the scope, or a large window would silently truncate.
 *
 * - `categories` and `types` default to `[]`, meaning all with no clause, and optionally narrow the
 *   read; a non-empty list expands to an `IN (…)`.
 *
 * A richer ad-hoc selection is a different `QueryFilter` entirely:
 *
 * - A `recorded_at` window
 * - A JSON `header` predicate on a correlation id
 * - A raw SQL fragment
 *
 * Such a filter, an `InspectFilter` for example, is a fetch that renders, and no runner folds it; a
 * `QueryProjection` folds a `MutableQueryFilter` the query runner windows. This filter stays the lean
 * checkpoint, category and type read.
 *
 * Every persistent projection runtime, read model and link, reads through it in filtered mode; the
 * safe watermark and the atomic batch keep link projections dense without an anti-join.
 *
 * @see StreamReader::safeHeadPosition()
 */
final readonly class ProjectionFilter implements QueryFilter
{
    public const int DEFAULT_LIMIT = 1000;

    /**
     * @param  positive-int  $limit  a zero would reach the store as `LIMIT 0`, an empty page
     *                               indistinguishable from a drained window
     * @param  list<string>  $categories
     * @param  list<string>  $types
     */
    public function __construct(
        public int $afterPosition,
        public int $maxPosition,
        public int $limit = self::DEFAULT_LIMIT,
        public array $categories = [],
        public array $types = [],
    ) {}

    public function apply(QueryBuilder $qb): void
    {
        $qb->where('e.sequence_no > :afterPosition')
            ->andWhere('e.sequence_no <= :maxPosition')
            ->orderBy('e.sequence_no', 'ASC')
            ->setMaxResults($this->limit)
            ->setParameter('afterPosition', $this->afterPosition, ParameterType::INTEGER)
            ->setParameter('maxPosition', $this->maxPosition, ParameterType::INTEGER);

        if ($this->categories !== []) {
            $qb->andWhere('e.category IN (:categories)')
                ->setParameter('categories', $this->categories, ArrayParameterType::STRING);
        }

        if ($this->types !== []) {
            $qb->andWhere('e.type IN (:types)')
                ->setParameter('types', $this->types, ArrayParameterType::STRING);
        }
    }
}
