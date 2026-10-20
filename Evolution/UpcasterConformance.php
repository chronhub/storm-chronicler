<?php

declare(strict_types=1);

namespace Storm\Chronicler\Evolution;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Storm\Chronicler\Console\UpcasterVerifyCommand;
use Storm\Chronicler\Exception\UpcasterFailed;
use Storm\Contracts\Chronicler\EventTypeMapper;
use Storm\Contracts\Chronicler\UnknownEventType;
use Storm\Contracts\Message\DomainEvent;

/**
 * The data-level readability conformance of the stored event rows.
 *
 * Every stored row must be readable by this binary's upcaster chain, verified as a deploy-time verdict
 * instead of a runtime `MissingUpcaster` on the first old row read.
 *
 * The floor needs no declaration: it is computed from the data, `min(event_version)` per stored type.
 * A version bump without an upcaster is legitimate as long as no row of the older shape exists, the
 * reset-based dev workflow; once such a row exists, this check names the missing steps. It reports five
 * failure families:
 *
 * - A stored type no declared class resolves, so its rows are unreadable outright
 *
 * - A stored type resolving to a loadable class that is not a domain event, a restore or import
 *   artifact the read path refuses before hydration
 *
 * - Rows stored above the runtime's version, a rolled-back binary reading a future schema
 *
 * - Chain gaps or duplicate step owners between the stored floor and the current version
 *
 * - An upcaster whose own probe threw, so the chain cannot say what it owns and the steps of that
 *   type go unjudged
 *
 * The families the reader refuses on and the families this reports must stay the same set, in both
 * directions: a verdict this omits ships a store the reader will reject row by row, and a verdict
 * only this holds refuses a deploy nothing would have failed on.
 *
 * The check guards the store, whose rows have no consumer edge and would otherwise fail only when read.
 * The neutral wire stays outside it: a foreign producer's old or future version is refused loud at
 * decode, the message captured or dropped at the consumer edge.
 *
 * @see UpcasterVerifyCommand
 */
final readonly class UpcasterConformance
{
    public function __construct(
        private EventTypeMapper $eventTypeMapper,
        private UpcasterChain $upcasters,
    ) {}

    /**
     * Interrogates the event store and reports every stored type this binary cannot fully read.
     *
     * @return list<string> problems; empty when every stored row is readable
     *
     * @throws Exception on a DBAL failure of the aggregation query
     */
    public function problems(Connection $connection): array
    {
        return array_map(static fn (ConformanceAnomaly $anomaly): string => $anomaly->problem, $this->anomalies($connection));
    }

    /**
     * The same interrogation, typed: one entry per problem, in the same order.
     *
     * Each entry carries the kind a deployment gate branches on, beside the phrase an operator reads.
     *
     * @return list<ConformanceAnomaly> empty when every stored row is readable
     *
     * @throws Exception on a DBAL failure of the aggregation query
     */
    public function anomalies(Connection $connection): array
    {
        /** @var list<array{type: string, floor: int|string, ceiling: int|string, n: int|string}> $stored */
        $stored = $connection->fetchAllAssociative(
            'SELECT type, min(event_version) AS floor, max(event_version) AS ceiling, count(*) AS n FROM event_store GROUP BY type ORDER BY type',
        );

        return $this->anomaliesOf($stored);
    }

    /**
     * The pure half, one verdict per stored type.
     *
     * It stays apart from the query so unit tests pin the rules without a database.
     *
     * @param  list<array{type: string, floor: int|string, ceiling: int|string, n: int|string}>  $stored
     * @return list<string>
     */
    public function verdicts(array $stored): array
    {
        return array_map(static fn (ConformanceAnomaly $anomaly): string => $anomaly->problem, $this->anomaliesOf($stored));
    }

    /**
     * The typed pure half, the single place a verdict is decided.
     *
     * Every other entry point here reads its answer, so the phrase and the kind can never disagree about
     * the same store.
     *
     * @param  list<array{type: string, floor: int|string, ceiling: int|string, n: int|string}>  $stored
     * @return list<ConformanceAnomaly>
     */
    public function anomaliesOf(array $stored): array
    {
        $anomalies = [];

        foreach ($stored as $row) {
            $type = $row['type'];
            $floor = (int) $row['floor'];
            $ceiling = (int) $row['ceiling'];
            $rows = (int) $row['n'];

            try {
                $class = $this->eventTypeMapper->toClass($type);
            } catch (UnknownEventType) {
                $anomalies[] = new ConformanceAnomaly(
                    type: $type, floor: $floor, current: null, ceiling: $ceiling, kind: AnomalyKind::UnknownType,
                    problem: sprintf('stored type "%s" (%d rows) resolves to no declared event class — those rows are unreadable', $type, $rows),
                );

                continue;
            }

            if (! is_a($class, DomainEvent::class, true)) {
                // the same gate the read path applies, and the reason for this check: the store holds
                // domain events exclusively, so a row whose type resolves elsewhere is refused before
                // its payload is hydrated. Reporting it here makes the verdict mean readable, where the
                // resolution alone only means the class loads
                $anomalies[] = new ConformanceAnomaly(
                    type: $type, floor: $floor, current: null, ceiling: $ceiling, kind: AnomalyKind::NotAnEvent,
                    problem: sprintf('stored type "%s" (%d rows) resolves to "%s", which is not a domain event — the read path refuses those rows before hydrating them', $type, $rows, $class),
                );

                continue;
            }

            $current = $this->eventTypeMapper->versionOf($class);

            if ($ceiling > $current) {
                $anomalies[] = new ConformanceAnomaly(
                    type: $type, floor: $floor, current: $current, ceiling: $ceiling, kind: AnomalyKind::AboveRuntime,
                    problem: sprintf('stored type "%s" has rows at schema version %d but this runtime only knows version %d — roll the binary forward', $type, $ceiling, $current),
                );
            }

            // the audit polls the canonical alias, exactly what the read path hands the chain after
            // resolving the stored one; auditing the stored alias would lie both ways on a renamed
            // type: report a gap the runtime never hits, or approve a chain it never calls
            $canonical = $this->eventTypeMapper->toType($class);
            $label = $canonical === $type ? sprintf('"%s"', $type) : sprintf('"%s" (canonical "%s")', $type, $canonical);

            try {
                $audit = $this->upcasters->audit($canonical, $floor, $current);
            } catch (UpcasterFailed $e) {
                $anomalies[] = new ConformanceAnomaly(
                    type: $type, floor: $floor, current: $current, ceiling: $ceiling, kind: AnomalyKind::UpcasterFailed,
                    problem: sprintf('stored type %s: %s', $label, $e->getMessage()),
                );

                continue;
            }

            $anomalies = [
                ...$anomalies,
                ...array_map(static fn (int $step): ConformanceAnomaly => new ConformanceAnomaly(
                    type: $type, floor: $floor, current: $current, ceiling: $ceiling, kind: AnomalyKind::MissingStep,
                    problem: sprintf('stored type %s (floor v%d, current v%d): no upcaster for the step v%d to v%d — rows below the gap are unreadable', $label, $floor, $current, $step, $step + 1),
                ), $audit->missing),
                ...array_map(static fn (int $step): ConformanceAnomaly => new ConformanceAnomaly(
                    type: $type, floor: $floor, current: $current, ceiling: $ceiling, kind: AnomalyKind::DuplicatedStep,
                    problem: sprintf('stored type %s: more than one upcaster claims the step v%d to v%d — the migration would depend on discovery order', $label, $step, $step + 1),
                ), $audit->duplicated),
            ];
        }

        return $anomalies;
    }
}
