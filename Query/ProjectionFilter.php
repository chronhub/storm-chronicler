<?php

declare(strict_types=1);

namespace Storm\Chronicler\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Storm\Chronicler\Store\StreamReader;

/**
 * The projector's per-cycle filtered read: events strictly after the checkpoint, up to the safe
 * head, optionally narrowed to the categories and types, oldest first, capped.
 *
 * `WHERE sequence_no > :after AND sequence_no <= :max [AND category IN (…)] [AND type IN (…)]
 * ORDER BY sequence_no ASC LIMIT :limit`.
 *
 * - `afterPosition` is the last applied checkpoint; the half-open `>` makes it resumable.
 *
 * - `maxPosition` is StreamReader::safeHeadPosition(), so a read never passes an in-flight position;
 *   a filtered projection has no gap net, so the watermark is mandatory.
 *
 * - `limit` is the read cap; the persistent runner passes its batch size, and it defaults to
 *   DEFAULT_LIMIT for ad-hoc callers. A one-shot fold over this filter reads at most `limit` rows, so
 *   set it to cover the scope, or a large window would silently truncate.
 *
 * - `categories` and `types` default to `[]`, meaning all with no clause; a non-empty list expands to
 *   an `IN (…)`.
 *
 * The simple, common read shape. For richer ad-hoc selection, such as a `recorded_at` window, a json
 * `header` predicate on a correlation id, or a raw SQL fragment, a one-shot QueryProjection uses a
 * different QueryFilter entirely, for example an InspectFilter, and folds it. This filter stays the
 * lean checkpoint, category, and type read.
 *
 * Used by every persistent projection runtime, ReadModel and Link, all reading in filtered mode; the
 * safe watermark and atomic batch keep link projections dense without an anti-join.
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
