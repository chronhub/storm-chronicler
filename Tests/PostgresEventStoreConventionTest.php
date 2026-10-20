<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Storm\Chronicler\SafeHead\SafeHeadAdvancer;
use Storm\Chronicler\Store\PostgresEventStore;

use function array_find;

/**
 * `PostgresEventStore` receives its `SafeHeadAdvancer` from whoever builds it.
 *
 * The advancer carries the configured grace, so a store left to build its own would compute its safe
 * head under a grace the deployment never set.
 */
final class PostgresEventStoreConventionTest extends TestCase
{
    #[Test]
    public function the_safe_head_advancer_has_no_default(): void
    {
        $parameter = array_find(
            new ReflectionMethod(PostgresEventStore::class, '__construct')->getParameters(),
            static fn (ReflectionParameter $candidate): bool => $candidate->getType() instanceof ReflectionNamedType
                && $candidate->getType()->getName() === SafeHeadAdvancer::class,
        );

        $this->assertInstanceOf(ReflectionParameter::class, $parameter, 'the constructor takes a SafeHeadAdvancer');
        $this->assertFalse($parameter->isOptional(), 'the SafeHeadAdvancer parameter must have no default');
        $this->assertFalse($parameter->allowsNull(), 'the SafeHeadAdvancer parameter must not be nullable');
    }
}
