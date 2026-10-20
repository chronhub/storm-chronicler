<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Console\MessageCheckCommand;
use Storm\Chronicler\Evolution\CatalogueCompatibility;
use Storm\Chronicler\Evolution\MappedEventTypeMapper;
use Storm\Chronicler\Evolution\Upcaster;
use Storm\Chronicler\Evolution\UpcasterChain;
use Storm\Chronicler\Exception\DuplicateUpcaster;
use Storm\Chronicler\Exception\MissingUpcaster;
use Storm\Chronicler\Exception\UnsupportedFutureEventVersion;
use Storm\Chronicler\Record\EventRowCodec;
use Storm\Chronicler\Record\StoredEventReader;
use Storm\Message\Header;
use Storm\Message\Message;
use Storm\Serializer\DefaultMessageSerializer;
use Storm\Stream\StreamName;
use Storm\Symfony\MessageCatalogue;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

require_once __DIR__.'/Fixture/BeforeV1/Event.php';
require_once __DIR__.'/Fixture/BeforeV2/Event.php';
require_once __DIR__.'/Fixture/AfterV1/Event.php';
require_once __DIR__.'/Fixture/AfterV2/Event.php';
require_once __DIR__.'/Fixture/StableV2/Event.php';

final class CatalogueRenameProofTest extends TestCase
{
    /**
     * @param  class-string<Throwable>  $expectedFailure
     */
    #[DataProvider('unreadableRenames')]
    public function test_catalogue_gate_refuses_a_renamed_alias_whose_previous_rows_are_unreadable(
        string $before,
        string $after,
        int $upcasterCount,
        string $expectedFailure,
    ): void {
        $result = $this->compare($before, $after, $this->chain($upcasterCount));

        self::assertInstanceOf($expectedFailure, $result['read_failure'], $result['record']);
        self::assertNull($result['message'], $result['record']);
        self::assertSame(Command::FAILURE, $result['status'], 'The declaration gate must refuse the same unreadable version transition as the real stored-row reader. '.$result['record']);
    }

    /**
     * @return iterable<string, array{string, string, int, class-string<Throwable>}>
     */
    public static function unreadableRenames(): iterable
    {
        yield 'rename and missing version step' => ['BeforeV1', 'AfterV2', 0, MissingUpcaster::class];
        yield 'rename and version rollback' => ['BeforeV2', 'AfterV1', 0, UnsupportedFutureEventVersion::class];
        yield 'rename and duplicate version step' => ['BeforeV1', 'AfterV2', 2, DuplicateUpcaster::class];
    }

    public function test_a_stable_alias_with_a_missing_step_is_refused(): void
    {
        $result = $this->compare('BeforeV1', 'StableV2', new UpcasterChain);

        self::assertInstanceOf(MissingUpcaster::class, $result['read_failure'], $result['record']);
        self::assertSame(Command::FAILURE, $result['status'], $result['record']);
    }

    public function test_a_rename_at_the_same_version_remains_readable(): void
    {
        $result = $this->compare('BeforeV1', 'AfterV1', new UpcasterChain);

        self::assertNull($result['read_failure'], $result['record']);
        self::assertSame(Command::SUCCESS, $result['status'], $result['record']);
        self::assertNotNull($result['message'], $result['record']);
        self::assertInstanceOf(Storm\Contracts\Message\DomainEvent::class, $result['message']->message(), $result['record']);
        self::assertSame(['id' => 'catalogue-proof', 'name' => 'original'], $result['message']->message()->toPayload(), $result['record']);
    }

    public function test_a_rename_with_its_canonical_upcaster_remains_readable(): void
    {
        $result = $this->compare('BeforeV1', 'AfterV2', $this->chain(1));

        self::assertNull($result['read_failure'], $result['record']);
        self::assertSame(Command::SUCCESS, $result['status'], $result['record']);
        self::assertNotNull($result['message'], $result['record']);
        self::assertInstanceOf(Storm\Contracts\Message\DomainEvent::class, $result['message']->message(), $result['record']);
        self::assertSame(['id' => 'catalogue-proof', 'name' => 'original', 'upcasted' => true], $result['message']->message()->toPayload(), $result['record']);
    }

    private function chain(int $count): UpcasterChain
    {
        $upcasters = [];
        for ($i = 0; $i < $count; $i++) {
            $upcasters[] = new CanonicalRenameUpcaster;
        }

        return new UpcasterChain($upcasters);
    }

    /**
     * @return array{status: int, read_failure: Throwable|null, message: Message|null, record: string}
     */
    private function compare(string $before, string $after, UpcasterChain $chain): array
    {
        $baselineJson = MessageCatalogue::render([__DIR__.'/Fixture/'.$before]);
        $currentJson = MessageCatalogue::render([__DIR__.'/Fixture/'.$after]);
        $baseline = json_decode($baselineJson, true, flags: JSON_THROW_ON_ERROR)['events'];
        $current = json_decode($currentJson, true, flags: JSON_THROW_ON_ERROR)['events'];
        self::assertCount(1, $baseline);
        self::assertCount(1, $current);
        $beforeClass = array_values($baseline)[0]['class'];
        $afterClass = array_values($current)[0]['class'];
        $oldMapper = new MappedEventTypeMapper([$beforeClass]);
        $currentMapper = new MappedEventTypeMapper([$afterClass]);
        $row = new EventRowCodec(new DefaultMessageSerializer, $oldMapper, new UpcasterChain)->toRow(
            new StreamName('catalogue')->withQualifier('proof'),
            new Message($beforeClass::fromPayload(['id' => 'catalogue-proof', 'name' => 'original']))->withHeader(Header::AggregateVersion, 1),
        );
        self::assertSame($afterClass, $currentMapper->toClass($row['type']));
        $reader = new StoredEventReader(new DefaultMessageSerializer, $currentMapper, $chain);
        $readFailure = null;
        $message = null;
        try {
            $message = $reader->toMessage($row);
        } catch (Throwable $caught) {
            $readFailure = $caught;
        }

        $baselinePath = tempnam(dirname(__DIR__, 4).'/tmp', 'catalogue-361-before-');
        $currentPath = tempnam(dirname(__DIR__, 4).'/tmp', 'catalogue-361-after-');
        self::assertIsString($baselinePath);
        self::assertIsString($currentPath);
        try {
            self::assertSame(strlen($baselineJson), file_put_contents($baselinePath, $baselineJson));
            self::assertSame(strlen($currentJson), file_put_contents($currentPath, $currentJson));
            $console = new CommandTester(new MessageCheckCommand(new CatalogueCompatibility($chain)));
            $status = $console->execute(['baseline' => $baselinePath, 'current' => $currentPath], ['interactive' => false]);
            // the proof record travels in the assertion messages, never STDERR, where it would fail
            // the initial pass of every mutation gate that runs this suite
            $record = json_encode([
                'before' => $before,
                'after' => $after,
                'gate_status' => $status,
                'reader_exception' => $readFailure === null ? null : $readFailure::class,
                'command_output' => $console->getDisplay(),
            ], JSON_THROW_ON_ERROR);
        } finally {
            unlink($baselinePath);
            unlink($currentPath);
        }

        return ['status' => $status, 'read_failure' => $readFailure, 'message' => $message, 'record' => $record];
    }
}

final class CanonicalRenameUpcaster implements Upcaster
{
    public function supports(string $alias, int $fromVersion): bool
    {
        return $alias === 'review361.renamed' && $fromVersion === 1;
    }

    public function upcast(array $payload): array
    {
        $payload['upcasted'] = true;

        return $payload;
    }
}
