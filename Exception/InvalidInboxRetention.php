<?php

declare(strict_types=1);

namespace Storm\Chronicler\Exception;

use InvalidArgumentException;
use Storm\Contracts\Chronicler\ChroniclerExceptionContract;

/**
 * A retention age would put the inbox cutoff before the Unix epoch or after the database clock.
 *
 * Refused before counting candidates or deleting any deduplication proof.
 */
final class InvalidInboxRetention extends InvalidArgumentException implements ChroniclerExceptionContract
{
    public static function forAge(int $ageSeconds): self
    {
        return new self(sprintf(
            'Inbox retention must be non-negative and must not precede the Unix epoch; got %d seconds.',
            $ageSeconds,
        ));
    }
}
