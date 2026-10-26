# Phase 2 — jsonb Instead of EAV (+ `product_listing` read model)

## Goal

Store product (later category) attribute values in `jsonb` per scope, migrate from EAV without downtime using the
standard migration pattern, and build one generic resolved read model `product_listing` that replaces flat tables
and the EAV index.

## Magento today

- Values in `catalog_product_entity_{varchar,int,decimal,text,datetime}` keyed by `(attribute_id, store_id, entity_id|row_id)`.
- Store fallback (store → default) resolved in collections with joins per attribute.
- Flat tables and `catalog_product_index_eav` exist mainly to compensate for EAV read cost.

## Design

### Write model
```sql
CREATE TABLE horobyna_product_attrs (
    product_id int      NOT NULL,
    store_id   smallint NOT NULL,      -- 0 = default scope
    attrs      jsonb    NOT NULL DEFAULT '{}',
    PRIMARY KEY (product_id, store_id)
);
```
- Store rows contain **only overrides**. Missing key = use default. JSON `null` = explicitly empty override.
- Attribute metadata stays in `eav_attribute` / `catalog_eav_attribute` (types, input, scope, flags).
- Value encoding:

| Magento backend/input | jsonb encoding |
|-----------------------|----------------|
| int / select / boolean | number (option id / 0-1) |
| multiselect | array of numbers |
| decimal | number (exact numeric in jsonb) |
| varchar / text | string |
| datetime | ISO-8601 string |
| media gallery, tier price, links | **stay relational** (not attributes in this sense) |

- Keys are `attribute_code` (readable, stable) — decide vs `attribute_id`; record in Decisions log.

### Read model `product_listing` (maintained by the pipeline)
One resolved row per `(store_id, product_id)`:
```sql
CREATE TABLE horobyna_product_listing (
    store_id     smallint,
    product_id   int,
    sku          text,
    type_id      text,
    attribute_set_id int,
    status       smallint,
    visibility   smallint,
    name         text,
    category_ids int[],        -- direct assignments
    attrs        jsonb,        -- resolved filterable/sortable/searchable attributes
    search_doc   tsvector,     -- phase 3
    PRIMARY KEY (store_id, product_id)
);
CREATE INDEX ON horobyna_product_listing USING gin (attrs jsonb_path_ops);
CREATE INDEX ON horobyna_product_listing USING gin (category_ids);
```
- **Hot, frequently changing data is NOT here**: prices → `product_price` (phase 6), stock → `product_stock` (phase 3).
- Resolution: `default.attrs || store.attrs` (right side wins).

### Migration pattern (reused in later phases)
1. **M1 Shadow:** pipeline channel `attrs_shadow` subscribed to all EAV value tables → rebuilds `horobyna_product_attrs` for dirty products. Legacy code keeps writing EAV.
2. **M2 Reconcile:** `bin/magento horobyna:attrs:reconcile` compares EAV vs jsonb for all/random products; must reach 0 diffs.
3. **M3 Switch reads:** product load + product collection read from jsonb (flag `attrs_read`).
4. **M4 Switch writes:** resource model writes jsonb; reverse sync jsonb → EAV kept temporarily for third-party code reading EAV tables directly (flag `attrs_write`).
5. **M5 Drop:** remove reverse sync and EAV value tables for products (flag `attrs_eav_dropped`, irreversible — only on throwaway DBs during the experiment).

## Tasks

- [ ] **P2.1** Schema for `horobyna_product_attrs` and `horobyna_product_listing` via `Horobyna_Schema`.
- [ ] **P2.2** Encoder/decoder per backend type (+ unit tests for every type, null/absent semantics, multiselect).
- [ ] **P2.3** Pipeline channel `attrs_shadow` (M1) incl. `row_id` mapping.
- [ ] **P2.4** Reconciliation tool (M2) with diff report.
- [ ] **P2.5** Read path (M3): product load (`ReadHandler`/resource `load`), `Product\Collection` translation:
      `addAttributeToSelect` → `attrs->'code'`, `addAttributeToFilter` → `@>` / `@@` / typed expressions, `addAttributeToSort` → typed expressions.
- [ ] **P2.6** Write path (M4) + reverse sync trigger/channel.
- [ ] **P2.7** Pipeline channel `product_listing` (resolve scopes, collect category ids, status/visibility).
- [ ] **P2.8** Expression-index helper: declare B-tree expression indexes for numeric range-filtered attributes (e.g. `((attrs->>'width')::numeric)`).
- [ ] **P2.9** Same pattern for categories (after products are stable).
- [ ] **P2.10** Benchmarks: storage size, product save, collection load (grid, listing), import 100k.

## Magento integration points

- `Magento\Catalog\Model\ResourceModel\Product` (`Magento\Eav\Model\Entity\AbstractEntity`: load/save attribute values)
- `Magento\Catalog\Model\ResourceModel\Product\Collection` / `Magento\Eav\Model\Entity\Collection\AbstractCollection`
- EntityManager handlers (`Magento\Eav\Model\ResourceModel\ReadHandler`, `CreateHandler`, `UpdateHandler`) — verify which path products use in the target version
- Import: `Magento\CatalogImportExport\Model\Import\Product` (writes EAV tables directly → must be redirected or covered by shadow sync)

## Acceptance criteria

- M2 reconciliation reports 0 diffs on the `large` fixture after random edits and imports.
- Catalog integration tests pass with `attrs_read` and with `attrs_write`.
- `catalog_product_index_eav` and flat tables are not needed for storefront listing (verified in phase 3).

## Risks & open questions

- Direct SQL against EAV tables in core/third-party code (import, mass update attributes) → keep shadow/reverse sync until audited.
- jsonb row size with many text attributes → TOAST; long descriptions could stay outside `attrs` (separate jsonb or table).
- Attribute rename/delete → key rename migration job.

## Decisions log

- _(empty)_
