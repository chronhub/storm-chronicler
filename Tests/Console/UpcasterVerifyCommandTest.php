<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests\Console;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Console\UpcasterVerifyCommand;
use Storm\Chronicler\Evolution\Upcaster;
use Storm\Chronicler\Evolution\UpcasterChain;
use Storm\Chronicler\Evolution\UpcasterConformance;
use Storm\Chronicler\Exception\UnknownEventType;
use Storm\Chronicler\Tests\Fixture\SomethingHappened;
use Storm\Contracts\Chronicler\EventTypeMapper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The deploy-time verdict's two exits: a clean store leaves the deploy alone, and an unreadable one
 * FAILS, since the whole point is to pay the verdict here rather than at the first read.
 *
 * The conformance is real, only its store is a double; the SQL it issues is pinned against a live
 * table by the integration twin.
 *
 * @see \Storm\Tests\Integration\Chronicler\UpcasterVerifyCommandTest
 */
final class UpcasterVerifyCommandTest extends TestCase
{
    #[Test]
    public function a_readable_store_succeeds_and_says_so(): void
    {
        $tester = $this->tester(
            [['type' => SomethingHappened::class, 'floor' => 1, 'ceiling' => 3, 'n' => 12]],
            version: 3,
            upcasters: [$this->claiming(SomethingHappened::class, 1), $this->claiming(SomethingHappened::class, 2)],
        );

        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('readable by this binary', $tester->getDisplay());
    }

    #[Test]
    public function an_empty_store_succeeds(): void
    {
        // nothing stored yet: the floor is computed from data that does not exist, so nothing is owed
        $tester = $this->tester([], version: 7);

        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    #[Test]
    public function an_unreadable_store_fails_and_lists_every_problem(): void
    {
        // two families at once, so the listing is proven to carry more than the first verdict: a gap in
        // the chain of one type, and another type no declared class resolves
        $tester = $this->tester(
            [
                ['type' => SomethingHappened::class, 'floor' => 1, 'ceiling' => 3, 'n' => 12],
                ['type' => 'ghost.event', 'floor' => 1, 'ceiling' => 1, 'n' => 4],
            ],
            version: 3,
        );

        $tester->execute([]);
        $display = $tester->getDisplay();

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('3 readability problem(s)', $display);
        $this->assertStringContainsString('no upcaster for the step v1 to v2', $display);
        $this->assertStringContainsString('no upcaster for the step v2 to v3', $display);
        $this->assertStringContainsString('"ghost.event" (4 rows) resolves to no declared event class', $display);
    }

    #[Test]
    public function the_failure_count_is_the_number_of_problems_not_of_types(): void
    {
        // one stored type, three missing steps: the operator is told how much is broken, not how many
        // rows carry it
        $tester = $this->tester(
            [['type' => SomethingHappened::class, 'floor' => 1, 'ceiling' => 4, 'n' => 1]],
            version: 4,
        );

        $tester->execute([]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('3 readability problem(s)', $tester->getDisplay());
    }

    /**
     * @param  list<array{type: string, floor: int, ceiling: int, n: int}>  $stored  what the store reports
     * @param  positive-int  $version  the version every declared type sits at
     * @param  list<Upcaster>  $upcasters
     */
    private function tester(array $stored, int $version, array $upcasters = []): CommandTester
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn($stored);

        return new CommandTester(new UpcasterVerifyCommand(
            $connection,
            new UpcasterConformance($this->mapperAt($version), new UpcasterChain($upcasters)),
        ));
    }

    /**
     * An identity-shaped mapper whose types are all at `$version`.
     *
     * @param  positive-int  $version
     */
    private function mapperAt(int $version): EventTypeMapper
    {
        return new readonly class($version) implements EventTypeMapper
        {
            /** @param positive-int $version */
            public function __construct(private int $version) {}

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
                return $this->version;
            }

            public function storedTypesOf(string $class): array
            {
                return [$class];
            }
        };
    }

    /** An upcaster claiming exactly one `(alias, fromVersion)` step, payload untouched. */
    private function claiming(string $alias, int $fromVersion): Upcaster
    {
        return new readonly class($alias, $fromVersion) implements Upcaster
        {
            public function __construct(private string $alias, private int $fromVersion) {}

            public function supports(string $alias, int $fromVersion): bool
            {
                return $alias === $this->alias && $fromVersion === $this->fromVersion;
            }

            public function upcast(array $payload): array
            {
                return $payload;
            }
        };
    }
}
