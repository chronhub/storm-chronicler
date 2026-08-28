<?php

declare(strict_types=1);

namespace Storm\Chronicler\Outbox;

/**
 * The outcome of one OutboxRelay::drain() run.
 *
 * `published` rows were relayed and disposed of, deleted or moved to the archive; nothing ever marks
 * a row `published`, disposal is the terminal success. `failed` rows were dead-lettered with
 * `status = 'failed'`, either a permanent decode or deserialize error or a publication that kept
 * failing past the relay's max attempts. A `failed` count is handled, the rows parked not lost, so the
 * run still succeeds. A transient publication failure that has not yet reached the cap is not counted
 * here; the relay re-throws it so the caller retries the tail next run.
 *
 * `stalled` is the third disposition, the one neither of the two above fits: rows THIS runtime cannot
 * decode though a corrected one could, a missing upcaster or a row written by a newer binary. They
 * stay `pending`, with their partition held, so nothing is lost and nothing needs a requeue, but their
 * egress is stopped until a deployment changes. `stalledCause` carries the first such failure's audit
 * digest, since a count alone would not tell an operator WHICH schema step is missing; every stalled
 * row also keeps its own digest durably in `last_error`. `stalledPartitions` names the partitions,
 * that is the aggregate streams, whose egress the stalls froze this run, distinct and in first-seen
 * order, so the alert says WHOSE egress stopped without a query on `es_outbox`.
 *
 * No parameter defaults: each field is a claim about what the run did, and the safe value of a
 * forgotten claim is not zero. A caller building this must say what happened.
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
         * `0 published` was already the honest end-of-drain signal, and a FULL batch was
         * indistinguishable from it: the runbook that says drain before a topology change would read a
         * green line, swap the code, and leave the rest of the queue flying with yesterday's serialized
         * state. Read from the claim's own SIZE rather than probed: over-reading one row would lock it
         * under `FOR UPDATE` and withhold it from another worker, and a probe inside the drain cannot
         * see past the run's own locks.
         *
         * So a full batch says there is more, and a SHORT one says only that the cap did not cut this
         * run. What is left over but not due is the other half of the question, and it is answered
         * away from here, by {@see OutboxRelay::countPending()}.
         */
        public bool $moreDue = false,
    ) {}
}
