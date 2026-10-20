<?php

declare(strict_types=1);

namespace Storm\Chronicler\Exception;

use RuntimeException;
use Storm\Contracts\Chronicler\ConcurrencyException as ConcurrencyExceptionContract;

/**
 * The optimistic-concurrency conflict this store raises on append.
 *
 * It is abstract because a conflict is always one of two concrete causes, handled differently:
 *
 * - `StaleVersion`: the in-app pre-check, a stream-head CAS, found the stream ahead of the loaded
 *   version. It is a clean optimistic miss, which a reload and a reapply get past.
 *
 * - `DuplicateVersion`: the database's unique `(stream, version)` rejected the write.
 *
 * @see StaleVersion
 * @see DuplicateVersion
 */
abstract class ConcurrencyException extends RuntimeException implements ConcurrencyExceptionContract {}
