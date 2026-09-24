# SEO Audit and Keywords

> Status: Canonical  
> Owner: `search-intelligence` (+ `seo` audit/scoring surfaces)  
> Last verified: 2026-09-24  
> Supersedes: `docs/archive/maps/MAP_SEO_AUDIT.md`, `MAP_SEO_PERFORMANCE_HUB.md`, `MAP_SEO_GSC_API_CONNECTIONS.md`, `docs/archive/audit-keywords/*` (architecture only — not phase playbooks)  
> **Breaking (2026-09-17):** Keyword Intelligence workspace + Topics/`cluster_key` + DNA tables retired (`omnichannel-addons` `3dd18c8`).  
> **Topic Core (site-scoped)** later replaced KI Topics — see sibling addons [`TOPIC_CORE.md`](../../../omnichannel-addons/docs/modules/TOPIC_CORE.md).  
> **Keyword MCP:** [`KEYWORD_MCP.md`](../contracts/KEYWORD_MCP.md) — Type 1 Landscape + Type 2 Relationship (**CLOSED — v1**).

## 1. Purpose

SEO technical audit + keyword research stack for one SEO DB connection (`omi_seo_ai`).

**Article Editor immediate analysis** (word count, links, image ratio in edit UI) is **not** owned by ArticlesOptimal — see [`ARTICLE_EDITOR_ANALYSIS_OWNERSHIP.md`](../architecture/ARTICLE_EDITOR_ANALYSIS_OWNERSHIP.md). Audit list still uses persisted `seo_rule_violations` / server `SeoAnalyzerService`.

| Surface | Role |
|---------|------|
| **ArticlesOptimal** | Scan/filter articles failing SEO rules; assign into Content Projects; Reviewed dashboard |
| **Keywords module** | Flat Dictionary + Focus + Anchor Audit (+ hidden AI Discovery) + **Topical Map** + **Relationship** (Type 2 UI). Not KI workspace / `cluster_key` |
| **SERP Intelligence** | Snapshot / intent evidence / content gaps — site-scoped; cluster-validation path retired |
| **GSC Intelligence** | Search Analytics facts, mappings, opportunities — CommandBus ingest; live Google Analytics adapter out of scope for handlers; query×page competition signals (`possible_cannibalization`) are GSC evidence only |
| **Performance Hub** | Legacy GSC snapshot KPI + SERP rank tracker UI; additive GSC Intelligence overlay |

SERP / GSC reuse `ContentProjectCommandBus` + `ActorContext` where applicable. Agent group `keywords` skill keys are **empty** after KI retirement (see [`AGENT_AND_MCP_CONTRACTS.md`](../contracts/AGENT_AND_MCP_CONTRACTS.md)).

## 2. Canonical routes

Panel prefix: `/seo/{connection_hash}/`

| Path | Page |
|------|------|
| `articles/optimal` | `ArticlesOptimal` — SEO Audit + Reviewed tabs |
| `performance-hub` | `SeoPerformanceHub` (nav via Keywords sidebar; Filament nav hidden) — month-scoped GSC + MCP drawer |
| `keywords` | Keyword Dictionary (`ListKeywords`) |
| `keywords/focus` | Focus keywords (`ListFocusKeywords`) |
| `keywords/anchor-audit` | Broken / weak link triage (`AnchorTextAuditWorkspace`) |
| `keywords/topical-map` | Site Topical Map (`KeywordTopicalMap`) — Keyword MCP Type 1 consumer |
| `keywords/relationships/{keyword}` | One-keyword Relationship graph (`KeywordRelationshipView`) — Keyword MCP Type 2 UI (**CLOSED — v1**) |
| `keywords/ai-discovery` | `AiKeywordDiscovery` — **hidden** from Keywords sidebar (`shouldRegisterNavigation = false`) |
| `settings/api/google-search-console/{id}/edit` | GSC master connection edit |
| `seo/oauth/google-search-console/callback` | GSC OAuth callback (global) |

**Keywords nav (SoT):** Dictionary \| Focus \| Anchor Audit (+ Topical Map / Relationship entry points on Keyword Resource) — `KeywordResource::getNavigationItems()` + `HasKeywordWorkspaceNavigation::getKeywordWorkspaceNavItems()`.

Legacy redirects: `keywords/workspace-3` → AI Discovery. **Removed (2026-09-03):** `keywords/cannibalization`. **Removed (2026-09-17):** `keywords/clusters` / KI Topic detail, `keyword-intelligence` / `keyword-intelligence/{workspace_ref}`, KI workspace pages (`ListKeywordWorkspaces`, `ViewKeywordWorkspace`, `KeywordTopicClusters*`, `KeywordWorkspaceTwo/Three`).

Gates: Audit via `ArticleResource::canViewAny()`; Hub / planner+ via `SeoAccessControl::canAccessPlannerFeatures()` (+ `SeoPlannerPermissionMiddleware`).

## 3. Main components

| Concern | Class |
|---------|--------|
| Audit page | `Filament/Pages/ArticlesOptimal` |
| Audit scan SQL | `Services/SeoAuditScanService` |
| Rule match | `Services/SeoAuditRuleMatcher` |
| Keyword review flags | `Services/SeoAuditKeywordFlagService` |
| Scoring SoT | `Support/SeoScoringRulesRegistry` + `SeoScoringEngine` / `SeoScoringCalculator` |
| Score queue | `SeoArticleScoringQueueService` → `AnalyzeArticleSeoJob` |
| Violations read | `Support/SeoRuleViolationsResolver` |
| Hub page | `Filament/Pages/SeoPerformanceHub` |
| Hub service | `Services/SeoPerformanceHubService` / `SeoPerformanceDashboardService` |
| Provider registry | `SeoProviderRegistry` + `SeoProviderCapabilityResolver` |
| Rank groups | `SeoRankKeywordGroupService` + `KeywordRankCheckService` |
| Keyword Dictionary / Focus / Anchor | `KeywordResource` + pages above; flat inventory `search-foundation` `Keyword` |
| Topical Map / Relationship | `KeywordTopicalMap`, `KeywordRelationshipView` — Landscape Type 1 / Relationship Type 2 via gateways (see [`KEYWORD_MCP.md`](../contracts/KEYWORD_MCP.md)) |
| Keywords language filter | Dictionary/Focus language scoping (site primary default) |
| Link triage (anchor-audit) | `AnchorTextAuditWorkspace` — `wp_post_id` via `wordpressLink`, not `articles.wp_post_id` |
| Vocabulary Suggest staging | `VocabularySuggestStagingQuery` — `TYPE_SUGGEST` + `ai_generated` (not Dictionary inventory) |
| KI leftovers (support only) | Tags/normalize/hide/skip-MCP; `KeywordIntelligencePublicRef` prefixes retained but workspace/cluster/topic map APIs unused; no-op scheduler stubs |
| SERP stack | `Services/SerpIntelligence/*` (site-scoped; `workspace_ref` ignored where present) |
| GSC stack | `Services/GscIntelligence/*` |
| GSC monthly Hub | `GscMonthlyPeriod` + `GscMonthlyDashboardService` — Hub month picker; `syncGscData()` = **selected month**; MCP drawer via `MonthlyMcpSnapshotService` (`McpSourceKey::Gsc`) |
| GSC URL Inspection | `GscIntelligence/UrlInspection/*` — feeds **Article Index Health**, not the monthly GSC dashboard |
| GSC sync stages | `GscSyncOperationService` + `GscSyncLockService` |
| GSC OAuth (core) | `GoogleSearchConsoleOAuthService` |
| Legacy GSC snapshot | `GoogleSearchConsoleSyncService` → SiteMeta `gsc_query_snapshot` |
| Panel nav (WP-style modules) | `Seo\Support\SeoUserNavigation` + `SeoPanelRoutes` |
| Domain context bar | `seo/resources/js/domainContextStore.js` + `domain-context.js` (GET `site_id` SoT); Planner month selector on Global SEO bar when on SEO Audit Planner |
| List loading shell | compat `list-table-loading-shell` + `seo/.../panelLoading.js` |
| SEO Workspace dashboard | `search-foundation` `Dashboard` — charts / sync widgets; live “top Topics” overview retired with KI |

**Retired (2026-09-17) — do not document as live:** `KeywordWorkspaceAnalysisService`, Topics/`KeywordClusterQuery`, `CanonicalCluster*`, `KeywordDna*`, `ReclusterTopicClusters*`, `ReconcileTopicMembershipJob`, `ReconcileFocusArticleTopicsService`, `TopicalMapBuilder`, `KeywordToContentProjectConverter`, KI Filament workspace pages, `seo:topics:reconcile-focus`.

## 4. Data ownership

**DB:** article/score/SERP/GSC facts on `omi_seo_ai`. GSC OAuth + property mappings on core `mysql`. Flat keyword inventory: `search-foundation.keywords`.

| State | Source of truth | Not SoT |
|-------|-----------------|---------|
| Review / Reviewed tab | `articles.review_status` (+ `reviewed_at`) via `ArticleReviewService` | Dropped `articles.is_reviewed` |
| Audit eligibility | Scope: countsTowardSeoScore, not trash, not category types, not in CP task, not `skip_seo_audit` meta, not approved review | Live HTML analyze in request |
| Rule violations | `article_meta.seo_rule_violations` (+ denormalized `articles.seo_score`) | Client-only score without persist job |
| Skip audit | `article_meta.skip_seo_audit=1` | WP demote / trash |
| **Keyword Dictionary** | Flat `keywords` inventory (phrase + type + site meta) | Legacy `keywords.parent_id` hierarchy (dropped 2026-08-27); **not** a grouping tree |
| **KI Topics / `cluster_key` / KI DNA** | **Retired** — KI tables dropped 2026-09-17 (see § Retirement) | Live KI cluster SSOT |
| **Topic Core** (site-scoped) | `seo_topics` / `seo_topic_keywords` / `seo_topic_keyword_dna` — see addons `TOPIC_CORE.md`; backs Keyword MCP Type 1+2 | KI `cluster_key` / workspace Topics |
| Planning DNA (CP only) | Audit Notes note snapshots (`AuditNoteDnaNormalizer` + `DnaPlacement` before\|after) | Live KW DNA tables (KI era) |
| GSC credentials | `seo_gsc_master_connections` (mysql) | Duplicating OAuth into `omi_seo_ai` |
| GSC facts | `seo_gsc_daily_metrics` etc. (`omi_seo_ai`) | Legacy SiteMeta snapshot |
| Hub legacy KPI | SiteMeta `gsc_query_snapshot` | GSC Intelligence tables (separate stack) |
| SERP snapshots | Immutable SERP snapshot models | Mutating approved topical maps (retired) |

### Retirement — dropped tables (2026-09-17)

Migration: `search-intelligence/.../2026_09_17_100000_drop_legacy_keyword_workspace_and_topic_derived_tables.php` on `omi_seo_ai` (`down()` empty). Older create/enrich migrations kept as **no-ops** via `MIGRATION_BASENAME_COMPAT.json`.

Includes: `seo_keyword_workspaces`, KI `seo_keywords` / clusters / topics / topical map versions / analysis ops / relationships / article mappings / project conversion links, `seo_serp_cluster_evidence`, MCP topic groups/members, `seo_keyword_dna`, `seo_topic_cluster_*`, `seo_keyword_classifications`. Soft FKs dropped from SERP/GSC mapping rows (`workspace_id` / `cluster_id` / `topic_id` where applicable). Ownership map: `DB_OWNERSHIP_MAP.json` — `search-intelligence` no longer owns KI workspaces.

Commit intent: wipe **KI** derived state. **Topic Core** (separate tables) later became the live site-scoped Topic SSOT for Keyword Landscape / Relationship — see addons `TOPIC_CORE.md` and [`KEYWORD_MCP.md`](../contracts/KEYWORD_MCP.md). Site MCP draft `resolveTopicalProfile` remains a separate Knowledge Profile concern (not Keyword Landscape).

## 5. Read path

### SEO Audit

1. Alpine tab `audit` | `reviewed` — client toggle only.
2. Default audit load: keyword review warning/danger via `SeoAuditKeywordFlagService` (`audit_sources=keyword_review`).
3. After Quét with scoring rules: `SeoAuditScanService::paginateResults()` — SQL on cached violations/score only (`audit_sources=seo_rules`). **No** UNION keyword_review when rules selected.
4. `missing_focus_keyword` ≠ keyword_review (canonical keyword present but warning is separate).
5. Reviewed: group by `reviewed_at` date; Alpine filters client-side.

### Keyword / SERP / GSC

- Filament / Agent / MCP reads → site-scoped services + public refs where still advertised.
- Keywords module tabs: **Dictionary \| Focus \| Anchor Audit**. Topical Map + Relationship are Keyword Resource pages (not KI workspace). AI Discovery remains a route but is not in the sidebar.
- **Keyword MCP Type 2 (CLOSED — v1):** Relationship UI uses `KeywordRelationshipGateway` only; schema `keyword.relationship.v1`; on-demand; **does not** persist `seo_mcp_source_snapshots`. Vite entry `addons/search-intelligence/resources/js/keyword-relationship-chart.js`. Deferred debt: `bugs/keyword-mcp-type-2-deferred.md`.
- **Keyword MCP Type 1:** Landscape via `KeywordLandscapeGateway` — approved consumers only (SEO Audit, Prompt Generator, Keywords / Topical Map). Do not broaden.
- **Vocabulary Suggest staging:** `VocabularySuggestStagingQuery` — Planner Idea Candidates consume this staging only (see [`CONTENT_PROJECTS.md`](CONTENT_PROJECTS.md) § Idea Candidates). **GSC MCP / Social Top 10 do not feed Idea Suggest.**
- Nav WP-style: `SeoUserNavigation` + `SeoPanelRoutes` (module top-level groups; active helpers avoid path wildcards). Stale helper `isKeywordsClustersNav()` may still check retired paths — live helpers: `isKeywordsModule`, `isKeywordsDictionaryNav`, `isKeywordsFocusNav`, `isKeywordsBrokenLinksNav`.
- Domain context: Global SEO bar / Keywords must follow GET `site_id` via `domainContextStore`.
- Performance Hub: `#[Computed]` state per active `source` (`gsc` vs SERP providers). GSC uses **month-scoped** view/sync; MCP snapshot rebuild is Hub drawer, separate from URL Inspection / Article Index Health.
- Hub **Social Top 10:** `GscSocialTop10Builder` — deterministic share candidates from GSC MCP; links to [`SITE_MCP_AND_DOMAINS.md`](SITE_MCP_AND_DOMAINS.md) Social Profiles. No AI.
- GSC Intelligence overlay additive — Overview/Queries/Pages/Opportunities may be placeholders; Sync CSV preview wired.
- List loading: Keywords use Article-style `list-table-loading-shell` + `panelLoading.js`.

## 6. Write path

### Audit

| Action | Path |
|--------|------|
| Skip audit | Set `skip_seo_audit` — no WP sync, no status change |
| Assign CP | Shared right-side drawer (`assign-content-project:open`) — **not** a per-page modal. Audit rows: `x-content::assign-to-content-project-trigger` (`source=seo_audit`). Keywords: `AssignToContentProjectActionFactory` (`keyword_table`) + Keyword detail `window` event (`keyword_detail`). Drawer submit → `ArticleResource::assignArticlesFromFormData` / `KeywordResource::executeAssignKeywordsToContentProjects`. See [`CONTENT_PROJECTS.md`](CONTENT_PROJECTS.md) § Assign UI. |
| Populate score cache | Editor save / WP import / domain queue → `AnalyzeArticleSeoJob` |

### Keywords (post-retirement)

Dictionary / Focus mutations stay on Keyword Resource + shared assign drawer. **No** CommandBus KI import → analyze → cluster → topical map → CP convert pipeline. Hide / skip-MCP / assign-to-CP remain.

**Planning DNA:** lives only on Content Project Audit Notes (`AuditNoteDnaNormalizer` + manual seeds). `AuditNoteClusterSuggestionQuery` returns **empty** (no live cluster hydrate). See [`CONTENT_PROJECTS.md`](CONTENT_PROJECTS.md).

### SERP / GSC

- SERP collect/import via `serp_intelligence.*` — do **not** auto-approve topical maps (pipeline retired). Skill `serp.validate_cluster` may still appear in catalog but cluster-evidence handlers were deleted — treat as orphan until cleaned.
- GSC import/sync/detect via `gsc_intelligence.*` — handlers **must not** import `Google\Client` / live Search Analytics SDK.
- Sync stages: `preparing` → `fetching` → `normalizing` → `persisting` → `mapping` → `aggregating` → `detecting` → `finalizing` → terminal.
- Lock: `gsc-sync:{property_ref}` (TTL from config).
- Daily facts upsert by `data_hash` (replace, not accumulate).

## 7. Public capabilities

| Family | Status |
|--------|--------|
| `keyword_intelligence.*` | **Retired** from Agent skill catalog + MCP `ContentProjectMcpToolCatalog` + CapabilityRegistry write/read caps |
| `keyword.relationship` | **CLOSED — v1** — Keyword MCP Type 2; schema `keyword.relationship.v1`; on-demand; no snapshot writes. SoT: [`KEYWORD_MCP.md`](../contracts/KEYWORD_MCP.md) |
| Keyword Landscape Type 1 | `KeywordLandscapeGateway` / `keywords.mcp.v2` — **not** a generic MCP API; consumers: SEO Audit, Prompt Generator, Keywords / Topical Map only |
| `serp_intelligence.*` | Collect/import/list gaps (site-scoped); validate_cluster orphaned |
| `gsc_intelligence.*` | List/get properties, sync runs, mappings, aggregates, opportunities; write import/sync/detect/cancel (MCP catalog: **reads**; app CommandBus writes) |

Agent policy for remaining SERP/GSC: `list_*`/`get_*` → `content-project:read`; other writes → `content-project:write`.

## 8. Internal-only capabilities

- `AnalyzeArticleSeoJob` / scoring queue internals
- `GscSyncOperationService` stage machine internals
- SERP page-fetch SSRF guards (`validateUrlForFetch`)
- Provider resolvers fail-closed config
- Legacy `GoogleSearchConsoleSyncService` snapshot path (Performance Hub)
- Rank check batch jobs on queue `seo`

## 9. Authorization and confirmation

- Tenant / article accessible query / site scope via `SeoAccessControl`.
- Admin viewing foreign connection: SEO panel read-only.
- KI convert confirmation / topical destructive rules — **N/A** after retirement.

## 10. Queue and scheduler ownership

| Job / queue | Owner |
|-------------|--------|
| `AnalyzeArticleSeoJob` | SEO scoring cache |
| `RunKeywordRankCheckBatchJob` / metric batch | Performance Hub rank tracker (`seo` queue) |
| Domain incremental/metadata/keyword resync | Domain module (feeds audit cache via sync) |
| GSC live API cron | Legacy snapshot service only — not GSC Intelligence handlers |

Worker must listen `seo` for rank jobs. No Queue Manager UI. Retired: `ReclusterTopicClustersJob`, `ReconcileTopicMembershipJob`, KI analysis ops.

## 11. Transactions and side effects

- Audit skip: meta only.
- Assign audit → CP: shared drawer (`seo_audit`); may prompt missing focus keyword; capacity toast when remaining ≤2. Do **not** reintroduce ArticlesOptimal sidebar/modal assign forms.
- GSC persist: dual-write in-memory + Eloquent when `property_id`/`site_id` present; skip mapping overwrite when `metadata.manual`.
- Manual keyword intent wins over SERP reconciler where still applicable.

## 12. Retry and recovery

- Scoring: domain “queue missing / retry failed” → requeue `AnalyzeArticleSeoJob`.
- GSC: `CancelGscSyncCommand` false after terminal; `RepairGscDateRangeCommand`; partial when valid rows + `invalid_count > 0`.
- Rank: `KeywordRankCheckService::reconcileStaleRuns()` before dispatch / mount.
- Retired: topical build lock / workspace analysis lock.

## 13. Compatibility paths

- Legacy Hub GSC KPI via SiteMeta snapshot alongside GSC Intelligence tables.
- `SeoEngineService` (core) wrapper for old audit/API callers.
- Violation resolver: new flat `seo_rule_violations` with legacy `seo_rank_math_score` / `seo_scoring_details` fallbacks.
- Some Hub sync paths may still resolve “first” GSC connection — prefer explicit mapping by `site_id`.
- Archive Keyword Workspace concept retired with KI tables.

## 14. Forbidden paths

1. Analyze HTML inside Audit HTTP request (`scanWithHtmlAnalysis` retired).
2. Reintroduce `articles.is_reviewed` as SoT.
3. Demote/trash WP posts to “fix” audit — use `skip_seo_audit`.
4. Mutate SERP/GSC from Filament without CommandBus.
5. Put GSC OAuth secrets on `omi_seo_ai`.
6. Live Google Search Analytics SDK inside GSC Intelligence handlers.
7. Leak numeric IDs on Agent/MCP keyword-adjacent surfaces that still exist.
8. Parallel Assign-to-Content-Project UI on Audit / Keywords (left drawer, Filament modal, `mountAction` from keyword detail). Reuse Contract + drawer.
9. Reintroduce Keyword Rule Groups (`seo_keyword_rule_groups*`) or `keywords.parent_id` hierarchy.
10. Reintroduce KI workspace / Topics/`cluster_key` / DNA tables / topical map convert without a dedicated rebuild task + docs pass.
11. Treat Performance Hub snapshot as GSC Intelligence SoT (or reverse).
12. Assume Audit Notes can hydrate live cluster DNA — suggestions are empty until Topic rebuild ships.

## 15. Tests and invariants

| Test / area | Invariant |
|-------------|-----------|
| `SeoAuditScanServiceTest` / missing-focus filters | Cache SQL filters; canonical focus keyword scope |
| `SeoAuditScoringIntegrationTest` | Registry ↔ audit filters |
| `SeoAuditMissingFocusKeywordAuditFilterTest` | missing_focus ≠ keyword_review UNION |
| `Gsc*Test` | Facts, sync, mapping, provider fail-closed |
| `Serp*` unit tests | Snapshot immutability, fetch security (cluster overlap suite largely deleted) |
| `AssignToContentProjectUiArchitectureGuardTest` | Audit + Keyword resources open canonical drawer; no Action `form()` |
| `KeywordListLoadingUxTest` / `DomainContextLoadingUxTest` | Loading shell + domain GET `site_id` |
| `SeoAuditGlobalDomainF5ContractTest` | Global domain F5 / planner domain context |
| `GlobalSeoBarPlannerActiveMonthVisibilityTest` | Planner month selector on Global SEO bar |

**Deleted with retirement (do not expect):** `CanonicalClusterAndDna*`, `DnaPlacementContractTest` (KW SSOT), `FullDomainReclusterRepairTest`, `FocusArticleTopicInvariantTest`, `DissolveTopicClusterUiTest`, many KI workspace / topical map suites, `SeoWorkspaceDashboardContractTest` cluster overview variants.

## 16. Related documents

- [KEYWORD_MCP.md](../contracts/KEYWORD_MCP.md) — Keyword MCP Type 1 + Type 2 (**CLOSED — v1**)
- [bugs/keyword-mcp-type-2-deferred.md](../../bugs/keyword-mcp-type-2-deferred.md) — Type 2 deferred debt (not v1 blockers)
- Sibling addons [TOPIC_CORE.md](../../../omnichannel-addons/docs/modules/TOPIC_CORE.md) — site-scoped Topic membership / DNA
- [ARTICLE_EDITOR.md](ARTICLE_EDITOR.md) — scoring client + save triggers score job
- [CONTENT_PROJECTS.md](CONTENT_PROJECTS.md) — assign / Audit Notes DNA / Site Planning
- [CONTENT_PROJECT_ASSIGN_UI_2026_08.md](../architecture/CONTENT_PROJECT_ASSIGN_UI_2026_08.md) — 2026-08 assign consolidation
- [SITE_MCP_AND_DOMAINS.md](SITE_MCP_AND_DOMAINS.md) — domain sync feeding articles; Site MCP draft topical profile ≠ Keyword Landscape
- [AGENT_AND_MCP_CONTRACTS.md](../contracts/AGENT_AND_MCP_CONTRACTS.md)
- [SEEDING.md](SEEDING.md) — Seeding Topic / Link Intelligence (separate from Keywords)
- Archive detail: `docs/archive/audit-keywords/*`, `docs/archive/maps/MAP_SEO_AUDIT.md`

### Quick ref — retired Keyword Cannibalization (2026-09-03)

seo-ops Keywords Cannibalization UI + KI `c1`/`c2` issue pipeline **removed**. Multi-article same/near keyword is accepted; quality via SEO score / Focus Keyword / Focus Article / internal links / GSC diagnostics. GSC `possible_cannibalization` (query×page competition) remains as planning evidence only — not a Keywords nav module.

### Quick ref — retired KI workspace (2026-09-17) vs Topic Core

KI workspace, Topics/`cluster_key`, KI DNA tables, Agent/MCP `keyword_intelligence.*`, topical convert → CP: **gone**.  
**Topic Core** (site-scoped `seo_topics` / membership / DNA) is the live replacement for landscape + relationship consumers — see addons `TOPIC_CORE.md` and [`KEYWORD_MCP.md`](../contracts/KEYWORD_MCP.md).

### Quick ref — Keyword MCP Type 2 (CLOSED — v1)

Capability `keyword.relationship` / schema `keyword.relationship.v1`. On-demand; no snapshot writes; Focus-Article internal-link neighborhood; locks = `source_locked` + `membership_locked` (not conflated `locked`). MySQL disposable proof tests exist but were **SKIPPED** (env not configured) — do not claim DB proof. Deferred items: `bugs/keyword-mcp-type-2-deferred.md`.

### Quick ref — audit low score

`SeoScoringRulesRegistry::AUDIT_LOW_SCORE_THRESHOLD` = **60**.
