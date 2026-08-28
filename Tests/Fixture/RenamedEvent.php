<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests\Fixture;

use Storm\Message\EventType;

#[EventType('test.renamed', replaces: ['test.old'])]
final class RenamedEvent {}
