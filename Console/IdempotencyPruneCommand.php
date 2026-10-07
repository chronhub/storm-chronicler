<?php

declare(strict_types=1);

namespace Storm\Chronicler\Console;

use Doctrine\DBAL\Exception;
use Storm\Chronicler\Idempotency\DbalIdempotencyRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Removes expired request identity claims in bounded batches. Expiration already permits
 * a new generation, so purging only reclaims storage and never shortens a live retry window.
 *
 * Examples:
 *
 * ```bash
 * bin/console storm:idempotency:prune --dry-run
 * bin/console storm:idempotency:prune --batch=500
 * ```
 */
#[AsCommand(
    name: 'storm:idempotency:prune',
    description: 'Remove expired request identity claims.',
    help: 'Remove only expired claims in bounded batches. Use --dry-run to count without deleting. Examples: storm:idempotency:prune --dry-run; storm:idempotency:prune --batch=500.',
)]
final readonly class IdempotencyPruneCommand
{
    public function __construct(private DbalIdempotencyRegistry $registry) {}

    /**
     * @throws Exception on a database failure
     * @throws \Storm\Contracts\Chronicler\InvalidIdempotencyClaim when the caller owns a transaction
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Rows deleted per batch.')]
        int $batch = 1000,
        #[Option(description: 'Count expired claims without deleting them.')]
        bool $dryRun = false,
    ): int {
        if ($batch < 1) {
            $io->error('--batch must be a positive integer.');

            return Command::INVALID;
        }
        $count = $dryRun ? $this->registry->countExpired() : $this->registry->prune($batch);
        $io->success(sprintf($dryRun ? 'Would prune %d expired claim(s).' : 'Pruned %d expired claim(s).', $count));

        return Command::SUCCESS;
    }
}
