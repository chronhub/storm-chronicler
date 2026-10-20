<?php

declare(strict_types=1);

namespace Storm\Chronicler\Evolution;

/**
 * Migrates one stored event payload up a single schema version, from `(alias, vN)` to `vN+1`.
 *
 * It is a pure data migration, array to array: the `UpcasterChain` applies the matching upcasters in
 * order, from the stored `event_version` to the type's current version, before the event object is
 * rebuilt from the payload. An upcaster is a service; tag it with `#[AsUpcaster]`.
 *
 * @see UpcasterChain
 */
interface Upcaster
{
    /**
     * Whether this upcaster handles the given version step of the given event alias.
     */
    public function supports(string $alias, int $fromVersion): bool;

    /**
     * Migrate the payload one version forward.
     *
     * @param  array<string, mixed>  $payload  the payload at `fromVersion`
     * @return array<string, mixed> the payload at `fromVersion + 1`
     */
    public function upcast(array $payload): array;
}
