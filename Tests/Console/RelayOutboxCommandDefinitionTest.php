<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests\Console;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Console\RelayOutboxCommand;
use Storm\Chronicler\Evolution\UpcasterChain;
use Storm\Chronicler\Outbox\OutboxPublisher;
use Storm\Chronicler\Outbox\OutboxRelay;
use Storm\Chronicler\Record\StoredEventReader;
use Storm\Contracts\Chronicler\EventTypeMapper;
use Storm\Serializer\MessageSerializer;

/**
 * The relay command declares the surface an operator and a supervisor call it with:
 *
 * - Its name
 * - A batch bound defaulting to 100
 * - The daemon loop's options, idling 800 milliseconds between empty passes
 */
final class RelayOutboxCommandDefinitionTest extends TestCase
{
    #[Test]
    public function the_relay_declares_its_batch_and_its_daemon_options(): void
    {
        $relay = new OutboxRelay(
            $this->createStub(Connection::class),
            new StoredEventReader($this->createStub(MessageSerializer::class), $this->createStub(EventTypeMapper::class), new UpcasterChain),
            $this->createStub(OutboxPublisher::class),
        );

        $command = new RelayOutboxCommand($relay);
        $definition = $command->getDefinition();

        $this->assertSame('storm:outbox:relay', $command->getName());
        $this->assertTrue($definition->getOption('batch')->isValueRequired());
        $this->assertSame('100', $definition->getOption('batch')->getDefault());
        $this->assertTrue($definition->hasOption('daemon'));
        $this->assertSame('800', $definition->getOption('sleep')->getDefault());
        $this->assertTrue($definition->hasOption('time-limit'));
    }
}
