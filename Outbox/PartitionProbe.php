<?php

declare(strict_types=1);

namespace Storm\Chronicler\Outbox;

/**
 * Ordering evidence from one partition's pending prefix at or below its highest claimed id.
 *
 * The drain checks ownership of the head and holes in the claimed window using these answers:
 * - The lowest pending id
 * - The lowest cooling id
 * - The due count
 *
 * Rows beyond the prefix cannot block a claimed row and do not contribute to the probe.
 */
final readonly class PartitionProbe
{
    public function __construct(
        /** The lowest pending id in the prefix, or null when the prefix holds no pending row. */
        public ?int $head,
        /** The lowest pending id in the prefix that is NOT due yet, or null; it blocks publication at or above it. */
        public ?int $coolingFloor,
        /** Due pending rows at or below the highest claimed id; fewer in the claim means a hole. */
        public int $dueInWindow,
    ) {}

    /**
     * Whether the claimed head is genuinely the partition's head; an older pending row belongs to
     * someone else's batch, or to the next tick, and order defers to it.
     */
    public function headIsOurs(int $firstClaimedId): bool
    {
        return $this->head === null || $this->head >= $firstClaimedId;
    }

    /**
     * Whether the partition's real head sits COOLING: the due-filter kept it out of the claim, no
     * sibling holds it, so the refusal is cold and the tail realigns onto it.
     */
    public function headIsCooling(): bool
    {
        return $this->head !== null && $this->coolingFloor === $this->head;
    }
}
