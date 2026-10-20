<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests\Fixture;

use RuntimeException;
use Storm\Contracts\Serializer\CipherKeyStore;
use Storm\Contracts\Serializer\SubjectForgotten;

/**
 * In-memory cipher keys for the unit suite, covering the port's whole verdict surface:
 *
 * - `issue()` is idempotent
 * - A tombstoned subject refuses
 * - Destroyed and never issued are distinguishable
 *
 * The material is real 32-byte key material, so the sodium calls under test are the real ones and
 * only the persistence is faked.
 */
final class FakeCipherKeyStore implements CipherKeyStore
{
    /** @var array<string, string> */
    private array $keys = [];

    /** @var array<string, true> */
    private array $tombstones = [];

    public function keyFor(string $subject): ?string
    {
        return $this->keys[$subject] ?? null;
    }

    public function issue(string $subject): string
    {
        if (isset($this->tombstones[$subject])) {
            throw new class(sprintf('Subject [%s] is forgotten.', $subject)) extends RuntimeException implements SubjectForgotten {};
        }

        return $this->keys[$subject] ??= random_bytes(32);
    }

    public function destroy(string $subject): bool
    {
        $first = ! isset($this->tombstones[$subject]);

        unset($this->keys[$subject]);
        $this->tombstones[$subject] = true;

        return $first;
    }

    public function isDestroyed(string $subject): bool
    {
        return isset($this->tombstones[$subject]);
    }
}
