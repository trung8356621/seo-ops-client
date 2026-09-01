# SEO Audit and Keywords

> Status: Canonical  
> Owner: SeoContentAi  
> Last verified: 2026-09-01  
> Supersedes: `docs/archive/maps/MAP_SEO_AUDIT.md`, `MAP_SEO_PERFORMANCE_HUB.md`, `MAP_SEO_GSC_API_CONNECTIONS.md`, `docs/archive/audit-keywords/*` (architecture only — not phase playbooks)

## 1. Purpose

SEO technical audit + keyword research stack for one SEO DB connection (`omi_seo_ai`).

**Article Editor immediate analysis** (word count, links, image ratio in edit UI) is **not** owned by ArticlesOptimal — see [`ARTICLE_EDITOR_ANALYSIS_OWNERSHIP.md`](../architecture/ARTICLE_EDITOR_ANALYSIS_OWNERSHIP.md). Audit list still uses persisted `seo_rule_violations` / server `SeoAnalyzerService`.

| Surface | Role |
|---------|------|
| **ArticlesOptimal** | Scan/filter articles failing SEO rules; assign into Content Projects; Reviewed dashboard |
| **Keyword Intelligence (KI)** | Import → normalize → intent → score → map → cluster → topical map → cannibalization → CP convert |
| **SERP Intelligence** | Snapshot / intent evidence / cluster overlap / content gaps — advisory to KI |
| **GSC Intelligence** | Search Analytics facts, mappings, opportunities — CommandBus ingest; live Google Analytics adapter out of scope for handlers |
| **Performance Hub** | Legacy GSC snapshot KPI + SERP rank tracker UI; additive GSC Intelligence overlay |

KI / SERP / GSC are **not** Content Project aggregates. They reuse `ContentProjectCommandBus` + `ActorContext` but own `KeywordIntelligencePublicRef` prefixes.

## 2. Canonical routes

Panel prefix: `/seo/{connection_hash}/`

| Path | Page |
|------|------|
| `articles/optimal` | `ArticlesOptimal` — SEO Audit + Reviewed tabs |
| `performance-hub` | `SeoPerformanceHub` (nav via Keywords sidebar; Filament nav hidden) — month-scoped GSC + MCP drawer |
| `keywords` | Keyword Dictionary (`ListKeywords`) |
| `keywords/clusters` | **Topics** UI (`KeywordTopicClusters` / detail) — product label Topics; code still `cluster_*` |
| `keywords/focus` | Focus keywords surface |
| `keywords/ai-discovery` | `AiKeywordDiscovery` |
| `keywords/cannibalization` | `KeywordCannibalizationWorkspace` |
| `keyword-intelligence` | `ListKeywordWorkspaces` |
| `keyword-intelligence/{workspace_ref}` | `ViewKeywordWorkspace` (Overview / Keywords / Clusters / Topical / Cannibalization / SERP / …) |
| `settings/api/google-search-console/{id}/edit` | GSC master connection edit |
| `seo/oauth/google-search-console/callback` | GSC OAuth callback (global) |

Legacy redirects: `keywords/workspace-3` → AI Discovery; `keywords/workspace-4` → cannibalization.

Gates: Audit via `ArticleResource::canViewAny()`; Hub / KI Planner+ via `SeoAccessControl::canAccessPlannerFeatures()` (+ `SeoPlannerPermissionMiddleware`); KI manager tabs via `canAccessManagerFeatures()`.

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
| KI pipeline | `Services/KeywordIntelligence/*` |
| KI analysis ops | `KeywordWorkspaceAnalysisService` |
| **Topics (UI label; code: Topic Cluster)** | `KeywordResource` Topic pages + `KeywordClusterQuery` — user-facing **Chủ đề / Topics** |
| Canonical cluster phrase | `Canonical/CanonicalClusterPhraseResolver` + `CanonicalClusterResolverService` + merge helpers |
| Keyword DNA | `KeywordDnaExtractor` / `KeywordDnaService` / `KeywordDnaDiagnosticsService` — tables `seo_keyword_dna`, cluster meta/alias |
| Full-domain recluster | `ReclusterTopicClustersService` + `Jobs/ReclusterTopicClustersJob` |
| Focus Article → Topic invariant | `ReconcileFocusArticleTopicsService` + `seo:topics:reconcile-focus` |
| Keywords language filter | `InteractsWithKeywordWorkspaceLanguageFilter` + `KeywordWorkspaceLanguageScope` |
| Topical map | `TopicalMapBuilder` + `KeywordTopicalMapMutationService` |
| KI → CP | `KeywordToContentProjectConverter` / topical converter |
| Cannibalization (KI) | `KeywordCannibalizationService` |
| SERP stack | `Services/SerpIntelligence/*` |
| GSC stack | `Services/GscIntelligence/*` |
| GSC monthly Hub | `GscMonthlyPeriod` + `GscMonthlyDashboardService` — Hub month picker; `syncGscData()` = **selected month**; MCP drawer via `MonthlyMcpSnapshotService` (`McpSourceKey::Gsc`) |
| GSC URL Inspection | `GscIntelligence/UrlInspection/*` — feeds **Article Index Health**, not the monthly GSC dashboard |
| GSC sync stages | `GscSyncOperationService` + `GscSyncLockService` |
| GSC OAuth (core) | `GoogleSearchConsoleOAuthService` |
| Legacy GSC snapshot | `GoogleSearchConsoleSyncService` → SiteMeta `gsc_query_snapshot` |
| Panel nav (WP-style modules) | `Seo\Support\SeoUserNavigation` + `SeoPanelRoutes` |
| Domain context bar | `seo/resources/js/domainContextStore.js` + `domain-context.js` (GET `site_id` SoT) |
| List loading shell | compat `list-table-loading-shell` + `seo/.../panelLoading.js` |
| Public refs | `KeywordIntelligencePublicRef` |
| Quotas / tenant | `KeywordIntelligenceQuotaGuard` / `KeywordIntelligenceTenantGuard` |
| Dashboard keyword overview | `DashboardKeywordOverviewService` + `KeywordOverviewWidget` — top Topics + keywords by internal links (single-domain dashboard) |
| SEO Workspace dashboard layout | `search-foundation` `Dashboard` — single-domain: `KeywordOverviewWidget` + `SeoScoreChart` + `WpSyncStatusTable`; all-domains: month workload charts + `AllDomainsListWidget` |

## 4. Data ownership

**DB:** article/score/KI/SERP/GSC facts on `omi_seo_ai`. GSC OAuth + property mappings on core `mysql`.

| State | Source of truth | Not SoT |
|-------|-----------------|---------|
| Review / Reviewed tab | `articles.review_status` (+ `reviewed_at`) via `ArticleReviewService` | Dropped `articles.is_reviewed` |
| Audit eligibility | Scope: countsTowardSeoScore, not trash, not category types, not in CP task, not `skip_seo_audit` meta, not approved review | Live HTML analyze in request |
| Rule violations | `article_meta.seo_rule_violations` (+ denormalized `articles.seo_score`) | Client-only score without persist job |
| Skip audit | `article_meta.skip_seo_audit=1` | WP demote / trash |
| KI entities | `seo_keyword_*` / `seo_topics` / topical versions | Content Project archive |
| Manual KI fields | `field_sources.*.source = manual` | Analysis overwrite |
| GSC credentials | `seo_gsc_master_connections` (mysql) | Duplicating OAuth into `omi_seo_ai` |
| GSC facts | `seo_gsc_daily_metrics` etc. (`omi_seo_ai`) | Legacy SiteMeta snapshot |
| Hub legacy KPI | SiteMeta `gsc_query_snapshot` | GSC Intelligence tables (separate stack) |
| SERP snapshots | Immutable SERP snapshot models | Mutating approved topical maps |
| **Keyword Dictionary** | Flat `keywords` inventory (phrase + type + site meta) | Legacy `keywords.parent_id` hierarchy (dropped 2026-08-27); **not** a grouping tree |
| **Keyword grouping / Topics** | Cluster (`cluster_key` / Topics index UI) + DNA residuals | Parent/child keyword tree; retired `KeywordRuleGroup*` tables (dropped 2026-08) |
| **Keyword DNA** | `seo_keyword_dna` (+ cluster canonical phrase) | Raw token diff / glue / cluster echo |
| **Focus Article Topic invariant** | Every SEO-eligible keyword with ≥1 Focus Article **must** have a Topic (`ReconcileFocusArticleTopicsService`) | Leaving Focus keywords in Unassigned |

Public ref prefixes (opaque only — numeric IDs rejected): `kww_`, `kw_`, `kwc_`, `kwt_`, `tmv_`, `kwa_`, `kwrel_`, `kwam_`, `kwtcl_`, `kci_*`, SERP `srpq_`/`srps_`/…, GSC `gscp_`/`gscs_`/….

## 5. Read path

### SEO Audit

1. Alpine tab `audit` | `reviewed` — client toggle only.
2. Default audit load: keyword review warning/danger via `SeoAuditKeywordFlagService` (`audit_sources=keyword_review`).
3. After Quét with scoring rules: `SeoAuditScanService::paginateResults()` — SQL on cached violations/score only (`audit_sources=seo_rules`). **No** UNION keyword_review when rules selected.
4. `missing_focus_keyword` ≠ keyword_review (canonical keyword present but warning is separate).
5. Reviewed: group by `reviewed_at` date; Alpine filters client-side.

### Keyword / SERP / GSC

- Filament / Agent / MCP reads → `*ReadService` with `site_id` + public refs.
- Keywords module tabs: Dictionary \| Topics \| Focus \| … — **Language** filter (`InteractsWithKeywordWorkspaceLanguageFilter`) on the right of the tab bar; default = site primary language; scope via `KeywordWorkspaceLanguageScope` (Focus article or link-map source language variants).
- Topics UI = renamed Topic Cluster surface (`topic_cluster_*` / `workspace_nav_two` → **Chủ đề / Topics**). Route still under clusters; clustering logic still uses `cluster_key`. Tables: `seo_topic_cluster_meta`, `seo_topic_cluster_aliases`, `seo_keyword_dna`.
- **Vocabulary Suggest staging:** `VocabularySuggestStagingQuery` — `TYPE_SUGGEST` + `ai_generated`; **not** Dictionary active inventory. Planner Idea Candidates consume this staging only (see [`CONTENT_PROJECTS.md`](CONTENT_PROJECTS.md) § Idea Candidates). **GSC MCP / Social Top 10 do not feed Idea Suggest.**
- Nav WP-style: `SeoUserNavigation` + `SeoPanelRoutes` (module top-level groups; active helpers avoid path wildcards).
- Domain context: Global SEO bar / Keywords must follow GET `site_id` via `domainContextStore` — do not keep a stale “global domain” that ignores the URL after soft navigation.
- Performance Hub: `#[Computed]` state per active `source` (`gsc` vs SERP providers). GSC uses **month-scoped** view/sync (`GscMonthlyPeriod` + `GscMonthlyDashboardService` on `SeoPerformanceHub`); sync targets the **selected month**; MCP snapshot rebuild is Hub drawer (`MonthlyMcpSnapshotService`), separate from URL Inspection / Article Index Health.
- Hub **Social Top 10:** `GscSocialTop10Builder` — deterministic share candidates from GSC MCP; links to [`SITE_MCP_AND_DOMAINS.md`](SITE_MCP_AND_DOMAINS.md) Social Profiles. No AI.
- GSC Intelligence overlay additive — Overview/Queries/Pages/Opportunities may be placeholders; Sync CSV preview wired.
- List loading: Keywords / Topics use the same Article-style `list-table-loading-shell` + `panelLoading.js` as Content Projects (domain switch → panel bar; table filter/pagination → shell overlay).

## 6. Write path

### Audit

| Action | Path |
|--------|------|
| Skip audit | Set `skip_seo_audit` — no WP sync, no status change |
| Assign CP | Shared right-side drawer (`assign-content-project:open`) — **not** a per-page modal. Audit rows: `x-content::assign-to-content-project-trigger` (`source=seo_audit`). Keywords: `AssignToContentProjectActionFactory` (`keyword_table`) + Keyword detail `window` event (`keyword_detail`). Drawer submit → `ArticleResource::assignArticlesFromFormData` / `KeywordResource::executeAssignKeywordsToContentProjects`. See [`CONTENT_PROJECTS.md`](CONTENT_PROJECTS.md) § Assign UI. |
| Populate score cache | Editor save / WP import / domain queue → `AnalyzeArticleSeoJob` |

### Keyword Intelligence (CommandBus)

Import → Analyze (locked operation) → Approve keywords/clusters → Build topical map (draft) → Approve map → Preview/Create CP.

Cluster / Topics mutations (merge/split/move/dissolve), full-domain **Recluster**, and cannibalization review are CommandBus/job-only — not Filament ad-hoc SQL.

**Focus Article reconcile:** `ReconcileFocusArticleTopicsService` ensures Focus keywords are never left Unassigned (attach existing Topic, shared Focus Topic, or singleton Topic — Focus overrides min-size-2). Runs inside recluster; Artisan: `seo:topics:reconcile-focus --site=N` (default reconcile-only; `--recluster` = full rebuild).

**DNA:** `KeywordDnaExtractor` derives residual modifiers from keyword vs cluster canonical; persist via `KeywordDnaService` → `seo_keyword_dna`. Topic title SSOT = `seo_topic_cluster_meta`. Audit Notes may override DNA for planning prompts only — see [`CONTENT_PROJECTS.md`](CONTENT_PROJECTS.md) § SEO Audit Notes.

### SERP / GSC

- SERP collect/import/validate via `serp_intelligence.*` — suggestions do not auto-approve topical maps.
- GSC import/sync/detect via `gsc_intelligence.*` — handlers **must not** import `Google\Client` / live Search Analytics SDK.
- Sync stages: `preparing` → `fetching` → `normalizing` → `persisting` → `mapping` → `aggregating` → `detecting` → `finalizing` → terminal.
- Lock: `gsc-sync:{property_ref}` (TTL from config).
- Daily facts upsert by `data_hash` (replace, not accumulate).

### Topical map

Builder does **not** re-analyze keywords. Draft only until `ApproveTopicalMap`. Conversion from approved version. No live sync map↔project after convert. CP archive does not delete topical planning data.

## 7. Public capabilities

Prefix families on CapabilityRegistry / Agent / MCP:

| Family | Examples |
|--------|----------|
| `keyword_intelligence.*` | list/get workspaces, keywords, clusters, topical map, cannibalization, analysis op; write import/analyze/approve/build/convert/archive |
| `serp_intelligence.*` | collect/import/validate/list gaps |
| `gsc_intelligence.*` | list/get properties, sync runs, mappings, aggregates, opportunities; write import/sync/detect/cancel (MCP catalog: **reads**; app CommandBus writes) |

Agent policy: `list_*`/`get_*` → `content-project:read`; other writes → `content-project:write`.

## 8. Internal-only capabilities

- `AnalyzeArticleSeoJob` / scoring queue internals
- `GscSyncOperationService` stage machine internals
- SERP page-fetch SSRF guards (`validateUrlForFetch`)
- Provider resolvers fail-closed config
- Legacy `GoogleSearchConsoleSyncService` snapshot path (Performance Hub)
- Rank check batch jobs on queue `seo`

## 9. Authorization and confirmation

- Tenant: `KeywordIntelligenceTenantGuard` / article accessible query / site scope via `SeoAccessControl`.
- Convert cluster/map → CP: confirmation token when over `convert_confirmation_threshold` (agent/api always when threshold exceeded).
- Destructive topical mutations require approved/draft rules in handlers.
- Admin viewing foreign connection: SEO panel read-only.

## 10. Queue and scheduler ownership

| Job / queue | Owner |
|-------------|--------|
| `AnalyzeArticleSeoJob` | SEO scoring cache |
| `RunKeywordRankCheckBatchJob` / metric batch | Performance Hub rank tracker (`seo` queue) |
| Domain incremental/metadata/keyword resync | Domain module (feeds audit cache via sync) |
| GSC live API cron | Legacy snapshot service only — not GSC Intelligence handlers |

Worker must listen `seo` for rank jobs. No Queue Manager UI.

## 11. Transactions and side effects

- Audit skip: meta only.
- Assign audit → CP: shared drawer (`seo_audit`); may prompt missing focus keyword; `ignore_monthly_capacity` + capacity toast when remaining ≤2. Do **not** reintroduce ArticlesOptimal sidebar/modal assign forms.
- KI analyze: fingerprint-stable cannibalization issues (`open`→`stale` when unseen).
- GSC persist: dual-write in-memory + Eloquent when `property_id`/`site_id` present; skip mapping overwrite when `metadata.manual`.
- Manual keyword intent wins over SERP reconciler.
- Approve topical map → immutable version snapshot.

## 12. Retry and recovery

- Scoring: domain “queue missing / retry failed” → requeue `AnalyzeArticleSeoJob`.
- GSC: `CancelGscSyncCommand` false after terminal; `RepairGscDateRangeCommand`; partial when valid rows + `invalid_count > 0`.
- Topical build lock: `keyword-topical-map-build:{workspace_ref}`.
- Analysis lock on workspace operation row.
- Rank: `KeywordRankCheckService::reconcileStaleRuns()` before dispatch / mount.

## 13. Compatibility paths

- Legacy Hub GSC KPI via SiteMeta snapshot alongside GSC Intelligence tables.
- `SeoEngineService` (core) wrapper for old audit/API callers.
- Violation resolver: new flat `seo_rule_violations` with legacy `seo_rank_math_score` / `seo_scoring_details` fallbacks.
- Keyword workspace archive ≠ Content Project “Destroy Workspace”.
- Some Hub sync paths may still resolve “first” GSC connection — prefer explicit mapping by `site_id`.

## 14. Forbidden paths

1. Analyze HTML inside Audit HTTP request (`scanWithHtmlAnalysis` retired).
2. Reintroduce `articles.is_reviewed` as SoT.
3. Demote/trash WP posts to “fix” audit — use `skip_seo_audit`.
4. Mutate KI/SERP/GSC from Filament without CommandBus.
5. Call `ApproveTopicalMap` from SERP/GSC services.
6. Put GSC OAuth secrets on `omi_seo_ai`.
7. Live Google Search Analytics SDK inside GSC Intelligence handlers.
8. Leak numeric IDs on Agent/MCP keyword surfaces.
9. Auto-schedule/publish from topical/keyword convert.
10. Treat Performance Hub snapshot as GSC Intelligence SoT (or reverse).
11. Parallel Assign-to-Content-Project UI on Audit / Keywords (left drawer, Filament modal, `mountAction` from keyword detail). Reuse Contract + drawer.
12. Reintroduce Keyword Rule Groups (`seo_keyword_rule_groups*`) or `keywords.parent_id` hierarchy.
13. Leave Focus Article keywords in Topics Unassigned after recluster/reconcile.

## 15. Tests and invariants

| Test / area | Invariant |
|-------------|-----------|
| `SeoAuditScanServiceTest` / missing-focus filters | Cache SQL filters; canonical focus keyword scope |
| `SeoAuditScoringIntegrationTest` | Registry ↔ audit filters |
| `SeoAuditMissingFocusKeywordAuditFilterTest` | missing_focus ≠ keyword_review UNION |
| `Gsc*Test` | Facts, sync, mapping, provider fail-closed |
| `Serp*` unit tests | Snapshot immutability, fetch security, overlap suggestions |
| Keyword intelligence unit suite | Quotas, tenant, public refs, analysis lock |
| `AssignToContentProjectUiArchitectureGuardTest` | Audit + Keyword resources open canonical drawer; no Action `form()` |
| `CanonicalClusterAndDnaTest` / `TopicIdeaCoverageAndDnaQualityTest` | DNA + canonical phrase |
| `FullDomainReclusterRepairTest` / `FocusArticleTopicInvariantTest` | Recluster + Focus→Topic |
| `KeywordDictionaryExcludeFromSeoVisibilityTest` / `TopicMembershipIntentGateTest` | Dictionary vs Topics eligibility |
| `KeywordListLoadingUxTest` / `DomainContextLoadingUxTest` | Loading shell + domain GET `site_id` |
| `SeoWorkspaceDashboardContractTest` | Dashboard widget registration + month chart presenters |

## 16. Related documents

- [ARTICLE_EDITOR.md](ARTICLE_EDITOR.md) — scoring client + save triggers score job
- [CONTENT_PROJECTS.md](CONTENT_PROJECTS.md) — assign / convert target; shared Assign drawer
- [CONTENT_PROJECT_ASSIGN_UI_2026_08.md](../architecture/CONTENT_PROJECT_ASSIGN_UI_2026_08.md) — 2026-08 assign consolidation
- [SITE_MCP_AND_DOMAINS.md](SITE_MCP_AND_DOMAINS.md) — domain sync feeding articles
- [AGENT_AND_MCP_CONTRACTS.md](../contracts/AGENT_AND_MCP_CONTRACTS.md)
- Archive detail: `docs/archive/audit-keywords/*`, `docs/archive/maps/MAP_SEO_AUDIT.md`

### Quick ref — cannibalization types (KI)

`c1_same_keyword_multi_article`, `c2_cluster_multi_article` (active); `c3`–`c6` reserved. Risk by distinct article count. Multi-keyword → one article is **not** auto cannibalization.

### Quick ref — audit low score

`SeoScoringRulesRegistry::AUDIT_LOW_SCORE_THRESHOLD` = **60**.
