<?php

declare(strict_types=1);

namespace Storm\Chronicler\Directory;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use Storm\Chronicler\Exception\StorageFailure;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Postgres StreamDirectory on Doctrine DBAL: a windowed SELECT over `stream_heads`, name-ordered,
 * resumable by the strict `stream > :after` lower bound.
 *
 * The category narrow matches the bare category stream OR the `category-` prefix, with LIKE
 * metacharacters escaped; a category may legitimately contain `_`, which would otherwise match
 * any character and leak a sibling lane into the browse.
 */
#[AsAlias(StreamDirectory::class)]
final readonly class DbalStreamDirectory implements StreamDirectory
{
    public function __construct(
        private Connection $connection,
    ) {}

    public function browse(?string $category, string $afterStream, int $limit): array
    {
        $sql = 'SELECT stream, last_version FROM stream_heads WHERE stream > :after';
        $params = ['after' => $afterStream, 'limit' => $limit];
        $types = ['limit' => ParameterType::INTEGER];

        if ($category !== null) {
            $escaped = addcslashes($category, '\\%_');
            $sql .= " AND (stream = :category OR stream LIKE :prefix ESCAPE '\\')";
            $params['category'] = $category;
            $params['prefix'] = $escaped.'-%';
        }

        $sql .= ' ORDER BY stream LIMIT :limit';

        try {
            /** @var list<array{stream: string, last_version: int|string}> $rows */
            $rows = $this->connection->fetchAllAssociative(/** @lang PostgreSQL */ $sql, $params, $types);
        } catch (Exception $e) {
            throw StorageFailure::wrap('the stream-directory browse', $e);
        }

        return array_map(
            static fn (array $row): StreamHead => new StreamHead($row['stream'], (int) $row['last_version']),
            $rows,
        );
    }
}
