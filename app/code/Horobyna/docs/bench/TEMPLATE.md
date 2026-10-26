# Bench: <phase / task ID> — <YYYY-MM-DD>

## Setup
- **Commit:** 
- **PostgreSQL:** version, key settings (shared_buffers, work_mem, effective_cache_size, jit)
- **PHP / Magento:** 
- **Hardware:** CPU, RAM, disk
- **Fixture profile:** small | medium | large | facets | custom (describe)
- **Feature flags enabled:** 
- **Cache state:** cold | warm; runs per measurement: 
- **Concurrency:** clients / threads

## Results

| Metric | Baseline (stock) | Experiment | Delta |
|--------|------------------|------------|-------|
| Full reindex time | | | |
| Import 10k / 100k | | | |
| Product save p50 / p95 | | | |
| Category query p50 / p95 | | | |
| Search + facets p50 / p95 | | | |
| DB size (total / relevant tables) | | | |
| Pipeline lag p95 / max queue depth | | | |

## Complexity scorecard

| Item | Removed | Added |
|------|---------|-------|
| Indexers | | |
| Cron jobs | | |
| Tables | | |
| External services | | |
| PHP LOC | | |
| SQL / PL/pgSQL LOC | | |

## Observations
- EXPLAIN excerpts (full plans in `raw/`):
- What matched the prediction, what did not, and why:

## Conclusion
- Decision / next step (also copy into the phase doc's Decisions log):
