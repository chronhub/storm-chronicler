<?php

declare(strict_types=1);

namespace Storm\Chronicler\Outbox;

use Throwable;

/**
 * Marker for a publishing failure that retrying can never fix.
 *
 * Three failures qualify, the opposite of a transient outage such as a broker being down:
 *
 * - The consumer rejected the message permanently
 * - The transport says it is unroutable, or refuses to encode it
 * - A handler hit an unrecoverable bug
 *
 * By default `OutboxRelay` treats every `publish()` throwable as retryable, backing off and
 * dead-lettering only after `maxAttempts`, the safe assumption. A publisher that knows a message is
 * hopeless throws, or wraps the cause in, an exception implementing this interface, and the relay
 * dead-letters it immediately instead of using up the retry budget. It mirrors Doctrine's
 * `RetryableException` marker, inverted.
 *
 * The other end of the same axis is a recognition the relay makes rather than a marker the publisher
 * throws: a failure whose cause chain names an outage that clears without the message changing, as
 * {@see \Storm\Support\Error\TransientFailure} lists them, is never dead-lettered, whatever the attempt
 * count. So the budget bounds the one band this marker leaves open, a failure that is neither declared
 * hopeless nor such an outage, a constraint violation the database raised among them.
 *
 * It is a marker with no members, the recognition contract the relay matches with `instanceof`, so any
 * exception can opt in without a forced base class. For the common case throw the shipped concrete
 * `UnroutableMessage` through `UnroutableMessage::reason('…', $cause)`. To mark an exception that already
 * has its own hierarchy, implement this interface on it directly; per the exception policy it should
 * still extend the right SPL base, so a runtime condition extends `RuntimeException`.
 *
 * @see OutboxRelay
 */
interface UnrecoverablePublishFailure extends Throwable {}
