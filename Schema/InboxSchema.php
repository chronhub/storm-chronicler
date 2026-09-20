<?php

declare(strict_types=1);

namespace Storm\Chronicler\Schema;

/**
 * Raw PostgreSQL DDL for `es_inbox`, the consumer-side deduplication log.
 *
 * One row per `(consumer_id, message_id)` marks that pair as already handled, the consumer as the
 * id `es_inbox_consumers` gave its name and the message id in its binary form. Message-bus middleware
 * inserts the row in the same transaction as the handler's effects, atomically, so a redelivery of an
 * already-processed message is a no-op. The composite key is `(consumer_id, message_id)`, not
 * `message_id` alone, so the same event fanned out to several transports or consumers is deduplicated
 * per consumer, not globally.
 *
 * `consumer_id` names the receiving transport through `es_inbox_consumers`, and `message_id` is the
 * binary form of the message id, sixteen bytes for a uuid and thirty-two for a hashed text; the
 * retention reads the v7 version nibble in a sixteen-byte key before trusting its leading bits as a
 * minting instant. Delivery is at-least-once, the outbox being able to re-drain and Messenger to
 * retry, so any stateful reaction needs this table; stateless or log reactions do not.
 */
final class InboxSchema
{
    /**
     * @return list<string>
     */
    public static function up(): array
    {
        return [
            // The consumer names behind `es_inbox.consumer_id`: registered once, on the first
            // proof recorded under a name, so a proof carries two bytes of consumer.
            /** @lang PostgreSQL */
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS es_inbox_consumers (
                    id   smallint GENERATED ALWAYS AS IDENTITY,
                    name text NOT NULL,
                    CONSTRAINT es_inbox_consumers_pk PRIMARY KEY (id),
                    CONSTRAINT es_inbox_consumers_name_uq UNIQUE (name)
                )
                SQL,
            // `message_id` is the binary form of the id, 16 bytes for a uuid and 32 for a hashed
            // text, never its characters: the key is what a proof costs, in the tuple and again in
            // the primary index. The registry is not a foreign key: a proof is written on every
            // consumed message, and the registry's row for a live consumer never goes away.
            /** @lang PostgreSQL */
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS es_inbox (
                    consumer_id       smallint NOT NULL,
                    message_id        bytea NOT NULL,
                    processed_at      timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                    duplicates        integer NOT NULL DEFAULT 0,
                    last_duplicate_at timestamptz(6),
                    CONSTRAINT es_inbox_pk PRIMARY KEY (consumer_id, message_id),
                    CONSTRAINT es_inbox_duplicates_chk CHECK (duplicates >= 0),
                    CONSTRAINT es_inbox_key_length_chk CHECK (octet_length(message_id) = ANY (ARRAY[16, 32]))
                )
                SQL,
            // An additive drift converges on a re-install: a CHECK dropped by hand between two
            // installs comes back, guarded, because CREATE TABLE IF NOT EXISTS no-ops on a live
            // table. No column is altered here: a table of another SHAPE is refused by the conformance
            // probe, never migrated. The guard reads the constraint on THIS relation: pg_constraint
            // spans the database, and a sibling schema carrying the same name would otherwise hide
            // the missing CHECK here.
            /** @lang PostgreSQL */
            <<<'SQL'
                DO $$
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1 FROM pg_constraint
                        WHERE conrelid = 'es_inbox'::regclass AND conname = 'es_inbox_duplicates_chk'
                    ) THEN
                        ALTER TABLE es_inbox ADD CONSTRAINT es_inbox_duplicates_chk CHECK (duplicates >= 0);
                    END IF;
                    IF NOT EXISTS (
                        SELECT 1 FROM pg_constraint
                        WHERE conrelid = 'es_inbox'::regclass AND conname = 'es_inbox_key_length_chk'
                    ) THEN
                        ALTER TABLE es_inbox ADD CONSTRAINT es_inbox_key_length_chk CHECK (octet_length(message_id) = ANY (ARRAY[16, 32]));
                    END IF;
                END
                $$
                SQL,
            // Serves the age-based half of `storm:inbox:prune`, the keys of another shape than a v7
            // uuid, hashed texts and other uuid versions, few; the v7 keys are pruned by a range of
            // the primary index, their first 48 bits being their minting instant. The length CHECK
            // above is what keeps the version read total: `get_byte` on a shorter key would fail the
            // whole prune rather than skip a row. BRIN, not btree: a few pages of range summaries at
            // near-zero write cost on a table that takes one INSERT per consumed message.
            /** @lang PostgreSQL */
            <<<'SQL'
                CREATE INDEX IF NOT EXISTS es_inbox_age_idx ON es_inbox USING brin (processed_at)
                SQL,
            // The rows a redelivery touched, for the duplicate gauges of the metrics scrape: a
            // partial index over the few rows with `duplicates > 0`, so the scrape never walks the
            // table, and an insert pays nothing until its row absorbs a first duplicate. The
            // absorbing UPDATE pays: it changes an indexed column, so it is no longer a HOT update
            // and writes an entry in every index of the table, the primary key included; the
            // redeliveries are the rare path, the scrape the frequent one.
            /** @lang PostgreSQL */
            <<<'SQL'
                CREATE INDEX IF NOT EXISTS es_inbox_duplicates_idx ON es_inbox (duplicates) WHERE duplicates > 0
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
            /** @lang PostgreSQL */
            'DROP TABLE IF EXISTS es_inbox_consumers',
        ];
    }
}
