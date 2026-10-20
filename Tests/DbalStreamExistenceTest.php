<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\Chronicler\Directory\DbalStreamExistence;
use Storm\Chronicler\Exception\StorageFailure;
use Storm\Stream\Exception\InvalidStreamException;
use Storm\Stream\StreamCategory;
use Storm\Stream\StreamName;

/**
 * The existence port's duties at its own boundary, with the `Connection` mocked:
 *
 * - The row-probe result maps to the bool, an absent row to false
 *
 * - A malformed category is rejected before any statement runs
 *
 * - A driver failure never reaches a caller as a Doctrine type: it surfaces as the port-owned
 *   `StorageFailure` with the original chained, under the operation label of the failing method
 *
 * The integration `StreamExistenceTest` proves the answers against a real store, which a mock cannot.
 */
final class DbalStreamExistenceTest extends TestCase
{
    #[Test]
    public function has_stream_answers_true_when_the_registry_holds_the_row(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('fetchOne')
            ->with($this->anything(), ['stream' => 'order-1']) // the bound param, pinned
            ->willReturn(1);

        $this->assertTrue(new DbalStreamExistence($connection)->hasStream(new StreamName('order-1')));
    }

    #[Test]
    public function has_stream_answers_false_when_the_registry_has_no_row(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('fetchOne')->willReturn(false);

        $this->assertFalse(new DbalStreamExistence($connection)->hasStream(new StreamName('order-1')));
    }

    #[Test]
    public function has_stream_translates_a_driver_failure_into_a_storage_failure_with_its_cause_chained(): void
    {
        $driverFailure = $this->driverFailure('the head registry vanished mid-probe');
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('fetchOne')->willThrowException($driverFailure);

        try {
            new DbalStreamExistence($connection)->hasStream(new StreamName('order-1'));
            $this->fail('a driver failure of the probe must surface as StorageFailure');
        } catch (StorageFailure $e) {
            $this->assertSame($driverFailure, $e->getPrevious()); // the original DBAL exception is the cause
            // the operation label
            $this->assertStringContainsString('the stream existence read', $e->getMessage());
            $this->assertStringContainsString('the head registry vanished mid-probe', $e->getMessage());
        }
    }

    #[Test]
    public function has_category_normalizes_the_category_before_binding_it(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('fetchOne')
            ->with($this->anything(), ['category' => 'order']) // trimmed + lowercased, pinned
            ->willReturn(1);

        $this->assertTrue(new DbalStreamExistence($connection)->hasCategory(new StreamCategory(' ORDER ')));
    }

    #[Test]
    public function has_category_answers_false_when_the_category_holds_no_stream(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('fetchOne')->willReturn(false);

        $this->assertFalse(new DbalStreamExistence($connection)->hasCategory(new StreamCategory('order')));
    }

    #[Test]
    public function has_category_rejects_a_qualified_stream_name_before_touching_the_connection(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('fetchOne');

        $this->expectException(InvalidStreamException::class);

        // a full stream name is not a category
        new DbalStreamExistence($connection)->hasCategory(new StreamCategory('order-42'));
    }

    #[Test]
    public function has_category_rejects_a_malformed_category_before_touching_the_connection(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('fetchOne');

        $this->expectException(InvalidStreamException::class);

        // must start with a letter
        new DbalStreamExistence($connection)->hasCategory(new StreamCategory('9order'));
    }

    #[Test]
    public function has_category_translates_a_driver_failure_into_a_storage_failure_with_its_cause_chained(): void
    {
        $driverFailure = $this->driverFailure('the store vanished mid-probe');
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('fetchOne')->willThrowException($driverFailure);

        try {
            new DbalStreamExistence($connection)->hasCategory(new StreamCategory('order'));
            $this->fail('a driver failure of the probe must surface as StorageFailure');
        } catch (StorageFailure $e) {
            $this->assertSame($driverFailure, $e->getPrevious()); // the original DBAL exception is the cause
            // the operation label
            $this->assertStringContainsString('the category existence read', $e->getMessage());
            $this->assertStringContainsString('the store vanished mid-probe', $e->getMessage());
        }
    }

    private function driverFailure(string $message): DbalException&RuntimeException
    {
        return new class($message) extends RuntimeException implements DbalException {};
    }
}
