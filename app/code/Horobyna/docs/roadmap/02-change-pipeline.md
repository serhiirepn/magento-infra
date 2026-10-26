# Phase 1 — Change Pipeline (mview replacement)

## Goal

A generic, PostgreSQL-native change-capture and processing pipeline that replaces Magento's `mview` and becomes
the shared infrastructure for indexers, jsonb migration (phase 2), search documents (3), prices (6), staging (7)
and cache invalidation.

## Magento today

- Row-level triggers on source tables (from `mview.xml` subscriptions) insert `entity_id` into `*_cl` tables.
- `mview_state` stores the processed `version_id`; cron `indexer_update_all_views` reads changelog ranges,
  calls `Magento\Framework\Mview\ActionInterface::execute($ids)`; `indexer_clean_all_changelogs` truncates.
- Weaknesses: per-row trigger cost on imports, duplicate ids, polling latency (minutes), changelog growth,
  **lost updates** possible because `version_id` is allocated before commit (a lower id can commit after a higher one was read),
  no semantics (row changes, not entity changes), no dependency graph.

## Design

### Concepts
- **Channel** — a named consumer (e.g. `catalog_product_price`, `product_listing`, `attrs_shadow`).
- **Subscription** — source table → channel, with a **key mapping** (e.g. `catalog_product_entity_decimal.row_id → entity_id`)
  and an optional **fan-out** (e.g. website change → all its store ids).
- **Dirty set** — one table per channel, `PRIMARY KEY` on the key → deduplication on insert.

```sql
CREATE TABLE horobyna_dirty_<channel> (
    entity_id  integer PRIMARY KEY,
    changed_at timestamptz NOT NULL DEFAULT now()
);
```

### Capture: statement-level triggers with transition tables
One trigger per (table, operation), because `OLD TABLE` exists only for UPDATE/DELETE and `NEW TABLE` only for INSERT/UPDATE.

```sql
CREATE OR REPLACE FUNCTION horobyna_mark_<channel>() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP IN ('INSERT','UPDATE') THEN
    INSERT INTO horobyna_dirty_<channel>(entity_id)
    SELECT DISTINCT <key_expr> FROM new_rows
    ON CONFLICT (entity_id) DO NOTHING;
  END IF;
  IF TG_OP = 'DELETE' THEN
    INSERT INTO horobyna_dirty_<channel>(entity_id)
    SELECT DISTINCT <key_expr> FROM old_rows
    ON CONFLICT (entity_id) DO NOTHING;
  END IF;
  PERFORM pg_notify('horobyna_pipeline', '<channel>');
  RETURN NULL;
END $$;
```

Notes:
- Transition tables can't be combined with `UPDATE OF col` → filter real changes by joining `old_rows`/`new_rows` with `IS DISTINCT FROM` when needed.
- `TRUNCATE` is not seen by row/statement DML triggers → add a `TRUNCATE` trigger that flags a full rebuild for the channel.
- Trigger functions are **generated** from subscription config, not hand-written.

### Wake-up: `LISTEN/NOTIFY`
Delivered on commit, deduplicated within a transaction, **not durable** → only a wake-up signal. Workers also poll on a timeout (e.g. 30 s).

### Processing: `SKIP LOCKED` batches

```sql
WITH batch AS (
  SELECT entity_id FROM horobyna_dirty_<channel>
  ORDER BY changed_at
  LIMIT :batch_size
  FOR UPDATE SKIP LOCKED
)
DELETE FROM horobyna_dirty_<channel> d
USING batch b WHERE d.entity_id = b.entity_id
RETURNING d.entity_id;
-- then the channel handler runs in the same transaction; COMMIT
```

- Multiple workers per channel are safe.
- At-least-once: on failure the transaction rolls back and ids return. Handlers must be idempotent (also for external targets).
- A writer touching an id that is currently being processed waits for the batch to finish and then re-marks it → no lost updates.
  Trade-off: writer latency ≤ batch duration → keep batches small (time-bounded, e.g. ≤ 200 ms).

### Compatibility mode (keep Magento's version API)
For stock indexers that use `ChangelogInterface::getList($from, $to)`:
changelog with `bigserial version_id` + `xid xid8 DEFAULT pg_current_xact_id()`; readers only take rows with
`xid < pg_snapshot_xmin(pg_current_snapshot())` (all such transactions are finished) → fixes the lost-update gap.

### Dependency graph
A declarative config `etc/horobyna_pipeline.xml`:
channel → subscriptions (table, key expression, fan-out) → handler class → downstream channels it marks dirty.
`mview.xml` is imported automatically into equivalent channels so stock indexers work unchanged.

### Worker
`bin/magento horobyna:pipeline:run [--channel=...] [--batch-size] [--max-runtime] [--memory-limit]`:
long-running, `LISTEN`, graceful shutdown on SIGTERM, restart by supervisor/systemd.

### Monitoring
View `horobyna_pipeline_status(channel, depth, oldest_changed_at, lag_seconds, workers)`.

## Tasks

- [ ] **P1.1** Config schema `horobyna_pipeline.xml` + merged config reader; import of `mview.xml` subscriptions.
- [ ] **P1.2** Trigger/function generator (statement-level, transition tables, key mapping incl. `row_id → entity_id`, fan-out, TRUNCATE trigger); idempotent install/remove.
- [ ] **P1.3** Dirty-set tables per channel (declared via `Horobyna_Schema`).
- [ ] **P1.4** Worker: claim loop with `SKIP LOCKED`, `LISTEN/NOTIFY`, poll fallback, time-bounded batches, signals.
- [ ] **P1.5** Handler interface `ChannelHandlerInterface::process(array $ids): void` + adapter that wraps any `Magento\Framework\Mview\ActionInterface`.
- [ ] **P1.6** Compatibility mode: `ChangelogInterface` implementation with `xid` watermark; `SubscriptionInterface` implementation using generated PG triggers.
- [ ] **P1.7** Full-rebuild path per channel (flag + `executeFull`) and `bin/magento horobyna:pipeline:rebuild <channel>`.
- [ ] **P1.8** Monitoring view + CLI `horobyna:pipeline:status`.
- [ ] **P1.9** Concurrency stress test: N writers randomly update products while workers run; afterwards verify that the target model equals a full recompute.
- [ ] **P1.10** Benchmark trigger overhead on 100k-row imports: stock mview vs Horobyna pipeline vs no triggers.

## Magento integration points

- `Magento\Framework\Mview\View\SubscriptionInterface`, `Magento\Framework\Mview\View\ChangelogInterface`,
  `Magento\Framework\Mview\View\StateInterface`, `Magento\Framework\Mview\ActionInterface`
- `Magento\Framework\Indexer\ActionInterface` (`executeFull`, `executeList`, `executeRow`)
- Cron groups `index` (to be disabled when native mode is on)

## Acceptance criteria

- With the flag on, all stock indexers in "Update by Schedule" mode stay consistent under the P1.9 stress test.
- Change-to-index latency p95 < 5 s with one worker per channel (target, calibrate in bench).
- Import overhead of triggers is lower than stock mview (P1.10).
- Disabling the flag restores stock mview behaviour.

## Risks & open questions

- Writer blocking during batches (mitigated by small batches). Alternative model to evaluate: `claimed_at` column + lease instead of delete-in-transaction.
- External handlers (OpenSearch) inside a DB transaction lengthen transactions → set `idle_in_transaction_session_timeout` carefully.
- Phase X: logical replication (CDC) as a trigger-free alternative for very write-heavy setups.

## Decisions log

- _(empty)_
