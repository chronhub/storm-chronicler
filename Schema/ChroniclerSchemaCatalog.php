<?php

declare(strict_types=1);

namespace Storm\Chronicler\Schema;

/**
 * What `storm:install` proves of the event store and its satellites after the DDL.
 *
 * It covers the tables the Chronicler `*Schema` classes create, as the runtime relies on them. It is the
 * module's own verification data, kept beside the DDL it mirrors; the core install composes it with the
 * other core modules' catalogs and hands the whole to the shared probe.
 */
final class ChroniclerSchemaCatalog
{
    /**
     * The `event_store` column shape, shared verbatim with its default partition below.
     *
     * @var array<string, string>
     */
    private const array EVENT_STORE_COLUMNS = [
        'category' => 'text not null',
        'sequence_no' => 'bigint not null',
        'stream' => 'text not null',
        'version' => 'bigint not null',
        'type' => 'text not null',
        'event_version' => 'smallint not null',
        'header' => 'jsonb not null',
        'content' => 'jsonb not null',
        'recorded_at' => 'timestamp(6) with time zone not null',
        'xact_id' => 'xid8 not null',
    ];

    /**
     * Every column each table must expose, pinned to the shape the probe composes from the live catalogs.
     *
     * The shape is `format_type` plus its nullability. A homonymous table with the right names but a
     * narrower type, a flipped nullability or a different primary key passes a name-only probe and fails
     * later, inside an `INSERT` on a path a backstop may swallow. A null value here falls back to a
     * presence-only check.
     *
     * @var array<string, array<string, string|null>>
     */
    public const array COLUMNS = [
        'es_idempotency' => [
            'scope_hash' => 'text not null',
            'key_hash' => 'text not null',
            'fingerprint' => 'text not null',
            'message_id' => 'text not null',
            'expires_at' => 'timestamp(6) with time zone not null',
        ],
        'event_store' => self::EVENT_STORE_COLUMNS,
        // a `LIST` partition's default child carries the parent's columns by construction; one
        // spelling serves both so the pair can never be edited apart
        'event_store_default' => self::EVENT_STORE_COLUMNS,
        'event_store_high_water' => [
            'id' => 'smallint not null',
            'position' => 'bigint not null',
            'updated_at' => 'timestamp(6) with time zone not null',
        ],
        'stream_heads' => [
            // the collation is verified, not assumed: a table installed before it was pinned carries
            // the right type and the right nullability, so a shape-only check blesses it while the
            // snapshot sweep, whose explicit `COLLATE "C"` keeps its rows right, loses the primary
            // key it ranges over
            'stream' => 'text not null collate C',
            'last_version' => 'bigint not null',
        ],
        'es_outbox' => [
            'id' => 'bigint not null',
            'position' => 'bigint not null',
            'partition_key' => 'text not null',
            'type' => 'text not null',
            'event_version' => 'smallint not null',
            'status' => 'text not null',
            'attempts' => 'integer not null',
            'last_error' => 'text null',
            'next_attempt_at' => 'timestamp(6) with time zone null',
            'occurred_at' => 'timestamp(6) with time zone not null',
            'failed_at' => 'timestamp(6) with time zone null',
        ],
        'es_outbox_relay' => [
            'relay' => 'text not null',
            'ticked_at' => 'timestamp(6) with time zone not null',
            'relayed_total' => 'bigint not null',
        ],
        'es_outbox_archive' => [
            'id' => 'bigint not null',
            'position' => 'bigint not null',
            'partition_key' => 'text not null',
            'type' => 'text not null',
            'event_version' => 'smallint not null',
            'header' => 'jsonb not null',
            'content' => 'jsonb not null',
            'attempts' => 'integer not null',
            'occurred_at' => 'timestamp(6) with time zone not null',
            'archived_at' => 'timestamp(6) with time zone not null',
        ],
        'es_inbox_consumers' => [
            'id' => 'smallint not null',
            'name' => 'text not null',
        ],
        'es_inbox' => [
            'consumer_id' => 'smallint not null',
            'message_id' => 'bytea not null',
            'processed_at' => 'timestamp(6) with time zone not null',
            'duplicates' => 'integer not null',
            'last_duplicate_at' => 'timestamp(6) with time zone null',
        ],
    ];

    /**
     * Named constraints per table; the OCC unique and the `CHECK`s are invariants the store relies on.
     *
     * A non-null value opening on its definition keyword is the complete definition the live
     * `pg_get_constraintdef` must equal, and any other is a fragment it must contain, the rule
     * `ConstraintShape` applies. A name alone proves nothing about a pre-existing homonym, since
     * `ADD CONSTRAINT` converges on `duplicate_object` exactly as `CREATE … IF NOT EXISTS` does, and a
     * `CHECK` that silently lost a value fails at runtime, not at install.
     *
     * The value-range `CHECK`s are a second line of defense rather than a guard on the normal paths,
     * which cannot produce a negative attempt count or a version of 0. They exist for restores, manual
     * repair and imports, the moments an append-only store has no other way to notice. They ship inline
     * in the `CREATE TABLE` only: the pre-version posture recreates an older store rather than
     * converging it, and its missing constraint surfaces here as a loud install refusal rather than a
     * silent absence.
     *
     * Values are declared where a degraded homonym does harm and where the text is stable across both the
     * source DDL and PostgreSQL's deparse; `SchemaCompletenessTest` pins each value against the rendered
     * DDL, a complete `CHECK` in its declared form with one parenthesis pair less, and the live probe
     * compares it against `pg_get_constraintdef`:
     *
     * - `es_outbox_status_chk` pins `'failed'`, the dead-letter status: an outbox predating that
     *   disposition passes a name-only probe and the first dead-letter `UPDATE` fails at runtime.
     *   The deparse rewrites `IN (…)` to `= ANY (ARRAY[…])`, so the literal is the stable part.
     *
     * - `es_outbox_failed_at_chk` pins the whole bi-implication: with only half of it, a row reads
     *   as a dead letter to one surface and as live work to another.
     *
     * - The numeric floors pin their complete definition, except on `position`, a keyword PostgreSQL
     *   quotes on deparse as `"position" > 0`, where no text is stable across both renderings;
     *   those two stay presence-only, the least harmful degradations of the set.
     *
     * - Every named primary key pins its full column list: `PRIMARY KEY (…)` deparses verbatim, and
     *   a homonym keyed differently misroutes the OCC target and every upsert in silence.
     *
     * @var array<string, array<string, string|null>>
     */
    public const array CONSTRAINTS = [
        'es_idempotency' => [
            'es_idempotency_pk' => 'PRIMARY KEY (scope_hash, key_hash)',
            'es_idempotency_scope_chk' => "scope_hash ~ '^[0-9a-f]{64}$'",
            'es_idempotency_key_chk' => "key_hash ~ '^[0-9a-f]{64}$'",
            'es_idempotency_fingerprint_chk' => "fingerprint ~ '^[0-9a-f]{64}$'",
            'es_idempotency_identity_chk' => 'CHECK ((length(message_id) > 0))',
        ],
        'event_store' => [
            'event_store_pk' => 'PRIMARY KEY (category, sequence_no)',
            'event_store_stream_version_uq' => null,
            'event_store_cat_stream_chk' => "category = split_part(stream, '-'",
            'event_store_version_chk' => 'CHECK ((version > 0))',
            'event_store_event_version_chk' => 'CHECK ((event_version > 0))',
        ],
        'event_store_high_water' => [
            'event_store_high_water_pk' => 'PRIMARY KEY (id)',
            'event_store_high_water_singleton_chk' => 'CHECK ((id = 1))',
            'event_store_high_water_position_chk' => null,
        ],
        'stream_heads' => [
            'stream_heads_pk' => 'PRIMARY KEY (stream)',
            'stream_heads_last_version_chk' => 'CHECK ((last_version >= 0))',
        ],
        'es_outbox' => [
            'es_outbox_pk' => 'PRIMARY KEY (id)',
            'es_outbox_status_chk' => "'failed'",
            'es_outbox_position_chk' => null,
            'es_outbox_event_version_chk' => 'CHECK ((event_version > 0))',
            'es_outbox_attempts_chk' => 'CHECK ((attempts >= 0))',
            'es_outbox_failed_at_chk' => "(failed_at IS NOT NULL) = (status = 'failed'",
        ],
        'es_outbox_relay' => [
            'es_outbox_relay_pk' => 'PRIMARY KEY (relay)',
            'es_outbox_relay_relayed_total_chk' => 'CHECK ((relayed_total >= 0))',
        ],
        'es_outbox_archive' => [
            'es_outbox_archive_pk' => 'PRIMARY KEY (id)',
            'es_outbox_archive_position_chk' => null,
            'es_outbox_archive_attempts_chk' => 'CHECK ((attempts >= 0))',
        ],
        'es_inbox_consumers' => [
            'es_inbox_consumers_pk' => 'PRIMARY KEY (id)',
            'es_inbox_consumers_name_uq' => 'UNIQUE (name)',
        ],
        'es_inbox' => [
            'es_inbox_pk' => 'PRIMARY KEY (consumer_id, message_id)',
            'es_inbox_duplicates_chk' => 'CHECK ((duplicates >= 0))',
            'es_inbox_key_length_chk' => 'CHECK ((octet_length(message_id) = ANY (ARRAY[16, 32])))',
        ],
    ];

    /**
     * Indexes per table; a non-null value is a fragment the live `indexdef` must contain.
     *
     * The pending partial predicate and the correlation expression are what make those two indexes worth
     * having, and `CREATE INDEX IF NOT EXISTS` never verifies a homonym kept them.
     */
    public const array INDEXES = [
        'es_idempotency' => ['es_idempotency_expiration_idx' => 'USING brin (expires_at)'],
        'event_store' => [
            'event_store_sequence_no_idx' => null,
            'event_store_correlation_idx' => "#>> '{__correlation_id}'",
        ],
        'es_outbox' => [
            'es_outbox_pending_idx' => "WHERE (status = 'pending'",
            // the relay's per-partition head probe must stay index-only, or the relay collapses under a
            // backlog of one row per partition; the fragment pins the leading `partition_key` column,
            // the `INCLUDE` that keeps the probe's aggregates index-only, and the partial predicate
            'es_outbox_partition_pending_idx' => "(partition_key, id) INCLUDE (next_attempt_at) WHERE (status = 'pending'",
            // the dead-letter's own partial index; `failed` rows are never disposed of, so this set
            // only grows, and every `storm:outbox:failed` read uses this predicate
            'es_outbox_failed_idx' => "WHERE (status = 'failed'",
        ],
        'es_outbox_archive' => ['es_outbox_archive_age_idx' => null],
        'es_inbox' => [
            'es_inbox_age_idx' => null,
            // the duplicate gauges' partial index: the predicate is what keeps a scrape off the table
            'es_inbox_duplicates_idx' => 'WHERE (duplicates > 0)',
        ],
    ];

    /** Tables that must be partitioned parents, with `relkind = 'p'`. */
    public const array PARTITIONED = ['event_store'];

    /**
     * The catch-all partition every category without one of its own routes to.
     *
     * It must be attached, not merely present: a detached `event_store_default` keeps its name and its
     * shape, so the DDL skips it and the parent still verifies as partitioned while an event of an
     * unnamed category has nowhere to land.
     */
    public const array DEFAULT_PARTITIONS = ['event_store' => 'event_store_default'];

    /**
     * `sequence_no` is `GENERATED ALWAYS AS IDENTITY`, the global monotone position the safe head scans.
     *
     * A pre-existing column of the right type and nullability but no generator accepts an explicit value
     * silently and never produces one on an omitted insert, which the column-shape check alone cannot
     * see. The default partition shares the parent's columns by construction, so it carries the same
     * entry.
     */
    public const array IDENTITIES = [
        'event_store' => ['sequence_no' => 'a'],
        'event_store_default' => ['sequence_no' => 'a'],
        'es_inbox_consumers' => ['id' => 'a'],
    ];

    /**
     * `xact_id` defaults to `pg_current_xact_id()`, forensic data for incident analysis.
     *
     * A pre-existing column of the right type keeps `NOT NULL` satisfied by a wrong or missing default,
     * so nothing here fails a single insert; the forensics would just be wrong, silently, which is the
     * case the column-shape check alone cannot see.
     */
    public const array DEFAULTS = [
        'event_store' => ['xact_id' => 'pg_current_xact_id()'],
        'event_store_default' => ['xact_id' => 'pg_current_xact_id()'],
    ];
}
