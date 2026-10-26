# Phase 4 — Category Tree on `ltree`

## Goal

Represent the category tree with `ltree`, resolve anchor categories (category + descendants) on read and remove the
need for the `catalog_category_product` index.

## Magento today

- `catalog_category_entity.path` stores `1/2/5/12`; `level`, `children_count` maintained by PHP.
- Anchor logic is precomputed by the `catalog_category_product` / `catalog_product_category` indexers per store.

## Design

```sql
ALTER TABLE catalog_category_entity
  ADD COLUMN tree ltree GENERATED ALWAYS AS (replace(path, '/', '.')::ltree) STORED;
CREATE INDEX ON catalog_category_entity USING gist (tree);
```
(Verify that the cast is accepted in a generated column; otherwise maintain `tree` by trigger.)

- Subtree: `SELECT entity_id FROM catalog_category_entity WHERE tree <@ :category_tree` → `:anchor_category_ids` for the listing query (phase 3).
- Non-anchor categories: only direct assignments.
- Anchor flag and `is_active` are per store → resolve via jsonb attrs of categories (phase 2, P2.9) or a small resolved table.
- Positions: `catalog_category_product(category_id, product_id, position)` stays as the source of direct positions;
  the anchor position rule from phase 3 is applied at query time.
- Tree moves: updating `path` of a subtree is one `UPDATE ... WHERE tree <@ :old` using `subpath`/concatenation.
- `level` and `children_count` become derivable (`nlevel(tree)`, count of descendants) — keep columns for compatibility, fill by trigger.

## Tasks

- [ ] **P4.1** Generated/trigger-maintained `tree` column + GiST index via `Horobyna_Schema`.
- [ ] **P4.2** Subtree resolver service (with per-request cache) used by the listing query builder.
- [ ] **P4.3** Anchor/is_active per store resolution.
- [ ] **P4.4** Category move implemented as a single set-based update; verify against stock move logic.
- [ ] **P4.5** Switch listing (phase 3) from `catalog_category_product` index to on-read subtree expansion; benchmark.
- [ ] **P4.6** Breadcrumbs/menus via ancestor queries (`tree @> ...`) as a side benefit.

## Magento integration points

- `Magento\Catalog\Model\ResourceModel\Category` (move, save), `Magento\Catalog\Model\Category\Tree`
- Indexers `catalog_category_product`, `catalog_product_category`

## Acceptance criteria

- Category pages show identical product sets (stock index vs on-read ltree) on the `large` fixture.
- Category move of a subtree with 1000 descendants runs as one statement and keeps listing consistent.

## Risks & open questions

- Stores with root-category differences per store group — verify subtree semantics.
- URL rewrites depend on category paths (out of scope here, but note the coupling).

## Decisions log

- _(empty)_
