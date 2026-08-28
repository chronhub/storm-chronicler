<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Evolution\IdentityEventTypeMapper;
use Storm\Chronicler\Exception\UnknownEventType;
use Storm\Chronicler\Tests\Fixture\SomethingHappened;

final class IdentityEventTypeMapperTest extends TestCase
{
    #[Test]
    public function maps_a_class_to_its_fqcn_as_type(): void
    {
        $mapper = new IdentityEventTypeMapper;

        $this->assertSame(SomethingHappened::class, $mapper->toType(SomethingHappened::class));
    }

    #[Test]
    public function maps_a_type_back_to_the_same_fqcn(): void
    {
        $mapper = new IdentityEventTypeMapper;

        $this->assertSame(SomethingHappened::class, $mapper->toClass(SomethingHappened::class));
    }

    #[Test]
    public function a_type_that_is_not_a_loadable_class_is_refused_not_passed_through(): void
    {
        // the port's promise, `@throws UnknownEventType`, holds for the identity fallback too: a
        // stored alias read through the fallback wiring is a wiring error, not "its own FQCN",
        // the negative mirror of MappedEventTypeMapper's own resolution test
        $this->expectException(UnknownEventType::class);

        new IdentityEventTypeMapper()->toClass('not.registered.and.not.a.class');
    }
}
