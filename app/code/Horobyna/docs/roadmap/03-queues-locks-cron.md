# Phase 1b — Message Queue, Locks and Cron on PostgreSQL

## Goal

Replace MySQL-specific coordination primitives with PostgreSQL-native ones and reuse the worker infrastructure from phase 1.

## Magento today

- `Magento_MysqlMq`: DB queue in `queue_message` / `queue_message_status`, consumers poll.
- Lock backend `Magento\Framework\Lock\Backend\Database` uses MySQL `GET_LOCK()`.
- Cron: `cron_schedule` rows, `ProcessCronQueueObserver` with locks to avoid double execution.

## Design

### Locks — advisory locks
- `pg_try_advisory_lock(hashtextextended(:name, 0))` (session level) for `LockManagerInterface::lock/unlock/isLocked`.
- Timeout support: loop with `pg_try_advisory_lock` + sleep, or `SET lock_timeout` + `pg_advisory_lock`.
- Transaction-level variant `pg_advisory_xact_lock` for code paths that lock inside one transaction.
- **Caveat:** session advisory locks and `LISTEN` don't work through PgBouncer in transaction mode. Document: direct connections or session pooling for workers.

### Queue — `SKIP LOCKED` + `NOTIFY`
- Table `horobyna_queue_message(id bigint identity, queue text, body jsonb, available_at timestamptz, attempts int, locked_until timestamptz)`.
- Publish: `INSERT` + `pg_notify('horobyna_queue', queue)` — in the same DB transaction as the business change → **transactional outbox for free**.
- Consume: `SELECT ... FOR UPDATE SKIP LOCKED LIMIT n` ordered by `available_at`; retries with backoff via `available_at`; dead-letter after max attempts.
- Optional: partition `horobyna_queue_message` by day and drop old partitions (phase 9).

### Cron — job claiming
- Claim due jobs with `UPDATE cron_schedule SET status='running' ... WHERE schedule_id IN (SELECT ... FOR UPDATE SKIP LOCKED)`.
- Optional experiment: run selected jobs via `pg_cron` calling SQL functions (only for pure-SQL jobs such as cleanup).

## Tasks

- [ ] **P1b.1** `LockManagerInterface` implementation on advisory locks (+ integration tests for timeouts, release on disconnect).
- [ ] **P1b.2** Queue connection type `horobyna` implementing Magento MessageQueue interfaces (publisher, consumer, queue management) — mirror `Magento_MysqlMq` structure.
- [ ] **P1b.3** Retry/backoff/dead-letter + CLI to inspect queues.
- [ ] **P1b.4** Cron claim with `SKIP LOCKED`; verify no double runs with several cron processes.
- [ ] **P1b.5** Outbox helper: `OutboxPublisher::publishInTransaction($topic, $payload)` documenting the transactional guarantee.
- [ ] **P1b.6** Benchmark: consumer throughput and latency vs `Magento_MysqlMq`.

## Magento integration points

- `Magento\Framework\Lock\LockManagerInterface`
- `Magento\Framework\MessageQueue\*` (connection config `queue_topology.xml`, `queue_publisher.xml`, `queue_consumer.xml`), `Magento_MysqlMq` as reference
- `Magento\Cron\Observer\ProcessCronQueueObserver`

## Acceptance criteria

- Inventory and bulk-operation consumers (`Magento_Inventory*`, `Magento_AsynchronousOperations`) run on the `horobyna` queue connection.
- Consumer latency after publish p95 < 1 s (wake-up via NOTIFY).
- No duplicate cron executions under 3 concurrent `cron:run` processes.

## Risks & open questions

- Long-running consumers + PHP memory: reuse phase 1 worker lifecycle (max runtime, memory limit, restart).
- Hash collisions of advisory-lock keys (64-bit hash, negligible but document).

## Decisions log

- _(empty)_
