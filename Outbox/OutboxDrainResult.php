<?php

declare(strict_types=1);

namespace Storm\Chronicler\Outbox;

/**
 * The outcome of one `OutboxRelay::drain()` run.
 *
 * - `published` rows were relayed and disposed of, deleted or moved to the archive; nothing ever marks
 *   a row `published`, disposal being the terminal success.
 *
 * - `failed` rows were dead-lettered with `status = 'failed'`, after a permanent decode or deserialize
 *   error or a publication that kept failing past the relay's max attempts. A `failed` count is handled,
 *   the rows parked and not lost, so the run still succeeds.
 *
 * - `stalled` is the third disposition, which neither of the other two fits: rows this runtime cannot
 *   decode though a corrected one could, a missing upcaster or a row written by a newer binary. They
 *   stay `pending` with their partition held, so nothing is lost and nothing needs a requeue, but their
 *   egress stops until a deployment changes.
 *
 * A transient publication failure that has not yet reached the cap is not counted here; the relay
 * rethrows it so the caller retries the tail next run.
 *
 * `stalledCause` carries the first stalled failure's audit digest, since a count alone would not tell an
 * operator which schema step is missing; every stalled row also keeps its own digest durably in
 * `last_error`. `stalledPartitions` names the partitions, the aggregate streams, whose egress the stalls
 * froze this run, distinct and in first-seen order, so the alert says whose egress stopped without a
 * query on `es_outbox`.
 *
 * Only `moreDue` has a default: every other field is a claim about what the run did, and the safe value
 * of a forgotten claim is not zero. A caller building this must say what happened.
 *
 * @see OutboxRelay::drain()
 */
final readonly class OutboxDrainResult
{
    /**
     * @param  list<string>  $stalledPartitions
     */
    public function __construct(
        public int $published,
        public int $failed,
        public int $stalled,
        public ?string $stalledCause,
        public array $stalledPartitions,
        /**
         * Whether the batch cap cut the drain short, so due work is still waiting.
         *
         * A published count alone cannot say whether the cap cut the run: a full batch reads like a
         * drain that happened to end there, so the runbook that says drain before a topology change
         * would read a green line, swap the code, and leave the rest of the queue in flight with
         * yesterday's serialized state. The flag is read from the claim's own size rather than probed:
         * over-reading one row would lock it under `FOR UPDATE` and withhold it from another worker, and
         * a probe inside the drain cannot see past the run's own locks.
         *
         * So a full batch says there is more, and a short one says only that the cap did not cut this
         * run. What is left over but not due is the other half of the question, and it is answered
         * away from here, by {@see OutboxRelay::countPending()}.
         */
        public bool $moreDue = false,
    ) {}
}
