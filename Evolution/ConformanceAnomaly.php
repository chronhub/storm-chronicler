<?php

declare(strict_types=1);

namespace Storm\Chronicler\Evolution;

/**
 * One typed verdict of the readability conformance.
 *
 * It says which stored type this binary cannot fully read, at which versions, and in which way. A value
 * object, not a service.
 *
 * The kind is what a deployment gate acts on, and the two repairs it separates are opposite moves:
 * rows above the runtime want the binary rolled forward, a step with no upcaster wants it held back
 * until the upcaster ships. Neither is legible in a sentence a script has to match.
 */
final readonly class ConformanceAnomaly
{
    /**
     * @param  string  $type  the stored `type` column, the alias exactly as the rows carry it
     * @param  int  $floor  `min(event_version)` for that type, the data-computed floor; the
     *                      aggregation reports no group without it, so every kind carries it
     * @param  int|null  $current  the version this runtime declares for the type; null under
     *                             `AnomalyKind::UnknownType` and `AnomalyKind::NotAnEvent` alone,
     *                             where no domain event class was reached and no version was ever
     *                             asked for, and set under every other kind
     * @param  int  $ceiling  `max(event_version)` for that type, the offending version under
     *                        `AnomalyKind::AboveRuntime` and the stored top otherwise
     * @param  string  $problem  the phrase the console prints for this verdict, carried rather than
     *                           rebuilt, so an operator and a script read one verdict
     */
    public function __construct(
        public string $type,
        public int $floor,
        public ?int $current,
        public int $ceiling,
        public AnomalyKind $kind,
        public string $problem,
    ) {}

    /**
     * The machine shape, snake_case on the wire, the console's `--json` contract.
     *
     * @return array{type: string, floor: int, current: int|null, ceiling: int, kind: string, problem: string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'floor' => $this->floor,
            'current' => $this->current,
            'ceiling' => $this->ceiling,
            'kind' => $this->kind->value,
            'problem' => $this->problem,
        ];
    }
}
