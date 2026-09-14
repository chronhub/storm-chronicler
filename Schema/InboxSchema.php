<?php

declare(strict_types=1);

namespace Storm\Chronicler\Schema;

/**
 * Raw PostgreSQL DDL for `es_inbox`, the consumer-side deduplication log.
 *
 * One row per `(consumer, message_id)` marks that pair as already handled. Message-bus middleware
 * inserts the row in the same transaction as the handler's effects, atomically, so a redelivery of an
 * already-processed message is a no-op. The composite key is `(consumer, message_id)`, not
 * `message_id` alone, so the same event fanned out to several transports or consumers is deduplicated
 * per consumer, not globally.
 *
 * `consumer` is the receiving transport name. Delivery is at-least-once, the outbox being able to
 * re-drain and Messenger to retry, so any stateful reaction needs this table; stateless or log
 * reactions do not.
 */
final class InboxSchema
{
    /**
     * @return list<string>
     */
    public static function up(): array
    {
        return [
            /** @lang PostgreSQL */
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS es_inbox (
                    consumer          text NOT NULL,
                    message_id        text NOT NULL,
                    processed_at      timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                    duplicates        integer NOT NULL DEFAULT 0,
                    last_duplicate_at timestamptz(6),
                    CONSTRAINT es_inbox_pk PRIMARY KEY (consumer, message_id),
                    CONSTRAINT es_inbox_duplicates_chk CHECK (duplicates >= 0)
                )
                SQL,
            // The redeliveries a row absorbed, counted where they are absorbed: the inbox is silent
            // when it works, and without the count nothing outside says a duplicate ever came. A table
            // installed before these columns converges by ADD COLUMN IF NOT EXISTS, the row shape being
            // what the conformance probe reads; the CHECK rides the CREATE for a new table and is added
            // guarded for an old one, so an old install does not stay loud at every boot. The guard reads
            // the constraint on THIS relation: pg_constraint spans the database, and a sibling schema
            // carrying the same name would otherwise hide the missing CHECK here.
            /* language=PostgreSQL */
            'ALTER TABLE es_inbox ADD COLUMN IF NOT EXISTS duplicates integer NOT NULL DEFAULT 0',
            /* language=PostgreSQL */
            'ALTER TABLE es_inbox ADD COLUMN IF NOT EXISTS last_duplicate_at timestamptz(6)',
            /* language=PostgreSQL */
            <<<'SQL'
                DO $$
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1 FROM pg_constraint
                        WHERE conrelid = 'es_inbox'::regclass AND conname = 'es_inbox_duplicates_chk'
                    ) THEN
                        ALTER TABLE es_inbox ADD CONSTRAINT es_inbox_duplicates_chk CHECK (duplicates >= 0);
                    END IF;
                END
                $$
                SQL,
            // Serves the age-based retention of `storm:inbox:prune`, whose BatchedDelete per-batch
            // `WHERE processed_at < :age` subselect would otherwise seq-scan the whole table each
            // batch. BRIN, not btree: the table is insert-ordered on processed_at, so a few pages
            // of range summaries index millions of rows at near-zero write cost; this table takes
            // one INSERT per consumed message.
            /** @lang PostgreSQL */
            <<<'SQL'
                CREATE INDEX IF NOT EXISTS es_inbox_age_idx ON es_inbox USING brin (processed_at)
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
            'DROP TABLE IF EXISTS es_inbox',
        ];
    }
}
