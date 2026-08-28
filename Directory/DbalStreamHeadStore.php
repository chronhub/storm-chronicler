<?php

declare(strict_types=1);

namespace Storm\Chronicler\Directory;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Storm\Chronicler\Exception\StorageFailure;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Postgres StreamHeadStore on Doctrine DBAL. The head CAS `advance()` is split by intent: a pure
 * UPDATE for an existing stream, a create-only `INSERT … ON CONFLICT DO NOTHING` for a new one. That
 * makes the new-stream guard, creation only at `expectedVersion = 0`, structural: no statement can
 * both create and CAS. `bump()` and `lockForErase()` stay PG UPSERTs via `ON CONFLICT DO UPDATE`.
 *
 * It opens no transaction of its own, so its writes add no nesting inside the event store's
 * `transactional()` and projectors reuse it for reads outside any transaction.
 *
 * A transient driver failure, a deadlock or lock timeout, translates to RetryableStorageFailure,
 * which keeps Doctrine's retry marker so RetryEventStore retries a wrapped transient exactly as it
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
     * Split by intent, never one UPSERT, because a CAS must NEVER be able to become a creation. A
     * single `INSERT … ON CONFLICT DO UPDATE` served both once, and its conflict arm corrupts under
     * a concurrent erase: a CAS with `expectedVersion > 0` that waits behind the eraser's head lock
     * has its speculative insertion RE-ATTEMPTED by Postgres when the erase commits; the retry
     * checks only the unique conflict, it does not re-evaluate the SELECT that guarded creation, so
     * with the head row deleted the insert succeeds. The head reappears at `newVersion` above a
     * history whose events were just erased: a silently wrong rehydration base, and a violation of
     * both the eraser's "starts a fresh, coherent stream" contract and the creation axiom.
     *
     * So `expectedVersion > 0` runs a pure UPDATE: under READ COMMITTED, the row a committed eraser
     * deleted matches nothing, the CAS returns false, and the appender gets the loud OCC conflict
     * it deserves. `expectedVersion = 0` runs `INSERT … ON CONFLICT DO NOTHING`: a creator that
     * waited behind an erase inserts fresh once it commits, exactly the fresh-stream case the
     * eraser promises, while an existing head makes the insert a no-op, false, so the guard holds
     * on that path too. `bump()` keeps its unconditional UPSERT: a fact append restarting a stream
     * from scratch after an erase is its documented behavior, not a corruption.
     */
    public function advance(string $stream, int $expectedVersion, int $newVersion): bool
    {
        try {
            if ($expectedVersion > 0) {
                return $this->connection->executeStatement(
                    /** @lang PostgreSQL */
                    'UPDATE stream_heads SET last_version = :newVersion
                     WHERE stream = :stream AND last_version = :expectedVersion',
                    ['stream' => $stream, 'newVersion' => $newVersion, 'expectedVersion' => $expectedVersion],
                ) > 0;
            }

            return $this->connection->executeStatement(
                /** @lang PostgreSQL */
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
                /** @lang PostgreSQL */
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
                /** @lang PostgreSQL */
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
                /** @lang PostgreSQL */
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
                /** @lang PostgreSQL */
                'DELETE FROM stream_heads WHERE stream = :stream',
                ['stream' => $stream],
            ) > 0;
        } catch (Exception $e) {
            throw StorageFailure::wrap('the stream-head deletion', $e);
        }
    }
}
