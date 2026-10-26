# Benchmarks

One file per run: `<phase>-<YYYY-MM-DD>.md` (e.g. `p1-2026-11-14.md`), created from `TEMPLATE.md`.
Never edit an old report — add a new one. Raw output (pgbench logs, EXPLAIN plans) goes to `raw/<same-name>/`.

## Rules
- Same fixture profile, hardware and PG settings for baseline and experiment; record them in the header.
- Baseline = stock Magento with the experiment's feature flags **off** (same DB, same data).
- Report p50 and p95, number of runs and warm/cold cache state.
- Record the complexity scorecard next to the performance numbers (see roadmap/00-overview.md).

## Index

| Report | Phase | Fixture | Headline result |
|--------|-------|---------|-----------------|
| _(none yet)_ | | | |
