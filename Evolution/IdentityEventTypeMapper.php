<?php

declare(strict_types=1);

namespace Storm\Chronicler\Evolution;

use Storm\Chronicler\Exception\UnknownEventType;
use Storm\Contracts\Chronicler\EventTypeMapper;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * A trivial EventTypeMapper: the stored type IS the FQCN, and the version is always 1, so no
 * aliasing and no versioning. The lightweight default, used directly in tests and the fallback
 * alias; the bundle's compiler pass overrides the EventTypeMapper alias with the
 * MappedEventTypeMapper, driven by `#[EventType]` when an app declares event paths.
 *
 * Identity does not mean unchecked: a stored type that is not a loadable class cannot be "its own
 * FQCN", so `toClass()` keeps the port's promise and fails fast with the contracted
 * UnknownEventType; usually an aliased store read through the fallback wiring.
 *
 * @see MappedEventTypeMapper
 */
#[AsAlias(EventTypeMapper::class)]
final class IdentityEventTypeMapper implements EventTypeMapper
{
    public function toType(string $class): string
    {
        return $class;
    }

    public function toClass(string $type): string
    {
        if (! class_exists($type)) {
            throw UnknownEventType::cannotResolve($type);
        }

        return $type;
    }

    public function versionOf(string $class): int
    {
        return 1;
    }

    public function storedTypesOf(string $class): array
    {
        return [$class];
    }
}
