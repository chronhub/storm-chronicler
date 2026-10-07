<?php

declare(strict_types=1);

namespace Storm\Chronicler\Schema;

/**
 * Raw PostgreSQL DDL for `stream_heads`, the optimistic-concurrency watermark.
 *
 * One row per stream. `last_version` is the CAS target on append, and `… WHERE last_version =
 * :expected` is what detects a conflict.
 *
 * `stream` PINS its collation to `C`, and that is load-bearing rather than tidy. The snapshot sweep
 * reads one category as the key range `[category-, category.)`, which is only a contiguous slice
 * where `-` sorts before `.`; under a libc locale collation punctuation weighs less than letters, so
 * the upper bound falls BELOW every stream of the category. The sweep therefore compares its range
 * under an explicit `COLLATE "C"`, which keeps the range right whatever the column says, and the pin
 * is what lets the primary-key index serve that comparison: a column whose pin has drifted costs the
 * index, not the range. Its join with `snapshots` names no collation and needs the pair to agree,
 * which `SnapshotSchema` states. The database's own collation cannot be relied on either: two
 * clusters declaring `en_US.utf8` sort `-` against `.` differently depending on whether they link
 * musl or glibc.
 *
 * @see EventStoreSchema
 * @see \Storm\AggregateRepository\Snapshot\DbalSnapshotStore::staleStreams() the range that depends on it
 */
final class StreamHeadsSchema
{
    /**
     * @return list<string>
     */
    public static function up(): array
    {
        return [
            /* language=PostgreSQL */
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS stream_heads (
                    stream        text   COLLATE "C" NOT NULL,
                    last_version  bigint NOT NULL DEFAULT 0,
                    CONSTRAINT stream_heads_pk PRIMARY KEY (stream),
                    -- 0 is legal, the value `lockForErase` seeds for a stream that never existed; below
                    -- zero is not a state any writer reaches, and the CAS would compare against nonsense.
                    CONSTRAINT stream_heads_last_version_chk CHECK (last_version >= 0)
                )
                SQL,
        ];
    }

    /**
     * @return list<string>
     */
    public static function down(): array
    {
        return [
            /* language=PostgreSQL */
            'DROP TABLE IF EXISTS stream_heads',
        ];
    }
}
