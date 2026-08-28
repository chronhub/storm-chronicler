<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests\Console;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Console\MessageCheckCommand;
use Storm\Chronicler\Evolution\CatalogueCompatibility;
use Storm\Chronicler\Evolution\UpcasterChain;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

use function json_encode;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * The command's own layer: what it does with a document it cannot read, which is the half the rules
 * below it never see.
 *
 * Refusing to JUDGE and judging INCOMPATIBLE are different answers that share an exit code, so the
 * message is the only thing telling them apart, and a reader acts on them differently: one is a
 * contract to fix, the other a document to regenerate. A command that returned the compatibility
 * wording for an unreadable file would send someone hunting a break that is not there.
 */
final class MessageCheckCommandTest extends TestCase
{
    /** @var list<string> */
    private array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            @unlink($path);
        }
    }

    #[Test]
    public function a_compatible_pair_passes(): void
    {
        $tester = $this->check(
            $this->catalogue(['order.placed' => $this->entry()]),
            $this->catalogue(['order.placed' => $this->entry(), 'order.shipped' => $this->entry()]),
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('compatible with any store', self::flat($tester), 'a gate that passes in silence teaches nothing about what it checked');
    }

    #[Test]
    public function an_incompatible_pair_fails_and_names_the_alias(): void
    {
        $tester = $this->check(
            $this->catalogue(['order.placed' => $this->entry()]),
            $this->catalogue([]),
        );

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('would break a store', self::flat($tester), 'the headline says WHAT kind of failure this is');
        self::assertStringContainsString('order.placed', self::flat($tester));
    }

    #[Test]
    #[Group('adversarial')]
    public function a_format_it_cannot_read_is_refused_and_not_reported_as_incompatible(): void
    {
        $tester = $this->check(
            $this->catalogue(['order.placed' => $this->entry()], schemaVersion: CatalogueCompatibility::SCHEMA_VERSION + 1),
            $this->catalogue(['order.placed' => $this->entry()]),
        );

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('format version', self::flat($tester));
        self::assertStringContainsString((string) (CatalogueCompatibility::SCHEMA_VERSION + 1), self::flat($tester), 'naming the version it read is what tells a reader which document to regenerate');
        self::assertStringNotContainsString('would break a store', self::flat($tester), 'an unreadable document says NOTHING about the contract');
    }

    #[Test]
    public function a_schema_version_that_is_not_even_a_number_is_refused_without_printing_it(): void
    {
        // the branch that says "unknown" rather than casting: a document may carry anything there,
        // and a refusal that tried to render an array would fail while refusing
        $tester = $this->check($this->file('{"schema_version": {"a": 1}, "events": {}}'), $this->catalogue([]));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('unknown', self::flat($tester));
    }

    #[Test]
    #[Group('adversarial')]
    public function a_file_that_is_not_json_is_refused_and_not_reported_as_incompatible(): void
    {
        $tester = $this->check($this->file('not json at all'), $this->catalogue([]));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('not readable JSON', self::flat($tester));
        self::assertStringNotContainsString('would break a store', self::flat($tester));
    }

    #[Test]
    public function json_that_is_not_a_catalogue_is_refused(): void
    {
        // valid JSON says nothing about being the right document; a reader that shrugged here would
        // compare an absent `events` against a real one and report every alias as lost
        $tester = $this->check($this->file('{"something": "else"}'), $this->catalogue([]));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('not a message catalogue', self::flat($tester));
    }

    #[Test]
    public function a_missing_catalogue_is_refused(): void
    {
        $tester = $this->check(sys_get_temp_dir().'/storm-no-such-catalogue.json', $this->catalogue([]));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No catalogue', self::flat($tester));
    }

    /**
     * The rendered output with its wrapping collapsed. `SymfonyStyle` breaks a block to the terminal
     * width, so a phrase asserted verbatim can fail on where the wrap fell rather than on what the
     * command said; the assertion is about the words, never the column they land in.
     */
    private static function flat(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private function check(string $baseline, string $current): CommandTester
    {
        $tester = new CommandTester(new MessageCheckCommand(new CatalogueCompatibility(new UpcasterChain)));
        $tester->execute(['baseline' => $baseline, 'current' => $current]);

        return $tester;
    }

    /**
     * @param  array<string, array{class: string, version: int, replaces: list<string>}>  $events
     */
    private function catalogue(array $events, ?int $schemaVersion = null): string
    {
        return $this->file((string) json_encode([
            'schema_version' => $schemaVersion ?? CatalogueCompatibility::SCHEMA_VERSION,
            'events' => (object) $events,
        ]));
    }

    private function file(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'storm-catalogue-');
        $this->written[] = $path;

        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * @return array{class: string, version: int, replaces: list<string>}
     */
    private function entry(): array
    {
        return ['class' => 'App\Order\OrderPlaced', 'version' => 1, 'replaces' => []];
    }
}
