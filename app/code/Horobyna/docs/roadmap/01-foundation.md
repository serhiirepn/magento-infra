# Phase 0 — Foundation

## Goal

Make experiments **comparable and reversible**: a hardened driver, dual-database test runs, a benchmark harness,
a PostgreSQL-aware declarative schema, and feature flags. Every later phase depends on this.

## Magento today

- Declarative schema (`db_schema.xml`) knows only MySQL types, BTREE/FULLTEXT/HASH indexes and simple constraints.
- `Magento\Framework\Lock\Backend\Database` uses MySQL `GET_LOCK()`.
- Many modules contain raw MySQL SQL (`ON DUPLICATE KEY`, `GROUP_CONCAT`, `FIND_IN_SET`, `IFNULL`, implicit casts).

## Design

### Driver hardening checklist
- Identifier quoting, reserved words.
- `insertOnDuplicate()` → `INSERT ... ON CONFLICT (...) DO UPDATE`; requires a known conflict target (PK/unique).
- `GROUP_CONCAT` → `string_agg`, `FIND_IN_SET` → `= ANY(string_to_array(...))`, `IFNULL` → `coalesce`.
- `AUTO_INCREMENT` → identity columns; `LAST_INSERT_ID` → `RETURNING`.
- Unsigned ints → wider signed types or CHECK constraints.
- Zero dates (`0000-00-00`) → `NULL`.
- **Case-insensitive comparisons:** MySQL default collations are `_ci`. Decide per column: ICU nondeterministic
  collation, `citext`, or `lower()` expression indexes. Track the decision in the Decisions log.
- Transactional DDL (PG supports it; MySQL implicitly commits). Make sure setup code doesn't rely on implicit commits.

### PG-aware declarative schema (`Horobyna_Schema`)
Extend `db_schema.xml` (own XSD extension + DI-registered element factories and definition processors;
investigate `Magento\Framework\Setup\Declaration\Schema\*` extension points and mirror the MySQL `Db\MySQL\Definition\*` processors).

Must support:
- Column types: `jsonb`, `tstzrange`, `ltree`, `tsvector`, `int[]`, `smallint[]`.
- Generated columns (`GENERATED ALWAYS AS (...) STORED`).
- Index methods: `gin`, `gist`, `brin`; operator classes (`jsonb_path_ops`, `gist_ltree_ops`, `gin_trgm_ops`);
  expression indexes; partial indexes (`WHERE`).
- Exclusion constraints (`EXCLUDE USING gist (...)`) and `WITHOUT OVERLAPS` keys.
- Declarative partitioning (phase 9).

Functions and triggers are **not** in `db_schema.xml`: they live in `etc/pg/*.sql` files applied by a recurring setup step.

### Feature flags
- Config `horobyna/features/<feature>` (bool).
- DI preferences cannot be toggled at runtime, so each replaced service gets a **strategy resolver**:
  the preference points to a resolver that delegates to either the stock implementation or the PG implementation
  depending on the flag.

## Tasks

- [x] **P0.1** Driver hardening: run the checklist above; grep core for MySQL-only SQL and list offenders in `docs/driver-gaps.md`.
- [ ] **P0.2** Dual-DB CI: `setup:install` + integration test suites (at least Catalog, CatalogSearch, CatalogRule, Inventory, Sales) on MySQL and PostgreSQL.
- [ ] **P0.3** Bench harness: scripts that generate fixtures (`small`, `medium`, `large`, `facets` custom profile) and record standard metrics (see overview) into `docs/bench/`.
- [ ] **P0.4** `Horobyna_Schema`: XSD extension + types/index methods/operator classes/expression & partial indexes/generated columns/exclusion constraints.
- [ ] **P0.5** Feature-flag infrastructure + strategy-resolver base class + CLI `bin/magento horobyna:features` (list/enable/disable).
- [ ] **P0.6** SQL asset runner: apply `etc/pg/*.sql` idempotently on `setup:upgrade`, with checksum tracking table `horobyna_sql_asset`.
- [ ] **P0.7** Observability in dev: `pg_stat_statements`, `auto_explain` (log_min_duration), a helper `bin/magento horobyna:explain <query-id>`.
- [x] **P0.8** Advisory-lock implementation of `Magento\Framework\Lock\LockManagerInterface` (details in 03-queues-locks-cron.md).

## Magento integration points

- `Magento\Framework\Setup\Declaration\Schema\*` (schema reader, DTO factories, DB definition processors)
- `Magento\Framework\Lock\LockManagerInterface`
- `Magento\Framework\DB\Adapter\AdapterInterface` (driver)
- `dev/tests/integration` configuration (`etc/install-config-mysql.php` analogue for PG)

## Acceptance criteria

- `setup:install` and `setup:upgrade` succeed on PG with all Horobyna modules enabled and disabled.
- Integration suites listed in P0.2 pass (or failures are listed with reasons in `docs/driver-gaps.md`).
- A `db_schema.xml` declaring a `jsonb` column with a GIN `jsonb_path_ops` index and a generated `tsvector` column installs, upgrades idempotently and is dropped cleanly on module disable.
- One command produces a benchmark report for a given fixture profile.

## Benchmark report template (`docs/bench/<phase>-<date>.md`)

```
Phase: / Commit: / PG version: / Fixture profile:
Hardware: / Flags enabled:
| Metric | Baseline (stock) | Experiment | Delta |
Notes / EXPLAIN excerpts:
```

## Risks & open questions

- Declarative schema extension points may be too MySQL-bound; fallback is a parallel `db_schema_pg.xml` reader.
- Case-insensitivity decisions affect uniqueness (emails, URL keys, SKUs). Decide early.
- Connection poolers (PgBouncer in transaction mode) break session advisory locks and `LISTEN`. Document the supported topology.

## Decisions log

- 2026-10-23 **P0.1:** checklist run and core offenders listed in `docs/driver-gaps.md` (verified through the adapter
  with `EXPLAIN`). Split: the case-insensitivity decision is not part of P0.1 any more; it stays open in
  `docs/driver-gaps.md` (*Open: case-insensitive columns*, recommendation `citext`). Fixes of the listed offenders
  are driven by P0.2 (fresh `setup:install`) and P0.3 (fixture generation); cron offenders first.
- 2026-10-23 **P0.8:** `Horobyna\Db\Lock\Backend\Database` replaces the stock `Lock\Backend\Database` by DI
  preference; `lock/provider = db` now uses session advisory locks (`hashtextextended(name, 0)`, can be taken more
  than once by the same process, released on disconnect). Waiting retries `pg_try_advisory_lock()` with backoff
  instead of `lock_timeout`, because a timeout error would abort the caller's transaction. Supported setup: direct
  connections or session pooling.
