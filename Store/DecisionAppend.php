<?php

declare(strict_types=1);

namespace Storm\Chronicler\Store;

use Storm\Chronicler\Exception\IncoherentDecisionVersions;
use Storm\Contracts\Chronicler\ConcurrencyException;
use Storm\Contracts\Chronicler\StorageFailure;
use Storm\Contracts\Serializer\SubjectForgotten;
use Storm\Stream\Stream;
use Throwable;

/**
 * The decision-append port: append a stream of DECISIONS under optimistic concurrency. A
 * decision is an event released by an aggregate that read its state and enforced its invariants
 * before writing. The caller states the version its decision was based on; a stream that moved
 * past it means the decision was made against stale state, so the append MUST fail and the
 * caller reloads and re-decides. That CAS is the whole point: it turns a would-be lost update
 * into a reload-and-retry.
 *
 * The guarded sibling of {@see FactAppend}: decision streams CAS here, fact streams bump there.
 * A stream is EITHER one OR the other, never both; the `UNIQUE (category, stream, version)`
 * backstop punishes the mix.
 */
interface DecisionAppend
{
    /**
     * Append the stream's messages, expecting the stream to currently be at `$expectedVersion`,
     * or 0 for a brand-new stream.
     *
     * The batch must stamp versions `$expectedVersion + 1` up to `+ N`, contiguous; the store
     * refuses a batch that claims anything else.
     *
     * @throws IncoherentDecisionVersions when the batch's stamped versions are not exactly that contiguous range
     * @throws SubjectForgotten when a personal payload names a tombstoned subject: no key can be
     *                          issued for it, so the write refuses rather than storing in clear
     * @throws ConcurrencyException when the stream has moved past `$expectedVersion`
     * @throws StorageFailure on a non-retryable store failure of the head ops; a retryable
     *                        `RetryableStorageFailure` is retried like any `RetryableException`
     * @throws Throwable the raw driver failure of the statements this port issues, unnameable here:
     *                   naming it would drag the driver into a package built to stay free of it, and
     *                   the retry boundary below pattern-matches it on purpose, so translation
     *                   happens at the consuming frontier and not at the source
     */
    public function appendTo(Stream $stream, int $expectedVersion): void;
}
