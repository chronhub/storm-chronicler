<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests\Fixture;

use Storm\Chronicler\Evolution\AsUpcaster;
use Storm\Chronicler\Evolution\Upcaster;

/**
 * Upcasts `test.upcast` v1 to v2 by defaulting the new `currency` field.
 */
#[AsUpcaster]
final class AddCurrencyUpcaster implements Upcaster
{
    public function supports(string $alias, int $fromVersion): bool
    {
        return $alias === 'test.upcast' && $fromVersion === 1;
    }

    public function upcast(array $payload): array
    {
        return [...$payload, 'currency' => 'EUR'];
    }
}
