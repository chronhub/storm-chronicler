<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Outbox\ClaimSummary;
use Storm\Chronicler\Outbox\OutboxRow;

/**
 * The claim's partitions come back as the strings the rows carry, whatever they look like.
 *
 * A key PHP reads as an integer must not reach a string parameter as one.
 */
final class ClaimSummaryTest extends TestCase
{
    #[Test]
    #[Group('adversarial')]
    public function a_partition_that_reads_as_an_integer_comes_back_as_its_string(): void
    {
        $summary = ClaimSummary::of([
            new OutboxRow(1, '42', 0, []),
            new OutboxRow(2, 'order-1', 0, []),
            new OutboxRow(3, '42', 0, []),
        ]);

        $this->assertSame(['42', 'order-1'], $summary->partitions());
        $this->assertSame(2, $summary->countFor('42'));
        $this->assertSame(3, $summary->maxFor('42'));
    }

    #[Test]
    public function a_partition_the_claim_never_saw_reads_zero(): void
    {
        // no row claimed, so no count and no highest id: both read zero, never one off it
        $summary = ClaimSummary::of([new OutboxRow(1, 'order-1', 0, [])]);

        $this->assertSame(0, $summary->countFor('order-2'));
        $this->assertSame(0, $summary->maxFor('order-2'));
    }
}
