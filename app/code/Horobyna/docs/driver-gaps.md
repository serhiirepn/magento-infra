# Driver gaps (P0.1)

Status of the Phase 0 driver hardening checklist and the MySQL-only SQL in Magento core that
`Horobyna\Db\Adapter\Pdo\Pgsql` does not handle yet.

Checked on 2026-10-23 against `vendor/magento` (Magento 2.4, PostgreSQL 17). Every "fails" below was
reproduced through the adapter with `EXPLAIN` (probe scripts in `var/horobyna-probe/`); DDL was run inside a
rolled-back transaction.

## Checklist

| Item | Status | Where |
|---|---|---|
| Identifier quoting, reserved words | Done: backticks → `"..."`, reserved words used as aliases are quoted | `Pgsql::translateIdentifiers()`, `RESERVED_WORDS` |
| `insertOnDuplicate()` → `ON CONFLICT` | Done, conflict target from PK/unique covered by the columns; raw `ON DUPLICATE KEY UPDATE` and `INSERT IGNORE` text translated too | `insertOnDuplicate()`, `translateOnDuplicateKeyUpdate()`, `translateInsertIgnore()` |
| `GROUP_CONCAT`, `FIND_IN_SET`, `IFNULL`, `IF`, `FIELD`, date functions | Done | `Sql\FunctionMapper` |
| `AUTO_INCREMENT` → identity, `LAST_INSERT_ID` | Done for adapter DDL and `lastInsertId()`; **not** for raw DDL text (see `Ddl\Sequence`) | `getColumnSpec()`, `lastInsertId()` |
| Unsigned ints | Done: widened (pgloader for existing data, `translateMysqlType()` for DDL) | |
| Zero dates | Done in declarative schema only; **zero-date values bound at runtime fail** | `Setup/Declaration/Schema/Db/SqlBuilder` |
| Case-insensitive comparisons | `like`/`nlike`/`regexp` conditions are case-insensitive (`ILIKE`). **No decision for `=` and unique keys** (DB collation `en_US.utf8`, no nondeterministic collations, no `citext`) | `prepareSqlCondition()` |
| Transactional DDL | Statements run in savepoints; setup code relying on implicit commits not audited | `runInSavepoint()` |

## Open: case-insensitive columns

Split out of P0.1 on 2026-10-23; the decision goes into the 01-foundation Decisions log when made.

MySQL `_ci` collations make comparisons, joins, grouping and unique keys case-insensitive, and core relies on it:
`sku IN (?)` lookups followed by `strtolower($sku)` maps in PHP (catalog import `SkuProcessor`, `Import/Product`,
`StatusProcessor`, `Catalog/Model/ResourceModel/Product`, `Quote/Model/Cart/ProductReader`,
`GetProductTypesBySkus`), `subscriber_email = ?` (newsletter), `email IN (?)` (customer import), `request_path = ?`
(`UrlRewrite DbStorage`). Nothing lowercases emails on save. On PostgreSQL today `Foo@x.com` and `foo@x.com` are
different customers and `abc` does not find `ABC`.

Candidate columns: `customer_entity.email`, `admin_user.email`, `admin_user.username`,
`newsletter_subscriber.subscriber_email`, `url_rewrite.request_path`, `salesrule_coupon.code`,
`store.code`, `store_website.code`, and `sku` in every table that joins on it (`catalog_product_entity`,
`inventory_source_item`, `inventory_reservation`, `sales_order_item`, ...); joins only stay case-insensitive when
both sides are.

| Option | Verdict |
|---|---|
| ICU nondeterministic collation (`und-u-ks-level2`) | Rejected: on PG 17.11 `LIKE` and `ILIKE` fail ("nondeterministic collations are not supported"), including the adapter's `CAST(x AS TEXT) ILIKE ?`; PG 18 adds `LIKE` only |
| `lower(col)` unique index + `lower(col) = lower(?)` queries | Only works if every query, join and `GROUP BY` is rewritten (preferences or adapter predicate rewriting); the index alone makes lookups miss and inserts fail as duplicates. Good for queries Horobyna owns |
| Application-side normalization (preferences, lowercasing on save) | Same coverage problem; cannot enforce uniqueness under concurrency; lowercasing SKUs changes displayed data. Useful only as a complement (e.g. lowercase emails on save) |
| `citext` on the candidate columns | **Recommended.** Comparisons, `LIKE`, joins, grouping and unique keys behave like `_ci` with no query changes (accent-insensitivity of `unicode_ci` is not reproduced). Needs: `describeTable()`/comparator mapping `citext` → `varchar` so `setup:upgrade` does not revert it, a migration with a case-variant duplicate check, benchmark of index lookups (P0.3); if slow, add `lower(col::text)` indexes for Horobyna-owned queries |

## Core offenders not handled

Already handled by an override before this check and therefore not listed:
`SqlVersionProvider` (`SHOW variables`),
`Setup\Validator\DbValidator` (`SHOW TABLES`, `LOCK TABLES`), `Mview\TriggerCleaner` (`information_schema.TRIGGERS`).
`Framework\DB\Adapter\Pdo\Mysql` and the Zend/Laminas MySQL adapters are not loaded.

Impact: **high** = hit by cron, indexers, admin grids or a fresh `setup:install`; **medium** = a specific feature;
**low** = deprecated or optional module.

| Construct | Location | Reached by | Impact | Suggested fix |
|---|---|---|---|---|
| `MATCH(...) AGAINST(?)` | `framework/View/Element/UiComponent/DataProvider/FulltextFilter.php:107` | keyword search in every admin grid | high | plugin on `FulltextFilter::apply()` → `to_tsvector(...) @@ plainto_tsquery(?)` (needs matching GIN indexes, see `getFullTextIndexColumns()`) |
| `MATCH ... AGAINST` (boolean mode) | `module-cms/Model/ResourceModel/Page/Query/PageIdsList.php:64-76` | deleting a CMS block | medium | same as above, or `ILIKE` |
| `ROUND(double precision, int)` | price indexer (`ROUND(ta_price.value * (1 - ROUND(tier...` in `catalog_product_index_price_temp`) | `catalog_product_price` reindex | high | cast the first argument to `numeric` when `ROUND` has two arguments (`FunctionMapper`) |
| Strict `GROUP BY` (ungrouped column in a nested select) | catalog search fulltext indexer, bundle option availability subquery (`stock_status.stock_status`) | `catalogsearch_fulltext` reindex | high | extend the adapter's `fixUngroupedColumns()` to nested selects / raw SQL, or a preference for the data provider |
| `@var :=` user variables, `SET @a = ...` | `module-reports/Model/ResourceModel/Helper.php:101-115` | bestsellers report refresh | medium | preference using `ROW_NUMBER() OVER (PARTITION BY store_id, period ...)` |
| `'0000-00-00 00:00:00'` bound as a value | `module-sales/Model/ResourceModel/AbstractGrid.php:97` | async grid indexing with an empty grid | medium | map zero dates to `-infinity`/`NULL` in `_prepareQuery()` binds |
| `CONCAT_WS(" ", ...)` (double-quoted literal = identifier in PG) | `module-grouped-import-export/Model/Import/Product/Type/Grouped/Links.php:91` | grouped product import | medium | preference; generic translation of `"..."` literals is ambiguous |
| `SHOW CREATE TABLE/VIEW/TRIGGER`, `SHOW FULL TABLES`, `LOCK TABLES` | `module-backup/...` | backups (deprecated module) | low | leave disabled |

## Fixed

2026-10-23, verified through the adapter against PostgreSQL (`var/horobyna-probe/values.php`: results compared with
MySQL semantics; DML and DDL on temporary tables or rolled back).

| Construct | Core location | Fix |
|---|---|---|
| `UTC_TIMESTAMP()`, `UTC_DATE()`, `INTERVAL n UNIT` outside `DATE_ADD` | cron `Schedule`, `ProcessCronQueueObserver` | `FunctionMapper`; amount may be a number, placeholder or column |
| `CURRENT_TIMESTAMP()` etc. with empty parentheses | `ImportExport Import/Data` | `FunctionMapper`, keyword without parentheses |
| `TIMESTAMPDIFF`, `TIMESTAMPADD`, `TIMEDIFF`, `TIME_TO_SEC`, `STR_TO_DATE` | `DB\Helper::getDateDiff()`, `ImportExport Import/Data`, `CleanExpiredOrders`, data exporter | `FunctionMapper` |
| `JSON_EXTRACT(doc, '$.a[0].b')` | `CleanupReservations` | `FunctionMapper`, jsonb `#>` (simple paths only) |
| `INSTR` | wishlist collection | `FunctionMapper`, `STRPOS`, boolean in condition context |
| `CAST(x AS SIGNED/UNSIGNED)` | EAV collection sort | `FunctionMapper`, leading integer of the text or 0 (a decimal is truncated, MySQL rounds) |
| `[NOT] REGEXP`/`RLIKE` | `Data\Tree\Dbp`, config re-encryptor, payment encryption update, media gallery, fixtures | `SyntaxMapper`, `~*`/`!~*` (left operand must be text) |
| `USE/FORCE/IGNORE INDEX (...)` | bundle price indexer, catalog search collection | `SyntaxMapper`, dropped |
| `UPDATE/DELETE ... [ORDER BY] LIMIT n` | `SessionIDColumnCleanUp` (customer, security) | `SyntaxMapper`, rows chosen by `ctid` subquery |
| `ANALYZE/OPTIMIZE TABLE` | data exporter `BatchTable`, `GenerateFixturesCommand` | `SyntaxMapper`, `ANALYZE` (no `VACUUM`: not allowed in a transaction) |
| `SHOW [SESSION/GLOBAL] VARIABLES [LIKE ...]` | import helper (`max_allowed_packet`), fixture `AutoIncrement` | adapter, from `MYSQL_SYSTEM_VARIABLES`; `max_allowed_packet` = 1 MB because the adapter does not chunk multi-row INSERTs below the 65535-parameter limit |
| `Ddl\Sequence` MySQL DDL | sales sequence `Builder` (new store / website) | preference `Horobyna\Db\Ddl\Sequence`, identity column |
| `SHOW TRIGGERS [LIKE]`, `SHOW CREATE TRIGGER`, `DROP TRIGGER [IF EXISTS] name` (no `ON`) | catalog rule `IndexerTableSwapper`, `UpdateMultiselectAttributesBackendTypes` patch | adapter; `SHOW TRIGGERS` reports `table.trigger` because PG trigger names are unique per table only (pgloader's `on_update_current_timestamp` exists on many tables); an ambiguous unqualified name is an error; `DROP` keeps the trigger function so the saved `pg_get_triggerdef()` statement can be re-run. Verified with a full `catalogrule_rule` reindex on `db_pgtest`: tables swapped, mview triggers restored |
| `CREATE [TEMPORARY] TABLE [IF NOT EXISTS] new LIKE old` | catalog rule `IndexerTableSwapper` | adapter, `(LIKE old INCLUDING ALL)`; serial columns of the copy become identity columns with their own sequence, otherwise dropping the old table fails |
| `GET_LOCK`/`RELEASE_LOCK`/`IS_USED_LOCK` | `Lock\Backend\Database` | preference, advisory locks (P0.8) |

## Not verified

- Raw SQL in `.phtml`, DB-stored SQL and third-party modules.
- MySQL-only constructs built through `Select` parts in ways the regex scan doesn't see (e.g. string `columns()`
  expressions assembled from variables); these surface in P0.2 integration runs.
- Setup code that relies on DDL implicit commits.
