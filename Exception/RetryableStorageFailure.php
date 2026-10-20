<?php

declare(strict_types=1);

namespace Storm\Chronicler\Exception;

use Doctrine\DBAL\Exception\RetryableException;

/**
 * A `StorageFailure` whose driver cause was transient, such as a deadlock or a lock timeout.
 *
 * It carries Doctrine's retry marker through the translation, so `RetryEventStore`, which retries on
 * `RetryableException`, retries a wrapped transient failure exactly as it retried the raw one. Without
 * the marker, translating at the port boundary would silently disarm the retry path.
 *
 * Built by {@see StorageFailure::wrap()}; never construct it for a non-retryable cause.
 */
final class RetryableStorageFailure extends StorageFailure implements RetryableException {}
