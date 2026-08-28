<?php

declare(strict_types=1);

namespace Storm\Chronicler\Evolution;

use Attribute;

/**
 * Marks an Upcaster for discovery: the bundle tags it `storm.upcaster` and the UpcasterChain
 * collects all tagged upcasters. Parameterless; the upcaster's `supports()` carries the alias and
 * version it handles.
 *
 * @see Upcaster
 * @see UpcasterChain
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AsUpcaster {}
