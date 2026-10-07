<?php

declare(strict_types=1);

namespace Storm\Chronicler\Evolution;

use Storm\Chronicler\Exception\DuplicateUpcaster;
use Storm\Chronicler\Exception\MissingUpcaster;
use Storm\Chronicler\Exception\UnsupportedFutureEventVersion;
use Storm\Chronicler\Exception\UpcasterFailed;
use Storm\Contracts\Chronicler\UndecodableRow;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Throwable;
use Traversable;

/**
 * Applies the registered Upcasters to bring a stored payload from its `event_version` up to the
 * type's current version, one step at a time, keyed on the canonical alias.
 *
 * Runs at the read boundary, `fromRow`, the outbox relay, and the neutral decoder, before the
 * event is rebuilt from the payload, so the deserializer always sees a current-version payload. A
 * version step with no upcaster is a fail-fast MissingUpcaster; a bump needs its upcaster. Two
 * upcasters on one step is the mirror error, and just as loud, a DuplicateUpcaster: the invariant is
 * ONE step = ONE upcaster, and an immutable history cannot have its migration decided by the order
 * services happen to be discovered in.
 */
final readonly class UpcasterChain
{
    /** @var list<Upcaster> */
    private array $upcasters;

    /**
     * @param  iterable<Upcaster>  $upcasters
     */
    public function __construct(
        #[AutowireIterator('storm.upcaster')]
        iterable $upcasters = [],
    ) {
        $this->upcasters = $upcasters instanceof Traversable
            ? iterator_to_array($upcasters, false)
            : array_values($upcasters);
    }

    /**
     * Statically audits the steps `[$fromVersion, $toVersion)` of one alias: which have no claiming
     * upcaster and which have more than one; the two ways `upcast()` fails loud at read, answered
     * BEFORE any row is read. Pure `supports()` polling, no payload touched: the deploy-time half
     * of the same invariant, consumed by the upcaster conformance.
     *
     * A missing or duplicated step is REPORTED here, never thrown: reporting is the whole point, and
     * a verdict that stopped at the first bad step would name one of the problems an operator is
     * about to fix in one deployment. Only the probe itself can end the audit.
     *
     * @throws UndecodableRow when an upcaster's own `supports()` threw, so the chain cannot say what
     *                        it owns; `resolvableByAnotherRuntime()` is true, a corrected deployment
     *                        answers
     *
     * @see UpcasterConformance
     */
    public function audit(string $alias, int $fromVersion, int $toVersion): ChainAudit
    {
        $missing = [];
        $duplicated = [];

        for ($version = $fromVersion; $version < $toVersion; $version++) {
            $claimants = count(array_filter(
                $this->upcasters,
                fn (Upcaster $upcaster): bool => $this->claims($upcaster, $alias, $version),
            ));

            if ($claimants === 0) {
                $missing[] = $version;
            }

            if ($claimants > 1) {
                $duplicated[] = $version;
            }
        }

        return new ChainAudit($missing, $duplicated);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws UndecodableRow when this runtime cannot decode a stored row, a missing or duplicated
     *                        version step, an upcaster that threw, or a row written by a newer
     *                        release; `resolvableByAnotherRuntime()` separates a deployment state
     *                        from a row no release will decode
     */
    public function upcast(string $alias, int $fromVersion, int $toVersion, array $payload): array
    {
        if ($fromVersion > $toVersion) {
            // the mirror asymmetry would be silent: the loop below is loud on the PAST, a missing
            // upcaster throws, but zero-iterates on the FUTURE; a downgrade must be as loud
            throw UnsupportedFutureEventVersion::forEvent($alias, $fromVersion, $toVersion);
        }

        for ($version = $fromVersion; $version < $toVersion; $version++) {
            $payload = $this->step($alias, $version, $payload);
        }

        return $payload;
    }

    /**
     * The one upcaster owning this step, applied to the payload.
     *
     * Every candidate is polled, never the first match returned: two services claiming the same step
     * would otherwise make the migrated payload depend on discovery order, silently, with the losing
     * implementation invisible and nothing failing at build or first read. The chain holds one
     * upcaster per version step of one alias, so the extra `supports()` calls are a handful on a path
     * that then json-decodes and rebuilds an event.
     *
     * The check is at read, not at build, because `supports()` is dynamic: a service decides at
     * runtime which steps it answers for, so no compiler pass can enumerate the claims. A declarative
     * identity, one attribute naming alias and version, would move this to boot; that is the trade
     * this API made, and this keeps its failure loud instead of silent.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws UndecodableRow when this runtime cannot decode a stored row, a missing or duplicated
     *                        version step, an upcaster that threw, or a row written by a newer
     *                        release; `resolvableByAnotherRuntime()` separates a deployment state
     *                        from a row no release will decode
     */
    private function step(string $alias, int $fromVersion, array $payload): array
    {
        $claimants = array_filter(
            $this->upcasters,
            fn (Upcaster $upcaster): bool => $this->claims($upcaster, $alias, $fromVersion),
        ) |> array_values(...);

        if ($claimants === []) {
            throw MissingUpcaster::forStep($alias, $fromVersion);
        }

        if (count($claimants) > 1) {
            throw DuplicateUpcaster::forStep($alias, $fromVersion, array_map(static fn (Upcaster $u): string => $u::class, $claimants));
        }

        try {
            return $claimants[0]->upcast($payload);
        } catch (Throwable $e) {
            // A user upcaster's own failure, a TypeError on a payload shaped otherwise than it
            // assumed or a failed assertion, must not escape raw: raw, it is indistinguishable
            // from an infrastructure crash, so the outbox relay would roll back its whole batch
            // and crash-loop the daemon over one row. Typed, it joins the other decode faults of
            // this chain, the same disposition: THIS binary cannot decode the row, a corrected
            // deployment can. The wrap names the service and the step, which the underlying
            // "undefined array key" never does.
            throw UpcasterFailed::forStep($alias, $fromVersion, $claimants[0]::class, $e);
        }
    }

    /**
     * One probe, wrapped: `supports()` runs user code too, so its failure joins the same typed
     * lane as a failed migration instead of escaping raw from both `audit()` and the read path.
     *
     * @throws UndecodableRow when this runtime cannot decode a stored row, a missing or duplicated
     *                        version step, an upcaster that threw, or a row written by a newer
     *                        release; `resolvableByAnotherRuntime()` separates a deployment state
     *                        from a row no release will decode
     */
    private function claims(Upcaster $upcaster, string $alias, int $version): bool
    {
        try {
            return $upcaster->supports($alias, $version);
        } catch (Throwable $e) {
            throw UpcasterFailed::whileProbing($alias, $version, $upcaster::class, $e);
        }
    }
}
