<?php

declare(strict_types=1);

namespace Storm\Chronicler\Schema;

use Storm\Chronicler\Record\SequencePosition;
use Storm\Chronicler\SafeHead\SafeHeadAdvancer;
use Storm\Chronicler\Store\StreamReader;

/**
 * Raw PostgreSQL DDL for the event store: append, load, and OCC.
 *
 * Shipped with the package, so a standalone installation carries its own schema. Ledger discovers it
 * and runs the statements one by one through DBAL. There is deliberately no doctrine/migrations diff:
 * LIST partitioning, the CHECK constraints and the identity sequence cannot be expressed that way.
 *
 * `event_store` is partitioned by LIST on `category`. An identity column on a partitioned table puts
 * the schema's floor at PostgreSQL 17. Its columns, and what each one is load-bearing for:
 *
 * - `sequence_no`, the global monotone identity and the SequencePosition. Aborts burn positions, and
 *   the safe head classifies the gaps they leave. It is `GENERATED ALWAYS` because that is the only
 *   global uniqueness guard available here: a partitioned UNIQUE must include the partition key, so
 *   nothing else stops an explicit INSERT minting a duplicate position that checkpoints and
 *   derived-stream links key on. A restore or an import states its intent with `OVERRIDING SYSTEM
 *   VALUE`, and the partition-split recipe is unaffected, staging rows into a standalone LIKE table
 *   where the identity is not copied, then ATTACHing.
 *
 * - `version`, the per-stream 1..N aggregate version and the OCC target. Bigint, aligned with
 *   `stream_heads.last_version`, because the fact path bumps unbounded. `UNIQUE (category, stream,
 *   version)` is the per-event OCC guardrail and doubles as the load-by-stream index.
 *
 * - `recorded_at`, DB-generated with `clock_timestamp()`, the real insert time. Monotone with
 *   `sequence_no` because a single INSERT stamps both, which is what lets SafeHeadAdvancer classify a
 *   gap without a tombstone: the present row just above a gap was inserted after the missing position,
 *   so its `recorded_at` bounds the gap's age.
 *
 * - `header`, which also carries the actor metadata; there are no dedicated columns for it.
 *
 * - `xact_id`, from `pg_current_xact_id()`, forensic data and nothing else. Read the trap below
 *   before drawing any conclusion from it.
 *
 * `event_store_default` is the DEFAULT partition, so inserts work for any category out of the box.
 * Per-category partitions are best provisioned EARLY, while the DEFAULT is still small: the first
 * split scans and migrates the matching rows out of it under an ACCESS EXCLUSIVE lock that blocks
 * appends, and that cost grows with accumulated volume. Provision a hot category's partition early, or
 * decide partitioning is not needed and drop the LIST declaration. Waiting for the volume that makes
 * the migration painful is the one path to avoid. The DEFAULT stays the catch-all.
 *
 * `event_store_correlation_idx` is an expression btree on `header #>> '{__correlation_id}'`, the
 * promoted trace header Header::CorrelationId. It ships by default: without it the bare header lookup
 * degrades to a full-store sweep per query, with it the same lookup is a point read on a handful of
 * buffers. The write side stays marginal because storm's v7 correlation ids are time-ordered, so
 * inserts hit the rightmost leaf without splitting. Retrofitting an EXISTING populated store takes
 * `CREATE INDEX` and then `ANALYZE`; an expression index stays planner-invisible until its expression
 * statistics exist, which is the trap of that operation.
 *
 * THE `xact_id` TRAP. The watermark never reads the column; it scans the `sequence_no` gap directly
 * through `event_store_sequence_no_idx`. What the column buys is DB-stamped history no one can
 * reconstruct afterwards: it groups one commit's events, all events of an append sharing one
 * `xact_id`, and it exposes the divergence between reservation and commit, a `xact_id` non-monotone
 * with `sequence_no` meaning the writer took its transaction id at the head CAS well before it
 * reserved its position.
 *
 * That divergence is the CONDITION under which the safe head can skip. It is NOT a marker that a skip
 * happened, and reading it as one misleads in both directions. The minimal pathological interleave,
 * one writer staying in flight past the grace while a second commits above it, comes out perfectly
 * MONOTONE, so no inversion appears where the hazard is. An inversion does appear for a writer that
 * merely lagged between its CAS and its insert, both committing normally, where there is no hazard at
 * all. Absence of inversion proves nothing; presence of one accuses the innocent. What a skip needs is
 * the visibility horizon at the moment the watermark passed, and nothing here records it.
 *
 * The column carries no index on purpose. Those reads are ad-hoc incident forensics that tolerate a
 * seq-scan, so a standing index on this hot append table would be a per-insert write tax buying no
 * hot-path read. `xid8` is 64-bit and wrap-safe.
 *
 * `stream_heads`, the OCC watermark, and `event_store_high_water`, the safe-head cursor, each live in
 * their own schema class, one table per file for cleaner future migrations; Ledger aggregates them all.
 *
 * @see SequencePosition
 * @see StreamReader::committedHeadPosition()
 * @see StreamReader::safeHeadPosition()
 * @see SafeHeadAdvancer
 * @see StreamHeadsSchema
 * @see EventStoreHighWaterSchema
 */
final class EventStoreSchema
{
    /**
     * @return list<string>
     */
    public static function up(): array
    {
        return [
            /** @lang PostgreSQL */
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS event_store (
                    category      text     NOT NULL,
                    sequence_no   bigint   GENERATED ALWAYS AS IDENTITY,
                    stream        text     NOT NULL,
                    version       bigint   NOT NULL,
                    type          text     NOT NULL,
                    event_version smallint NOT NULL DEFAULT 1,
                    header        jsonb    NOT NULL DEFAULT '{}'::jsonb,
                    content       jsonb    NOT NULL DEFAULT '{}'::jsonb,
                    recorded_at   timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                    xact_id       xid8     NOT NULL DEFAULT pg_current_xact_id(),
                    CONSTRAINT event_store_pk PRIMARY KEY (category, sequence_no),
                    CONSTRAINT event_store_stream_version_uq UNIQUE (category, stream, version),
                    CONSTRAINT event_store_cat_stream_chk CHECK (category = split_part(stream, '-', 1)),
                    -- neither floor is reachable by the append path, and a row below one breaks a reader
                    -- in silence: the CAS for `version`, the upcaster chain for `event_version`, which is
                    -- the `#[EventType]` revision and 1-based by contract
                    CONSTRAINT event_store_version_chk CHECK (version > 0),
                    CONSTRAINT event_store_event_version_chk CHECK (event_version > 0)
                ) PARTITION BY LIST (category)
                SQL,
            /** @lang PostgreSQL */
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS event_store_default PARTITION OF event_store DEFAULT
                SQL,
            // Serves the safe-head gap scan, SafeHeadAdvancer's `sequence_no > floor ORDER BY sequence_no`
            // and `max(sequence_no)`, AND the projector's per-cycle filtered read, ProjectionFilter's
            // `sequence_no` range across partitions.
            /** @lang PostgreSQL */
            <<<'SQL'
                CREATE INDEX IF NOT EXISTS event_store_sequence_no_idx ON event_store (sequence_no)
                SQL,
            // The correlation point-lookup, serving the correlation_trace recipe, drill-down and
            // forensics. The key literal is Header::CorrelationId's wire value.
            //
            // A fixed-path consumer rides it in every planner mode by writing the SAME literal
            // expression. The inspect filter cannot do that because its path is caller-provided; its
            // `#>> string_to_array(:p, ',')` form matches only when a custom plan folds the binding.
            // The `->>` key form returns identical text and matches NOTHING here, so a query written
            // that way scans the whole store and no result ever looks wrong. A comparison against a
            // column pinned `COLLATE "C"` misses it the same way, resolving to a collation this index
            // is not built under; collate to the side whose index must serve.
            /** @lang PostgreSQL */
            <<<'SQL'
                CREATE INDEX IF NOT EXISTS event_store_correlation_idx ON event_store ((header #>> '{__correlation_id}'))
                SQL,
        ];
    }

    /**
     * @return list<string>
     */
    public static function down(): array
    {
        return [
            // No CASCADE: partitions ride with their parent, and nothing else Storm owns depends
            // on the store; a dependent OUTSIDE the manifest, such as an application view, makes the drop
            // refuse loud, naming it, instead of being silently destroyed.
            /** @lang PostgreSQL */
            'DROP TABLE IF EXISTS event_store',
        ];
    }
}
