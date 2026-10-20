<?php

declare(strict_types=1);

namespace Storm\Chronicler\Schema;

/**
 * Request identity claims on the events connection.
 *
 * Expiration is fixed at acquisition; changing the configured lifetime never extends an existing claim.
 */
final class IdempotencyRegistrySchema
{
    /**
     * @return list<string>
     */
    public static function up(): array
    {
        return [
            /* language=PostgreSQL */
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS es_idempotency (
                    scope_hash text NOT NULL,
                    key_hash text NOT NULL,
                    fingerprint text NOT NULL,
                    message_id text NOT NULL,
                    expires_at timestamptz(6) NOT NULL,
                    CONSTRAINT es_idempotency_pk PRIMARY KEY (scope_hash, key_hash),
                    CONSTRAINT es_idempotency_scope_chk CHECK (scope_hash ~ '^[0-9a-f]{64}$'),
                    CONSTRAINT es_idempotency_key_chk CHECK (key_hash ~ '^[0-9a-f]{64}$'),
                    CONSTRAINT es_idempotency_fingerprint_chk CHECK (fingerprint ~ '^[0-9a-f]{64}$'),
                    CONSTRAINT es_idempotency_identity_chk CHECK (length(message_id) > 0)
                )
                SQL,
            'CREATE INDEX IF NOT EXISTS es_idempotency_expiration_idx ON es_idempotency USING brin (expires_at)',
        ];
    }

    /**
     * @return list<string>
     */
    public static function down(): array
    {
        return ['DROP TABLE IF EXISTS es_idempotency'];
    }
}
