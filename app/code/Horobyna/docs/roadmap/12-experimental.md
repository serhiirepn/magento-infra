# Phase X — Experimental Research Topics

Short research spikes. Each produces a note in `docs/research/<topic>.md` with findings and a go/no-go.

## X.1 Row Level Security for B2B shared catalogs / customer-group visibility
- `SET app.customer_group = :id` per request (`set_config` with `is_local`), RLS policies on listing/price tables.
- Questions: performance of policies in listing queries, interaction with connection pooling, admin bypass role.

## X.2 UNLOGGED tables as cache/session backend
- Magento cache and session backends on UNLOGGED tables (no WAL, lost on crash — acceptable for cache).
- Compare with Redis for small installations; tag-based invalidation via `text[]` + GIN.

## X.3 `pg_ivm` for simple aggregates
- Try IMMV for simple join/aggregate read models (e.g. review summaries, counts per category).
- Measure write amplification and locking under concurrent writes; check availability on managed PG.

## X.4 CDC via logical replication (trigger-free pipeline)
- `wal_level=logical`, publications with row filters/column lists, consumer (e.g. a small Go service) feeding dirty sets.
- Compare with phase 1 triggers on import-heavy workloads; slot retention (`max_slot_wal_keep_size`), monitoring.

## X.5 Semantic search with `pgvector`
- Hybrid ranking: FTS score + vector similarity (reciprocal rank fusion) for quick search.
- Reuses phase 8 embeddings channel.

## X.6 BM25 ranking extension
- Evaluate a BM25 extension (e.g. ParadeDB `pg_search`) vs `ts_rank_cd` relevance; maturity and licensing check.

## X.7 Roaring bitmaps for facets
- If chosen in the phase 3 decision gate: bitmap per (store, attribute option), counts via intersection; maintenance through the pipeline.

## Decisions log

- _(empty)_
