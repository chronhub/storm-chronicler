<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests\Fixture;

use Storm\Message\CommandType;

#[CommandType('test.charge', replaces: ['test.charge.v0'])]
final class AliasedCommand {}
