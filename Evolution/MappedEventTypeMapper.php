<?php

declare(strict_types=1);

namespace Storm\Chronicler\Evolution;

use ReflectionAttribute;
use ReflectionClass;
use Storm\Chronicler\Exception\DuplicateEventAlias;
use Storm\Chronicler\Exception\UnknownEventType;
use Storm\Contracts\Chronicler\EventTypeMapper;
use Storm\Message\AliasDeclaration;
use Storm\Message\EventType;

/**
 * The `EventTypeMapper` backed by alias-declaring attributes, `#[EventType]` and `#[CommandType]`.
 *
 * The stored type is a stable alias chosen by the developer, decoupled from the FQCN, and one map
 * serves both sorts.
 *
 * - `toType()`, on write, reflects the class's own declaration to its canonical alias, and no
 *   declaration means the FQCN, for gradual adoption. Being reflection-based, it is correct without
 *   the scan, and a writer never silently mistypes.
 *
 * - `toClass()`, on read, uses the scanned reverse map, canonical plus replaces, keyed to the class. A
 *   stored type that is not a registered alias falls back to a loadable FQCN for legacy or unaliased
 *   rows; if neither resolves, it fails fast with `UnknownEventType`, usually because the scan or the
 *   bundle was not wired.
 *
 * The reverse map is built from the classes the scan finds; an empty list means FQCNs only, an app
 * with no aliases. `DuplicateEventAlias` keeps aliases unique across the union, and refuses a class
 * declaring both sorts.
 */
final class MappedEventTypeMapper implements EventTypeMapper
{
    /** @var array<string, class-string> each alias, canonical plus replaces, mapped to its class */
    private array $classByAlias = [];

    /**
     * @param  list<class-string>  $eventClasses  classes carrying an `AliasDeclaration` from the scan
     *
     * @throws DuplicateEventAlias when two classes claim the same alias, or one class declares two sorts
     */
    public function __construct(array $eventClasses = [])
    {
        foreach ($eventClasses as $class) {
            $declarations = self::declarationsOf($class);

            if (count($declarations) > 1) {
                throw DuplicateEventAlias::twoSortsOnOneClass($class, $declarations[0]->alias, $declarations[1]->alias);
            }

            $declaration = $declarations[0] ?? null;

            if ($declaration === null) {
                continue;
            }

            foreach ([$declaration->alias, ...$declaration->replaces] as $alias) {
                if (isset($this->classByAlias[$alias]) && $this->classByAlias[$alias] !== $class) {
                    throw DuplicateEventAlias::between($alias, $this->classByAlias[$alias], $class);
                }

                $this->classByAlias[$alias] = $class;
            }
        }
    }

    public function toType(string $class): string
    {
        $declaration = self::attributeOf($class);

        return $declaration === null ? $class : $declaration->alias;
    }

    public function versionOf(string $class): int
    {
        $declaration = self::attributeOf($class);

        // a command has no schema version: it is never replayed from a store, so nothing upcasts it
        return $declaration instanceof EventType ? $declaration->version : 1;
    }

    public function storedTypesOf(string $class): array
    {
        $declaration = self::attributeOf($class);

        if ($declaration === null) {
            return [$class];
        }

        // the current alias, every replaced alias and the FQCN, for rows written before the alias existed
        return array_values(array_unique([$declaration->alias, ...$declaration->replaces, $class]));
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

    private static function attributeOf(string $class): ?AliasDeclaration
    {
        // Memoized: the declaration of a class never changes at runtime, yet `toType()`, `versionOf()` and
        // `storedTypesOf()` reflect it for every event and every command written, millions of times per
        // drain. The result, an immutable declaration or null, is a pure function of $class, so a long-lived
        // worker reflects once per class and then serves O(1); static, so every mapper instance shares one
        // table. The constructor refused a class of two sorts, so the first declaration is the one.
        /** @var array<string, AliasDeclaration|null> $cache */
        static $cache = [];

        if (array_key_exists($class, $cache)) {
            // equivalent: the recomputation below is a pure function of the class,
            // so falling through rebuilds an equal value; the cache buys time alone
            // @infection-ignore-all
            return $cache[$class];
        }

        return $cache[$class] = self::declarationsOf($class)[0] ?? null;
    }

    /**
     * Every alias declaration a class carries, in attribute order; none for a class that does not load.
     *
     * @return list<AliasDeclaration>
     */
    private static function declarationsOf(string $class): array
    {
        if (! class_exists($class)) {
            return [];
        }

        return array_map(
            static fn (ReflectionAttribute $attribute): AliasDeclaration => $attribute->newInstance(),
            new ReflectionClass($class)->getAttributes(AliasDeclaration::class, ReflectionAttribute::IS_INSTANCEOF),
        );
    }
}
