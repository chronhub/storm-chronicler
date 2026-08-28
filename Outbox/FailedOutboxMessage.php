<?php

declare(strict_types=1);

namespace Storm\Chronicler\Outbox;

/**
 * One dead-lettered outbox row with `status = 'failed'`, as the operator needs to see it: its id and
 * global `position`, the event `type`, how many delivery `attempts` were spent, the `lastError` that
 * killed it and when it `failedAt`. A read projection of `es_outbox` for the `storm:outbox:failed`
 * view; the gateway decodes the row into this, the console only renders it.
 *
 * @see OutboxDeadLetter the producer
 */
final readonly class FailedOutboxMessage
{
    public function __construct(
        public int $id,
        public int $position,
        public string $type,
        public int $attempts,
        public ?string $lastError,
        public ?string $failedAt,
    ) {}
}
