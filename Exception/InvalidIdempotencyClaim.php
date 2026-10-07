<?php

declare(strict_types=1);

namespace Storm\Chronicler\Exception;

use LogicException;
use Storm\Contracts\Chronicler\InvalidIdempotencyClaim as InvalidIdempotencyClaimContract;

/**
 * Refuses a claim that cannot satisfy the registry boundary.
 */
final class InvalidIdempotencyClaim extends LogicException implements InvalidIdempotencyClaimContract {}
