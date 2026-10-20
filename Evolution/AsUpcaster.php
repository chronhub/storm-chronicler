<?php

declare(strict_types=1);

namespace Storm\Chronicler\Evolution;

use Attribute;

/**
 * Marks an `Upcaster` for discovery.
 *
 * The bundle tags it `storm.upcaster`, and the `UpcasterChain` collects every tagged upcaster. The
 * attribute takes no parameter: the upcaster's `supports()` carries the alias and version it handles.
 *
 * @see Upcaster
 * @see UpcasterChain
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AsUpcaster {}
