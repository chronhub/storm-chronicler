<?php

declare(strict_types=1);

namespace Storm\Chronicler\Schema;

use Storm\Chronicler\SafeHead\SafeHeadAdvancer;

/**
 * Raw PostgreSQL DDL for `event_store_high_water`, the safe-head cursor.
 *
 * A single row, guarded by CHECK id = 1, holding the floor the catch-up watermark has ratcheted to.
 * Internal catch-up machinery, not a domain table. Correctness does NOT depend on it: SafeHeadAdvancer
 * recomputes correctly from any floor; the cursor only keeps the per-cycle `sequence_no` gap scan's
 * tail short.
 *
 * It carries no FK to `event_store`, being an independent singleton, so creation order between the
 * two does not matter.
 *
 * @see EventStoreSchema
 * @see SafeHeadAdvancer
 */
final class EventStoreHighWaterSchema
{
    /**
     * @return list<string>
     */
    public static function up(): array
    {
        return [
            /** @lang PostgreSQL */
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS event_store_high_water (
                    id          smallint       NOT NULL DEFAULT 1,
                    position    bigint         NOT NULL DEFAULT 0,
                    updated_at  timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                    CONSTRAINT event_store_high_water_pk PRIMARY KEY (id),
                    CONSTRAINT event_store_high_water_singleton_chk CHECK (id = 1),
                    -- the ratcheted floor starts at 0 and only climbs; a negative would send every
                    -- projection reading it below the first event
                    CONSTRAINT event_store_high_water_position_chk CHECK (position >= 0)
                )
                SQL,
            // Seed the singleton at floor 0; the advancer upserts on conflict, so this is just a convenience.
            /** @lang PostgreSQL */
            <<<'SQL'
                INSERT INTO event_store_high_water (id, position) VALUES (1, 0) ON CONFLICT (id) DO NOTHING
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
            'DROP TABLE IF EXISTS event_store_high_water',
        ];
    }
}
