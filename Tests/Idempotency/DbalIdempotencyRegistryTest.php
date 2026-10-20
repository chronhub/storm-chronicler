<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests\Idempotency;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ConnectionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Exception\IdempotencyConflict;
use Storm\Chronicler\Exception\InvalidIdempotencyClaim;
use Storm\Chronicler\Idempotency\DbalIdempotencyRegistry;
use Storm\Contracts\Chronicler\StorageFailure;
use Storm\Contracts\Message\MetaIdentityGenerator;
use Throwable;

final class DbalIdempotencyRegistryTest extends TestCase
{
    #[Test]
    #[DataProvider('invalidLifetimes')]
    public function invalid_lifetimes_fail_at_construction(int $seconds): void
    {
        $this->expectException(InvalidIdempotencyClaim::class);
        new DbalIdempotencyRegistry($this->createStub(Connection::class), $this->createStub(MetaIdentityGenerator::class), $seconds);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidLifetimes(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'above cap' => [31536001];
    }

    #[Test]
    #[DataProvider('extremeLifetimes')]
    public function the_extreme_lifetimes_are_accepted(int $seconds): void
    {
        // one second and one year are the bounds the guard exists to allow; read as `<=` or `>=`,
        // it would refuse them
        $registry = new DbalIdempotencyRegistry($this->createStub(Connection::class), $this->createStub(MetaIdentityGenerator::class), $seconds);

        $this->assertInstanceOf(DbalIdempotencyRegistry::class, $registry);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function extremeLifetimes(): iterable
    {
        yield 'one second' => [1];
        yield 'one year' => [31536000];
    }

    #[Test]
    public function the_claim_returns_the_identity_its_transaction_settled(): void
    {
        $this->assertSame('identity', $this->registryOver(static fn (): string => 'identity')->claim(...self::digests()));
    }

    #[Test]
    public function a_driver_failure_of_the_transaction_crosses_as_a_storage_failure(): void
    {
        $cause = new ConnectionException('server closed the connection');

        try {
            $this->registryOver(static fn (): never => throw $cause)->claim(...self::digests());
            $this->fail('A driver failure must cross the port as the contracted storage failure.');
        } catch (StorageFailure $failure) {
            $this->assertSame($cause, $failure->getPrevious());
        }
    }

    #[Test]
    #[DataProvider('registryRefusals')]
    public function a_registry_refusal_crosses_the_translation_unchanged(Throwable $refusal): void
    {
        $escaped = null;
        try {
            $this->registryOver(static fn (): never => throw $refusal)->claim(...self::digests());
        } catch (Throwable $thrown) {
            $escaped = $thrown;
        }

        $this->assertSame($refusal, $escaped);
    }

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function registryRefusals(): iterable
    {
        yield 'a conflicting fingerprint' => [new IdempotencyConflict('bound to another request')];
        yield 'a lost locked claim' => [new InvalidIdempotencyClaim('no locked claim returned')];
    }

    #[Test]
    #[DataProvider('malformedDigests')]
    public function a_malformed_digest_is_refused_before_the_transaction(string $scope, string $key, string $fingerprint): void
    {
        $this->expectException(InvalidIdempotencyClaim::class);
        $this->registryOver(static fn (): string => 'identity')->claim($scope, $key, $fingerprint);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function malformedDigests(): iterable
    {
        [$scope, $key, $fingerprint] = self::digests();

        yield 'the scope' => ['scope', $key, $fingerprint];
        yield 'the key' => [$scope, strtoupper($key), $fingerprint];
        yield 'the fingerprint' => [$scope, $key, substr($fingerprint, 1)];
    }

    #[Test]
    #[DataProvider('callerTransactions')]
    public function a_caller_transaction_is_refused_before_the_claim(bool $autoCommit, bool $transactionActive): void
    {
        $this->expectException(InvalidIdempotencyClaim::class);
        $this->registryOver(static fn (): string => 'identity', $autoCommit, $transactionActive)->claim(...self::digests());
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function callerTransactions(): iterable
    {
        yield 'autocommit disabled' => [false, false];
        yield 'an open transaction' => [true, true];
    }

    /**
     * A registry over a connection whose transaction runs `$transaction` in place of the claim.
     *
     * @param  Closure(): string  $transaction
     */
    private function registryOver(Closure $transaction, bool $autoCommit = true, bool $transactionActive = false): DbalIdempotencyRegistry
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('isAutoCommit')->willReturn($autoCommit);
        $connection->method('isTransactionActive')->willReturn($transactionActive);
        $connection->method('transactional')->willReturnCallback(static fn (): string => $transaction());

        return new DbalIdempotencyRegistry($connection, $this->createStub(MetaIdentityGenerator::class));
    }

    /**
     * @return array{string, string, string}
     */
    private static function digests(): array
    {
        return [hash('sha256', 'scope'), hash('sha256', 'key'), hash('sha256', 'body')];
    }
}
