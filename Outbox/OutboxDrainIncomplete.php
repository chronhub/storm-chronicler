<?php

declare(strict_types=1);

namespace Storm\Chronicler\Outbox;

use RuntimeException;
use Throwable;

/**
 * A drain stopped by a transient publishing failure, thrown after the progress made so far committed.
 *
 * Rows published, dead letters and the failed row's bumped back-off are all durable. The relay fails
 * forward: signaling the outage must not hide the work that did commit, so this carries it. The
 * operator's log reads "relayed N, dead-lettered M, then stopped on: …" instead of a bare failure; on a
 * scheduled, at-least-once drain the committed counts are exactly what the primary monitoring surface
 * needs. The first transient failure is the cause, and the failed row's partition tail stays `pending`
 * for the next tick.
 *
 * @see OutboxRelay::drain()
 */
final class OutboxDrainIncomplete extends RuntimeException
{
    private function __construct(
        public readonly OutboxDrainResult $progress,
        Throwable $cause,
    ) {
        parent::__construct(
            sprintf(
                'Outbox drain stopped by a transient publish failure after relaying %d message(s) (%d dead-lettered%s): %s',
                $progress->published,
                $progress->failed,
                // A drain can stall a partition and hit an outage, on different partitions; whoever
                // reads only this message must not be left thinking the outage was the whole story,
                // since a stall is the half no retry clears.
                $progress->stalled === 0 ? '' : sprintf(', %d stalled on a runtime incompatibility', $progress->stalled),
                $cause->getMessage(),
            ),
            previous: $cause,
        );
    }

    public static function after(OutboxDrainResult $progress, Throwable $cause): self
    {
        return new self($progress, $cause);
    }
}
