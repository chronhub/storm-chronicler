<?php

declare(strict_types=1);

namespace Storm\Chronicler\Evolution;

use ReflectionClass;
use Storm\Chronicler\Exception\DuplicateEventAlias;
use Storm\Chronicler\Exception\UnknownEventType;
use Storm\Contracts\Chronicler\EventTypeMapper;
use Storm\Message\EventType;

/**
 * EventTypeMapper backed by the `#[EventType]` attributes: the stored type is a stable,
 * dev-chosen alias decoupled from the FQCN.
 *
 * - `toType`, on write, reflects the class's own `#[EventType]` to its canonical alias; no
 *   attribute means the FQCN, for gradual adoption. It is reflection-based, so it is correct
 *   without the scan and a writer never silently mistypes.
 *
 * - `toClass`, on read, uses the scanned reverse map, canonical plus replaces, keyed to the class;
 *   a stored type that is not a registered alias falls back to a loadable FQCN for legacy or
 *   unaliased rows; if neither resolves it fails fast with UnknownEventType, usually because the
 *   scan or bundle wasn't wired.
 *
 * The reverse map is built from the event classes the scan finds; an empty list means all-FQCN,
 * an app with no aliases. Aliases are unique across the union, enforced by DuplicateEventAlias.
 */
final class MappedEventTypeMapper implements EventTypeMapper
{
    /** @var array<string, class-string> each alias, canonical plus replaces, mapped to its class */
    private array $classByAlias = [];

    /**
     * @param  list<class-string>  $eventClasses  classes carrying `#[EventType]` from the scan
     *
     * @throws DuplicateEventAlias when two classes claim the same alias
     */
    public function __construct(array $eventClasses = [])
    {
        foreach ($eventClasses as $class) {
            $eventType = self::attributeOf($class);

            if ($eventType === null) {
                continue;
            }

            foreach ([$eventType->alias, ...$eventType->replaces] as $alias) {
                if (isset($this->classByAlias[$alias]) && $this->classByAlias[$alias] !== $class) {
                    throw DuplicateEventAlias::between($alias, $this->classByAlias[$alias], $class);
                }

                $this->classByAlias[$alias] = $class;
            }
        }
    }

    public function toType(string $class): string
    {
        $eventType = self::attributeOf($class);

        return $eventType === null ? $class : $eventType->alias;
    }

    public function versionOf(string $class): int
    {
        $eventType = self::attributeOf($class);

        return $eventType === null ? 1 : $eventType->version;
    }

    public function storedTypesOf(string $class): array
    {
        $eventType = self::attributeOf($class);

        if ($eventType === null) {
            return [$class];
        }

        // current alias + every replaced alias + the FQCN, for rows written before the alias existed
        return array_values(array_unique([$eventType->alias, ...$eventType->replaces, $class]));
    }

    public function toClass(string $type): string
    {
        if (isset($this->classByAlias[$type])) {
            return $this->classByAlias[$type];
        }

        if (class_exists($type)) {
            return $type;
        }

        throw UnknownEventType::cannotResolve($type);
    }

    private static function attributeOf(string $class): ?EventType
    {
        // Memoized: the #[EventType] of a class never changes at runtime, yet toType/versionOf/storedTypesOf
        // reflect it for EVERY event written, millions of times per drain. The
        // result, an immutable readonly EventType or null, is a pure function of $class, so cache it per class;
        // a long-lived worker reflects once then serves O(1). Static so all mapper instances share one table.
        /** @var array<string, EventType|null> $cache */
        static $cache = [];

        if (array_key_exists($class, $cache)) {
            // @infection-ignore-all; equivalent: the recomputation below is a pure function of the class,
            // so falling through rebuilds an equal value; the cache buys time alone
            return $cache[$class];
        }

        if (! class_exists($class)) {
            return $cache[$class] = null;
        }

        $attributes = new ReflectionClass($class)->getAttributes(EventType::class);

        return $cache[$class] = ($attributes === [] ? null : $attributes[0]->newInstance());
    }
}
