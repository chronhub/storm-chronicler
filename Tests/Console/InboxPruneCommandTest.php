<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests\Console;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Console\InboxPruneCommand;
use Storm\Chronicler\Inbox\DbalInboxStore;
use Storm\Chronicler\Inbox\InboxConsumers;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The prune command's own refusals, decided before the store is reached: what the store deletes is
 * proven against PostgreSQL by the integration suite, so the connection here must never be read.
 */
final class InboxPruneCommandTest extends TestCase
{
    #[Test]
    public function a_retention_equal_to_the_idempotency_lifetime_reaches_the_batch_check(): void
    {
        // the retention must COVER the retry window, and a retention of exactly that window does, so
        // the next refusal is the batch's own; read as `<=`, the retention guard would answer first
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method($this->anything());

        $tester = new CommandTester(new InboxPruneCommand(new DbalInboxStore($connection, new InboxConsumers($connection)), 3600));
        $tester->execute(['--before' => '1h', '--batch' => '0']);

        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $this->assertStringContainsString('--batch must be a positive integer', $tester->getDisplay());
        $this->assertStringNotContainsString('idempotency retry window', $tester->getDisplay());
    }
}
