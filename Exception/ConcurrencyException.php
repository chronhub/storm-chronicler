<?php

declare(strict_types=1);

namespace Storm\Chronicler\Exception;

use RuntimeException;
use Storm\Contracts\Chronicler\ConcurrencyException as ConcurrencyExceptionContract;

/**
 * Optimistic-concurrency conflict while appending to a stream, the catchable umbrella. Abstract:
 * a conflict is always one of its two concrete causes, which diverge in application handling:
 *
 * - StaleVersion: the in-app pre-check, a stream-head CAS, found the stream ahead of the loaded
 *   version. A clean optimistic miss, so the command boundary retries by reload and reapply and
 *   progresses.
 *
 * - DuplicateVersion: the database's unique `(stream, version)` rejected the write. Either a true
 *   race that slipped past the pre-check, where retry helps, OR a redelivery of the same message
 *   replaying the same events, where retry is futile: it is already done, so treat it idempotently
 *   and ack; best handled upstream by dedup on message-id.
 *
 * Catch this base for the common conflict-then-bounded-retry path; catch a concrete type only when
 * the two must diverge.
 *
 * @see StaleVersion
 * @see DuplicateVersion
 */
abstract class ConcurrencyException extends RuntimeException implements ConcurrencyExceptionContract {}
