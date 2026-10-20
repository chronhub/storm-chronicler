<?php

declare(strict_types=1);

namespace Storm\Chronicler\Store;

/**
 * The single home of the multi-`VALUES` chunk size of the hot write paths.
 *
 * Every batched `INSERT` into `event_store`, `es_outbox` and `es_inbox` splits its rows into statements
 * of at most this many tuples, inside the same transaction.
 *
 * The value guards a driver limit rather than tuning throughput: pdo_pgsql caps a statement at 65 535
 * bound parameters, so the widest row, `event_store` at 7 parameters, allows about 9 300 tuples, and
 * 1 000 keeps a ninefold margin while already amortizing 99.9 % of the per-row round trips. No real
 * caller comes near it, since appends carry 1 to 8 events and inbox batches are bounded by the
 * prefetch; only a giant burst of facts ever chunks.
 *
 * It stays a constant rather than configuration: if a parse-time cost shows on huge bursts, lowering it
 * is a one-line change with no semantics attached.
 */
final readonly class InsertChunk
{
    public const int ROWS = 1000;

    // nothing in src/ or tests/ instantiates the class, which only holds a constant read as
    // `InsertChunk::ROWS`: this constructor blocks instantiation and is unreachable except through
    // reflection, which would assert nothing about behavior
    // @codeCoverageIgnoreStart
    private function __construct() {}

    // @codeCoverageIgnoreEnd
}
