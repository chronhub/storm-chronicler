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
 * The decision-append port: appends a stream of decisions under optimistic concurrency.
 *
 * A decision is an event released by an aggregate that read its state and enforced its invariants
 * before writing. The caller states the version its decision was based on; a stream that moved past it
 * means the decision was made against stale state, so the append must fail and the caller reloads and
 * re-decides. That CAS turns a would-be lost update into a reload and retry.
 *
 * It is the guarded sibling of {@see FactAppend}: decision streams compare and swap here, fact streams
 * bump there. A stream is one or the other, never both, and the `UNIQUE (category, stream, version)`
 * backstop rejects the mix.
 */
interface DecisionAppend
{
    /**
     * Append the stream's messages, expecting the stream at `$expectedVersion`, or 0 for a new stream.
     *
     * The batch must stamp versions `$expectedVersion + 1` up to `+ N`, contiguous; the store
     * refuses a batch that claims anything else.
     *
     * @throws IncoherentDecisionVersions when the batch's stamped versions are not exactly that
     *                                    contiguous range
     * @throws SubjectForgotten when a personal payload names a tombstoned subject: no key can be
     *                          issued for it, so the write refuses rather than storing in clear
     * @throws ConcurrencyException when the stream has moved past `$expectedVersion`
     * @throws StorageFailure on a non-retryable store failure of the head ops; a retryable
     *                        `RetryableStorageFailure` is retried like any `RetryableException`
     * @throws Throwable the raw driver failure of the statements this port issues, unnameable here:
     *                   naming it would drag the driver into a package built to stay free of it, and
     *                   the retry boundary below matches it by pattern, so translation happens at the
     *                   consuming frontier and not at the source
     */
    public function appendTo(Stream $stream, int $expectedVersion): void;
}
