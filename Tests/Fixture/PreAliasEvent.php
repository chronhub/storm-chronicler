<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests\Fixture;

use Storm\Message\EventType;

/**
 * A class given an alias without being renamed, whose author also listed the FQCN its pre-alias rows
 * were stored under. The mapper appends that FQCN itself, so the two sources overlap; it is the one
 * overlap the attribute cannot refuse, since it never sees the class it decorates.
 */
#[EventType('test.pre_alias', replaces: ['Storm\Chronicler\Tests\Fixture\PreAliasEvent'])]
final class PreAliasEvent {}
