<?php

declare(strict_types=1);

namespace Storm\Chronicler\Exception;

use Doctrine\DBAL\Exception\RetryableException;
use RuntimeException;
use Storm\Contracts\Chronicler\StorageFailure as StorageFailureContract;
use Throwable;

/**
 * A storage operation failed for an infrastructural reason: the adapter-boundary translation of a
 * driver exception, Doctrine DBAL here, the driver's exception kept as the `getPrevious()` cause.
 * Implements the contracted `StorageFailureContract` on an SPL `RuntimeException`, so ports never
 * surface a driver type.
 *
 * Translation rule at the adapters: a driver failure carrying Doctrine's retry marker, a
 * `RetryableException` such as a deadlock or lock timeout, becomes a `RetryableStorageFailure` so
 * the retry decorator keeps matching it; everything else becomes this class. Use `wrap()`, which
 * picks the right one.
 */
class StorageFailure extends RuntimeException implements StorageFailureContract
{
    final protected function __construct(string $message, Throwable $cause)
    {
        parent::__construct($message, previous: $cause);
    }

    /**
     * Translate a driver failure at the port boundary, preserving retryability: a Doctrine
     * `RetryableException` cause yields a {@see RetryableStorageFailure}.
     */
    public static function wrap(string $operation, Throwable $cause): self
    {
        $message = sprintf('Storage failure during %s: %s', $operation, $cause->getMessage());

        return $cause instanceof RetryableException
            ? new RetryableStorageFailure($message, $cause)
            : new self($message, $cause);
    }
}
