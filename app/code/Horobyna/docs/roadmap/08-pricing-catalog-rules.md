# Phase 6 — Pricing with Time Ranges & Catalog Rules without Daily Cron

## Goal

Store final prices as data with validity ranges (`tstzrange`) per website and customer group, compute catalog
rule effects with the condition compiler, and remove the daily rule reindex and its timezone problems.

## Magento today

- `catalog_product_index_price` computed by type-specific indexers + price modifiers.
- Catalog rules: `catalogrule_product` (matches) and `catalogrule_product_price` **per day** (`rule_date` for yesterday/today/tomorrow)
  per website/group → daily cron; frequent timezone bugs with rule dates per website.
- Special price with `special_from_date` / `special_to_date` evaluated by date.

## Design

### Tables
```sql
CREATE TABLE horobyna_rule_product (
    rule_id int, product_id int, website_id smallint, customer_group_id smallint,
    valid tstzrange NOT NULL,
    action text, amount numeric(12,4), sort_order int, stop_further boolean
);
CREATE TABLE product_price (
    product_id int, website_id smallint, customer_group_id smallint,
    price numeric(12,4), min_price numeric(12,4), max_price numeric(12,4),
    valid tstzrange NOT NULL,
    EXCLUDE USING gist (product_id WITH =, website_id WITH =, customer_group_id WITH =, valid WITH &&)
);
```
- Read: `valid @> now()` — no "switch" moment, no daily cron.
- Website timezone: boundaries built in SQL, e.g. `('2026-11-01'::timestamp AT TIME ZONE :website_tz)`; DST handled by PG.

### Computation (pipeline channel `product_price`)
1. Base price per type (simple/virtual/downloadable; configurable = min of salable children; bundle; grouped).
   **Step 1 (pragmatic):** reuse Magento's price indexer computation for base/min/max prices, change only storage + time model.
   **Step 2:** port selected type strategies to SQL.
2. Collect time segments from: special price dates, tier prices, matching catalog rules (`horobyna_rule_product`).
   Split into non-overlapping segments (multiranges `range_agg` help).
3. Per segment: apply rules in `sort_order`, honour `stop_further`, apply action (`by_percent`, `by_fixed`, `to_percent`, `to_fixed`).
4. Write segments into `product_price` (replace product's rows in one transaction).

Rule matching uses the **condition compiler** (phase 5): on rule save → products via `attrs @@ compiled`; on product change → rules via reverse match.

### Price modifiers
Third-party `PriceModifierInterface` implementations: run them in step 1 (pragmatic path); document which ones are incompatible with segment-based computation.

## Tasks

- [ ] **P6.1** Schema `horobyna_rule_product`, `product_price` (exclusion constraint, GiST).
- [ ] **P6.2** Rule match channel using compiler (rule → products, product → rules).
- [ ] **P6.3** Segment builder (special price, tier price, rules) with website timezone boundaries.
- [ ] **P6.4** Rule folding (sort_order, stop_further, actions) — pure function + unit tests.
- [ ] **P6.5** Pragmatic base price source: adapter over stock price indexer results.
- [ ] **P6.6** Listing/search (phase 3) and product page price read from `product_price`.
- [ ] **P6.7** Differential test vs stock `catalogrule_product_price` for chosen dates/websites/groups.
- [ ] **P6.8** Timezone edge tests: DST switch days, websites in different timezones, rule ending at midnight.
- [ ] **P6.9** Cache: compute next price boundary per page/product → `max-age` until boundary (see phase 7 trick).
- [ ] **P6.10** Benchmarks: rule save → prices visible latency; full rebuild; storage vs stock per-day tables.

## Magento integration points

- `Magento\Catalog\Model\ResourceModel\Product\Indexer\Price\*`, `PriceModifierInterface`
- `Magento\CatalogRule\Model\Indexer\*`, `Magento\CatalogRule\Model\ResourceModel\Rule`
- Final price resolution for storefront: `Magento\Catalog\Pricing\Price\*` (`FinalPrice`, `CatalogRulePrice`)

## Acceptance criteria

- No daily rule cron needed; rule start/end takes effect at the exact boundary.
- Differential test P6.7: 0 mismatches.
- Rule save → storefront price visible p95 < 10 s on `large` (target).

## Risks & open questions

- Bundle/configurable complexity — keep step 1 pragmatic.
- Number of segments per product (many overlapping rules) — measure.
- Cart price rules are out of scope (quote-time evaluation), but the compiler could serve product conditions there later.

## Decisions log

- _(empty)_
