<?php

declare(strict_types=1);

namespace Storm\Chronicler\Outbox;

/**
 * What the claim handed the drain, per partition: how many rows, and up to which id. The
 * partition probe's window-check compares the partition's due pending rows against these, derived
 * from the claim itself: no extra query, no extra locks.
 */
final readonly class ClaimSummary
{
    /**
     * @param  array<string, int>  $count
     * @param  array<string, int>  $max
     */
    private function __construct(
        private array $count,
        private array $max,
    ) {}

    /**
     * @param  list<OutboxRow>  $rows  the claim, in id order, so the last id seen per partition is its max
     */
    public static function of(array $rows): self
    {
        $count = [];
        $max = [];

        foreach ($rows as $row) {
            $count[$row->partition] = ($count[$row->partition] ?? 0) + 1;
            $max[$row->partition] = $row->id; // the claim is ORDER BY id: last seen = max
        }

        return new self($count, $max);
    }

    /**
     * @return list<string> the claimed partitions in first-seen order, the order the claim walks
     */
    public function partitions(): array
    {
        // PHP stores a key like `42` as an integer, so the keys go back to the strings the rows carry
        return array_map(strval(...), array_keys($this->count));
    }

    public function countFor(string $partition): int
    {
        return $this->count[$partition] ?? 0;
    }

    public function maxFor(string $partition): int
    {
        return $this->max[$partition] ?? 0;
    }
}
