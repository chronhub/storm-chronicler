<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Exception\DuplicateVersion;
use Storm\Chronicler\Exception\StaleVersion;
use Storm\Contracts\Chronicler\ConcurrencyException;
use Storm\Contracts\Chronicler\RetryableConcurrencyConflict;

/**
 * Pins which concrete concurrency conflicts are retryable and which are terminal, THROUGH the
 * contracted types alone, the way a consumer decoupled from this package sees them. The trap this
 * closes: `catch (ConcurrencyException)` receives both, and the family root carries no
 * recoverability of its own while DuplicateVersion, terminal by its own docblock, rode
 * the same type. Retry policy hangs on the RetryableConcurrencyConflict marker, never the root.
 */
final class ConcurrencyTaxonomyTest extends TestCase
{
    #[Test]
    public function a_stale_version_is_a_retryable_conflict_through_the_contract(): void
    {
        $stale = StaleVersion::forStream('account-1', 4, 7);

        $this->assertInstanceOf(ConcurrencyException::class, $stale);
        $this->assertInstanceOf(RetryableConcurrencyConflict::class, $stale, 'the marker carries the retry policy portably');
    }

    #[Test]
    public function a_duplicate_version_is_a_conflict_but_never_a_retryable_one(): void
    {
        $duplicate = DuplicateVersion::forStream('account-1');

        $this->assertInstanceOf(ConcurrencyException::class, $duplicate, 'the family root still classifies it');
        // statically "always true"; that is the PIN: it turns red the day someone marks DuplicateVersion retryable
        $this->assertNotInstanceOf(RetryableConcurrencyConflict::class, $duplicate, 'terminal: the decision may already be applied — a blind retry is exactly what the marker exists to prevent'); // @phpstan-ignore method.alreadyNarrowedType
    }

    #[Test]
    #[Group('adversarial')]
    public function the_family_root_catches_both_so_policy_must_hang_on_the_marker(): void
    {
        // the documented trap, kept green as a reminder: a generic catch on the root RECEIVES both
        // conflicts; any consumer branching on the root alone applies one policy to two opposite
        // semantics. The marker is the only contracted discriminant.
        $caught = [];
        foreach ([StaleVersion::forStream('s', 1, 2), DuplicateVersion::forStream('s')] as $conflict) {
            try {
                throw $conflict;
            } catch (ConcurrencyException $e) {
                $caught[] = $e instanceof RetryableConcurrencyConflict;
            }
        }

        $this->assertSame([true, false], $caught);
    }
}
