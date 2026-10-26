# Phase 9 — Partitioning, BRIN, Data Integrity, Report Materialized Views

## Goal

Independent improvements with clear wins; good tasks between larger phases.

## Design

### Partitioning (declarative, by time)
- Candidates: `report_event`, `customer_visitor`, `customer_log`-like logs, `cron_schedule`, `horobyna_queue_message`,
  `horobyna_*` audit tables, old `quote` rows (cleanup).
- Retention = `DROP` / `DETACH PARTITION` instead of `DELETE` (no bloat, instant).
- `sales_*` tables: harder — PK must include the partition key, conflicts with `entity_id` PKs and FKs. Evaluate only as research.
- Optional `pg_partman` for partition management, or own cron job.

### BRIN indexes
- Append-mostly time columns (`created_at` on sales/order grids, logs) → BRIN instead of B-tree (tiny, cheap).

### Integrity in the schema
- CHECK constraints (non-negative qty/price, status enums), domains for common types.
- Case-insensitive uniqueness: customer email per website, URL `request_path` per store — ICU nondeterministic collation, `citext` or `lower()` unique indexes (consistent with phase 0 decision).
- FKs where Magento relies on PHP-only checks.

### Report materialized views
- Replace `sales_*_aggregated_*` refresh logic with `MATERIALIZED VIEW` + `REFRESH ... CONCURRENTLY` (needs a unique index), scheduled via cron or `pg_cron`. Full recomputation nightly is acceptable here — the right use of PG matviews.

## Tasks

- [ ] **P9.1** Partition logs and queue tables; retention job.
- [ ] **P9.2** BRIN on time columns; compare size/latency with B-tree.
- [ ] **P9.3** Integrity constraints catalogue (`docs/constraints.md`) + implementation via `Horobyna_Schema`.
- [ ] **P9.4** Case-insensitive uniqueness for emails and URL rewrites.
- [ ] **P9.5** Sales report matviews + refresh scheduling; compare with stock report refresh.
- [ ] **P9.6** Research note: partitioning `sales_order` — constraints, FK implications, benefit.

## Acceptance criteria

- Log retention does not produce table bloat (verify with `pgstattuple` or size tracking).
- Reports show identical numbers to stock aggregation.

## Decisions log

- _(empty)_
