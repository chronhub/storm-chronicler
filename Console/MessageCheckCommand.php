<?php

declare(strict_types=1);

namespace Storm\Chronicler\Console;

use JsonException;
use Override;
use Storm\Chronicler\Evolution\CatalogueCompatibility;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function file_get_contents;
use function is_array;
use function is_file;
use function json_decode;

/**
 * The pull-request verdict: compares the message catalogue a revision published with the one this
 * revision publishes, and refuses a change that would make an existing store unreadable.
 *
 * The upstream face of `storm:upcaster:verify`, and it needs no database, which is the whole reason
 * it can run where that one cannot. Verify computes its floor FROM the rows, so it speaks about a
 * store that exists, at deploy. This compares two DECLARATIONS, so it speaks before any row does.
 * Neither covers the other.
 *
 * Both documents come from `storm:message:catalogue`. The current one is the tracked file, and
 * trusting it is not a leap: the drift test that renders it in memory and compares runs in the same
 * lane, so by the time this reads the file, another gate has already proven the file is the code.
 *
 * A document whose `schema_version` this binary does not know is REFUSED rather than judged. A
 * verdict about fields it guessed at would be worse than no verdict, since it would carry the same
 * green.
 *
 * Examples:
 *
 * ```bash
 * # the shape a CI step uses: the base revision's document against the working one
 * git show "$BASE:docs/message-catalogue.json" > /tmp/baseline.json
 * bin/console storm:message:check /tmp/baseline.json docs/message-catalogue.json
 * ```
 *
 * @see CatalogueCompatibility the rules
 * @see UpcasterVerifyCommand the deploy-time twin, which needs the store this one does not
 */
#[AsCommand(name: 'storm:message:check', description: 'Compare two message catalogues and refuse a change that would make stored rows unreadable.')]
final class MessageCheckCommand extends Command
{
    public function __construct(
        private readonly CatalogueCompatibility $compatibility,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('baseline', InputArgument::REQUIRED, 'The catalogue of the revision being compared against');
        $this->addArgument('current', InputArgument::REQUIRED, 'The catalogue this revision publishes');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $baseline = $this->events((string) $input->getArgument('baseline'), $io);
        $current = $this->events((string) $input->getArgument('current'), $io);

        if ($baseline === null || $current === null) {
            return Command::FAILURE;
        }

        $problems = $this->compatibility->verdicts($baseline, $current);

        if ($problems === []) {
            $io->success('The message contract is compatible with any store holding the previous shapes.');

            return Command::SUCCESS;
        }

        $io->error('The message contract would break a store holding the previous shapes:');
        $io->listing($problems);

        return Command::FAILURE;
    }

    /**
     * The `events` map of a catalogue file, or null with the reason said out loud.
     *
     * Every refusal here is a refusal to JUDGE, never a compatibility verdict, and the two must not
     * be confused by a reader: an unreadable document says nothing about the contract.
     *
     * @return array<string, array{class: string, version: int, replaces: list<string>}>|null
     */
    private function events(string $path, SymfonyStyle $io): ?array
    {
        if (! is_file($path)) {
            $io->error(sprintf('No catalogue at "%s".', $path));

            return null;
        }

        try {
            $document = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $io->error(sprintf('The catalogue at "%s" is not readable JSON: %s', $path, $e->getMessage()));

            return null;
        }

        if (! is_array($document) || ! isset($document['schema_version'], $document['events']) || ! is_array($document['events'])) {
            $io->error(sprintf('The document at "%s" is not a message catalogue.', $path));

            return null;
        }

        if ($document['schema_version'] !== CatalogueCompatibility::SCHEMA_VERSION) {
            // The `(string)` cast below survives mutation and the pin is WITHHELD on purpose: its line
            // also carries the ternary, which tests DO kill, and `@infection-ignore-all` matches by
            // line, so pinning here would smother a mutant the suite catches. `%s` performs the same
            // conversion, so the cast buys the analyser a type and the output nothing; it is an
            // equivalent this scope carries rather than one it silences.
            $io->error(sprintf(
                'The catalogue at "%s" is format version %s and this binary reads version %d — regenerate it, or read it with the binary that wrote it.',
                $path,
                is_scalar($document['schema_version']) ? (string) $document['schema_version'] : 'unknown',
                CatalogueCompatibility::SCHEMA_VERSION,
            ));

            return null;
        }

        /** @var array<string, array{class: string, version: int, replaces: list<string>}> $events */
        $events = $document['events'];

        return $events;
    }
}
