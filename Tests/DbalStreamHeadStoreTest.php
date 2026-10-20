<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\Chronicler\Directory\DbalStreamHeadStore;
use Storm\Chronicler\Exception\StorageFailure;

/**
 * The head store's translation duty at its own boundary.
 *
 * A driver failure of a statement never reaches a caller as a Doctrine type: it surfaces as the
 * port-owned `StorageFailure` with the original chained, under the operation label of the failing
 * method. Four of the store's five methods are pinned here as units, with the `Connection` mocked:
 *
 * - `advance()`
 * - `bump()`
 * - `lastVersion()`, which also reads its result back, an absent row mapping to version 0
 * - `delete()`
 *
 * `lockForErase()` is left to the erasure path that owns it. The integration
 * `StreamHeadStorageFailureTest` still proves the read crosses a real driver, which a mock cannot.
 */
final class DbalStreamHeadStoreTest extends TestCase
{
    #[Test]
    public function advance_translates_a_driver_failure_into_a_storage_failure_with_its_cause_chained(): void
    {
        $driverFailure = $this->driverFailure('deadlock on the head CAS');
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('executeStatement')
            // bound params, pinned
            ->with($this->anything(), ['stream' => 'order-1', 'newVersion' => 4, 'expectedVersion' => 3])
            ->willThrowException($driverFailure);

        try {
            new DbalStreamHeadStore($connection)->advance('order-1', 3, 4);
            $this->fail('a driver failure of the CAS must surface as StorageFailure');
        } catch (StorageFailure $e) {
            $this->assertSame($driverFailure, $e->getPrevious()); // the original DBAL exception is the cause
            $this->assertStringContainsString('the stream-head CAS', $e->getMessage()); // the operation label
            $this->assertStringContainsString('deadlock on the head CAS', $e->getMessage());
        }
    }

    #[Test]
    public function advance_loses_the_cas_when_the_update_matches_no_row(): void
    {
        // the OCC verdict itself: an expected version that no longer stands updates nothing, and
        // that empty result is the conflict, reported as false rather than mistaken for success
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('executeStatement')->willReturn(0);

        $this->assertFalse(new DbalStreamHeadStore($connection)->advance('order-1', 3, 4));
    }

    #[Test]
    public function advance_wins_the_cas_when_the_update_matches_its_row(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('executeStatement')->willReturn(1);

        $this->assertTrue(new DbalStreamHeadStore($connection)->advance('order-1', 3, 4));
    }

    #[Test]
    public function advance_creates_a_head_through_the_conflict_free_insert(): void
    {
        // `expectedVersion` 0 is the creation arm, and it must not be served by the `UPDATE`: an
        // update on a head that does not exist matches nothing and would report a conflict where the
        // caller is entitled to create. The statement tells the two arms apart, not the outcome
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('executeStatement')
            ->with(
                $this->stringContains('INSERT INTO stream_heads'),
                // no expectedVersion: the insert does not bind one
                ['stream' => 'order-1', 'newVersion' => 1],
            )
            ->willReturn(1);

        $this->assertTrue(new DbalStreamHeadStore($connection)->advance('order-1', 0, 1));
    }

    #[Test]
    public function advance_refuses_to_create_a_head_that_already_exists(): void
    {
        // the creation arm keeps the guard too: an existing head makes the conflict-free insert a
        // no-op, and a no-op is a lost CAS, never a silent overwrite of a live history's base
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('executeStatement')->willReturn(0);

        $this->assertFalse(new DbalStreamHeadStore($connection)->advance('order-1', 0, 1));
    }

    #[Test]
    public function bump_translates_a_driver_failure_into_a_storage_failure_with_its_cause_chained(): void
    {
        $driverFailure = $this->driverFailure('the wire dropped mid-bump');
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('fetchOne')
            ->with($this->anything(), ['stream' => 'order-1', 'count' => 2]) // bound params, pinned
            ->willThrowException($driverFailure);

        try {
            new DbalStreamHeadStore($connection)->bump('order-1', 2);
            $this->fail('a driver failure of the bump must surface as StorageFailure');
        } catch (StorageFailure $e) {
            $this->assertSame($driverFailure, $e->getPrevious()); // the original DBAL exception is the cause
            // the operation label
            $this->assertStringContainsString('the stream-head bump', $e->getMessage());
            $this->assertStringContainsString('the wire dropped mid-bump', $e->getMessage());
        }
    }

    #[Test]
    public function last_version_translates_a_driver_failure_into_a_storage_failure_with_its_cause_chained(): void
    {
        $driverFailure = $this->driverFailure('the head table vanished mid-read');
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('fetchOne')
            ->with($this->anything(), ['stream' => 'order-1']) // the bound param, pinned
            ->willThrowException($driverFailure);

        try {
            new DbalStreamHeadStore($connection)->lastVersion('order-1');
            $this->fail('a driver failure of the read must surface as StorageFailure');
        } catch (StorageFailure $e) {
            $this->assertSame($driverFailure, $e->getPrevious()); // the original DBAL exception is the cause
            // the operation label
            $this->assertStringContainsString('the stream-head read', $e->getMessage());
            $this->assertStringContainsString('the head table vanished mid-read', $e->getMessage());
        }
    }

    #[Test]
    public function last_version_reads_back_the_stored_version(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('fetchOne')
            ->with($this->anything(), ['stream' => 'order-1'])
            ->willReturn('5'); // pdo_pgsql hands the column back as a string; the store casts it to int

        $this->assertSame(5, new DbalStreamHeadStore($connection)->lastVersion('order-1'));
    }

    #[Test]
    public function last_version_reports_zero_for_a_stream_with_no_head_row(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('fetchOne')
            ->with($this->anything(), ['stream' => 'never-seen'])
            ->willReturn(false); // fetchOne returns false when no row matches

        $this->assertSame(0, new DbalStreamHeadStore($connection)->lastVersion('never-seen'));
    }

    #[Test]
    public function delete_translates_a_driver_failure_into_a_storage_failure_with_its_cause_chained(): void
    {
        $driverFailure = $this->driverFailure('the wire dropped mid-delete');
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('executeStatement')
            ->with($this->anything(), ['stream' => 'order-1']) // the bound param, pinned
            ->willThrowException($driverFailure);

        try {
            new DbalStreamHeadStore($connection)->delete('order-1');
            $this->fail('a driver failure of the deletion must surface as StorageFailure');
        } catch (StorageFailure $e) {
            $this->assertSame($driverFailure, $e->getPrevious()); // the original DBAL exception is the cause
            // the operation label
            $this->assertStringContainsString('the stream-head deletion', $e->getMessage());
            $this->assertStringContainsString('the wire dropped mid-delete', $e->getMessage());
        }
    }

    #[Test]
    public function delete_reports_whether_a_head_row_was_actually_removed(): void
    {
        // the eraser reads this verdict to tell a stream it wiped from one that was never there
        $absent = $this->createMock(Connection::class);
        $absent->expects($this->once())->method('executeStatement')->willReturn(0);

        $present = $this->createMock(Connection::class);
        $present->expects($this->once())->method('executeStatement')->willReturn(1);

        $this->assertFalse(new DbalStreamHeadStore($absent)->delete('never-seen'));
        $this->assertTrue(new DbalStreamHeadStore($present)->delete('order-1'));
    }

    private function driverFailure(string $message): DbalException&RuntimeException
    {
        return new class($message) extends RuntimeException implements DbalException {};
    }
}
