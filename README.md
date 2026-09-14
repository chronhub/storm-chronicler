# Storm Chronicler

The PostgreSQL event store at the heart of Storm: three narrow ports instead of one god interface,
a transactional write path with explicit optimistic concurrency, an integrated outbox and inbox,
filtered bounded reads, upcasting with deploy-time conformance, and a commit-order safe watermark
for catch-up readers.

> PostgreSQL (17+) is assumed, not abstracted: partitioned DDL, advisory locks, `ON CONFLICT`,
> `pg_try_advisory_xact_lock` and `jsonb` are load-bearing. Driver: `pdo_pgsql` via Doctrine DBAL.

## When you reach for it

Most of the time you don't touch it directly: `AggregateRepository` writes your decisions through
it and `Projector` reads through it. You reach INTO it when —

- **appending facts** (`FactAppend`): streams that record what happened without competing writers —
  registries, link streams, journals — where the store allocates the position and no version is
  claimed;
- **reading outside a projection** (`StreamReader` + a `QueryFilter/`): a custom read-side need,
  a live feed, a range or since-version slice — ready-made filters cover aggregate loads, ranges
  and derived streams, and the filter is where the bound lives;
- **publishing events** (`storm:outbox:relay`): the outbox is the only bridge from the append
  transaction to your broker — same-transaction rows, ordered per stream;
- **deduplicating a consumer** (`InboxStore::once()`): idempotency atomic with your handler's DB
  effects;
- **evolving an event schema**: write an `Upcaster`, mark it `#[AsUpcaster]`, and let
  `storm:upcaster:verify` prove every stored row is readable before a read pays for a hole.

## The three ports

| Port | Verb | Guarantee |
|---|---|---|
| `DecisionAppend` | `appendTo(stream, expectedVersion)` | CAS on the stream head; the caller stamps versions `expected+1..+N` and the store REFUSES a batch that claims anything else (`IncoherentDecisionVersions`) |
| `FactAppend` | `appendAny(stream)` | no OCC; the store allocates a dense version range itself — a fact's position is never the producer's claim |
| `StreamReader` | `retrieveAll` / `retrieveByFilter` / `committedHeadPosition` / `safeHeadPosition` | bounded reads (pdo_pgsql buffers everything — never unbounded), explicit query filters, the visible committed target, and the safe watermark below |

A decision that loses the CAS throws `StaleVersion` (retryable-forward); the
`UNIQUE (category, stream, version)` backstop throws `DuplicateVersion` (terminal). DBAL is raw at
the source; consumers wrap into the contracted `StorageFailure` at THEIR port boundary.

## Write path

`RetryEventStore` (transient DBAL retries) wraps `DbalTransactionalEventStore` (one transaction per
append: events + stream head + outbox rows commit together) around `PostgresEventStore` (raw SQL,
opens no transaction itself). `FactAppend` rides the SAME decorated chain: it is aliased to
`DecisionAppend` on purpose, because decoration re-routes only the id it targets — see the
contributor doctrine below.

## Reading and upcasting

Every read of a stored row goes through one decoder (`StoredEventReader`): resolve the alias, run
the `UpcasterChain`, rebuild the event in today's shape. One decoder means the store, the outbox
relay and any transport-level consumer can never drift apart on how a row is read.

- An upcaster is a `1:1` payload migration step, discovered by `#[AsUpcaster]`; its `supports()`
  names the alias and version it lifts. Missing steps fail fast at read time, never silently.
- `storm:upcaster:verify` proves READABILITY at deploy time: the floor is computed from the DATA
  (`min(event_version)` per stored type), so a fresh schema owes no upcasters and every gap between
  what is stored and what this binary can lift is named BEFORE a read pays it. The install runs the
  same conformance.

## Outbox / inbox

- **Outbox**: rows are self-carrying (`header` + `content`); `storm:outbox:relay` publishes with
  per-partition ordering (partition = the qualified stream), N-worker-safe through THREE guards — a
  transaction-scoped advisory lock per partition, a pending-head check after the claim (the
  row-first `SKIP LOCKED` batch may hand a worker the tail while a sibling holds the head's row
  lock), and a window probe that refuses a partition whose claimed range has a HOLE, since holding
  the head is not holding the range. Backoff with dead-letter (`failed`), disposal `delete` or `archive`. A row THIS runtime cannot
  decode (missing upcaster, or written by a newer binary) is neither: it is **stalled** — kept `pending`
  with its partition held and no attempt burnt, so a corrected deployment republishes it with no
  requeue. That one never self-heals, so the drain reports it and the command exits non-zero.
- **Inbox**: consumer-side idempotency, `once(consumer, messageId, handler)` runs the handler
  atomically with its dedup mark; a batched sibling (`onceBatch`) amortizes the commit.

## Safe head

`committedHeadPosition()` is the highest position visible as committed to the current statement. It
is a freshness target only. A younger missing position can keep the scanner below it, so catch-up
reads must never use it as their upper bound.

`safeHeadPosition()` is the catch-up watermark: gap-scan over `sequence_no`, holes younger than the
grace hold it, older ones are presumed aborted and stepped over. The safety is **bounded by the
grace, not absolute** — a transaction outliving the grace that later commits lands below the
watermark and is skipped by projections that passed it. Operator rule: keep the writers' PostgreSQL
`transaction_timeout` strictly under the grace. `idle_in_transaction_session_timeout` is not the
guard: it terminates a session sitting idle inside a transaction, never one that is busy.

## Erasure and personal data

For personal data, prefer crypto-shredding: `#[Personal]` fields (Message package) are ciphered at
rest and die with their key. On the inspection surfaces, `PersonalDataVeil` enforces the no-decrypt
rule at render time: an operator sees the ciphered envelope the store holds, never the person's
cleartext — diagnosing a stream does not need the name, and the envelope is itself the proof the
field is protected.

`StreamEraser` is the dev/test cleanup, not the GDPR answer: it deletes the stream's events, its
head and its UNDELIVERED outbox rows in one transaction; the bundle's decoration deletes the
snapshot first. Projections do not un-project: reset them yourself.

## DDL, partitioning, install

Six tables shipped as versioned schema classes: `event_store` (partitioned LIST by category +
`_default`), `stream_heads`, `event_store_high_water`, `es_outbox`, `es_outbox_archive`,
`es_inbox`. `storm:install` aggregates them with the other packages' schemas: it runs in a
transaction under an advisory lock, verifies schema conformance instead of trusting `IF NOT
EXISTS`, and its destructive faces (`--drop`, `--reset`) ask unless `--force`.

Partition EARLY: PostgreSQL refuses to split a DEFAULT partition that already holds the category —
`storm:event-store:partition` guards the volume and prints the manual recipe instead. Operator
commands: `storm:outbox:relay`, `storm:outbox:prune`, `storm:outbox:failed`, `storm:inbox:prune`,
`storm:event-store:partition`, `storm:upcaster:verify`.

## Observability

`EventStoreObservability` hooks fire on the store's own paths (inside the append transaction, in a
read's `finally`, before an OCC throw): implementations are contract-bound FAIL-OPEN — a throwing
sink would roll back a written append or mask the original failure. Default is a Null
implementation; the Telemetry package (opt-in) fans out to logs/metrics/audit.

## Contributor doctrine — the load-bearing rules

**1. Three narrow ports, never a god interface.** A single `EventStore` interface existed and was
deliberately deleted: a capability either belongs to one port's guarantee or it is its own port.
Widening a port's signature to smuggle a new concern in is the regression to watch for.

**2. DBAL stays raw at the source.** The store throws the driver's own failures; the contracted
`StorageFailure` wrap happens at each CONSUMING boundary, not here — the retry layers
pattern-match the raw driver's transient failures, and wrapping at the source would blind them.

**3. Decoration re-routes ONE id.** `#[AsDecorator]` targets a single service id; every alias that
should ride the decorated chain must point at the decorated id, never at the concrete —
`FactAppend` aliasing `DecisionAppend` is the canonical case. Adding a decorator means re-checking
every alias route.

**4. A hot-path query ships its index in the same commit.** The outbox taught this the hard way:
a claim/head-check/cooling query without its index is a saturation cliff that no later migration
should have to discover.

**5. Observability never throws into the store.** Hooks run inside append transactions and read
paths; a sink that throws corrupts what it observes. Fail-open is the contract, not a courtesy.

## Tests

```bash
vendor/bin/phpunit src/Chronicler/Tests            # unit, from the storm root
vendor/bin/phpunit tests/Integration/Chronicler    # real PostgreSQL
```

## Resources

This package is developed in the `chronhub/storm` monorepo; a standalone repository for it is a
READ-ONLY subtree split. Report issues and open pull requests on the monorepo, where the tests,
the architecture gates and the full internal documentation live.

---

*Pre-version: this package changes without deprecation cycles — pin a commit if you need
stability, expect resets rather than migrations until the first tagged version.*

## Partition names and existing children

Partition provisioning identifies existing children by their schema, parent and full LIST bound.
A correctly attached child remains usable under its existing name, including a name PostgreSQL
truncated during an earlier provisioning. No automatic rename or data movement is performed.

New children keep `event_store_p_<category>` when it fits `max_identifier_length`. Longer names
use a shortened category plus a SHA-256 suffix within that server limit. Creation and the printed
manual migration recipe share this naming rule. An incompatible relation occupying the generated
name causes an explicit failure. Concurrent provisioning may fail on a competing DDL statement;
retry after the other transaction commits.

Rows already stored in DEFAULT still require the existing manual migration procedure. Correcting
the naming rule does not move those rows into a new child automatically.
