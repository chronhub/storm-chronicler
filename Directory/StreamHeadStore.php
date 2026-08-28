<?php

declare(strict_types=1);

namespace Storm\Chronicler\Directory;

use Storm\Contracts\Chronicler\StorageFailure;

/**
 * Per-stream OCC watermark store, stream_heads: last_version, the CAS target for optimistic
 * concurrency. A module-local port, like StreamReader and StreamPersistence; Chronicler is its
 * writer, and the aggregate repository's snapshot guard reads `lastVersion()` as the coherence
 * probe; nothing in its vocabulary needs the shared layer.
 *
 * Pure signatures, a DB-agnostic seam, though the default implementation is Postgres. That
 * implementation runs only statements on its connection and never opens a transaction, so inside
 * the event store's `transactional()` its writes stay atomic with the events.
 *
 * Failures cross this port as the contracted StorageFailure with the driver cause preserved;
 * transient causes stay retryable through the translation, never as a driver type.
 */
interface StreamHeadStore
{
    /**
     * CAS: move the stream from the expected version to the new version, creating the row for
     * a new stream. Returns false when a competing writer already moved the head. Creation is
     * the `expectedVersion = 0` intent only: a positive CAS on a stream with no head, including
     * one whose head a concurrent erase just removed, returns false; it must never create.
     *
     * @throws StorageFailure on a store failure of the CAS
     */
    public function advance(string $stream, int $expectedVersion, int $newVersion): bool;

    /**
     * Fact-path allocation: move the head forward by `$count` UNCONDITIONALLY, creating the row
     * for a new stream and return the NEW last version; the allocated range is
     * `[returned - count + 1 ...]`. Never loses: concurrent bumps serialize on the head
     * row lock and each gets a distinct dense range. The unguarded sibling of `advance()`.
     *
     * @param  positive-int  $count
     * @return positive-int the new last version, the upper bound of the allocated range
     *
     * @throws StorageFailure on a store failure of the bump
     */
    public function bump(string $stream, int $count): int;

    /**
     * Read the last version of the stream.
     *
     * @throws StorageFailure on a store failure of the read
     */
    public function lastVersion(string $stream): int;

    /**
     * Hold the stream's head row for the caller's transaction, so a concurrent append serializes
     * behind it instead of interleaving with it: every append verb, CAS or bump, writes this row as
     * its FIRST storage statement, so holding it fences the whole stream.
     *
     * A stream with no head is materialized at version 0 and held: a row that does not exist cannot
     * be locked, and without it a writer could create the stream mid-erase and leave its events
     * behind. That materialized row IS a stream to `hasStream()`, so this is only for a transaction
     * that ends by `delete()`ing the head; committing without it leaves a phantom empty stream.
     *
     * @throws StorageFailure on a store failure of the lock
     */
    public function lockForErase(string $stream): void;

    /**
     * Delete the stream's head row.
     *
     * @throws StorageFailure on a store failure of the deletion
     */
    public function delete(string $stream): bool;
}
