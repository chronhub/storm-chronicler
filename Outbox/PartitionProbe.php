<?php

declare(strict_types=1);

namespace Storm\Chronicler\Outbox;

/**
 * One partition's three answers, read in one statement over its pending rows: the head, the
 * cooling floor, and the due count inside the claimed window. Owning a partition is neither
 * owning its head nor seeing its holes; the drain judges both against this probe.
 */
final readonly class PartitionProbe
{
    public function __construct(
        /** The lowest pending id, or null when the partition holds no pending row. */
        public ?int $head,
        /** The lowest pending id that is NOT due yet, or null; nothing may publish at or above it. */
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
