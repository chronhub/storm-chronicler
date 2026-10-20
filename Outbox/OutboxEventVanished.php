<?php

declare(strict_types=1);

namespace Storm\Chronicler\Outbox;

use RuntimeException;

/**
 * An outbox row whose event is no longer in the store.
 *
 * The row is a publication index and the store holds the payload, so a row that outlives its event has
 * nothing to publish and names the fault instead, dead-lettered so its partition keeps draining behind
 * it.
 */
final class OutboxEventVanished extends RuntimeException
{
    public static function at(string $partition, int|string $position): self
    {
        return new self(sprintf('The event at position %s of partition "%s" is no longer in the store; the outbox row outlived it', $position, $partition));
    }
}
