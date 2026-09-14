<?php

declare(strict_types=1);

namespace Storm\Chronicler\Outbox;

use Throwable;

/**
 * Marker for a publishing failure that retrying can never fix: the consumer rejected the message
 * permanently, the transport says it is unroutable, or a handler hit an unrecoverable bug. The
 * opposite of a transient outage such as a broker being down.
 *
 * By default, OutboxRelay treats every `publish()` throwable as retryable, backing off and
 * dead-lettering only after `maxAttempts`, the safe assumption. A publisher that knows a message is
 * hopeless throws, or wraps the cause in, an exception implementing this interface, and the relay
 * dead-letters it immediately instead of burning the retry budget. Mirrors Doctrine's
 * `RetryableException` marker, inverted.
 *
 * The opposite end of the same axis is not a marker the publisher throws but a recognition the relay
 * makes: a failure whose cause chain names the transport or the database is an outage, and it is
 * never dead-lettered, whatever the attempt count. So the budget bounds the ONE band this marker
 * leaves open, a failure that is neither declared hopeless nor recognizably infrastructural.
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
