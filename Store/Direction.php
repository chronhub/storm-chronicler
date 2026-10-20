<?php

declare(strict_types=1);

namespace Storm\Chronicler\Store;

/**
 * The order in which a stream's events are read.
 *
 * `Direction::Forward` reads oldest first and is the default, used for reconstitution and projections.
 * `Direction::Backward` reads newest first, for example to load from the latest event or snapshot.
 *
 * The backing value is the SQL sort order applied to `sequence_no`.
 */
enum Direction: string
{
    case Forward = 'asc';
    case Backward = 'desc';
}
