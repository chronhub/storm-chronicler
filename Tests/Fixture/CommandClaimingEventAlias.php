<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests\Fixture;

use Storm\Message\CommandType;

/**
 * Claims the alias `AliasedEvent` declares, so the mapper has a collision across sorts to refuse.
 */
#[CommandType('test.aliased')]
final class CommandClaimingEventAlias {}
