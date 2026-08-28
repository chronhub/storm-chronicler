<?php

declare(strict_types=1);

namespace Storm\Chronicler\Schema;

use Storm\Chronicler\Console\OutboxPruneCommand;
use Storm\Chronicler\Outbox\OutboxRelay;

/**
 * Raw PostgreSQL DDL for `es_outbox_archive`, the delivery audit of the transactional outbox.
 *
 * Where a row goes once it has been published, when `storm.outbox.disposal` says archive rather than
 * delete. Append-only with zero updates, so neither churn nor bloat; `archived_at` is the publication
 * instant, the move happening inside the drain transaction that published.
 *
 * Retention belongs to `storm:outbox:prune`, which sweeps by age. A BRIN on `archived_at` keeps that
 * sweep cheap on a table this insert-ordered.
 *
 * The table is OPTIONAL in a way `es_outbox` is not, since a store running the delete disposal never
 * writes a row here. It is installed unconditionally all the same: the disposal is runtime config,
 * and an archive table missing at the moment that config flips would fail a drain rather than a boot.
 *
 * @see OutboxSchema the hot table it drains from
 * @see OutboxRelay
 * @see OutboxPruneCommand
 */
final class OutboxArchiveSchema
{
    /**
     * @return list<string>
     */
    public static function up(): array
    {
        return [
            /** @lang PostgreSQL */
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS es_outbox_archive (
                    id            bigint   NOT NULL,
                    position      bigint   NOT NULL,
                    partition_key text     NOT NULL DEFAULT '',
                    type          text     NOT NULL,
                    event_version smallint NOT NULL DEFAULT 1,
                    header        jsonb    NOT NULL DEFAULT '{}'::jsonb,
                    content       jsonb    NOT NULL DEFAULT '{}'::jsonb,
                    attempts      integer  NOT NULL DEFAULT 0,
                    occurred_at   timestamptz(6) NOT NULL,
                    archived_at   timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                    CONSTRAINT es_outbox_archive_pk PRIMARY KEY (id),
                    -- the same floors the hot table states: a row only lands here by being copied from
                    -- one that already satisfied them, so a violation means the copy, not the relay
                    CONSTRAINT es_outbox_archive_position_chk CHECK (position > 0),
                    CONSTRAINT es_outbox_archive_attempts_chk CHECK (attempts >= 0)
                )
                SQL,
            /** @lang PostgreSQL */
            <<<'SQL'
                CREATE INDEX IF NOT EXISTS es_outbox_archive_age_idx ON es_outbox_archive USING brin (archived_at)
                SQL,
        ];
    }

    /**
     * @return list<string>
     */
    public static function down(): array
    {
        return [
            /** @lang PostgreSQL */
            'DROP TABLE IF EXISTS es_outbox_archive',
        ];
    }
}
