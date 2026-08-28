<?php

declare(strict_types=1);

namespace Storm\Chronicler\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Storm\Stream\StreamName;

/**
 * The query lane's windowed feed read: events under one stream scope and/or event-type set, with the
 * position window and page size stamped by the runner. The checkpoint-less, mutable pendant of
 * {@see ProjectionFilter}, the same lean `sequence_no` / `category` shape, plus an optional exact
 * `stream` narrow and `type` set, but {@see \Storm\Projector\Query\QueryProjectionRunner::stream()} pages
 * it via `window()`: `window()` advances `(after, head]` and caps the read, `apply()` emits it.
 *
 * `WHERE sequence_no > :after AND sequence_no <= :head [AND category = :cat] [AND stream = :stream]
 * [AND type IN (…)] ORDER BY sequence_no ASC LIMIT :limit`.
 *
 * The scope is a single {@see \Storm\Stream\StreamName}, not a separate category and stream: `category` is
 * `split_part(stream, '-', 1)`, enforced by a DB CHECK, so the two can never legitimately diverge;
 * the VO makes that unrepresentable. A bare `StreamName('account')` scopes to the whole category; a
 * qualified `StreamName('account')->withQualifier($id)` scopes to one aggregate. Both predicates are
 * still emitted when qualified: `category` is the partition-prune key and the leading column of the
 * `UNIQUE (category, stream, version)` index, redundant in data but load-bearing in the plan.
 *
 * Deliberately no JSON `header` or `content` predicate: an arbitrary predicate is the escape hatch, a
 * caller-owned {@see QueryFilter} via the runner's one-shot `run()`, for example an `InspectFilter`
 * from the optional LiveQuery package. This filter stays LiveQuery-free, Chronicler layer only.
 */
final class EventFeedFilter implements MutableQueryFilter
{
    private int $after = 0;

    private int $head = PHP_INT_MAX;

    private int $limit = ProjectionFilter::DEFAULT_LIMIT;

    /**
     * @param  StreamName|null  $scope  null selects every stream; a bare category selects the whole
     *                                  category; a qualified name selects one exact stream, a category
     *                                  prune plus a stream narrow
     * @param  list<string>|null  $types  stored type strings, alias-resolved by the caller; null or
     *                                    `[]` selects all types with no `type` clause
     */
    public function __construct(
        private readonly ?StreamName $scope = null,
        private readonly ?array $types = null,
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
            $qb->andWhere('e.category = :feedCategory')->setParameter('feedCategory', $this->scope->category);

            if ($this->scope->qualifier !== null) {
                $qb->andWhere('e.stream = :feedStream')->setParameter('feedStream', $this->scope->toString());
            }
        }

        if ($this->types !== null && $this->types !== []) {
            $qb->andWhere('e.type IN (:feedTypes)')
                ->setParameter('feedTypes', $this->types, ArrayParameterType::STRING);
        }
    }
}
