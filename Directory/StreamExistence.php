<?php

declare(strict_types=1);

namespace Storm\Chronicler\Directory;

use Storm\Chronicler\Erasure\StreamEraser;
use Storm\Contracts\Chronicler\StorageFailure;
use Storm\Stream\Exception\InvalidStreamException;
use Storm\Stream\StreamName;

/**
 * Read-only existence port over the store: does this exact stream exist, does this category hold
 * at least one stream. A module-local port, like StreamHeadStore: pure signatures, a DB-agnostic
 * seam, though the default implementation is Postgres.
 *
 * Two deliberately distinct questions that legitimately diverge. `hasStream` answers for the exact
 * name, so a bare category names the category stream itself and `order` can be false while `order-*`
 * streams exist. `hasCategory` answers liveness: at least one stream, bare or qualified, lives in
 * the category. Exact existence is authoritative through the stream-heads registry: both append
 * paths create the head inside the store's transaction and the eraser removes events and head
 * atomically, so a stream exists exactly when its head row does.
 *
 * The caching boundary follows the method boundary: per-stream answers must never be cached, their
 * key space unbounded and erasure revoking positives, so `hasStream` is always live; category-level
 * answers are the sanctioned cache seam, served by an opt-in decorator.
 *
 * @see StreamHeadStore the registry behind the exact question
 * @see StreamEraser the destructive op that keeps the registry honest
 */
interface StreamExistence
{
    /**
     * Does a stream with this exact name exist?
     *
     * @throws StorageFailure on a store failure of the existence read
     */
    public function hasStream(StreamName $streamName): bool;

    /**
     * Does the category hold at least one stream, bare or qualified?
     *
     * The category is normalized like StreamName normalizes its category segment, trimmed and
     * lowercased, before validation.
     *
     * @throws InvalidStreamException when `$category` is not a bare, well-formed stream category
     * @throws StorageFailure on a store failure of the existence read
     */
    public function hasCategory(string $category): bool;
}
