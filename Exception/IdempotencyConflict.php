<?php

declare(strict_types=1);

namespace Storm\Chronicler\Exception;

use RuntimeException;
use Storm\Contracts\Chronicler\IdempotencyConflict as IdempotencyConflictContract;

/**
 * Rejects reuse of a live key without exposing its caller, key or fingerprint.
 */
final class IdempotencyConflict extends RuntimeException implements IdempotencyConflictContract {}
