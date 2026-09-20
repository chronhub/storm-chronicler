<?php

declare(strict_types=1);

namespace Storm\Chronicler\Outbox;

/**
 * One claimed outbox row, typed where the drain reasons about it, raw where the reader needs it:
 * `id`, `partition` and `attempts` drive ordering, fencing and the retry budget, while `raw` is
 * the row exactly as claimed, the shape `StoredEventReader::toMessage()` decodes.
 */
final readonly class OutboxRow
{
    /**
     * @param  array<string, mixed>  $raw
     * @param  bool  $vanished  whether the event the row names is gone from the store, read by the claim's left join
     */
    public function __construct(
        public int $id,
        public string $partition,
        public int $attempts,
        public array $raw,
        public bool $vanished = false,
    ) {}

    /**
     * @param  array<string, mixed>  $raw  a row of the drain's claim, keys as selected
     */
    public static function of(array $raw): self
    {
        return new self((int) $raw['id'], (string) $raw['partition_key'], (int) $raw['attempts'], $raw, (int) ($raw['vanished'] ?? 0) === 1);
    }
}
