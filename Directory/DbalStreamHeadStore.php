<?php

declare(strict_types=1);

namespace Storm\Chronicler\Directory;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Storm\Chronicler\Exception\StorageFailure;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * The Postgres `StreamHeadStore` on Doctrine DBAL.
 *
 * `advance()` splits the head CAS by intent, while `bump()` and `lockForErase()` use their own
 * `ON CONFLICT DO UPDATE` rules. With no transaction of its own, the store adds no nesting inside the
 * event store's `transactional()`, and projectors reuse it for reads outside any transaction.
 *
 * A transient driver failure, a deadlock or a lock timeout, translates to `RetryableStorageFailure`,
 * which keeps Doctrine's retry marker so `RetryEventStore` retries a wrapped transient exactly as it
 * retried the raw one.
 */
#[AsAlias(StreamHeadStore::class)]
final readonly class DbalStreamHeadStore implements StreamHeadStore
{
    public function __construct(
        private Connection $connection,
    ) {}

    /**
     * {@inheritDoc}
     *
     * The CAS is split by intent rather than one upsert, because a CAS must never become a creation. A
     * single `INSERT … ON CONFLICT DO UPDATE` serving both corrupts under a concurrent erase: a CAS with
     * `expectedVersion > 0` that waits behind the eraser's head lock has its speculative insertion
     * attempted again by Postgres when the erase commits, and that retry checks only the unique
     * conflict without re-evaluating the `SELECT` that guarded creation, so with the head row deleted
     * the insert succeeds. The head reappears at `newVersion` above a history whose events were just
     * erased: a silently wrong rehydration base, breaking both the eraser's "starts a fresh, coherent
     * stream" contract and the creation axiom.
     *
     * - `expectedVersion > 0` runs a pure `UPDATE`: under `READ COMMITTED`, the row a committed eraser
     *   deleted matches nothing, the CAS returns false, and the appender gets a loud OCC conflict.
     *
     * - `expectedVersion = 0` inserts a new head, or updates an existing head only while its version
     *   is 0. A creator waiting behind an erase inserts fresh after it commits, and a nonzero head makes
     *   the conflict arm a no-op that returns false, so an empty head can receive its first append
     *   without weakening the version guard.
     *
     * `bump()` keeps its unconditional upsert: a fact append that restarts a stream from scratch after
     * an erase is its documented behavior, not a corruption.
     */
    public function advance(string $stream, int $expectedVersion, int $newVersion): bool
    {
        try {
            if ($expectedVersion > 0) {
                return $this->connection->executeStatement(
                    /* language=PostgreSQL */
                    'UPDATE stream_heads SET last_version = :newVersion
                     WHERE stream = :stream AND last_version = :expectedVersion',
                    ['stream' => $stream, 'newVersion' => $newVersion, 'expectedVersion' => $expectedVersion],
                ) > 0;
            }

            return $this->connection->executeStatement(
                /* language=PostgreSQL */
                'INSERT INTO stream_heads (stream, last_version) VALUES (:stream, :newVersion)
                 ON CONFLICT (stream) DO UPDATE
                    SET last_version = EXCLUDED.last_version
                    WHERE stream_heads.last_version = 0',
                ['stream' => $stream, 'newVersion' => $newVersion],
            ) > 0;
        } catch (Exception $e) {
            throw StorageFailure::wrap('the stream-head CAS', $e);
        }
    }

    public function bump(string $stream, int $count): int
    {
        try {
            /** @var positive-int */
            return (int) $this->connection->fetchOne(
                /* language=PostgreSQL */
                'INSERT INTO stream_heads (stream, last_version) VALUES (:stream, :count)
                 ON CONFLICT (stream) DO UPDATE
                    SET last_version = stream_heads.last_version + :count
                 RETURNING last_version',
                ['stream' => $stream, 'count' => $count],
            );
        } catch (Exception $e) {
            throw StorageFailure::wrap('the stream-head bump', $e);
        }
    }

    public function lastVersion(string $stream): int
    {
        try {
            $version = $this->connection->fetchOne(
                /* language=PostgreSQL */
                'SELECT last_version FROM stream_heads WHERE stream = :stream',
                ['stream' => $stream],
            );
        } catch (Exception $e) {
            throw StorageFailure::wrap('the stream-head read', $e);
        }

        return $version === false ? 0 : (int) $version;
    }

    /**
     * {@inheritDoc}
     *
     * An UPSERT rather than `SELECT … FOR UPDATE`, because the row to lock may not exist yet and the
     * unknown-stream case is exactly the unfenced one: `FOR UPDATE` on nothing locks nothing, so a
     * writer could create the stream between the eraser's deletes and have its events swept, or
     * survive them. `ON CONFLICT DO UPDATE` locks the existing row, and inserts one when there is
     * none, in a single statement with no read-then-write window of its own. The update writes the
     * head back onto itself: a no-op in value, a real row lock in effect.
     */
    public function lockForErase(string $stream): void
    {
        try {
            $this->connection->executeStatement(
                /* language=PostgreSQL */
                'INSERT INTO stream_heads (stream, last_version) VALUES (:stream, 0)
                 ON CONFLICT (stream) DO UPDATE SET last_version = stream_heads.last_version',
                ['stream' => $stream],
            );
        } catch (Exception $e) {
            throw StorageFailure::wrap('the stream-head erase lock', $e);
        }
    }

    public function delete(string $stream): bool
    {
        try {
            return $this->connection->executeStatement(
                /* language=PostgreSQL */
                'DELETE FROM stream_heads WHERE stream = :stream',
                ['stream' => $stream],
            ) > 0;
        } catch (Exception $e) {
            throw StorageFailure::wrap('the stream-head deletion', $e);
        }
    }
}
