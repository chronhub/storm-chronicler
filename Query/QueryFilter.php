<?php

declare(strict_types=1);

namespace Storm\Chronicler\Query;

use Doctrine\DBAL\Query\QueryBuilder;
use Storm\Chronicler\Record\EventRecord;
use Storm\Chronicler\Schema\EventStoreSchema;
use Storm\Chronicler\Store\StreamReader;

/**
 * A read strategy for `StreamReader::retrieveByFilter()`, shaping the query that selects stored events.
 *
 * The store owns the `SELECT` columns and the `FROM`, `event_store` aliased `e`, and the mapping of a
 * row to an `EventRecord`; a filter only narrows and orders the rows. It adds `WHERE`, `ORDER BY` and
 * `LIMIT` against the `e` alias over any `event_store` column, whose source of truth is
 * `EventStoreSchema`, and must order by `e.sequence_no` for catch-up reads, the global append position
 * the projector checkpoints on. Keeping the `SELECT` shape out of the filter's reach means a filter can
 * never break a projection by selecting the wrong columns.
 *
 * It lives in the package rather than in Contracts because it names a concrete external type,
 * Doctrine's `QueryBuilder`, like `StreamReader` itself.
 *
 * A filter may add a `JOIN`, since filters are trusted framework code: it is the one place a filter
 * reaches past the single `event_store` table, and the module owning the joined schema ships such a
 * filter, never this one. Two constraints keep the store contract honest:
 *
 * - Join only bookkeeping owned by a Storm module, never arbitrary domain or read-model tables.
 *
 * - The `SELECT` stays `e.*`, the store's columns: a join narrows the rows and never adds columns to
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
