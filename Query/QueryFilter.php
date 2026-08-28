<?php

declare(strict_types=1);

namespace Storm\Chronicler\Query;

use Doctrine\DBAL\Query\QueryBuilder;
use Storm\Chronicler\Record\EventRecord;
use Storm\Chronicler\Schema\EventStoreSchema;
use Storm\Chronicler\Store\StreamReader;

/**
 * A read strategy for StreamReader::retrieveByFilter(): it shapes the query that selects stored
 * events.
 *
 * The store owns the SELECT columns and FROM, `event_store` aliased `e`, and the row-to-EventRecord
 * mapping; a filter only narrows and orders the rows. It adds WHERE, ORDER BY, and LIMIT against the
 * `e` alias over any `event_store` column, whose source of truth is Schema\EventStoreSchema, and
 * must order by `e.sequence_no` for catch-up reads, the global append position the projector
 * checkpoints on. Keeping the SELECT shape out of the filter's reach means a filter can never break
 * a projection by selecting the wrong columns.
 *
 * Lives in the package, not Contracts: it names a concrete external type, Doctrine's QueryBuilder,
 * like StreamReader itself.
 *
 * Joins are allowed. A filter MAY add a JOIN. This is deliberate, since filters are trusted
 * framework code, and it is the one place a filter reaches past the single `event_store` table; the
 * module OWNING the joined schema ships such a filter, never this one. Two constraints keep the
 * store contract honest:
 *
 * - Join only bookkeeping owned by a Storm module, never arbitrary domain or read-model tables.
 *
 * - The SELECT stays `e.*`, the store's columns; a join narrows the rows, it never adds columns to
 *   the result.
 *
 * @see EventRecord
 * @see EventStoreSchema
 * @see StreamReader
 */
interface QueryFilter
{
    /**
     * Narrow and order the store's read query for this filter's selection.
     */
    public function apply(QueryBuilder $qb): void;
}
