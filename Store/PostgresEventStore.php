<?php

declare(strict_types=1);

namespace Storm\Chronicler\Store;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Query\QueryBuilder;
use Generator;
use LogicException;
use Storm\Chronicler\Directory\StreamHeadStore;
use Storm\Chronicler\Exception\DuplicateVersion;
use Storm\Chronicler\Exception\IncoherentDecisionVersions;
use Storm\Chronicler\Exception\StaleVersion;
use Storm\Chronicler\Outbox\OutboxEntry;
use Storm\Chronicler\Outbox\OutboxWriter;
use Storm\Chronicler\Query\QueryFilter;
use Storm\Chronicler\Record\SequencePosition;
use Storm\Chronicler\Record\StreamPersistence;
use Storm\Chronicler\SafeHead\SafeHeadAdvancer;
use Storm\Chronicler\Telemetry\AppendContext;
use Storm\Chronicler\Telemetry\EventStoreObservability;
use Storm\Chronicler\Telemetry\LoadContext;
use Storm\Chronicler\Telemetry\OccConflictContext;
use Storm\Contracts\Chronicler\InvalidPosition;
use Storm\Contracts\Chronicler\Position;
use Storm\Contracts\Chronicler\UndecodableRow;
use Storm\Contracts\Chronicler\UnknownEventType;
use Storm\Contracts\Clock\ClockExceptionContract;
use Storm\Contracts\Serializer\SerializationExceptionContract;
use Storm\Message\Header;
use Storm\Message\Message;
use Storm\Serializer\Exception\SerializationException;
use Storm\Serializer\SerializedMessage;
use Storm\Stream\Stream;
use Storm\Stream\StreamName;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * PostgreSQL event store on Doctrine DBAL, a pure store: it does NOT open a transaction, so it can
 * run raw for migrations, copy, or dump. Atomicity of an append, the OCC CAS, the events, the head
 * position, and the outbox, is provided by the DbalTransactionalEventStore decorator wrapping each
 * append in one transaction; the collaborators StreamHeadStore and OutboxWriter only run
 * statements on the shared connection. `appendTo` is CAS-first, failing fast before inserting, with
 * `UNIQUE (category, stream, version)` as a backstop. Reads stream rows as a lazy cursor.
 *
 * The one concrete behind the three ports. The writer aliases live on the DECORATED id:
 * `DecisionAppend` is aliased here and decorated by the chain, Transactional then Retry, and
 * `FactAppend` aliases `DecisionAppend` in `config/services.php` so both writer ports resolve to
 * the whole chain. `StreamReader` alone stays on this raw store; reads bypass the writer decorators
 * by design, with nothing to transact and nothing to retry.
 *
 * @see \Storm\Chronicler\Store\DbalTransactionalEventStore
 */
#[AsAlias(StreamReader::class)]
#[AsAlias(DecisionAppend::class)]
final readonly class PostgresEventStore implements DecisionAppend, FactAppend, StreamReader
{
    public function __construct(
        private Connection $connection,
        private StreamPersistence $persistence,
        private StreamHeadStore $streamHeads,
        private OutboxWriter $outbox,
        private EventStoreObservability $obs,
        /**
         * Autowired in prod; null means it is lazily built from the connection for manual
         * construction in tests.
         */
        private ?SafeHeadAdvancer $safeHeadAdvancer = null,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws IncoherentDecisionVersions when the batch's claimed versions are not exactly
     *                                    `expectedVersion + 1 .. expectedVersion + N`; refused before
     *                                    the head moves, nothing written
     * @throws StaleVersion when the CAS on the stream head loses because the stream moved past `$expectedVersion`
     * @throws DuplicateVersion when the `UNIQUE (category, stream, version)` backstop fires on a true race
     * @throws Exception on a DBAL failure of the insert or outbox write
     */
    public function appendTo(Stream $stream, int $expectedVersion): void
    {
        $start = microtime(true);

        /** @var list<Message> $messages */
        $messages = iterator_to_array($stream->messages(), false);

        if ($messages === []) {
            return;
        }

        $streamName = $stream->streamName;
        $name = $streamName->toString();
        $newVersion = $expectedVersion + count($messages);

        // The write-side mirror of the repository's read-side continuity guard: the store persists the
        // versions the producer CLAIMS, since toRow reads Header::AggregateVersion verbatim, while the head
        // moves by arithmetic. So a batch whose claims are not exactly expected+1..expected+N must be
        // refused BEFORE the head moves, or stream_heads and the durable rows divorce and the next OCC
        // base is corrupt. N int comparisons on a path dominated by json_encode.
        foreach ($messages as $i => $message) {
            if ($message->aggregateVersion() !== $expectedVersion + $i + 1) {
                throw IncoherentDecisionVersions::forStream($name, $expectedVersion + $i + 1, $message->aggregateVersion() ?? 'null', $i);
            }
        }

        // The CAS head: fail fast before inserting. StreamHeadStore wraps every DBAL failure into
        // StorageFailure at its own boundary, so nothing here can reach persist()'s UNIQUE catch.
        if (! $this->streamHeads->advance($name, $expectedVersion, $newVersion)) {
            $actual = $this->streamHeads->lastVersion($name);
            $this->obs->recordOccConflict(new OccConflictContext($name, $streamName->category, $expectedVersion, $actual, 'stale'));

            throw StaleVersion::forStream($name, $expectedVersion, $actual);
        }

        $this->persist($streamName, $messages, $newVersion, occExpected: $expectedVersion, start: $start);
    }

    /**
     * {@inheritDoc}
     *
     * @throws DuplicateVersion when the `UNIQUE (category, stream, version)` backstop fires; on this
     *                          path only reachable when a stream mixes the CAS and fact verbs, which
     *                          the FactAppend contract forbids
     * @throws Exception on a DBAL failure of the insert or outbox write
     */
    public function appendAny(Stream $stream): void
    {
        $start = microtime(true);

        /** @var list<Message> $messages */
        $messages = iterator_to_array($stream->messages(), false);

        if ($messages === []) {
            return;
        }

        $streamName = $stream->streamName;

        $newVersion = $this->streamHeads->bump($streamName->toString(), count($messages));

        // The allocated range stamps the header, and through it the version column, since toRow
        // reads aggregateVersion(); a fact's position is decided here, never by the producer.
        $version = $newVersion - count($messages);
        foreach ($messages as $i => $message) {
            $messages[$i] = $message->withHeader(Header::AggregateVersion, ++$version);
        }

        $this->persist($streamName, $messages, $newVersion, occExpected: $newVersion, start: $start);
    }

    /**
     * {@inheritDoc}
     *
     * @throws Exception on a DBAL failure of the underlying read
     */
    public function retrieveAll(StreamName $streamName, Direction $direction = Direction::Forward): Generator
    {
        $query = $this->connection->createQueryBuilder()
            ->select('sequence_no', 'stream', 'type', 'event_version', 'header', 'content', 'recorded_at')
            ->from('event_store')
            ->where('category = :category')
            ->setParameter('category', $streamName->category);

        if ($streamName->qualifier !== null) {
            // ONE stream: order by `version`, identical order to `sequence_no` within a stream, since the
            // CAS serializes its appends, but the UNIQUE (category, stream, version) index PROVIDES
            // it, so the hottest read, aggregate rehydration, skips the Sort node a `sequence_no`
            // order would force. The same reasoning drives AggregateStreamSince.
            $query->andWhere('stream = :stream')
                ->orderBy('version', $direction->value)
                ->setParameter('stream', $streamName->toString());
        } else {
            // A whole category: `version` is per-stream, only the global `sequence_no` is the order.
            $query->orderBy('sequence_no', $direction->value);
        }

        return $this->cursor(
            $query,
            source: 'retrieveAll',
            target: $streamName->qualifier !== null ? $streamName->toString() : 'category:'.$streamName->category,
        );
    }

    /**
     * {@inheritDoc}
     *
     * @throws Exception on a DBAL failure of the underlying read
     */
    public function retrieveByFilter(QueryFilter $filter): Generator
    {
        $query = $this->connection->createQueryBuilder()
            ->select('e.sequence_no', 'e.stream', 'e.type', 'e.event_version', 'e.header', 'e.content', 'e.recorded_at')
            ->from('event_store', 'e');

        $filter->apply($query);

        return $this->cursor($query, source: 'retrieveByFilter', target: $filter::class);
    }

    /**
     * {@inheritDoc}
     *
     * @throws Exception on a DBAL failure of the head query
     */
    public function committedHeadPosition(): ?Position
    {
        $position = $this->connection->fetchOne(
            /** @lang PostgreSQL */
            'SELECT max(sequence_no) FROM event_store',
        );

        return $position === null || $position === false
            ? null
            : SequencePosition::fromString((string) $position);
    }

    /**
     * {@inheritDoc}
     *
     * @throws Exception on a DBAL failure of the watermark query
     */
    public function safeHeadPosition(): ?Position
    {
        return ($this->safeHeadAdvancer ?? new SafeHeadAdvancer($this->connection))->compute();
    }

    /**
     * The shared tail of both append verbs: insert and outbox, the `UNIQUE (category, stream, version)`
     * backstop, and the append telemetry. The version-resolution heads stay in the public verbs; that
     * difference is the contract, this tail is the mechanism.
     *
     * @param  list<Message>  $messages
     * @param  int  $occExpected  the head this writer expected, reported on a duplicate
     * @param  float  $start  the verb's entry time, so the append telemetry covers the whole write
     *
     * @throws DuplicateVersion when the UNIQUE backstop fires; a true race on `appendTo`, or on the
     *                          fact path only reachable when a stream mixes the CAS and fact verbs
     * @throws Exception on a DBAL failure of the insert or outbox write
     * @throws SerializationException when a message is not a serializable payload
     */
    private function persist(StreamName $streamName, array $messages, int $newVersion, int $occExpected, float $start): void
    {
        $name = $streamName->toString();

        try {
            $entries = $this->insertEvents($streamName, $messages);
        } catch (UniqueConstraintViolationException) {
            // No lastVersion() read here: the UNIQUE violation ABORTED the surrounding transaction,
            // 25P02, so any further query would explode into a StorageFailure masking this throw.
            // The head this writer moved to $newVersion goes back with the abort, so the head that
            // stands is the expected one; that is what the conflict reports.
            $this->obs->recordOccConflict(new OccConflictContext($name, $streamName->category, $occExpected, $occExpected, 'duplicate'));

            throw DuplicateVersion::forStream($name);
        }

        // OUTSIDE the OCC catch: only the event insert's UNIQUE backstop is a duplicate stream
        // version. The outbox write's own violation, es_outbox_pk colliding after a restore left the
        // identity sequence behind its explicit ids, is a storage fault; classified as DuplicateVersion
        // it would ride the terminal OCC path with the wrong diagnosis, so it escapes raw, like every
        // DBAL failure at this source.
        $this->outbox->write($entries);

        $this->obs->recordAppend(new AppendContext(
            streamName: $name,
            category: $streamName->category,
            eventCount: count($messages),
            newVersion: $newVersion,
            durationMs: (microtime(true) - $start) * 1000,
        ));
    }

    /**
     * The shared tail of both retrieve verbs: execute lazily, stream rows as domain events, and record
     * the load telemetry when the consumer stops consuming. Nothing runs, not even the query, until the
     * first iteration. The `finally` records the load on EVERY exit, so the count and duration always
     * reflect what the consumer actually saw and an interrupted read is never silently absent from
     * telemetry. Every exit is one of:
     *
     * - Full exhaustion;
     * - An early break, where PHP runs a suspended generator's `finally` at destruction;
     * - A throw mid-hydration.
     *
     * @throws Exception on a DBAL failure of the underlying read
     * @throws InvalidPosition when a row's `sequence_no` is not a positive integer
     * @throws ClockExceptionContract when a stored point in time failed to be parsed
     * @throws SerializationExceptionContract when a stored event type is unknown / not deserializable
     * @throws UnknownEventType when the stored `type` resolves to no known class, or to a class
     *                          that is not a domain event
     * @throws UndecodableRow when this runtime cannot decode a stored row, a missing or duplicated
     *                        version step, an upcaster that threw, or a row written by a newer
     *                        release; `resolvableByAnotherRuntime()` separates a deployment state
     *                        from a row no release will decode
     */
    private function cursor(QueryBuilder $query, string $source, string $target): Generator
    {
        $start = microtime(true);

        $result = $query->executeQuery();

        $count = 0;

        try {
            while (($row = $result->fetchAssociative()) !== false) {
                $record = $this->persistence->fromRow($row);
                $count++; // before the yield: a generator abandoned mid-suspension has DELIVERED this record

                yield $record;
            }
        } finally {
            // free the buffered result on EVERY exit: pdo_pgsql holds the whole set client-side,
            // and an abandoned Generator in a long-lived worker would otherwise retain it until GC
            $result->free();

            $this->obs->recordLoad(new LoadContext(
                source: $source,
                target: $target,
                eventCount: $count,
                durationMs: (microtime(true) - $start) * 1000,
            ));
        }
    }

    /**
     * Insert the events with ONE multi-VALUES statement per {@see InsertChunk} of rows, returning one
     * outbox entry per event, its position and serialized row.
     *
     * `sequence_no` is engine-assigned by IDENTITY, so each row's position is read back from
     * `RETURNING version, sequence_no` and matched by `version`, unique here because every row of a
     * call shares the stream and `UNIQUE (category, stream, version)` backstops it. NEVER by RETURNING
     * row order: the standard leaves it unspecified, and nothing contracts it on a partitioned table. A
     * version the RETURNING did not cover is impossible on the happy path, since any failed tuple aborts
     * the whole statement and throws; it would mean this mapping's premise broke, a bug, hence
     * LogicException.
     *
     * @param  list<Message>  $messages
     * @return list<OutboxEntry> in message order
     *
     * @throws Exception on a DBAL failure of the insert
     * @throws SerializationException when a message is not a serializable payload
     */
    private function insertEvents(StreamName $streamName, array $messages): array
    {
        // Unreachable: this is private, reached only through persist(), itself private and called only by
        // appendTo() and appendAny(), both of which return on an empty message list before getting here.
        // No third caller exists. Kept as a defensive floor for a future one.
        // @codeCoverageIgnoreStart
        if ($messages === []) {
            return [];
        }
        // @codeCoverageIgnoreEnd

        $rows = [];

        foreach ($messages as $message) {
            $rows[] = $this->persistence->toRow($streamName, $message);
        }

        $positions = [];

        foreach (array_chunk($rows, InsertChunk::ROWS) as $chunk) {
            $params = [];

            foreach ($chunk as $row) {
                $params[] = $row['category'];
                $params[] = $row['stream'];
                $params[] = $row['version'];
                $params[] = $row[SerializedMessage::TYPE];
                $params[] = $row['event_version'];
                $params[] = $row[SerializedMessage::HEADER];
                $params[] = $row[SerializedMessage::CONTENT];
            }

            $placeholders = implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, CAST(? AS jsonb), CAST(? AS jsonb))'));

            $returned = $this->connection->fetchAllAssociative(
                /** @lang PostgreSQL */
                'INSERT INTO event_store (category, stream, version, type, event_version, header, content)
                 VALUES '.$placeholders.'
                 RETURNING version, sequence_no',
                $params,
            );

            foreach ($returned as $inserted) {
                $positions[(int) $inserted['version']] = (int) $inserted['sequence_no'];
            }
        }

        $entries = [];

        foreach ($rows as $row) {
            $version = (int) $row['version'];
            // The throw is a bug-marker, not a branch: Postgres guarantees RETURNING covers every row of a
            // successful multi-VALUES INSERT, or the whole statement aborts and throws first, a
            // transaction-level guarantee. A version missing from the map would mean that broke.
            // @codeCoverageIgnoreStart
            $position = $positions[$version]
                ?? throw new LogicException("Multi-VALUES RETURNING did not cover version {$version} of stream {$streamName->toString()}");
            // @codeCoverageIgnoreEnd

            $entries[] = new OutboxEntry(
                $position,
                $streamName->toString(),
                (string) $row[SerializedMessage::TYPE],
                (int) $row['event_version'],
            );
        }

        return $entries;
    }
}
