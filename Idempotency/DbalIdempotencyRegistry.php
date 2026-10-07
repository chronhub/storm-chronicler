<?php

declare(strict_types=1);

namespace Storm\Chronicler\Idempotency;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use InvalidArgumentException;
use Storm\Chronicler\Exception\IdempotencyConflict;
use Storm\Chronicler\Exception\InvalidIdempotencyClaim;
use Storm\Contracts\Chronicler\IdempotencyRegistry;
use Storm\Contracts\Message\MetaIdentityGenerator;
use Storm\Support\Dbal\BatchedDelete;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Serializes claims with PostgreSQL's unique key and row lock. The short transaction ends
 * before the caller can dispatch; a failed dispatch never releases the persisted identity.
 * No-op conflict updates lock the winning row before checking its current expiration.
 */
#[AsAlias(IdempotencyRegistry::class)]
final readonly class DbalIdempotencyRegistry implements IdempotencyRegistry
{
    /**
     * @throws InvalidIdempotencyClaim when the lifetime is outside one second through one year
     */
    public function __construct(
        private Connection $connection,
        private MetaIdentityGenerator $identity,
        private int $lifetimeSeconds = 86_400,
    ) {
        if ($lifetimeSeconds < 1 || $lifetimeSeconds > 31_536_000) {
            throw new InvalidIdempotencyClaim('The request identity lifetime must be between 1 and 31536000 seconds.');
        }
    }

    public function claim(string $scopeHash, string $keyHash, string $fingerprint): string
    {
        if (! array_all([$scopeHash, $keyHash, $fingerprint], static fn (string $digest): bool => preg_match('/\A[0-9a-f]{64}\z/', $digest) === 1)) {
            throw new InvalidIdempotencyClaim('Scope, key and fingerprint must be lowercase SHA-256 hex digests.');
        }
        $this->requireAutocommit();

        return $this->connection->transactional(function () use ($scopeHash, $keyHash, $fingerprint): string {
            $candidate = $this->identity->generate();
            $row = $this->connection->fetchAssociative(
                /* language=PostgreSQL */
                <<<'SQL'
                    INSERT INTO es_idempotency (scope_hash, key_hash, fingerprint, message_id, expires_at)
                    VALUES (:scope, :key, :fingerprint, :identity, clock_timestamp() + make_interval(secs => :lifetime))
                    ON CONFLICT (scope_hash, key_hash) DO UPDATE SET key_hash = EXCLUDED.key_hash
                    RETURNING fingerprint, message_id, expires_at <= clock_timestamp() AS expired
                    SQL,
                ['scope' => $scopeHash, 'key' => $keyHash, 'fingerprint' => $fingerprint,
                    'identity' => $candidate, 'lifetime' => $this->lifetimeSeconds],
            );
            if ($row === false) {
                throw new InvalidIdempotencyClaim('The registry did not return its locked claim.');
            }
            if ($row['expired'] === true) {
                $candidate = $this->identity->generate();
                $this->connection->executeStatement(
                    /* language=PostgreSQL */
                    <<<'SQL'
                        UPDATE es_idempotency SET fingerprint = :fingerprint, message_id = :identity,
                            expires_at = clock_timestamp() + make_interval(secs => :lifetime)
                        WHERE scope_hash = :scope AND key_hash = :key
                        SQL,
                    ['scope' => $scopeHash, 'key' => $keyHash, 'fingerprint' => $fingerprint,
                        'identity' => $candidate, 'lifetime' => $this->lifetimeSeconds],
                );

                return $candidate;
            }
            if (! hash_equals((string) $row['fingerprint'], $fingerprint)) {
                throw new IdempotencyConflict('This request key is already bound to a different request.');
            }

            return (string) $row['message_id'];
        });
    }

    /**
     * Count claims whose fixed expiration has passed on the database clock.
     *
     * @throws Exception on a database failure
     */
    public function countExpired(): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM es_idempotency WHERE expires_at <= statement_timestamp()');
    }

    /**
     * Remove expired claims in lock-skipping batches. A concurrent acquisition keeps its row locked.
     *
     * @throws InvalidIdempotencyClaim when a caller transaction would retain every batch lock
     * @throws InvalidArgumentException when the batch size is not positive
     * @throws Exception on a database failure
     */
    public function prune(int $batch = 1000): int
    {
        $this->requireAutocommit();

        return BatchedDelete::run($this->connection, 'es_idempotency', 'expires_at <= statement_timestamp()', [], $batch);
    }

    /**
     * @throws InvalidIdempotencyClaim when the caller owns a transaction
     */
    private function requireAutocommit(): void
    {
        if (! $this->connection->isAutoCommit() || $this->connection->isTransactionActive()) {
            throw new InvalidIdempotencyClaim('Request identity operations require their own committed transaction boundary.');
        }
    }
}
