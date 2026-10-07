<?php

declare(strict_types=1);

namespace Storm\Chronicler\Console;

use JsonException;
use Override;
use Storm\Chronicler\Evolution\CatalogueCompatibility;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_is_list;
use function array_key_exists;
use function array_map;
use function file_get_contents;
use function get_debug_type;
use function implode;
use function in_array;
use function is_array;
use function is_file;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function sprintf;
use function trim;

/**
 * The pull-request verdict: compares the message catalogue a revision published with the one this
 * revision publishes, and refuses a change that would make an existing store unreadable.
 *
 * The upstream face of `storm:upcaster:verify`, and it needs no database, which is the whole reason
 * it can run where that one cannot. Verify computes its floor FROM the rows, so it speaks about a
 * store that exists, at deploy. This compares two DECLARATIONS, so it speaks before any row does.
 * Neither covers the other.
 *
 * The writer emits format 1. Format 2 inputs carry personal declarations and must agree with the
 * current container on the current side. Their historical metadata is declarative, not an
 * attestation of provenance. A consumer must bind generated documents to its sources with a drift
 * test before treating them as its tracked contract. Mixed formats cannot establish compatibility.
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

        $baseline = $this->catalogue((string) $input->getArgument('baseline'), $io);
        $current = $this->catalogue((string) $input->getArgument('current'), $io);

        if ($baseline === null || $current === null) {
            return Command::FAILURE;
        }

        if ($baseline['schema_version'] !== $current['schema_version']) {
            $io->error('Cannot compare mixed catalogue formats; historical personal context is unavailable and the message contract was not judged.');

            return Command::FAILURE;
        }

        if ($current['schema_version'] === 2) {
            $mismatches = $this->compatibility->currentPersonalMismatches($current['events']);
            if ($mismatches !== []) {
                $io->error('The current catalogue disagrees with the current runtime; the message contract was not judged.');
                $io->listing(array_map(OutputFormatter::escape(...), $mismatches));

                return Command::FAILURE;
            }
        }

        $problems = $this->compatibility->verdicts($baseline['events'], $current['events']);

        if ($problems === []) {
            $io->success($current['schema_version'] === 2
                ? 'The checked message declarations and personal contexts are compatible. Historical personal context comes from the baseline document; its provenance is not attested. Classes not catalogued remain outside these guarantees; stored payloads were not inspected.'
                : 'The checked message declarations are compatible. Historical personal field changes, subject changes and removal of personal declarations are not checked. Classes not catalogued remain outside these guarantees.');

            return Command::SUCCESS;
        }

        $io->error('The message contract would break a store holding the previous shapes:');
        $io->listing(array_map(OutputFormatter::escape(...), $problems));

        return Command::FAILURE;
    }

    /**
     * The validated format and entries of a catalogue file, or null with the reason said out loud.
     *
     * Every refusal here is a refusal to JUDGE, never a compatibility verdict, and the two must not
     * be confused by a reader: an unreadable document says nothing about the contract.
     *
     * @return array{schema_version: int, events: array<string, array{class: string, version: int, replaces: list<string>, personal?: array{subject: string, keys: list<string>}|null}>}|null
     */
    private function catalogue(string $path, SymfonyStyle $io): ?array
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

        if (! in_array($document['schema_version'], CatalogueCompatibility::READABLE_SCHEMA_VERSIONS, true)) {
            // The `(string)` cast below survives mutation and the pin is WITHHELD on purpose: its line
            // also carries the ternary, which tests DO kill, and `@infection-ignore-all` matches by
            // line, so pinning here would smother a mutant the suite catches. `%s` performs the same
            // conversion, so the cast buys the analyser a type and the output nothing; it is an
            // equivalent this scope carries rather than one it silences.
            $io->error(sprintf(
                'The catalogue at "%s" is format version %s and this binary reads versions %s — regenerate it, or read it with the binary that wrote it.',
                $path,
                is_scalar($document['schema_version']) ? (string) $document['schema_version'] : 'unknown',
                implode(', ', CatalogueCompatibility::READABLE_SCHEMA_VERSIONS),
            ));

            return null;
        }

        $index = 0;
        foreach ($document['events'] as $alias => $entry) {
            $aliasString = (string) $alias;
            if (trim($aliasString) === '') {
                $location = sprintf('events[%d]', $index);
                $io->error(sprintf(
                    'The catalogue at "%s" contains an invalid entry at %s: alias cannot be empty; the message contract was not judged.',
                    $path,
                    $location,
                ));

                return null;
            }

            $diagnosis = self::diagnoseEntry($entry);
            if ($diagnosis === null && $document['schema_version'] === 2) {
                $diagnosis = self::diagnosePersonal($entry);
            }
            if ($diagnosis !== null) {
                [$suffix, $reason] = $diagnosis;
                // @infection-ignore-all; equivalent: `%s` below renders the one false `json_encode()` can
                // answer as the empty string the cast would produce, so the cast answers the analyzer alone
                $aliasKey = (string) json_encode($aliasString, JSON_UNESCAPED_SLASHES);
                $location = sprintf('events[%s]%s', $aliasKey, $suffix);
                $io->error(sprintf(
                    'The catalogue at "%s" contains an invalid entry at %s: %s; the message contract was not judged.',
                    $path,
                    $location,
                    $reason,
                ));

                return null;
            }

            $index++;
        }

        $events = $document['events'];
        if ($document['schema_version'] === 1) {
            $events = array_map(
                static fn (array $entry): array => ['class' => $entry['class'], 'version' => $entry['version'], 'replaces' => $entry['replaces']],
                $events,
            );
        }
        $diagnosis = self::diagnoseAliasClaims($events);
        if ($diagnosis !== null) {
            [$location, $reason] = $diagnosis;
            $io->error(sprintf(
                'The catalogue at "%s" contains an invalid entry at %s: %s; the message contract was not judged.',
                $path,
                $location,
                $reason,
            ));

            return null;
        }

        return ['schema_version' => $document['schema_version'], 'events' => $events];
    }

    /**
     * @param  array<string, array{class: string, version: int, replaces: list<string>}>  $events
     * @return array{string, string}|null
     */
    private static function diagnoseAliasClaims(array $events): ?array
    {
        $claims = [];
        foreach ($events as $canonical => $entry) {
            $canonical = (string) $canonical;
            $location = sprintf('events[%s]', (string) json_encode($canonical, JSON_UNESCAPED_SLASHES));
            if (isset($claims[$canonical])) {
                return [$location, 'alias is already claimed at '.$claims[$canonical]];
            }
            $claims[$canonical] = $location;
            $local = [];

            foreach ($entry['replaces'] as $index => $alias) {
                $replacementLocation = sprintf('%s.replaces[%d]', $location, $index);
                if ($alias === $canonical) {
                    return [$replacementLocation, 'cannot replace its own alias'];
                }
                if (isset($local[$alias])) {
                    return [$replacementLocation, 'duplicates '.$local[$alias]];
                }
                if (isset($claims[$alias])) {
                    return [$replacementLocation, 'alias is already claimed at '.$claims[$alias]];
                }
                $local[$alias] = $replacementLocation;
                $claims[$alias] = $replacementLocation;
            }
        }

        return null;
    }

    /**
     * @return array{string, string}|null
     */
    private static function diagnosePersonal(mixed $entry): ?array
    {
        if (! is_array($entry) || ! array_key_exists('personal', $entry)) {
            return ['.personal', 'field is required in format 2'];
        }
        $personal = $entry['personal'];
        if ($personal === null) {
            return null;
        }
        if (! is_array($personal) || array_is_list($personal)) {
            return ['.personal', 'must be null or an object containing subject and keys'];
        }
        if (! isset($personal['subject']) || ! is_string($personal['subject']) || trim($personal['subject']) === '') {
            return ['.personal.subject', 'must be a non-blank string'];
        }
        if (! isset($personal['keys']) || ! is_array($personal['keys']) || ! array_is_list($personal['keys'])) {
            return ['.personal.keys', 'must be a list'];
        }
        $seen = [];
        foreach ($personal['keys'] as $index => $key) {
            if (! is_string($key) || trim($key) === '') {
                return [sprintf('.personal.keys[%d]', $index), 'must be a non-blank string'];
            }
            if (isset($seen[$key])) {
                return [sprintf('.personal.keys[%d]', $index), 'must not duplicate another personal key'];
            }
            // @infection-ignore-all; equivalent: a set, read by the `isset()` above alone, so the
            // value is arbitrary
            $seen[$key] = true;
        }

        return null;
    }

    /**
     * @return array{string, string}|null
     */
    private static function diagnoseEntry(mixed $entry): ?array
    {
        if (! is_array($entry)) {
            return ['', sprintf('must be a JSON object (received %s)', get_debug_type($entry))];
        }

        if (! array_key_exists('class', $entry)) {
            return ['.class', 'field is required'];
        }

        if (! is_string($entry['class'])) {
            return ['.class', sprintf('must be a string (received %s)', get_debug_type($entry['class']))];
        }

        if (trim($entry['class']) === '') {
            return ['.class', 'cannot be blank'];
        }

        if (! array_key_exists('version', $entry)) {
            return ['.version', 'field is required'];
        }

        if (! is_int($entry['version'])) {
            return ['.version', sprintf('must be an integer (received %s)', get_debug_type($entry['version']))];
        }

        if ($entry['version'] < 1) {
            return ['.version', 'must be at least 1'];
        }

        if (! array_key_exists('replaces', $entry)) {
            return ['.replaces', 'field is required'];
        }

        if (! is_array($entry['replaces']) || ! array_is_list($entry['replaces'])) {
            return ['.replaces', sprintf('must be a list (received %s)', get_debug_type($entry['replaces']))];
        }

        foreach ($entry['replaces'] as $index => $alias) {
            if (! is_string($alias)) {
                return [sprintf('.replaces[%d]', $index), sprintf('must be a string (received %s)', get_debug_type($alias))];
            }

            if (trim($alias) === '') {
                return [sprintf('.replaces[%d]', $index), 'cannot be blank'];
            }
        }

        return null;
    }
}
