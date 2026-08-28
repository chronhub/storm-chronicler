<?php

declare(strict_types=1);

namespace Storm\Chronicler\Directory;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Storm\Chronicler\Exception\StorageFailure;
use Storm\Stream\Exception\InvalidStreamException;
use Storm\Stream\StreamName;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use function Symfony\Component\String\u;

/**
 * Postgres StreamExistence on Doctrine DBAL, reading the two authoritative sides of the store.
 *
 * `hasStream` reads the stream-heads registry: one primary-key probe on a plain table, no
 * partition routing. `hasCategory` reads the event store itself, where `category` is the LIST
 * partition key: the probe prunes to the category's partition and stops at its first row. A
 * secondary existence query against `event_store` for an exact stream, where category and stream
 * ride the `(category, stream, version)` unique index, stays a fallback, unused here.
 *
 * Runs only statements on the injected connection and never opens a transaction. Every driver
 * failure is translated at this boundary into the port's contracted StorageFailure with the DBAL
 * exception kept as cause.
 */
#[AsAlias(StreamExistence::class)]
final readonly class DbalStreamExistence implements StreamExistence
{
    public function __construct(
        private Connection $connection,
    ) {}

    public function hasStream(StreamName $streamName): bool
    {
        try {
            return $this->connection->fetchOne(
                /** @lang PostgreSQL */
                'SELECT 1 FROM stream_heads WHERE stream = :stream',
                ['stream' => $streamName->toString()],
            ) !== false;
        } catch (Exception $e) {
            throw StorageFailure::wrap('the stream existence read', $e);
        }
    }

    public function hasCategory(string $category): bool
    {
        $category = u($category)->trim()->lower()->toString();

        if ($category === '' || ! preg_match(StreamName::CATEGORY_REGEX, $category)) {
            throw InvalidStreamException::invalidCategory($category);
        }

        try {
            return $this->connection->fetchOne(
                /** @lang PostgreSQL */
                'SELECT 1 FROM event_store WHERE category = :category LIMIT 1',
                ['category' => $category],
            ) !== false;
        } catch (Exception $e) {
            throw StorageFailure::wrap('the category existence read', $e);
        }
    }
}
