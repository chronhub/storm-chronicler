<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests\Fixture;

use Storm\Message\CommandType;
use Storm\Message\EventType;

/**
 * Declares itself an event and a command at once, the shape the mapper refuses.
 */
#[EventType('test.both')]
#[CommandType('test.both.command')]
final class BothSortsMessage {}
