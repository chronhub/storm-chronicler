<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests;

use Doctrine\DBAL\Exception\RetryableException;
use Exception;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Storm\Chronicler\Exception\DuplicateVersion;
use Storm\Chronicler\Exception\InvalidPosition;
use Storm\Chronicler\Exception\RetryableStorageFailure;
use Storm\Chronicler\Exception\StaleVersion;
use Storm\Chronicler\Exception\StorageFailure;
use Storm\Chronicler\Exception\UnknownEventType;
use Storm\Contracts\Chronicler\ChroniclerExceptionContract;
use Storm\Contracts\Chronicler\ConcurrencyException as ConcurrencyExceptionContract;
use Storm\Contracts\Chronicler\InvalidPosition as InvalidPositionContract;
use Storm\Contracts\Chronicler\StorageFailure as StorageFailureContract;
use Storm\Contracts\Chronicler\UnknownEventType as UnknownEventTypeContract;
use Throwable;

/**
 * The exception seam of the Chronicler ports: each port's throws clause names a Contracts-owned
 * interface, the implementation hierarchy carries it, so code written against the ports catches
 * domain types without knowing this package.
 */
final class ContractedExceptionsTest extends TestCase
{
    #[Test]
    public function a_concurrency_conflict_is_catchable_by_the_contracted_type(): void
    {
        $this->assertInstanceOf(ConcurrencyExceptionContract::class, StaleVersion::forStream('order-1', 1, 2));
        $this->assertInstanceOf(ConcurrencyExceptionContract::class, DuplicateVersion::forStream('order-1'));
    }

    #[Test]
    public function an_unknown_event_type_is_catchable_by_the_contracted_type(): void
    {
        $this->assertInstanceOf(UnknownEventTypeContract::class, UnknownEventType::cannotResolve('order.placed'));
    }

    #[Test]
    public function an_unknown_event_type_keeps_the_disposition_and_the_actionable_message(): void
    {
        $unresolved = UnknownEventType::cannotResolve('order.placed');
        $foreign = UnknownEventType::notAnEvent('order.placed', self::class);

        $this->assertTrue($unresolved->resolvableByAnotherRuntime());
        $this->assertStringContainsString('order.placed', $unresolved->getMessage());
        $this->assertFalse($foreign->resolvableByAnotherRuntime());
        $this->assertStringContainsString(self::class, $foreign->getMessage());
    }

    #[Test]
    public function an_invalid_position_is_catchable_by_the_contracted_type(): void
    {
        $this->assertInstanceOf(InvalidPositionContract::class, InvalidPosition::notAPositiveInteger('abc'));
    }

    #[Test]
    public function a_storage_failure_implements_the_contract_and_keeps_the_driver_cause(): void
    {
        $cause = new RuntimeException('connection lost');

        $failure = StorageFailure::wrap('the stream-head read', $cause);

        $this->assertInstanceOf(StorageFailureContract::class, $failure);
        $this->assertNotInstanceOf(RetryableException::class, $failure); // non-transient cause, so a plain failure
        $this->assertSame($cause, $failure->getPrevious());
        $this->assertStringContainsString('the stream-head read', $failure->getMessage());
        $this->assertStringContainsString('connection lost', $failure->getMessage());
    }

    #[Test]
    public function a_retryable_driver_cause_stays_retryable_through_the_translation(): void
    {
        // deadlock / lock-timeout shape: Doctrine marks them with RetryableException
        $cause = new class('deadlock detected') extends Exception implements RetryableException {};

        $failure = StorageFailure::wrap('the stream-head CAS', $cause);

        // the marker survives the wrap; RetryEventStore keeps retrying a translated transient
        $this->assertInstanceOf(RetryableStorageFailure::class, $failure);
        $this->assertInstanceOf(RetryableException::class, $failure);
        $this->assertInstanceOf(StorageFailureContract::class, $failure);
    }

    #[Test]
    public function every_contracted_failure_rolls_up_to_the_module_umbrella(): void
    {
        // the high-level catch net: any contracted Chronicler failure IS a ChroniclerExceptionContract;
        // break a leaf's `extends ChroniclerExceptionContract` and this fails.
        $this->assertInstanceOf(ChroniclerExceptionContract::class, StaleVersion::forStream('order-1', 1, 2));
        $this->assertInstanceOf(ChroniclerExceptionContract::class, DuplicateVersion::forStream('order-1'));
        $this->assertInstanceOf(ChroniclerExceptionContract::class, UnknownEventType::cannotResolve('order.placed'));
        $this->assertInstanceOf(ChroniclerExceptionContract::class, InvalidPosition::notAPositiveInteger('abc'));
        $this->assertInstanceOf(ChroniclerExceptionContract::class, StorageFailure::wrap('a stream read', new RuntimeException('x')));
    }

    /**
     * The umbrella's own sentence, held to the ports rather than to a list.
     *
     * The five assertions above name instances by hand, so they prove the leaves they mention and
     * nothing about the ones nobody thought of; the root's claim is UNIVERSAL, every failure the module
     * lets cross its boundary, and a universal cannot be tested by enumeration. Six types had crossed
     * for months while the sentence stood, one of them forcing a sibling module to import four concrete
     * classes to rebuild a classification the contracts layer was supposed to carry.
     *
     * A declared tail is the one exemption, and it is not a loophole: `Throwable` says in so many words
     * that the port grants no exhaustiveness there, which is the opposite of a silent escape.
     */
    #[Test]
    #[Group('adversarial')]
    public function no_port_declares_a_failure_the_umbrella_does_not_cover(): void
    {
        $offenders = [];

        foreach (self::portFiles() as $file) {
            $source = (string) file_get_contents($file);

            if (preg_match('/^interface \w+/m', $source) !== 1) {
                continue; // a concrete: its clause may name what the port hides
            }

            foreach (self::declaredThrows($source) as $type) {
                if ($type === Throwable::class || is_a($type, ChroniclerExceptionContract::class, allow_string: true)) {
                    continue;
                }

                // Another module's contracted root, re-exposed rather than originated: a serialization,
                // clock or stream failure travels under ITS OWN umbrella, which is the same promise one
                // package over. What the rule forbids is a CONCRETE crossing under no umbrella at all.
                if (str_starts_with($type, 'Storm\\Contracts\\') || self::carriesAContract($type)) {
                    continue;
                }

                $offenders[] = sprintf('%s declares %s', basename($file), $type);
            }
        }

        self::assertSame([], $offenders, implode("\n", [
            'A Chronicler port lets a failure cross that the umbrella does not cover, so an app catching',
            'ChroniclerExceptionContract as its outer net misses it. Give the type the contract, or give',
            'the port a Throwable tail that says it grants no exhaustiveness:',
            ...$offenders,
        ]));
    }

    /**
     * Whether a concrete names a `Storm\Contracts` interface among what it implements, so it crosses
     * under an umbrella even though it is not this module's.
     *
     * @param  class-string  $type
     */
    private static function carriesAContract(string $type): bool
    {
        return array_any(class_implements($type) ?: [], fn ($interface) => str_starts_with($interface, 'Storm\\Contracts\\'));
    }

    /**
     * @return list<string>
     */
    private static function portFiles(): array
    {
        $files = [];

        /** @var iterable<SplFileInfo> $found */
        $found = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__)));

        foreach ($found as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && ! str_contains($file->getPathname(), '/Tests/')) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * The types a source file's `@throws` clauses name, resolved through its imports.
     *
     * @return list<class-string>
     */
    private static function declaredThrows(string $source): array
    {
        preg_match_all('/^use ([^\s;]+)(?: as (\w+))?;/m', $source, $uses, PREG_SET_ORDER);

        $imports = [];
        foreach ($uses as $use) {
            $imports[$use[2] ?? substr((string) strrchr('\\'.$use[1], '\\'), 1)] = $use[1];
        }

        preg_match_all('/@throws ([^\s]+)/', $source, $thrown);

        $types = [];
        foreach ($thrown[1] as $clause) {
            foreach (explode('|', $clause) as $name) {
                $resolved = $imports[$name] ?? ltrim($name, '\\');

                if (class_exists($resolved) || interface_exists($resolved)) {
                    /** @var class-string $resolved */
                    $types[] = $resolved;
                }
            }
        }

        return array_values(array_unique($types));
    }
}
