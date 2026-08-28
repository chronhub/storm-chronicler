<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests\Fixture;

use Storm\Chronicler\Evolution\Upcaster;
use TypeError;

/**
 * Claims the `test.upcast` v1 to v2 step and blows up on every payload: the shape of a shipped
 * upcaster whose own code is wrong, a strict-typed read of a field the stored payload never had.
 * Deliberately NOT tagged `#[AsUpcaster]`, so no container-wired chain picks it up; tests compose it
 * by hand.
 */
final class ThrowingUpcaster implements Upcaster
{
    public function supports(string $alias, int $fromVersion): bool
    {
        return $alias === 'test.upcast' && $fromVersion === 1;
    }

    public function upcast(array $payload): array
    {
        throw new TypeError('currency must be of type string, null given');
    }
}
