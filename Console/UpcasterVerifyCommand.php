<?php

declare(strict_types=1);

namespace Storm\Chronicler\Console;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use JsonException;
use Override;
use Storm\Chronicler\Evolution\UpcasterConformance;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The deploy-time `MissingUpcaster` verdict: verifies every stored event row is readable by THIS
 * binary's upcaster chain, before any read pays the failure. A version bump shipped without its
 * upcaster is invisible until the first old row is read, at runtime and mid-drain; this command
 * moves that verdict to the deploy, where a binary and an existing store first meet.
 *
 * The floor is computed from the data, `min(event_version)` per stored type, so the reset-based
 * posture, a bump on a fresh schema with no old rows, needs no declaration and passes clean.
 * `storm:install` runs the same conformance inside its transaction when the event side carries
 * rows; this command is the standalone moment for a code-only deploy, which runs no install.
 *
 * Examples:
 *
 * ```bash
 * bin/console storm:upcaster:verify
 * ```
 */
#[AsCommand(name: 'storm:upcaster:verify', description: 'Verify every stored event row is readable by this binary\'s upcaster chain.')]
final class UpcasterVerifyCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly UpcasterConformance $conformance,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the machine-readable verdict and its reasons');
    }

    /**
     * {@inheritDoc}
     *
     * @throws Exception on a DBAL failure of the conformance query
     * @throws JsonException on encoding the --json output
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $problems = $this->conformance->problems($this->connection);

        if ($input->getOption('json') === true) {
            // a deployment gate is scripted by definition: the verdict rides the exit code, the reasons
            // ride the document, and neither has to be scraped out of an error block
            $output->writeln(json_encode(
                ['conformant' => $problems === [], 'problems' => $problems],
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $problems === [] ? Command::SUCCESS : Command::FAILURE;
        }

        if ($problems === []) {
            $io->success('Every stored event row is readable by this binary\'s upcaster chain.');

            return Command::SUCCESS;
        }

        $problems
            |> count(...)
            |> (static fn ($x) => sprintf('%d readability problem(s) — reading the affected rows would fail loud at runtime:', $x))
            |> $io->error(...);
        $io->listing($problems);

        return Command::FAILURE;
    }
}
