<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Storm\Chronicler\Store\DecisionAppend;
use Storm\Chronicler\Store\FactAppend;

/*
 * Chronicler package wiring.
 *
 * autowire + autoconfigure so the #[AsAlias] attributes bind the StreamReader / DecisionAppend,
 * StreamPersistence and EventTypeMapper contracts; PostgresEventStore autowires the DBAL
 * Connection. Value objects, EventRecord and SequencePosition, the raw SQL schema, the query
 * filters, interfaces and exceptions are not services.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    // FactAppend rides the SAME decorated write chain as DecisionAppend, Retry then Transactional then
    // Postgres. Alias-to-alias, deliberately: the decorators declare #[AsDecorator(DecisionAppend)],
    // and decoration re-routes ONLY the id it targets; a FactAppend alias pointing at the concrete
    // would silently bypass the whole chain, with no retry and no per-append transaction.
    $services->alias(FactAppend::class, DecisionAppend::class);

    $services->load('Storm\\Chronicler\\', dirname(__DIR__).'/')
        ->exclude([
            dirname(__DIR__).'/Exception/',
            dirname(__DIR__).'/Schema/',
            dirname(__DIR__).'/Query/', // read strategies: the port and the filters a caller builds per query
            dirname(__DIR__).'/Tests/',
            dirname(__DIR__).'/config/',
            dirname(__DIR__).'/Record/EventRecord.php',
            dirname(__DIR__).'/Record/SequencePosition.php',
            dirname(__DIR__).'/Evolution/ChainAudit.php', // the audit's value object, not a service
            dirname(__DIR__).'/Outbox/OutboxEntry.php',
            dirname(__DIR__).'/Outbox/FailedOutboxMessage.php',
            dirname(__DIR__).'/Inbox/InboxItem.php',
            dirname(__DIR__).'/Directory/StreamHead.php', // the CAS head row, a value
            // the relay's working values: rows, claims, probes and drain verdicts, never services
            dirname(__DIR__).'/Outbox/OutboxRow.php',
            dirname(__DIR__).'/Outbox/ClaimSummary.php',
            dirname(__DIR__).'/Outbox/PartitionProbe.php',
            dirname(__DIR__).'/Outbox/OutboxDrainResult.php',
            dirname(__DIR__).'/Outbox/OutboxDrainIncomplete.php',
            dirname(__DIR__).'/Store/InsertChunk.php', // one INSERT statement's slice, a value
            // per-call observation payloads handed to the telemetry port, not services
            dirname(__DIR__).'/Telemetry/AppendContext.php',
            dirname(__DIR__).'/Telemetry/SafeHeadCrossingContext.php',
            dirname(__DIR__).'/Telemetry/LoadContext.php',
            dirname(__DIR__).'/Telemetry/OccConflictContext.php',
            // opt-in on purpose: the bundle wires the category existence cache only when
            // storm.stream_existence_cache is enabled; excluded here so "off" means zero services
            dirname(__DIR__).'/Directory/CategoryCachedStreamExistence.php',
            dirname(__DIR__).'/Erasure/CacheEvictingStreamEraser.php',
        ]);
};
