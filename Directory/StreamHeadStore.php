<?php

declare(strict_types=1);

namespace Storm\Chronicler\Directory;

use Storm\Contracts\Chronicler\StorageFailure;

/**
 * The per-stream OCC watermark, `stream_heads.last_version`, the CAS target of optimistic concurrency.
 *
 * It is a module-local port, like `StreamReader` and `StreamPersistence`: Chronicler is its writer, the
 * aggregate repository's snapshot guard reads `lastVersion()` as the coherence probe, and nothing in
 * its vocabulary needs the shared layer.
 *
 * Its signatures are pure, a database-agnostic seam, though the default implementation is Postgres.
 * That implementation runs only statements on its connection and never opens a transaction, so inside
 * the event store's `transactional()` its writes stay atomic with the events.
 *
 * Failures cross this port as the contracted `StorageFailure` with the driver cause preserved;
 * transient causes stay retryable through the translation, never as a driver type.
 */
interface StreamHeadStore
{
    /**
     * Compare and swap: move the stream from the expected version to the new one.
     *
     * It creates the row for a new stream, and returns false when a competing writer already moved
     * the head. Creation is the `expectedVersion = 0` intent only: a positive CAS on a stream with no
     * head, including one whose head a concurrent erase just removed, returns false and must never
     * create.
     *
     * @throws StorageFailure on a store failure of the CAS
     */
    public function advance(string $stream, int $expectedVersion, int $newVersion): bool;

    /**
     * Fact-path allocation: move the head forward by `$count`, unconditionally.
     *
     * It creates the row for a new stream and returns the new last version, so the allocated range is
     * `[returned - count + 1 ...]`. A bump never loses: concurrent bumps serialize on the head row lock
     * and each gets a distinct dense range. It is the unguarded sibling of `advance()`.
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
     * Hold the stream's head row for the caller's transaction, so a concurrent append waits behind it.
     *
     * Every append verb, CAS or bump, writes this row as its first storage statement, so holding it
     * fences the whole stream instead of letting an append interleave.
     *
     * A stream with no head is materialized at version 0 and held: a row that does not exist cannot be
     * locked, and without it a writer could create the stream mid-erase and leave its events behind.
     * That materialized row is a stream to `hasStream()`, so this serves only a transaction that ends by
     * `delete()`ing the head; committing without it leaves a phantom empty stream.
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
