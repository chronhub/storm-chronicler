<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests\Console;

use ErrorException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Console\MessageCheckCommand;
use Storm\Chronicler\Evolution\CatalogueCompatibility;
use Storm\Chronicler\Evolution\UpcasterChain;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Throwable;

use function json_encode;
use function str_replace;
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
    public function a_personal_class_rename_is_refused_and_names_the_alias_and_both_classes(): void
    {
        $checker = new CatalogueCompatibility(new UpcasterChain, ['App\\CurrentEvent' => ['subject' => 'id', 'keys' => ['name'], 'fallbacks' => ['name' => null]]]);
        $tester = new CommandTester(new MessageCheckCommand($checker));
        $tester->execute([
            'baseline' => $this->catalogue(['customer.created' => [...$this->entry(), 'class' => 'App\\OldEvent']]),
            'current' => $this->catalogue(['customer.created' => [...$this->entry(), 'class' => 'App\\CurrentEvent']]),
        ]);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('customer.created', self::flat($tester));
        self::assertStringContainsString('App\\OldEvent', self::flat($tester));
        self::assertStringContainsString('App\\CurrentEvent', self::flat($tester));
    }

    #[Test]
    public function a_rename_that_leaves_its_upcaster_behind_is_refused_and_names_both_aliases(): void
    {
        $tester = $this->check(
            $this->catalogue(['order.placed' => $this->entry()]),
            $this->catalogue(['ordering.placed' => [...$this->entry(), 'version' => 2, 'replaces' => ['order.placed']]]),
        );

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('order.placed', self::flat($tester));
        self::assertStringContainsString('canonical "ordering.placed"', self::flat($tester));
        self::assertStringContainsString('v1 to v2', self::flat($tester));
    }

    #[Test]
    public function a_compatible_pair_passes(): void
    {
        $tester = $this->check(
            $this->catalogue(['order.placed' => $this->entry()]),
            $this->catalogue(['order.placed' => $this->entry(), 'order.shipped' => $this->entry()]),
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('checked message declarations are compatible', self::flat($tester), 'a gate that passes in silence teaches nothing about what it checked');
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
            $this->catalogue(['order.placed' => $this->entry()], schemaVersion: max(CatalogueCompatibility::READABLE_SCHEMA_VERSIONS) + 1),
            $this->catalogue(['order.placed' => $this->entry()]),
        );

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('format version', self::flat($tester));
        self::assertStringContainsString((string) (max(CatalogueCompatibility::READABLE_SCHEMA_VERSIONS) + 1), self::flat($tester), 'naming the version it read is what tells a reader which document to regenerate');
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
     * @param  array<string, mixed>  $events
     */
    #[Test]
    #[DataProvider('malformedEntries')]
    public function a_malformed_entry_is_refused_without_a_compatibility_verdict(array $events, bool $baseline): void
    {
        $invalid = $this->file((string) json_encode([
            'schema_version' => CatalogueCompatibility::SCHEMA_VERSION,
            'events' => (object) $events,
        ]));
        $valid = $this->catalogue(['order.placed' => $this->entry()]);
        $error = null;
        $tester = null;
        set_error_handler(static function (int $severity, string $message): never {
            throw new ErrorException($message, severity: $severity);
        });
        try {
            $tester = $this->check($baseline ? $invalid : $valid, $baseline ? $valid : $invalid);
        } catch (Throwable $caught) {
            $error = $caught;
        } finally {
            restore_error_handler();
        }

        self::assertNull($error, $error?->getMessage() ?? '');
        self::assertInstanceOf(CommandTester::class, $tester);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('invalid entry', self::flat($tester));
        self::assertStringNotContainsString('would break a store', self::flat($tester));
        self::assertStringNotContainsString('checked message declarations are compatible', self::flat($tester));
    }

    #[Test]
    public function historical_classes_and_unknown_metadata_do_not_require_loading(): void
    {
        $entry = ['class' => 'Removed\\HistoricalEvent', 'version' => 1, 'replaces' => ['former.alias'], 'extension' => ['ignored' => true]];
        $path = $this->catalogue(['historical.event' => $entry]);
        $tester = $this->check($path, $path);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('checked message declarations are compatible', self::flat($tester));
    }

    /**
     * @param  array<string, array{class: string, version: int, replaces: list<string>}>  $events
     */
    #[Test]
    #[DataProvider('ambiguousDeclarations')]
    public function ambiguous_alias_declarations_are_not_judged(array $events, string $location, string $reason, bool $baseline): void
    {
        $invalid = $this->catalogue($events);
        $valid = $this->catalogue(['order.placed' => $this->entry()]);
        $tester = $this->check($baseline ? $invalid : $valid, $baseline ? $valid : $invalid);
        $output = self::flat($tester);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('invalid entry', $output);
        self::assertStringContainsString($invalid, $output);
        self::assertStringContainsString($location, $output);
        self::assertStringContainsString($reason, $output);
        self::assertStringContainsString('not judged', $output);
        self::assertStringNotContainsString('would break a store', $output);
        self::assertStringNotContainsString('checked message declarations are compatible', $output);
        self::assertStringNotContainsString('private-class', $output);
        self::assertStringNotContainsString("\033", $output);
        self::assertStringNotContainsString('<error>', $output);
    }

    /**
     * @return iterable<string, array{array<string, array{class: string, version: int, replaces: list<string>}>, string, string, bool}>
     */
    public static function ambiguousDeclarations(): iterable
    {
        $entry = ['class' => 'private-class', 'version' => 1, 'replaces' => []];
        $adverse = "\033[31m<error>private-alias</error>";
        $cases = [
            'self replacement' => [
                ['a' => array_replace($entry, ['replaces' => ['a']])],
                'events["a"].replaces[0]', 'cannot replace its own alias',
            ],
            'local duplicate' => [
                ['a' => array_replace($entry, ['replaces' => ['old', 'old']])],
                'events["a"].replaces[1]', 'duplicates events["a"].replaces[0]',
            ],
            'historical collision' => [
                ['a' => array_replace($entry, ['replaces' => ['old']]), 'b' => array_replace($entry, ['replaces' => ['old']])],
                'events["b"].replaces[0]', 'alias is already claimed at events["a"].replaces[0]',
            ],
            'canonical then replacement' => [
                ['a' => $entry, 'b' => array_replace($entry, ['replaces' => ['a']])],
                'events["b"].replaces[0]', 'alias is already claimed at events["a"]',
            ],
            'replacement then canonical' => [
                ['b' => array_replace($entry, ['replaces' => ['a']]), 'a' => $entry],
                'events["a"]', 'alias is already claimed at events["b"].replaces[0]',
            ],
            'reversed historical collision' => [
                ['b' => array_replace($entry, ['replaces' => ['old']]), 'a' => array_replace($entry, ['replaces' => ['old']])],
                'events["a"].replaces[0]', 'alias is already claimed at events["b"].replaces[0]',
            ],
            'adverse replacement value' => [
                ['a' => array_replace($entry, ['replaces' => [$adverse]]), 'b' => array_replace($entry, ['replaces' => [$adverse]])],
                'events["b"].replaces[0]', 'alias is already claimed at events["a"].replaces[0]',
            ],
        ];
        foreach ([true, false] as $baseline) {
            foreach ($cases as $name => [$events, $location, $reason]) {
                yield ($baseline ? 'baseline ' : 'current ').$name => [$events, $location, $reason, $baseline];
            }
        }
    }

    #[Test]
    public function shape_errors_precede_alias_claim_errors(): void
    {
        $path = $this->catalogue([
            'a' => array_replace($this->entry(), ['replaces' => ['a']]),
            'b' => array_replace($this->entry(), ['version' => 0]),
        ]);
        $tester = $this->check($path, $this->catalogue([]));

        self::assertStringContainsString('events["b"].version: must be at least 1', self::flat($tester));
    }

    #[Test]
    public function a_valid_historical_split_and_empty_catalogues_are_accepted(): void
    {
        $baseline = $this->catalogue(['old.entry' => array_replace($this->entry(), ['replaces' => ['x', 'y']])]);
        $current = $this->catalogue([
            'a.entry' => array_replace($this->entry(), ['replaces' => ['x', 'old.entry']]),
            'b.entry' => array_replace($this->entry(), ['class' => 'App\\Other', 'replaces' => ['y']]),
        ]);
        self::assertSame(Command::SUCCESS, $this->check($baseline, $current)->getStatusCode());
        self::assertSame(Command::SUCCESS, $this->check($this->catalogue([]), $this->catalogue([]))->getStatusCode());
    }

    #[Test]
    public function malformed_entries_identify_the_location_and_reason_without_exposing_values(): void
    {
        $validEntry = $this->entry();
        $cases = [
            [
                'label' => 'scalar entry',
                'events' => ['order.placed' => 'private-payload-string'],
                'location' => 'events["order.placed"]',
                'rule' => 'must be a JSON object',
                'privateValues' => ['private-payload-string'],
            ],
            [
                'label' => 'null entry',
                'events' => ['order.placed' => null],
                'location' => 'events["order.placed"]',
                'rule' => 'must be a JSON object',
                'privateValues' => [],
            ],
            [
                'label' => 'empty string alias',
                'events' => ['' => $validEntry],
                'location' => 'events[0]',
                'rule' => 'alias cannot be empty',
                'privateValues' => [],
            ],
            [
                'label' => 'empty alias after a valid entry',
                'events' => ['order.placed' => $validEntry, '' => $validEntry],
                'location' => 'events[1]',
                'rule' => 'alias cannot be empty',
                'privateValues' => [],
            ],
            [
                'label' => 'blank whitespace alias',
                'events' => ['   ' => $validEntry],
                'location' => 'events[0]',
                'rule' => 'alias cannot be empty',
                'privateValues' => [],
            ],
            [
                'label' => 'missing class',
                'events' => ['order.placed' => ['version' => 1, 'replaces' => []]],
                'location' => 'events["order.placed"].class',
                'rule' => 'field is required',
                'privateValues' => [],
            ],
            [
                'label' => 'missing version',
                'events' => ['order.placed' => ['class' => 'App\\Order\\OrderPlaced', 'replaces' => []]],
                'location' => 'events["order.placed"].version',
                'rule' => 'field is required',
                'privateValues' => [],
            ],
            [
                'label' => 'missing replaces',
                'events' => ['order.placed' => ['class' => 'App\\Order\\OrderPlaced', 'version' => 1]],
                'location' => 'events["order.placed"].replaces',
                'rule' => 'field is required',
                'privateValues' => [],
            ],
            [
                'label' => 'null class',
                'events' => ['order.placed' => ['class' => null, 'version' => 1, 'replaces' => []]],
                'location' => 'events["order.placed"].class',
                'rule' => 'must be a string',
                'privateValues' => [],
            ],
            [
                'label' => 'null version',
                'events' => ['order.placed' => ['class' => 'App\\Order\\OrderPlaced', 'version' => null, 'replaces' => []]],
                'location' => 'events["order.placed"].version',
                'rule' => 'must be an integer',
                'privateValues' => [],
            ],
            [
                'label' => 'null replaces',
                'events' => ['order.placed' => ['class' => 'App\\Order\\OrderPlaced', 'version' => 1, 'replaces' => null]],
                'location' => 'events["order.placed"].replaces',
                'rule' => 'must be a list',
                'privateValues' => [],
            ],
            [
                'label' => 'class non-string integer',
                'events' => ['order.placed' => ['class' => 424242, 'version' => 1, 'replaces' => []]],
                'location' => 'events["order.placed"].class',
                'rule' => 'must be a string',
                'privateValues' => ['424242'],
            ],
            [
                'label' => 'class non-string boolean',
                'events' => ['order.placed' => ['class' => true, 'version' => 1, 'replaces' => []]],
                'location' => 'events["order.placed"].class',
                'rule' => 'must be a string',
                'privateValues' => [],
            ],
            [
                'label' => 'blank class empty',
                'events' => ['order.placed' => ['class' => '', 'version' => 1, 'replaces' => []]],
                'location' => 'events["order.placed"].class',
                'rule' => 'cannot be blank',
                'privateValues' => [],
            ],
            [
                'label' => 'blank class whitespace',
                'events' => ['order.placed' => ['class' => '   ', 'version' => 1, 'replaces' => []]],
                'location' => 'events["order.placed"].class',
                'rule' => 'cannot be blank',
                'privateValues' => [],
            ],
            [
                'label' => 'version string',
                'events' => ['order.placed' => ['class' => 'App\\Order\\OrderPlaced', 'version' => 'secret-ver', 'replaces' => []]],
                'location' => 'events["order.placed"].version',
                'rule' => 'must be an integer',
                'privateValues' => ['secret-ver'],
            ],
            [
                'label' => 'version float',
                'events' => ['order.placed' => ['class' => 'App\\Order\\OrderPlaced', 'version' => 2.5, 'replaces' => []]],
                'location' => 'events["order.placed"].version',
                'rule' => 'must be an integer',
                'privateValues' => ['2.5'],
            ],
            [
                'label' => 'version boolean',
                'events' => ['order.placed' => ['class' => 'App\\Order\\OrderPlaced', 'version' => false, 'replaces' => []]],
                'location' => 'events["order.placed"].version',
                'rule' => 'must be an integer',
                'privateValues' => [],
            ],
            [
                'label' => 'version zero',
                'events' => ['order.placed' => ['class' => 'App\\Order\\OrderPlaced', 'version' => 0, 'replaces' => []]],
                'location' => 'events["order.placed"].version',
                'rule' => 'must be at least 1',
                'privateValues' => [],
            ],
            [
                'label' => 'version negative',
                'events' => ['order.placed' => ['class' => 'App\\Order\\OrderPlaced', 'version' => -5, 'replaces' => []]],
                'location' => 'events["order.placed"].version',
                'rule' => 'must be at least 1',
                'privateValues' => ['-5'],
            ],
            [
                'label' => 'replaces non-array scalar',
                'events' => ['order.placed' => ['class' => 'App\\Order\\OrderPlaced', 'version' => 1, 'replaces' => 'secret-rep']],
                'location' => 'events["order.placed"].replaces',
                'rule' => 'must be a list',
                'privateValues' => ['secret-rep'],
            ],
            [
                'label' => 'replaces non-list associative',
                'events' => ['order.placed' => ['class' => 'App\\Order\\OrderPlaced', 'version' => 1, 'replaces' => ['former' => 'former.alias']]],
                'location' => 'events["order.placed"].replaces',
                'rule' => 'must be a list',
                'privateValues' => ['former'],
            ],
            [
                'label' => 'replaces item non-string index 0',
                'events' => ['order.placed' => ['class' => 'App\\Order\\OrderPlaced', 'version' => 1, 'replaces' => [65432]]],
                'location' => 'events["order.placed"].replaces[0]',
                'rule' => 'must be a string',
                'privateValues' => ['65432'],
            ],
            [
                'label' => 'replaces item non-string index 1',
                'events' => ['order.placed' => ['class' => 'App\\Order\\OrderPlaced', 'version' => 1, 'replaces' => ['valid.alias', 87654]]],
                'location' => 'events["order.placed"].replaces[1]',
                'rule' => 'must be a string',
                'privateValues' => ['87654'],
            ],
            [
                'label' => 'replaces item blank index 0',
                'events' => ['order.placed' => ['class' => 'App\\Order\\OrderPlaced', 'version' => 1, 'replaces' => ['']]],
                'location' => 'events["order.placed"].replaces[0]',
                'rule' => 'cannot be blank',
                'privateValues' => [],
            ],
            [
                'label' => 'replaces item blank index 1',
                'events' => ['order.placed' => ['class' => 'App\\Order\\OrderPlaced', 'version' => 1, 'replaces' => ['valid.alias', '   ']]],
                'location' => 'events["order.placed"].replaces[1]',
                'rule' => 'cannot be blank',
                'privateValues' => [],
            ],
            [
                'label' => 'alias with console markup',
                'events' => ['<error>malicious.alias</error>' => ['class' => 112233, 'version' => 1, 'replaces' => []]],
                'location' => 'events["<error>malicious.alias</error>"].class',
                'rule' => 'must be a string',
                'privateValues' => ['112233'],
            ],
            [
                'label' => 'alias with terminal ansi escape',
                'events' => ["\033[31mmalicious.alias\033[0m" => ['class' => 445566, 'version' => 1, 'replaces' => []]],
                'location' => 'events["\\u001b[31mmalicious.alias\\u001b[0m"].class',
                'rule' => 'must be a string',
                'privateValues' => ['445566'],
            ],
        ];

        foreach ([true, false] as $baseline) {
            foreach ($cases as $case) {
                $invalidPath = $this->file((string) json_encode([
                    'schema_version' => CatalogueCompatibility::SCHEMA_VERSION,
                    'events' => (object) $case['events'],
                ]));
                $validPath = $this->catalogue(['order.placed' => $validEntry]);

                $tester = $this->check(
                    $baseline ? $invalidPath : $validPath,
                    $baseline ? $validPath : $invalidPath,
                );

                $output = self::flat($tester);

                self::assertSame(Command::FAILURE, $tester->getStatusCode(), $case['label']);
                self::assertStringContainsString('invalid entry', $output, $case['label']);
                self::assertStringContainsString($invalidPath, $output, $case['label']);
                self::assertStringContainsString($case['location'], $output, $case['label']);
                self::assertStringContainsString($case['rule'], $output, $case['label']);
                self::assertStringNotContainsString('would break a store', $output, $case['label']);
                self::assertStringNotContainsString('checked message declarations are compatible', $output, $case['label']);

                $outputWithoutPath = str_replace($invalidPath, '', $output);
                foreach ($case['privateValues'] as $privateValue) {
                    self::assertStringNotContainsString($privateValue, $outputWithoutPath, $case['label']);
                }

                self::assertStringNotContainsString("\033", $output, $case['label']);
                self::assertStringNotContainsString("\e", $output, $case['label']);
            }
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function malformedEntries(): iterable
    {
        $entry = ['class' => 'App\\Order\\OrderPlaced', 'version' => 1, 'replaces' => []];
        $cases = ['scalar entry' => 'private-value', 'null entry' => null];
        foreach (['class', 'version', 'replaces'] as $field) {
            $missing = $entry;
            unset($missing[$field]);
            $cases['missing '.$field] = $missing;
        }
        foreach (['class' => ['', 42], 'version' => ['1', 0, -1, 1.5, true], 'replaces' => [null, 'former.alias', ['x' => 'former.alias'], [42], ['']]] as $field => $values) {
            foreach ($values as $index => $value) {
                $cases[$field.' invalid '.$index] = array_replace($entry, [$field => $value]);
            }
        }
        foreach ([true, false] as $baseline) {
            $side = $baseline ? 'baseline' : 'current';
            foreach ($cases as $name => $value) {
                yield $side.' '.$name => [['order.placed' => $value], $baseline];
            }
            yield $side.' empty alias' => [['' => $entry], $baseline];
        }
    }

    #[Test]
    public function identical_v2_personal_catalogues_are_accepted(): void
    {
        $events = ['customer.created' => self::v2Entry()];
        $tester = $this->checkV2($events, $events, self::v2Map());
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('personal contexts', self::flat($tester));
        self::assertStringContainsString('provenance is not attested', self::flat($tester));
        self::assertStringNotContainsString('removal of personal declarations are not checked', self::flat($tester));
        self::assertStringContainsString('not catalogued', self::flat($tester));
    }

    #[Test]
    public function historical_personal_key_removal_is_refused(): void
    {
        $current = self::v2Entry();
        $current['personal']['keys'] = ['name'];
        $map = self::v2Map();
        $map['App\\Customer']['keys'] = ['name'];
        $tester = $this->checkV2(['customer.created' => self::v2Entry()], ['customer.created' => $current], $map);
        self::assertSemanticV2Failure($tester, 'personal key');
        self::assertStringContainsString('email', self::flat($tester));
    }

    #[Test]
    public function historical_personal_subject_change_is_refused(): void
    {
        $current = self::v2Entry();
        $current['personal']['subject'] = 'account_id';
        $map = self::v2Map();
        $map['App\\Customer']['subject'] = 'account_id';
        $tester = $this->checkV2(['customer.created' => self::v2Entry()], ['customer.created' => $current], $map);
        self::assertSemanticV2Failure($tester, 'personal subject');
    }

    #[Test]
    public function removal_of_a_personal_declaration_is_refused(): void
    {
        $current = self::v2Entry();
        $current['personal'] = null;
        self::assertSemanticV2Failure($this->checkV2(['customer.created' => self::v2Entry()], ['customer.created' => $current], []), 'personal declaration');
    }

    #[Test]
    public function a_v2_class_change_is_refused_even_when_the_current_class_is_not_personal(): void
    {
        $current = self::v2Entry();
        $current['class'] = 'App\\Renamed';
        $current['personal'] = null;
        self::assertSemanticV2Failure($this->checkV2(['customer.created' => self::v2Entry()], ['customer.created' => $current], []), 'PHP class');
    }

    #[Test]
    public function v2_personal_checks_follow_replaced_aliases(): void
    {
        $current = self::v2Entry();
        $current['replaces'] = ['customer.created'];
        $current['personal']['keys'] = ['name'];
        $map = self::v2Map();
        $map['App\\Customer']['keys'] = ['name'];
        $tester = $this->checkV2(['customer.created' => self::v2Entry()], ['customer.registered' => $current], $map);
        self::assertSemanticV2Failure($tester, 'personal key');
        self::assertStringContainsString('customer.registered', self::flat($tester));
    }

    #[Test]
    public function adding_or_reordering_personal_keys_is_compatible(): void
    {
        foreach ([['email', 'name'], ['name', 'email', 'address']] as $keys) {
            $current = self::v2Entry();
            $current['personal']['keys'] = $keys;
            $map = self::v2Map();
            $map['App\\Customer']['keys'] = array_reverse($keys);
            $tester = $this->checkV2(['customer.created' => self::v2Entry()], ['customer.created' => $current], $map);
            self::assertSame(Command::SUCCESS, $tester->getStatusCode(), self::flat($tester));
        }
    }

    #[Test]
    public function current_v2_personal_metadata_must_match_the_runtime_map(): void
    {
        $entry = self::v2Entry();
        foreach ([null, ['subject' => 'other', 'keys' => ['name', 'email']], ['subject' => 'id', 'keys' => ['name']]] as $context) {
            $current = $entry;
            $current['personal'] = $context;
            $tester = $this->checkV2(['customer.created' => $entry], ['customer.created' => $current], self::v2Map());
            self::assertSame(Command::FAILURE, $tester->getStatusCode());
            self::assertStringContainsString('current runtime', self::flat($tester));
            self::assertStringContainsString('not judged', self::flat($tester));
            self::assertStringNotContainsString('would break a store', self::flat($tester));
        }
        $tester = $this->checkV2(['customer.created' => $entry], ['customer.created' => $entry], []);
        self::assertStringContainsString('current runtime', self::flat($tester));
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    #[Test]
    public function both_mixed_format_orientations_are_not_judged(): void
    {
        $v1 = self::v2Entry();
        unset($v1['personal']);
        $documents = [1 => ['customer.created' => $v1], 2 => ['customer.created' => self::v2Entry()]];
        foreach ([[1, 2], [2, 1]] as [$baselineVersion, $currentVersion]) {
            $tester = $this->checkV2($documents[$baselineVersion], $documents[$currentVersion], self::v2Map(), $baselineVersion, $currentVersion);
            self::assertSame(Command::FAILURE, $tester->getStatusCode());
            self::assertStringContainsString('mixed catalogue formats', self::flat($tester));
            self::assertStringContainsString('not judged', self::flat($tester));
            self::assertStringNotContainsString('would break a store', self::flat($tester));
        }
    }

    #[Test]
    public function malformed_v2_personal_contexts_are_not_judged_on_either_side(): void
    {
        $entry = self::v2Entry();
        $missing = $entry;
        unset($missing['personal']);
        $cases = [$missing];
        foreach (['secret-value', [], ['keys' => ['name']], ['subject' => '', 'keys' => ['name']], ['subject' => 'id'], ['subject' => 'id', 'keys' => 'secret-value'], ['subject' => 'id', 'keys' => [42]], ['subject' => 'id', 'keys' => ['']], ['subject' => 'id', 'keys' => ['name', 'name']], ['subject' => 'id', 'keys' => ['x' => 'name']]] as $context) {
            $cases[] = [...$entry, 'personal' => $context];
        }
        foreach ($cases as $invalid) {
            foreach ([true, false] as $baseline) {
                $tester = $this->checkV2(['customer.created' => $baseline ? $invalid : $entry], ['customer.created' => $baseline ? $entry : $invalid], self::v2Map());
                self::assertSame(Command::FAILURE, $tester->getStatusCode());
                self::assertStringContainsString('invalid entry', self::flat($tester));
                self::assertStringContainsString('.personal', self::flat($tester));
                self::assertStringContainsString('not judged', self::flat($tester));
                self::assertStringNotContainsString('secret-value', self::flat($tester));
                self::assertStringNotContainsString('would break a store', self::flat($tester));
            }
        }
    }

    #[Test]
    public function v2_non_personal_class_renames_remain_compatible_with_other_personal_classes(): void
    {
        $baseline = [...self::v2Entry(), 'personal' => null, 'class' => 'App\\Ordinary'];
        $current = [...$baseline, 'class' => 'App\\Renamed'];
        $tester = $this->checkV2(['customer.created' => $baseline], ['customer.created' => $current], self::v2Map());
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), self::flat($tester));
    }

    #[Test]
    public function v1_does_not_interpret_personal_fields_from_an_unknown_extension(): void
    {
        $entry = [...self::v2Entry(), 'personal' => 'not-a-context'];
        $tester = $this->checkV2(['customer.created' => $entry], ['customer.created' => $entry], [], 1, 1);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('removal of personal declarations are not checked', self::flat($tester));
    }

    #[Test]
    public function v2_preserves_the_current_personal_class_rename_guard(): void
    {
        $baseline = [...self::v2Entry(), 'class' => 'App\\OldCustomer', 'personal' => null];
        $tester = $this->checkV2(['customer.created' => $baseline], ['customer.created' => self::v2Entry()], self::v2Map());
        self::assertSemanticV2Failure($tester, 'PHP class');
    }

    #[Test]
    public function v2_preserves_alias_and_version_guards(): void
    {
        $entry = self::v2Entry();
        $cases = [
            'no longer resolves' => [[], 'would break a store'],
            'down to' => [['customer.created' => [...$entry, 'version' => 1]], 'would break a store'],
            'no upcaster' => [['customer.created' => [...$entry, 'version' => 3]], 'would break a store'],
            'alias is already claimed' => [['customer.created' => $entry, 'customer.other' => [...$entry, 'replaces' => ['customer.created']]], 'invalid entry'],
        ];
        foreach ($cases as $reason => [$current, $category]) {
            $tester = $this->checkV2(['customer.created' => [...$entry, 'version' => 2]], $current, self::v2Map());
            self::assertSame(Command::FAILURE, $tester->getStatusCode());
            self::assertStringContainsString($reason, self::flat($tester));
            self::assertStringContainsString($category, self::flat($tester));
        }
    }

    #[Test]
    public function compatibility_and_runtime_diagnostics_preserve_literal_alias_markup(): void
    {
        $alias = '<error>customer.created</error>';
        $entry = self::v2Entry();
        $removed = $this->checkV2([$alias => $entry], [], []);
        self::assertSame(Command::FAILURE, $removed->getStatusCode());
        self::assertStringContainsString($alias, self::flat($removed));
        self::assertStringContainsString('no longer resolves', self::flat($removed));
        $mismatch = $this->checkV2([$alias => $entry], [$alias => $entry], []);
        self::assertSame(Command::FAILURE, $mismatch->getStatusCode());
        self::assertStringContainsString($alias, self::flat($mismatch));
        self::assertStringContainsString('not judged', self::flat($mismatch));
    }

    #[Test]
    public function an_empty_personal_object_names_its_required_fields(): void
    {
        $entry = [...self::v2Entry(), 'personal' => (object) []];
        $tester = $this->checkV2(['customer.created' => $entry], ['customer.created' => self::v2Entry()], self::v2Map());
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('object containing subject and keys', self::flat($tester));
        self::assertStringContainsString('not judged', self::flat($tester));
    }

    private static function assertSemanticV2Failure(CommandTester $tester, string $reason): void
    {
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('customer.created', self::flat($tester));
        self::assertStringContainsString($reason, self::flat($tester));
        self::assertStringNotContainsString('format version', self::flat($tester));
        self::assertStringNotContainsString('invalid entry', self::flat($tester));
    }

    /**
     * @return array{class: string, version: int, replaces: list<string>, personal: array{subject: string, keys: list<string>}}
     */
    private static function v2Entry(): array
    {
        return ['class' => 'App\\Customer', 'version' => 1, 'replaces' => [], 'personal' => ['subject' => 'id', 'keys' => ['name', 'email']]];
    }

    /**
     * @return array{'App\\Customer': array{subject: string, keys: list<string>, fallbacks: array<string, mixed>}}
     */
    private static function v2Map(): array
    {
        return ['App\\Customer' => ['subject' => 'id', 'keys' => ['name', 'email'], 'fallbacks' => ['name' => null, 'email' => null]]];
    }

    /**
     * @param  array<string, mixed>  $baseline
     * @param  array<string, mixed>  $current
     * @param  array<string, array{subject: string, keys: list<string>, fallbacks: array<string, mixed>}>  $map
     */
    private function checkV2(array $baseline, array $current, array $map, int $baselineVersion = 2, int $currentVersion = 2): CommandTester
    {
        $tester = new CommandTester(new MessageCheckCommand(new CatalogueCompatibility(new UpcasterChain, $map)));
        $tester->execute([
            'baseline' => $this->file((string) json_encode(['schema_version' => $baselineVersion, 'events' => (object) $baseline])),
            'current' => $this->file((string) json_encode(['schema_version' => $currentVersion, 'events' => (object) $current])),
        ]);

        return $tester;
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
