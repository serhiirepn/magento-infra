# Phase 3 — Listing, Search & Facets (search engine adapter on PostgreSQL)

## Goal

Serve category pages and quick search from PostgreSQL: filters, sorting, pagination, layered-navigation facets and
full-text search — implemented as a Magento **search engine adapter**, so the stock storefront works unchanged.

## Magento today

- Since 2.4 **both category listing and layered navigation go through the search engine** (`search_request.xml`
  containers such as `catalog_view_container`, `quick_search_container`); facet data comes from search response aggregations.
- Only OpenSearch/Elasticsearch engines are supported; data is pushed by the `catalogsearch_fulltext` indexer.

## Design

### Data
- `horobyna_product_listing` (phase 2) + `search_doc tsvector` generated per store:
  `setweight(to_tsvector(cfg, name), 'A') || setweight(to_tsvector(cfg, sku), 'A') || setweight(to_tsvector(cfg, description), 'C') ...`
  where `cfg` is a `regconfig` mapped from the store locale (`german`, `english`, `russian`, `simple`).
  Magento search weights 1–10 map onto PG's four weights A–D (document the mapping).
- `pg_trgm` GIN index on name/sku for typo tolerance and autocomplete.
- Narrow hot tables joined at query time:
  - `product_price(product_id, website_id, customer_group_id, price, valid tstzrange)` (phase 6; until then a simple price table)
  - `product_stock(stock_id, product_id, salable_qty, stock_bucket)` maintained by the pipeline from MSI reservations.

### Query shape
```sql
SELECT p.product_id, p.name, pr.price, count(*) OVER () AS total
FROM horobyna_product_listing p
JOIN product_price pr ON pr.product_id = p.product_id
 AND pr.website_id = :website AND pr.customer_group_id = :group AND pr.valid @> now()
WHERE p.store_id = :store
  AND p.category_ids && :anchor_category_ids       -- from ltree (phase 4)
  AND p.status = 1 AND p.visibility IN (2,4)
  AND p.attrs @@ '$.color == 49 || $.color == 52'  -- OR within attribute
  AND p.attrs @> '{"material": 7}'                 -- AND across attributes
  AND pr.price BETWEEN :min AND :max
ORDER BY pr.price, p.product_id
LIMIT :limit OFFSET :offset;
```

### Translation of Magento search requests
- `Magento\Framework\Search\Request` queries (`match`, `bool`, `filter`) and filters (`term`, `range`, `wildcard`) → SQL fragments.
- `match` → `search_doc @@ websearch_to_tsquery(cfg, :q)` + `ts_rank_cd` + attribute boosts; fallback to trigram similarity when no FTS hit.
- Synonyms (`search_synonyms`) → query expansion; stopwords via text search config.

### Sort & filter providers (extension point)
```php
interface SortProviderInterface {
    public function join(Select $select, ListingContext $ctx): void;
    public function orderExpression(string $direction): string;
}
interface FilterProviderInterface {
    public function apply(Select $select, ListingContext $ctx, mixed $value): void;
    public function aggregate(ListingContext $ctx): ?AggregationQuery; // facet support
}
```
Built-in providers: position, name, price, stock (by `stock_bucket` then position), relevance.
Custom sorts (popularity, margin, rating, news_from_date) = narrow pipeline-maintained table + provider registered via DI.

### Positions
Category positions in `(category_id, product_id, position)`. Rule for anchor categories must be explicit
(e.g. direct assignments first, then min position from descendants). Record the rule in the Decisions log.

### Facets — the main performance risk
- Baseline: aggregate over the filtered set
  `SELECT key, value, count(*) FROM filtered, jsonb_each(attrs) ... GROUP BY 1,2` (+ `jsonb_array_elements` for multiselect).
- Disjunctive facets (selected value of attribute A must not narrow A's own options) → one aggregation per selected attribute, or `GROUPING SETS`.
- Price buckets: `width_bucket` / Magento's dynamic algorithms (auto, improved, manual).
- **Decision gate (P3.8):** benchmark on the `facets` fixture. If baseline p95 is too high, evaluate:
  (a) narrow facet table `(store_id, attr_id, option_id, product_id)`;
  (b) `pg_roaringbitmap` — one bitmap per option, counts via bitmap intersection.

### Pagination
`OFFSET` (users rarely go deep) + `count(*) OVER ()`. Keyset pagination only for API consumers.

## Tasks

- [ ] **P3.1** Engine skeleton `horobyna` registered as `catalog/search/engine` option (mirror OpenSearch module structure: adapter, mapper, aggregation builder, index handler).
- [ ] **P3.2** Index handler: populate `search_doc` / listing via pipeline instead of push-to-engine.
- [ ] **P3.3** Request → SQL mapper (queries, filters, scoring).
- [ ] **P3.4** Aggregations: term buckets, multiselect, price buckets; disjunctive facets.
- [ ] **P3.5** Sort/filter provider interfaces + built-ins (position, name, price, relevance, stock).
- [ ] **P3.6** `product_stock` channel from MSI (`inventory_source_item`, `inventory_reservation`), `stock_bucket` + cache-tag invalidation only on bucket change.
- [ ] **P3.7** Autocomplete/suggestions with `pg_trgm`; synonyms; locale → `regconfig` mapping.
- [ ] **P3.8** Facet benchmark & decision gate (baseline vs facet table vs roaring bitmaps).
- [ ] **P3.9** Storefront functional check: category pages, layered nav, search results, sorting, paging with engine = `horobyna`.
- [ ] **P3.10** Publish a clean listing/search JSON API for the alternative frontend track.

## Magento integration points

- `Magento\Framework\Search\AdapterInterface`, `Magento\Framework\Search\RequestInterface`, `Magento\Framework\Search\Response\*` (aggregations)
- `Magento\Search\Model\AdapterFactory`, engine config `catalog/search/engine`, `search_request.xml`
- `Magento\Framework\Indexer\SaveHandler\IndexerInterface` (index handler for `catalogsearch_fulltext`)
- Layered navigation filters `Magento\CatalogSearch\Model\Layer\Filter\*`
- MSI: `Magento\InventorySalesApi\Api\GetProductSalableQtyInterface`, `IsProductSalableInterface`

## Acceptance criteria

- Stock storefront works with engine `horobyna` (category, search, layered nav, sort, paging) on the `large` fixture.
- Targets (calibrate in bench): category page query p95 < 50 ms for a 20k-product category; facets p95 < 100 ms.
- `catalog_product_index_eav`, flat tables and OpenSearch not required.

## Risks & open questions

- Relevance quality vs OpenSearch (BM25). Optional experiment: BM25 extension (e.g. ParadeDB `pg_search`) — check maturity/compatibility first.
- Only four FTS weights.
- Very large categories (200k+) — facet strategy decided by P3.8.

## Decisions log

- _(empty)_
