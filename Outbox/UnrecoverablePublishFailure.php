<?php

declare(strict_types=1);

namespace Storm\Chronicler\Outbox;

use Throwable;

/**
 * Marker for a publishing failure that retrying can never fix: the consumer rejected the message
 * permanently, the transport says it is unroutable, or a handler hit an unrecoverable bug. The
 * opposite of a transient outage such as a broker being down.
 *
 * By default, OutboxRelay treats every `publish()` throwable as transient, retrying with back-off and
 * dead-lettering only after `maxAttempts`, the safe assumption. A publisher that knows a message is
 * hopeless throws, or wraps the cause in, an exception implementing this interface, and the relay
 * dead-letters it immediately instead of burning the retry budget. Mirrors Doctrine's
 * `RetryableException` marker, inverted.
 *
 * It is a marker with no members, the recognition contract the relay matches via `instanceof`, so any
 * exception can opt in without a forced base class. For the common case throw the shipped concrete
 * UnroutableMessage via `UnroutableMessage::reason('…', $cause)`. To mark an exception that already has
 * its own hierarchy, implement this interface on it directly; per the exception policy, it should still
 * extend the right SPL base, so a runtime condition extends `RuntimeException`.
 *
 * @see OutboxRelay
 */
interface UnrecoverablePublishFailure extends Throwable {}
