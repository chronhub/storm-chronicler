<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests\Fixture;

use Storm\Message\EventType;

/**
 * A class given an alias without being renamed, whose author also listed its pre-alias FQCN.
 *
 * Its pre-alias rows were stored under that FQCN. The mapper appends it itself, so the two sources
 * overlap; it is the one overlap the attribute cannot refuse, since it never sees the class it
 * decorates.
 */
#[EventType('test.pre_alias', replaces: [PreAliasEvent::class])]
final class PreAliasEvent {}
