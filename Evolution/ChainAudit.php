<?php

declare(strict_types=1);

namespace Storm\Chronicler\Evolution;

/**
 * The static verdict of {@see UpcasterChain::audit()} over one alias's version steps.
 *
 * It answers, before any row is read, the two ways `upcast()` fails loud at read. A value object, not a
 * service.
 */
final readonly class ChainAudit
{
    /**
     * @param  list<int>  $missing  steps `[v, v+1)` no upcaster claims; reading a row at or below
     *                              such a step fails with `MissingUpcaster`
     * @param  list<int>  $duplicated  steps more than one upcaster claims; reading through such a
     *                                 step fails with `DuplicateUpcaster`
     */
    public function __construct(
        public array $missing,
        public array $duplicated,
    ) {}

    /**
     * Whether every audited step has exactly one owner.
     */
    public function ok(): bool
    {
        return $this->missing === [] && $this->duplicated === [];
    }
}
