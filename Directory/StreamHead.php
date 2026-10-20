<?php

declare(strict_types=1);

namespace Storm\Chronicler\Directory;

/**
 * One row of the stream directory: a stream name and its last committed version.
 *
 * Read from `stream_heads`, it is a plain data carrier yielded by browse reads, never written through.
 *
 * @see StreamDirectory
 */
final readonly class StreamHead
{
    public function __construct(
        public string $stream,
        public int $lastVersion,
    ) {}
}
