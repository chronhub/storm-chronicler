<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Outbox\OutboxRow;

final class OutboxRowTest extends TestCase
{
    #[Test]
    public function a_row_built_without_the_flag_is_not_vanished(): void
    {
        // the event a row names is in the store unless the claim's left join says otherwise:
        // defaulting to vanished would send every row built by hand down the gone-event path
        $this->assertFalse(new OutboxRow(1, 'order-1', 0, [])->vanished);
    }
}
