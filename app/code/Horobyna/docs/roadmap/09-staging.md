# Phase 7 — Staging (Scheduled Changes) with Ranges and jsonb Patches

## Goal

Implement scheduled content changes ("campaigns") on top of Magento Open Source using range types, exclusion
constraints and jsonb patches — a simpler model than Adobe Commerce staging, with preview-by-timestamp for free.

Non-goal: compatibility with Adobe Commerce `Magento_Staging` (`row_id` model).

## Adobe Commerce today (reference)

- `row_id` versions instead of `entity_id`, `sequence_*` tables, `created_in` / `updated_in` version ids.
- Each update **copies the entity row and all EAV values**; rollback entries; non-overlap enforced in PHP.
- Cron `staging_apply_version` switches the current version → reindex of affected entities.

## Design

### Layered model: base + campaign patches
```sql
CREATE TABLE horobyna_campaign (
    campaign_id int PRIMARY KEY, name text, valid tstzrange NOT NULL, status text
);
CREATE TABLE horobyna_product_campaign (
    product_id  int,
    campaign_id int REFERENCES horobyna_campaign,
    store_id    smallint,
    valid       tstzrange NOT NULL,           -- copied from campaign
    attrs_patch jsonb NOT NULL,               -- only changed attributes
    PRIMARY KEY (product_id, store_id, valid WITHOUT OVERLAPS)   -- PG 18
    -- PG < 18: EXCLUDE USING gist (product_id WITH =, store_id WITH =, valid WITH &&)
);
```
- Resolved value: `base.attrs || coalesce(campaign.attrs_patch, '{}')` where `campaign.valid @> :ts`.
- End of range = automatic rollback. No copies, no rollback rows, no version switch.
- **Preview:** same queries with `:ts = preview timestamp` instead of `now()`.
- Prices in campaigns: become segments in `product_price` (phase 6).

### Read model strategies (choose per experiment)
- **A — boundary recompute:** worker finds the next boundary (`min(lower(valid)) > now()`, same for `upper`) and marks affected products dirty at that time.
- **B — versions in read model:** `horobyna_product_listing` carries `valid` ranges (base row split by campaigns), query adds `valid @> :ts`. Instant switch + preview for listings.

### Cache
Each page knows the nearest upcoming boundary of its products → `Cache-Control: max-age = seconds until boundary` → pages expire exactly at campaign start/end without explicit purges.

## Tasks

- [ ] **P7.1** Schema (campaign, product_campaign; PG18 temporal PK with fallback exclusion constraint).
- [ ] **P7.2** Resolver with timestamp parameter (`now()` or preview) used by product load and listing channel.
- [ ] **P7.3** Read-model strategy A (boundary scheduler using pipeline + `send at` timing).
- [ ] **P7.4** Read-model strategy B (ranged listing rows) — compare with A in bench.
- [ ] **P7.5** Preview mode (admin token → request-scoped timestamp).
- [ ] **P7.6** Minimal admin UI: campaign CRUD, attach product changes as patches, conflict display from constraint errors.
- [ ] **P7.7** Campaigns for categories and CMS blocks/pages (same pattern).
- [ ] **P7.8** `max-age` until next boundary in FPC/Varnish headers.

## Magento integration points

- Product/category resource models and the phase 2 resolver
- `Magento\PageCache` / Varnish headers
- Admin UI components (product form)

## Acceptance criteria

- Campaign start/end visible on storefront within seconds of the boundary without full reindex.
- Overlapping campaigns for the same product/store are rejected by the database.
- Preview shows the store at any future timestamp, including listings with strategy B.

## Risks & open questions

- Merge semantics for multiselect/structured values in patches (replace vs merge) — define.
- Interaction of several campaigns on different attributes of the same product (currently forbidden by non-overlap; consider per-attribute ranges later).
- History/audit (system-versioned tables) is not in PG core — out of scope.

## Decisions log

- _(empty)_
