<?php

declare(strict_types=1);

namespace Storm\Chronicler\Exception;

use Doctrine\DBAL\Exception\RetryableException;
use RuntimeException;
use Storm\Contracts\Chronicler\StorageFailure as StorageFailureContract;
use Throwable;

/**
 * The adapter-boundary translation of a Doctrine DBAL driver exception.
 *
 * The contracted `StorageFailureContract` sits on an SPL `RuntimeException`, so ports never surface a
 * driver type.
 *
 * At the adapters, a driver failure that carries Doctrine's retry marker, a `RetryableException` such as
 * a deadlock or a lock timeout, becomes a `RetryableStorageFailure` so the retry decorator keeps
 * matching it; every other failure becomes this class. Use `wrap()`, which picks the right one.
 */
class StorageFailure extends RuntimeException implements StorageFailureContract
{
    final protected function __construct(string $message, Throwable $cause)
    {
        parent::__construct($message, previous: $cause);
    }

    public static function wrap(string $operation, Throwable $cause): self
    {
        $message = sprintf('Storage failure during %s: %s', $operation, $cause->getMessage());

        return $cause instanceof RetryableException
            ? new RetryableStorageFailure($message, $cause)
            : new self($message, $cause);
    }
}
