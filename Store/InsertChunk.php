<?php

declare(strict_types=1);

namespace Storm\Chronicler\Store;

/**
 * The single home of the multi-VALUES chunk size: every batched INSERT on the hot write paths,
 * `event_store`, `es_outbox`, and `es_inbox`, splits its rows into statements of at most this many
 * tuples, inside the SAME transaction.
 *
 * The value is a safety rail, not a tuning knob: pdo_pgsql caps a statement at 65 535 bound
 * parameters, so the widest row, `event_store` at 7 parameters, allows ~9 300 tuples; 1 000 keeps a
 * ×9 margin while already amortizing 99.9 % of the per-row round-trips. No real caller comes near it,
 * since appends carry 1-8 events and inbox batches are prefetch-bounded; only a giant fact-burst ever
 * chunks. Deliberately NOT configuration; if a parse-time cost ever shows on huge bursts, lowering
 * this constant is a one-line change with zero semantics attached.
 */
final readonly class InsertChunk
{
    public const int ROWS = 1000;

    // No `new InsertChunk(...)` call site exists anywhere in src/ or tests/: the class is a static-const
    // holder, read only as InsertChunk::ROWS. This constructor exists solely to block instantiation, so
    // it is unreachable except through Reflection, which would assert nothing about behavior.
    // @codeCoverageIgnoreStart
    private function __construct() {}

    // @codeCoverageIgnoreEnd
}
