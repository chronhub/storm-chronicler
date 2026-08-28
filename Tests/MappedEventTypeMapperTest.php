<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Evolution\MappedEventTypeMapper;
use Storm\Chronicler\Exception\DuplicateEventAlias;
use Storm\Chronicler\Exception\UnknownEventType;
use Storm\Chronicler\Tests\Fixture\AliasedEvent;
use Storm\Chronicler\Tests\Fixture\DuplicateAliasEvent;
use Storm\Chronicler\Tests\Fixture\PreAliasEvent;
use Storm\Chronicler\Tests\Fixture\RenamedEvent;
use Storm\Chronicler\Tests\Fixture\SomethingHappened;

final class MappedEventTypeMapperTest extends TestCase
{
    #[Test]
    public function to_type_returns_the_canonical_alias_for_an_attributed_class(): void
    {
        $mapper = new MappedEventTypeMapper([AliasedEvent::class]);

        $this->assertSame('test.aliased', $mapper->toType(AliasedEvent::class));
    }

    #[Test]
    public function to_type_falls_back_to_the_fqcn_without_the_attribute(): void
    {
        // reflection-based, so correct even without scanning the class
        $mapper = new MappedEventTypeMapper;

        $this->assertSame(SomethingHappened::class, $mapper->toType(SomethingHappened::class));
    }

    #[Test]
    public function to_class_resolves_the_canonical_alias(): void
    {
        $mapper = new MappedEventTypeMapper([AliasedEvent::class]);

        $this->assertSame(AliasedEvent::class, $mapper->toClass('test.aliased'));
    }

    #[Test]
    public function to_class_resolves_a_replaced_legacy_alias_and_the_canonical(): void
    {
        $mapper = new MappedEventTypeMapper([RenamedEvent::class]);

        $this->assertSame(RenamedEvent::class, $mapper->toClass('test.old'));      // legacy
        $this->assertSame(RenamedEvent::class, $mapper->toClass('test.renamed'));  // canonical
    }

    #[Test]
    public function to_class_falls_back_to_a_loadable_fqcn(): void
    {
        $mapper = new MappedEventTypeMapper;

        $this->assertSame(SomethingHappened::class, $mapper->toClass(SomethingHappened::class));
    }

    #[Test]
    public function to_class_throws_on_an_unresolvable_type(): void
    {
        $mapper = new MappedEventTypeMapper;

        $this->expectException(UnknownEventType::class);

        $mapper->toClass('not.registered.and.not.a.class');
    }

    #[Test]
    public function the_constructor_rejects_a_duplicate_alias(): void
    {
        $this->expectException(DuplicateEventAlias::class);

        new MappedEventTypeMapper([AliasedEvent::class, DuplicateAliasEvent::class]);
    }

    #[Test]
    public function stored_types_of_returns_just_the_fqcn_without_the_attribute(): void
    {
        $mapper = new MappedEventTypeMapper;

        $this->assertSame([SomethingHappened::class], $mapper->storedTypesOf(SomethingHappened::class));
    }

    #[Test]
    public function a_class_that_no_longer_exists_resolves_to_the_string_itself(): void
    {
        // a query may name an event class since deleted; reflection cannot load it, so the memoized
        // lookup answers null, no attribute to read, and the stored type is the bare string, no crash.
        $mapper = new MappedEventTypeMapper;

        $this->assertSame('App\\DeletedEvent', $mapper->toType('App\\DeletedEvent'));
        $this->assertSame(['App\\DeletedEvent'], $mapper->storedTypesOf('App\\DeletedEvent'));
        $this->assertSame(1, $mapper->versionOf('App\\DeletedEvent'));
    }

    #[Test]
    public function stored_types_of_returns_the_alias_and_the_fqcn(): void
    {
        $mapper = new MappedEventTypeMapper([AliasedEvent::class]);

        // both forms a row may have been stored as: the alias, and the FQCN for pre-alias rows
        $this->assertSame(['test.aliased', AliasedEvent::class], $mapper->storedTypesOf(AliasedEvent::class));
    }

    #[Test]
    public function stored_types_of_includes_replaced_aliases(): void
    {
        $mapper = new MappedEventTypeMapper([RenamedEvent::class]);

        $this->assertSame(
            ['test.renamed', 'test.old', RenamedEvent::class],
            $mapper->storedTypesOf(RenamedEvent::class),
        );
    }

    #[Test]
    public function stored_types_of_names_an_overlapping_source_once(): void
    {
        // The FQCN is the one source that can repeat: the attribute refuses a self-replacing alias
        // and a duplicated replacement, but it never sees the class it decorates, so an author who
        // lists the type the pre-alias rows carry names what the mapper appends anyway. The result
        // feeds an IN clause, which a repeat would widen for nothing.
        $mapper = new MappedEventTypeMapper([PreAliasEvent::class]);

        $this->assertSame(
            ['test.pre_alias', PreAliasEvent::class],
            $mapper->storedTypesOf(PreAliasEvent::class),
        );
    }
}
