<?php

declare(strict_types=1);

namespace Storm\Chronicler\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Storm\Stream\StreamName;

/**
 * The query lane's windowed feed read: events under one stream scope, one event-type set, or both.
 *
 * It is the checkpoint-less, mutable counterpart of `ProjectionFilter`: the same lean `sequence_no` and
 * `category` shape, plus an optional exact `stream` narrow and `type` set. The runner stamps the
 * position window and page size: {@see \Storm\Projector\Query\QueryProjectionRunner::stream()} pages
 * it, `window()` advancing `(after, head]` and capping the read, then `apply()` emitting it.
 *
 * `WHERE sequence_no > :after AND sequence_no <= :head [AND category = :cat] [AND stream = :stream]
 * [AND type IN (…)] ORDER BY sequence_no ASC LIMIT :limit`.
 *
 * The scope is a single `StreamName` rather than a separate category and stream: `category` is
 * `split_part(stream, '-', 1)`, enforced by a database `CHECK`, so the two can never legitimately
 * diverge, and the value object makes that unrepresentable.
 *
 * - A bare `StreamName('account')` scopes to the whole category.
 * - A qualified `StreamName('account')->withQualifier($id)` scopes to one aggregate.
 *
 * - `exactStream` narrows a bare name to that exact stream, without changing the default category
 *   selection.
 *
 * A qualified scope still emits both predicates: `category` is the partition-prune key and the leading
 * column of the `UNIQUE (category, stream, version)` index, redundant in data but needed by the plan.
 *
 * The filter carries no JSON `header` or `content` predicate. An arbitrary predicate belongs to a fetch
 * that renders, such as an `InspectFilter` from the optional LiveQuery package, which no runner folds;
 * a fold over another selection takes a caller-built `MutableQueryFilter` that honors the stamped
 * window. This filter stays free of LiveQuery, in the Chronicler layer only.
 */
final class EventFeedFilter implements MutableQueryFilter
{
    private int $after = 0;

    private int $head = PHP_INT_MAX;

    private int $limit = ProjectionFilter::DEFAULT_LIMIT;

    /**
     * @param  StreamName|null  $scope  null selects every stream; a bare category selects the whole
     *                                  category unless `exactStream` is true; a qualified name selects
     *                                  one exact stream, a category prune plus a stream narrow
     * @param  list<string>|null  $types  stored type strings, alias-resolved by the caller; null or
     *                                    `[]` selects all types with no `type` clause
     */
    public function __construct(
        private readonly ?StreamName $scope = null,
        private readonly ?array $types = null,
        private readonly bool $exactStream = false,
    ) {}

    public function window(int $after, int $head, int $limit): void
    {
        $this->after = $after;
        $this->head = $head;
        $this->limit = $limit;
    }

    public function apply(QueryBuilder $qb): void
    {
        $qb->where('e.sequence_no > :feedAfter')
            ->andWhere('e.sequence_no <= :feedHead')
            ->orderBy('e.sequence_no', 'ASC')
            ->setMaxResults($this->limit)
            ->setParameter('feedAfter', $this->after, ParameterType::INTEGER)
            ->setParameter('feedHead', $this->head, ParameterType::INTEGER);

        if ($this->scope !== null) {
            $qb->andWhere('e.category = :feedCategory')->setParameter('feedCategory', $this->scope->category->value);

            if ($this->exactStream || $this->scope->qualifier !== null) {
                $qb->andWhere('e.stream = :feedStream')->setParameter('feedStream', $this->scope->toString());
            }
        }

        if ($this->types !== null && $this->types !== []) {
            $qb->andWhere('e.type IN (:feedTypes)')
                ->setParameter('feedTypes', $this->types, ArrayParameterType::STRING);
        }
    }
}
