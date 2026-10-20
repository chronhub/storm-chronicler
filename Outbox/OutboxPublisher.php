<?php

declare(strict_types=1);

namespace Storm\Chronicler\Outbox;

use Storm\Message\Message;
use Throwable;

/**
 * Publishes an outbox message to the async transport, after the append has committed.
 *
 * The relay rebuilds the `Message` from the outbox row and hands it here. The default implementation
 * dispatches through Symfony Messenger via `Storm\Story\Outbox\MessengerOutboxPublisher`, which lives in
 * Story to keep Chronicler transport-neutral; `InMemoryOutboxPublisher` serves tests and local dev.
 *
 * {@see OutboxRelay} calls `publish()` inside its drain transaction, since a row may be disposed of only
 * after a successful publish. Three clauses follow:
 *
 * - It must be a fast, durable handoff: enqueue to a durable transport and return. The relay holds row
 *   locks, partition advisory locks and an open transaction for its whole duration, so slow work here
 *   serializes every relay and stretches the transaction.
 *
 * - It must not execute application handlers in-process as part of publishing. The shipped Messenger
 *   implementation enforces this by construction: it sends straight to a named transport,
 *   `storm.outbox.events_transport`, and never consults the Messenger routing, whose silent default for
 *   an un-routed class is to run handlers synchronously. A custom publisher must uphold the same clause
 *   itself.
 *
 * - A deployment that runs handlers synchronously anyway couples them to the relay: they execute inside
 *   the relay's transaction on the default connection, so their writes are not durable until the drain
 *   commits, and they commit with the batch. The relay defends itself per row with a savepoint: a
 *   publish that fails, even aborting the transaction or after partial writes, leaves zero trace, the
 *   back-off and dead-letter machinery still commits, and the retry reruns the handler from a clean
 *   slate.
 */
interface OutboxPublisher
{
    /**
     * Enqueue the message on the async transport.
     *
     * @throws UnrecoverablePublishFailure when the message can never be delivered, because a consumer
     *                                     rejected it, it is unroutable, or the publisher meets the same
     *                                     refusal on every attempt. `OutboxRelay` dead-letters it
     *                                     immediately; implement this marker to opt out of the retry
     *                                     budget.
     * @throws Throwable on any other transport or publish failure; the target is open-ended, whether
     *                   Messenger or in-memory, so the cause type cannot be narrowed, and the relay
     *                   treats it as a transient failure that bumps attempts, backs off, and eventually
     *                   dead-letters
     */
    public function publish(Message $message): void;
}
