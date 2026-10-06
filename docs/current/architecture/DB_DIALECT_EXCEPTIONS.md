# Database dialect exceptions (active runtime)

Current production database: **MySQL**. This file enumerates **active runtime** database-specific SQL that remains after the pre-host ORM/QB cleanup.

Not listed: historical migrations, debug scripts, test-only SQL, portable raw SQL (`1 = 0`, `COUNT`, `CASE WHEN`, `COALESCE`, Laravel `lockForUpdate()`), Import/Export internals owned by another task (except a one-line boundary), Keyword/Topic redesign (except a deferred boundary).

If current source conflicts with this file, source wins — then update this file.

---

## Keyword / Topic identity (deferred domain)

- Repo: seo-ops-addons
- File: `search-foundation/src/Services/KeywordPersistenceService.php` and callers using `COLLATE utf8mb4_unicode_ci`; Topic `LOWER(name) LIKE`; Keyword EAV `JSON_CONTAINS` on tag meta
- Symbol / method: `KeywordPersistenceService::findByPhrase` and Topic query helpers
- Domain: Keyword / Topic
- Current database: MySQL
- SQL/dialect dependency: CI collation (`utf8mb4_unicode_ci`), JSON EAV membership, case-folding `LIKE`
- Why ORM / Query Builder is not sufficient: Matching **is** the product identity. Changing it is a domain redesign.
- Business semantics that must be preserved: Phrase uniqueness, reuse-on-import, tag membership, topic membership
- Current MySQL implementation: Collation / JSON_CONTAINS / LOWER LIKE (untouched in this pass)
- PostgreSQL equivalent / likely future path: Explicit Keyword/Topic semantic phase (not a mechanical SQL swap)
- Risk: HIGH
- Required regression tests: Phrase identity + membership suites in that later phase
- Status: DEFERRED_POSTGRES

---

## Import / Export and legacy SQL dump (deferred concurrent work)

- Repo: seo-ops-client / seo-ops-addons
- File: ClientTransfer, Import Lab, `search-foundation/src/Services/SeoDatabaseBackupService.php`
- Symbol / method: export/import pipelines; `SeoDatabaseBackupService::exportConnection`
- Domain: Transfer / backup
- Current database: MySQL
- SQL/dialect dependency: Import Lab `CREATE DATABASE … CHARACTER SET/COLLATE`; SEO gzip SQL dump (`SET NAMES utf8mb4`, `FOREIGN_KEY_CHECKS`, `SHOW TABLES`)
- Why ORM / Query Builder is not sufficient: Owned by a concurrent All-in-One Import/Export task; dump may be retired after that lands
- Business semantics that must be preserved: Transfer mapping, lab reset, backup restore
- Current MySQL implementation: Unchanged
- PostgreSQL equivalent / likely future path: Logical ClientTransfer (not dump replay)
- Risk: HIGH
- Required regression tests: Owned by Import/Export task
- Status: DEFERRED_POSTGRES

---

## Legacy flat category_ids (FIND_IN_SET)

- Repo: seo-ops-addons (+ helper in seo-ops-client)
- File: `content/src/Filament/Resources/ArticleResource.php`; `content-projects/src/Services/ContentProject/SeoAudit/SeoAuditExistingContentSuggestionService.php`
- Symbol / method: `ArticleResource::articleMetaContainsCategoryWpIdSql`; `SeoAuditExistingContentSuggestionService::applyTaxonomyTermScope`
- Domain: Article list / SEO audit suggestions
- Current database: MySQL
- SQL/dialect dependency: `FIND_IN_SET` over flattened `article_meta.category_ids` (`[1, 2]` or CSV-like)
- Why ORM / Query Builder is not sufficient: Storage is **not** proven JSON on all rows. `LIKE` would false-positive (`12` vs `1`). `whereJsonContains` would miss CSV/invalid JSON.
- Business semantics that must be preserved: Exact id membership, no substring matches, numeric id in flattened list
- Current MySQL implementation: Isolated in `App\Support\Database\LegacyFlatJsonIdListSql::contains()`
- PostgreSQL equivalent / likely future path: Normalize meta to JSONB **or** `string_to_array` after proven format
- Risk: HIGH
- Required regression tests: Category filter + audit suggestion term filter with `[1,12]` vs `1`
- Status: ACTIVE_MYSQL

---

## SEO audit violation JSON filter

- Repo: seo-ops-addons
- File: `seo/src/Services/SeoAuditScanService.php`
- Symbol / method: violation `orWhereHas('articleMetas', …)`
- Domain: SEO Audit
- Current database: MySQL
- SQL/dialect dependency: `JSON_VALID(meta_value) = 1 AND JSON_CONTAINS(meta_value, ?)`
- Why ORM / Query Builder is not sufficient: `whereJsonContains` on MySQL does not skip invalid legacy JSON; `JSON_VALID` guards scan errors
- Business semantics that must be preserved: Rule-key membership in violations array; invalid JSON rows excluded, not fatal
- Current MySQL implementation: Raw `JSON_VALID` + `JSON_CONTAINS` with `json_encode($ruleKey)` (JSON string)
- PostgreSQL equivalent / likely future path: JSONB containment after sanitizing invalid rows; number vs string fixtures
- Risk: HIGH
- Required regression tests: string key in array; invalid JSON row; missing key; empty array
- Status: ACTIVE_MYSQL

---

## Content Project archive access (JSON site_id)

- Repo: seo-ops-addons
- File: `content-projects/src/Services/ContentProject/ContentProjectArchiveAccessScope.php`
- Symbol / method: `whereArchiveHasAccessibleItem`
- Domain: Content Project archive ACL
- Current database: MySQL
- SQL/dialect dependency: `CAST(JSON_UNQUOTE(JSON_EXTRACT(cai.article_snapshot, '$.site_id')) AS UNSIGNED)`
- Why ORM / Query Builder is not sufficient: Access control; JSON path + numeric cast; string vs number `site_id` in snapshots
- Business semantics that must be preserved: No cross-site leak; no missing visibility; null/missing `site_id` same as today
- Current MySQL implementation: Unchanged (not converted this pass)
- PostgreSQL equivalent / likely future path: `(article_snapshot->>'site_id')::int` after fixtures for string/number/null
- Risk: HIGH
- Required regression tests: `ContentProjectArchiveAccessScopeIntegrationTest` + string/number/null `site_id`
- Status: ACTIVE_MYSQL

---

## Content Project legacy archive import_source JSON

- Repo: seo-ops-addons
- File: `content-projects/src/Support/ContentProject/ContentProjectGlobalLegacyArchive.php`
- Symbol / method: import_source predicates
- Domain: Content Project archive
- Current database: MySQL
- SQL/dialect dependency: `JSON_UNQUOTE(JSON_EXTRACT(…, '$.import_source'))`
- Why ORM / Query Builder is not sufficient: Same snapshot JSON semantics as ACL; tests currently assert literal `JSON_EXTRACT`
- Business semantics that must be preserved: Legacy import-source filtering
- Current MySQL implementation: Unchanged
- PostgreSQL equivalent / likely future path: `->>` / JSONB
- Risk: HIGH
- Required regression tests: Existing archive contract tests plus portable assertions when converted
- Status: ACTIVE_MYSQL

---

## Content Project ops metrics atomic increment

- Repo: seo-ops-addons
- File: `content-projects/src/Services/ContentProject/Operations/ContentProjectOpsMetrics.php`
- Symbol / method: `ContentProjectOpsMetrics::atomicIncrement`
- Domain: Content Project
- Current database: MySQL
- SQL/dialect dependency: `INSERT … ON DUPLICATE KEY UPDATE value = value + VALUES(value)`
- Why ORM / Query Builder is not sufficient: Laravel `upsert()` cannot atomically add to an existing counter without a lost-update race
- Business semantics that must be preserved: No lost update / no double increment under concurrency
- Current MySQL implementation: Isolated raw upsert (same SQL as before)
- PostgreSQL equivalent / likely future path: `INSERT … ON CONFLICT … DO UPDATE SET value = seo_content_project_ops_metrics.value + EXCLUDED.value`
- Risk: MEDIUM
- Required regression tests: repeated increment; concurrent increment when a fixture exists
- Status: DEFERRED_POSTGRES

---

## Content Project AI cost JSON aggregates

- Repo: seo-ops-addons
- File: `content-projects/src/Services/ContentProject/Operations/ContentProjectAiCostAggregateService.php`
- Symbol / method: `aggregate`; `modelExpression`
- Domain: Content Project ops
- Current database: MySQL
- SQL/dialect dependency: `JSON_UNQUOTE(JSON_EXTRACT(token_usage, '$.…'))` + `CAST … AS UNSIGNED` / `DECIMAL`
- Why ORM / Query Builder is not sufficient: In-SQL SUM over JSON keys; PHP fold would change scan cost and need full row load
- Business semantics that must be preserved: Daily totals / by_model / by_site token and cost
- Current MySQL implementation: Unchanged raw extracts
- PostgreSQL equivalent / likely future path: `(token_usage->>'prompt_tokens')::int` (or generated columns)
- Risk: MEDIUM
- Required regression tests: missing key, string-vs-number tokens, null `token_usage`
- Status: ACTIVE_MYSQL

---

## Article list CAST keyword_meta.main_article_id (Keyword-adjacent)

- Repo: seo-ops-addons
- File: `content/src/Filament/Resources/ArticleResource.php`
- Symbol / method: keyword usage filter `selectRaw('CAST(meta_value AS UNSIGNED)')`
- Domain: Article list / Keyword EAV
- Current database: MySQL
- SQL/dialect dependency: `CAST(meta_value AS UNSIGNED)`
- Why ORM / Query Builder is not sufficient: Tied to Keyword meta EAV; left untouched with Keyword deferral
- Business semantics that must be preserved: Filter articles by keyword main article id stored as text
- Current MySQL implementation: Unchanged
- PostgreSQL equivalent / likely future path: `NULLIF(meta_value, '')::int` after EAV review
- Risk: MEDIUM
- Required regression tests: Keyword article filter
- Status: DEFERRED_POSTGRES

---

## Cross-site relation audit CAST join

- Repo: seo-ops-addons
- File: `search-foundation/src/Services/Audit/CrossSiteRelationAuditService.php`
- Symbol / method: join `articles` on `CAST(km.meta_value AS UNSIGNED)`
- Domain: Audit / Keyword meta
- Current database: MySQL
- SQL/dialect dependency: `CAST(meta_value AS UNSIGNED)`
- Why ORM / Query Builder is not sufficient: Keyword-meta join; deferred with Keyword EAV
- Business semantics that must be preserved: Audit join correctness
- Current MySQL implementation: Unchanged
- PostgreSQL equivalent / likely future path: typed integer meta or `::int`
- Risk: MEDIUM
- Required regression tests: Audit fixtures
- Status: DEFERRED_POSTGRES

---

## Portable wrappers (not MySQL-only at the call site)

These remain grammar-specific **inside** `App\Support\Database\SqlExpressions` and Laravel JSON grammar:

- `SqlExpressions::yearMonth()` — MySQL `DATE_FORMAT`, SQLite `strftime`, PG `to_char`
- `SqlExpressions::calendarDate()` — `DATE()` vs PG `::date`
- `SqlExpressions::asDateTime()` — `CAST AS DATETIME` vs SQLite `datetime()` vs PG `::timestamp`
- `SeoMediaBuilder` article_id JSON — Laravel `whereJsonContains` (MySQL still emits `JSON_CONTAINS`)

Status: PORTABLE_WRAPPER

---

## Connection config (not SQL)

Logical names `mysql` / `omi_seo_ai` / `omi_seeding` are unchanged. Runtime config is built by `App\Support\Database\DriverAwareConnectionConfig` (default driver MySQL). Not a query dialect exception.
