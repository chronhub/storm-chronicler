<?php

declare(strict_types=1);

namespace Storm\Chronicler\Outbox;

use Storm\Message\Message;
use Throwable;

/**
 * Publishes an outbox message to the async transport, after the append has committed.
 *
 * The relay reconstructs the Message from the outbox row and hands it here. The default implementation
 * dispatches through Symfony Messenger via `Storm\Story\Outbox\MessengerOutboxPublisher`, which lives
 * in Story to keep Chronicler transport-neutral; InMemoryOutboxPublisher is for tests and local dev.
 *
 * The hard contract: `publish()` is called by {@see OutboxRelay} inside its drain transaction, since
 * the `published` mark may only follow a successful publish. Three clauses follow:
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
 * - If a deployment runs handlers synchronously anyway, know what it couples: the handlers execute
 *   inside the relay's transaction on the default connection, so their writes are not durable until the
 *   drain commits, and they commit with the batch. The relay defends itself per row with a savepoint: a
 *   publish that fails, even aborting the transaction or after partial writes, leaves zero trace, the
 *   back-off and dead-letter machinery still commits, and the retry re-runs the handler from a clean
 *   slate.
 */
interface OutboxPublisher
{
    /**
     * Enqueue the message on the async transport.
     *
     * @throws UnrecoverablePublishFailure when the message can never be delivered, because a consumer
     *                                     rejected it or it is unroutable. OutboxRelay dead-letters it
     *                                     immediately; implement this marker to opt out of the retry
     *                                     budget.
     * @throws Throwable on any other transport or publish failure; the target is open-ended, whether
     *                   Messenger or in-memory, so the cause type cannot be narrowed, and the relay
     *                   treats it as a transient failure that bumps attempts, backs off, and eventually
     *                   dead-letters
     */
    public function publish(Message $message): void;
}
