<?php

declare(strict_types=1);

namespace Storm\Chronicler\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use InvalidArgumentException;
use Override;
use Storm\Chronicler\Schema\EventStoreSchema;
use Storm\Message\Header;

use function count;
use function implode;

/**
 * Every stored event carrying one of the given correlation ids, in `sequence_no` order.
 *
 * A NAMED question, not an escape hatch, and the distinction is the whole reason this lives in
 * Chronicler. `EventFeedFilter` refuses an arbitrary JSON predicate on purpose and hands that role
 * to a caller-owned filter; this one carries a single fixed clause on a promoted trace header the
 * store INDEXES itself, so the predicate finally sits in the layer that already committed to
 * serving it. Nothing here is caller-shaped: no column choice, no operator, no path.
 *
 * The rendered form is load-bearing and is not free to change. `EventStoreSchema` ships
 * `event_store_correlation_idx` on `header #>> '{__correlation_id}'`, and only two spellings resolve
 * to it, the literal path form and the `#>> string_to_array(:p, ',')` bound form used below. The
 * `->>` key form returns identical text, matches the index NOTHING, and scans the whole store
 * while every result still looks right; a comparison against a column pinned `COLLATE "C"` misses
 * it the same way.
 *
 * The set form is the contract, not a convenience: a lineage is resolved where it is known and
 * passed in whole. This filter traces the ids it is GIVEN and claims nothing beyond them, so it
 * never means "the whole tree" in one application and "one instance" in another.
 *
 * @see EventStoreSchema the index this spelling must keep matching
 */
final readonly class CorrelationFeedFilter implements QueryFilter
{
    /**
     * @param  list<string>  $correlationIds  matched by equality against the bound set; the filter
     *                                        validates its own emptiness rather than trusting a caller
     *                                        the analyser cannot see
     * @param  positive-int  $limit
     *
     * @throws InvalidArgumentException when the id set is empty; an empty set renders a predicate
     *                                  matching nothing while reading as a filter that was merely
     *                                  not narrow, and no caller means that
     */
    public function __construct(
        private array $correlationIds,
        public int $limit,
    ) {
        if (count($correlationIds) === 0) {
            throw new InvalidArgumentException('A correlation set cannot be empty: an empty set matches nothing, which no caller means.');
        }
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function apply(QueryBuilder $qb): void
    {
        $qb->andWhere("e.header #>> string_to_array(:correlationPath, ',') IN (:correlationIds)")
            ->setParameter('correlationPath', implode(',', [Header::CorrelationId->value]))
            ->setParameter('correlationIds', $this->correlationIds, ArrayParameterType::STRING)
            ->orderBy('e.sequence_no', 'ASC')
            ->setMaxResults($this->limit);
    }
}
