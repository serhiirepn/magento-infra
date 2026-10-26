# Phase 8 — Related Product Rules (TargetRule-like) with Correlated Conditions

## Goal

Rule-based related/up-sell/cross-sell blocks computed on read by PostgreSQL, using the condition compiler extended
with **correlated conditions** (references to the viewed product), optionally ranked by vector similarity.

## Adobe Commerce today (reference)

- `Magento_TargetRule`: "products to match" conditions + "products to display" actions, which may reference the
  matched product ("same category", "price lower than"). Results precomputed/cached in `magento_targetrule_index`.

## Design

### Rule model
- `match_condition` → compiled jsonpath (phase 5) — which source products the rule applies to.
- `display_condition` → IR with `SourceRef` nodes, e.g. `brand == SourceRef(brand)`, `price < SourceRef(price) * 1.2`.
- `limit`, `rotation` (`position` | `random` | `similarity`), `priority`, `types` (related / upsell / crosssell).

### Compilation of `SourceRef`
- **At query time** substitute the source product's values into the jsonpath literal → the path stays a constant → `@@` can use the GIN index.
- (Alternative `jsonb_path_exists(attrs, path, vars)` supports variables but isn't index-assisted.)

### Query shape
```sql
SELECT c.product_id
FROM horobyna_product_listing c
JOIN product_price cp ON cp.product_id = c.product_id
 AND cp.website_id = :w AND cp.customer_group_id = :g AND cp.valid @> now()
LEFT JOIN product_stock s ON s.stock_id = :stock AND s.product_id = c.product_id
WHERE c.store_id = :store
  AND c.product_id <> :source_id
  AND c.attrs @@ :display_path_with_substituted_values
  AND cp.price < :source_price * 1.2
  AND coalesce(s.stock_bucket, 0) > 0
ORDER BY random()          -- or position / similarity
LIMIT :limit;
```
For several rules: `LATERAL` per rule ordered by priority, de-duplicate, cut to block size.

### Optional: semantic ranking with `pgvector`
- `product_embedding(product_id, store_id, embedding vector(n))` maintained by a pipeline channel.
- Rules give hard constraints; `ORDER BY embedding <=> :source_embedding` ranks inside them.

### Caching
Result is part of the product page block → FPC with product cache tags; no precomputed index table.

## Tasks

- [ ] **P8.1** Extend compiler IR/backends with `SourceRef` and value substitution.
- [ ] **P8.2** Rule storage + admin UI (reuse Magento rule condition UI components).
- [ ] **P8.3** Query builder (multi-rule LATERAL, priority, dedup, rotation modes).
- [ ] **P8.4** Blocks for related / upsell / crosssell (product page, cart).
- [ ] **P8.5** Optional: embeddings channel + `pgvector` ranking (`hnsw` index).
- [ ] **P8.6** Benchmarks: block query latency p95 on `large`; cache hit behaviour.

## Magento integration points

- `Magento\Catalog\Block\Product\ProductList\Related`, `Upsell`, `Magento\Checkout\Block\Cart\Crosssell`
- Rule condition UI (`Magento\Rule\Block\*`, `Magento\Rule\Model\Condition\*`)

## Acceptance criteria

- Blocks render from on-read queries with p95 < 20 ms (target) and no precomputed index table.
- Differential tests for `SourceRef` conditions vs a PHP reference evaluation.

## Risks & open questions

- `ORDER BY random()` on large candidate sets → use `TABLESAMPLE` or random offset over an indexed order when needed.
- Embedding generation is outside PG (external model) — keep it optional.

## Decisions log

- _(empty)_
