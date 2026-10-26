# Phase 5 — Condition Compiler (Magento rule conditions → jsonpath / SQL)

## Goal

Compile Magento rule condition trees into an intermediate representation and then into PostgreSQL `jsonpath`
and SQL predicates, so rule matching becomes a set-based query instead of per-product PHP validation.
One compiler, many consumers.

## Consumers

| Consumer | Phase |
|----------|-------|
| Catalog price rules | 6 |
| Related product rules (TargetRule-like) | 8 |
| Widget "Catalog Products List" conditions | 5 (demo consumer) |
| Smart / auto categories (Visual Merchandiser-like) | later |
| Customer segments (product-based conditions) | later |

## Magento today

- Conditions are PHP object trees (`Magento\Rule\Model\Condition\Combine`, `...\Product\AbstractProduct`,
  `Magento\CatalogRule\Model\Rule\Condition\Product`), stored serialized (`conditions_serialized`, JSON).
- Matching = load products + `validate()` per product (`Magento\CatalogRule\Model\Indexer\IndexBuilder`).

## Design

### Pipeline
```
conditions_serialized (JSON)
   → Parser → IR (typed AST)
   → Normalizer (flatten, push negations, constant folding)
   → Backends:  jsonpath  |  SQL WHERE fragment  |  PHP residual
```

### IR
- `And(children)`, `Or(children)`, `Not(child)`, `True`, `False`
- `Cmp(attribute, op, value, type)` with `op ∈ {eq, neq, gt, gte, lt, lte, in, nin, contains, ncontains, like}`
- `Category(ids, semantics: direct|anchor)`, `AttributeSet(ids)`, `Type(ids)`, `Sku(values)`
- `SourceRef(attribute)` — reference to the source product's value (phase 8, correlated conditions)
- `Opaque(condition)` — anything the compiler can't translate

### Mapping Magento → IR
- `Combine` aggregator `all` / `any` + value `true` / `false`:
  - all + true → `And(...)`; any + true → `Or(...)`
  - all + false → `And(Not(c) for c)`; any + false → `Or(Not(c) for c)`
- Operators `==, !=, >=, <=, >, <, {}, !{}, (), !()` → `eq, neq, gte, lte, gt, lt, contains, ncontains, in, nin`
- Value typing from `eav_attribute.backend_type` / `frontend_input` (select → int, multiselect → int[], decimal → numeric, date → date, text → string).

### Backend: jsonpath
- Lax mode auto-unwraps arrays → `$.size == 12` matches if any multiselect element equals 12 (= Magento "contains").
- Case-insensitive text → `like_regex "..." flag "i"`.
- Dates → compare ISO strings, or `.datetime()` (non-immutable → not index-assisted; acceptable for rule evaluation).
- Example: brand = 31 AND (category 12 OR color 49)
  → `$.brand == 31 && ($.category_ids[*] == 12 || $.color == 49)`
- Indexability: equality/containment parts benefit from GIN (`jsonb_path_ops` supports `@@`); range parts don't.

### Backend: SQL
For predicates on typed columns (price, status, visibility, category arrays) — emits SQL fragments for the listing query builder.

### Partial compilation (soundness rule)
Uncompilable leaves (`Opaque`) are replaced **polarity-aware**: in positive position by `True`, under `Not` by `False`.
This yields a **superset** prefilter in SQL; the original PHP `validate()` then runs only on the candidate set (residual check).
Result: fast path for most rules, exact results for all rules.

### Storage
Columns on rule tables (or side table): `compiled_jsonpath jsonpath`, `compiled_sql text`, `compile_status (full|partial|failed)`,
`compiler_version int`. Recompile on rule save and on compiler version change.

### Two directions with one representation
```sql
-- rule saved → matching products
SELECT product_id FROM horobyna_product_listing WHERE store_id = :s AND attrs @@ :compiled;
-- product changed → matching rules (rules are few; full scan is fine)
SELECT r.rule_id FROM horobyna_rule_compiled r, horobyna_product_listing p
WHERE p.product_id = :id AND p.store_id = :s AND p.attrs @@ r.compiled_jsonpath;
```

## Tasks

- [ ] **P5.1** IR classes + parser from `conditions_serialized`.
- [ ] **P5.2** Normalizer (negation push-down, flattening, constant folding).
- [ ] **P5.3** jsonpath backend (all types/operators above; escaping; lax-mode semantics).
- [ ] **P5.4** SQL backend for typed columns and category semantics (`direct` vs `anchor` via phase 4 subtree resolver).
- [ ] **P5.5** Partial compilation with polarity-aware over-approximation + PHP residual executor.
- [ ] **P5.6** Registry for third-party condition types: interface `ConditionCompilerInterface` registered via DI; unknown types → `Opaque`.
- [ ] **P5.7** **Differential testing:** random products × random/real rules; assert compiled result == PHP `validate()` (property-based, run in CI).
- [ ] **P5.8** CLI `bin/magento horobyna:conditions:explain <rule>` — shows IR, jsonpath, SQL, status.
- [ ] **P5.9** Demo consumer: "Catalog Products List" widget using the compiler.
- [ ] **P5.10** Benchmark: rule matching for 100 rules on `large` fixture (stock IndexBuilder vs compiled).

## Magento integration points

- `Magento\Rule\Model\Condition\AbstractCondition`, `Combine`, `Product\AbstractProduct`
- `Magento\CatalogRule\Model\Rule\Condition\Product`, `Magento\CatalogRule\Model\Indexer\IndexBuilder`
- `Magento\CatalogWidget\Model\Rule\Condition\*` (widget conditions)

## Acceptance criteria

- Differential tests: 0 mismatches over ≥ 100k (product, rule) pairs.
- ≥ 90% of real-world rules on the fixture compile fully; the rest compile partially with correct residual results.
- Matching 100 rules is at least an order of magnitude faster than the stock IndexBuilder (target, calibrate).

## Risks & open questions

- Exact semantics of category conditions differ per consumer (direct vs anchor) — define per consumer.
- Attribute scope: conditions evaluated per store/website — make the evaluation scope explicit in the compiler context.
- jsonpath numeric vs string typing pitfalls → strict encoding rules from phase 2.

## Decisions log

- _(empty)_
