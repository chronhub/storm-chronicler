<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests\Idempotency;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Exception\InvalidIdempotencyClaim;
use Storm\Chronicler\Idempotency\DbalIdempotencyRegistry;
use Storm\Contracts\Message\MetaIdentityGenerator;

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
}
