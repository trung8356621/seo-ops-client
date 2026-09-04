# Quick Documentation Summary

> Status: working summary, not canonical source of truth  
> Updated: 2026-09-04  
> Purpose: digest gần nhất để session sau re-orient nhanh. Canonical behavior vẫn ở `docs/README.md` và module/architecture docs.

## 1. Documentation Map

- `docs/README.md` — index + precedence.
- `docs/architecture/*` — boundaries, ADR, handoff.
- `docs/modules/*` — 16 module docs (thêm `CONTEXTUAL_HELP.md` 2026-09-01).
- `docs/contracts/*`, `docs/operations/*`, `docs/audits/*`.
- `docs/archive/*` — historical only.
- `resources/help-seed/` — human-facing Help topics (48); không override canonical dev docs.

## 2. Documentation update policy (2026-09-01)

- **Đã bỏ** trigger `XONG!` per-agent session.
- Cập nhật docs **một lần tổng hợp** khi user yêu cầu hoặc sau đợt code lớn.
- Rule: `.cursor/rules/auto-update-docs.mdc`. Skill: `.agents/skills/docs-update-on-xong/SKILL.md` (bulk mode).

## 3. Batch 2026-08-27 → 2026-08-30

Canonical đã sync: `CONTENT_PROJECTS.md`, `SEO_AUDIT_AND_KEYWORDS.md`, `ARTICLE_EDITOR.md`, `WORDPRESS_BRIDGE.md`.

### Content Projects — Draft → Execution Project

- **Draft Reviewed → Create Execution Project** via `SplitDraftContentProjectService` / `content_project.split_draft`.
- Writers = user thật; **không** `SeoOpsSystemUser`.
- Pack **max-30** task/EP per writer+month (`ContentProjectExecutionPackingService`).
- Repair: `seo:repair-execution-project-naming`, `seo:repair-execution-project-packing`.
- List buckets: `all|draft|project|archived`. SEO Audit Notes: DNA snapshot per cluster.

### Topics / Keyword DNA

- UI **Topics / Chủ đề** (`cluster_key` trong code).
- Keyword Dictionary **flat**; DNA + recluster + focus reconcile (`seo:topics:reconcile-focus`).
- `PaginationWindow` (±2 desktop). Keywords + CP dùng `list-table-loading-shell`.

### GSC / nav / reviews / vocabulary

- `SeoUserNavigation` / `SeoPanelRoutes`. GSC month-scoped.
- `ProductReviewCreationPolicy` + AI history recorder.
- Vocabulary Suggest persist; Planner Idea Candidates chỉ từ Suggest.
- Social Profiles + GSC Social Top 10.

### Editor (26–30/8)

- Domain link list soft match — `ARTICLE_EDITOR_DOMAIN_LINK_LIST.md`.
- Widget locks — SEO unlocked, còn lại locked (`ARTICLE_EDITOR_WIDGET_LOCKS.md`).

## 4. Batch 2026-08-31 (bổ sung canonical 2026-09-01)

### Contextual Help System — `omnichannel-client` 0.2.5–0.2.8

- Full stack: `HelpGroupRegistry`, `HelpPublishService`, `HelpRemoteSyncService`, `HelpSyncCommand`.
- Filament admin: `HelpTopicsAdmin`, `HelpTopicCreate`, `HelpTopicEdit`.
- `HelpUi::fieldHintAction()` — mở help client-side (`seo-global-help:open`), không Livewire toggle.
- `HelpContextKeyRegistry` + `HelpRuntimePayloadBuilder`.
- **48 topics** `resources/help-seed/docs/`; VERSION `2026.08.31.1`.
- Help **không** nằm Settings nav; tách khỏi Prompt Guidance.
- Module doc: `docs/modules/CONTEXTUAL_HELP.md`.

### Site Sync V3 — `omnichannel-addons` 0.2.7

- `SiteSyncV3Schema` (`site_sync.v3`), `SiteSyncProtocolRouter`, `RunSiteSyncV3Orchestrator`.
- Phases: discover → import → reconcile_stale → catch_up → verify → complete.
- **Không** ghi `articles.body` / `wp_post_content*` meta.
- Keyset cursors; migrations run state + WAL index.
- Tests: `SiteSyncV3ContractTest`, `SiteSyncV3HardeningIntegrationTest`.
- Ghi trong `SITE_SYNC.md` §17.

### Article meta + WP content cache — `addons` 0.2.7

- `ArticleMetaKeyCatalog` + `ArticleRequiredDataRegistry`.
- Xóa `wp_post_content*` khỏi `article_meta` (migration 2026-08-31).
- Bảng `article_wp_content_cache` + `ArticleWpContentCacheService`.
- Refactor persist/readiness/FAQ/links/TOC/gallery services.
- Ghi trong `ARTICLE_EDITOR.md` §15, `WORDPRESS_BRIDGE.md`.

### Domain UI (compat shell)

- Site health card, sync preflight modal, progress partials (`seo-content-ai-compat`).

## 5. Repos & caution

- Code chính: `omnichannel-addons` + light `omnichannel-client`.
- Canonical docs chỉ ở `omnichannel-client/docs`.
- `git status --short` trước khi sửa — tránh revert unrelated.

## 6. Batch 2026-09-01 (pass này)

Phạm vi code: **2026-08-31 → 2026-09-01** (`omnichannel-addons` peer addons).

### Domain Prompt Context ↔ WordPress field sync

- `DomainPromptContextWordPressFieldSyncService` — explicit pull `company_short_identity` / `short_description`.
- `WordPressSiteProfileReader` — lightweight `GET /sync/v2/profile`; **không** Site Sync run.
- UI: `SyncsDomainPromptContextFromWordPress` trên Edit Domain + loading states.
- Docs: `SITE_MCP_AND_DOMAINS.md`, `PROMPTS_AND_AI.md`, `SITE_SYNC.md`.

### Article social links + archive reporting

- Bảng `seo_article_social_links` (`social` addon); migration archive → article-level.
- API: `GET|POST /api/seo/articles/{article}/social-links`.
- Archive preview + Excel (per-project + monthly) dùng `ArticleSocialLinkService`.
- Docs: `SITE_MCP_AND_DOMAINS.md`, `CONTENT_PROJECTS.md`.

### SEO Workspace dashboard refresh

- Single-domain: `KeywordOverviewWidget` (`DashboardKeywordOverviewService`).
- All-domains: month workload charts (`DashboardDomainArticlesChartWidget` / `DashboardWriterArticlesChartWidget`).
- Test contract: `SeoWorkspaceDashboardContractTest`.
- Doc: `SEO_AUDIT_AND_KEYWORDS.md`.

### Content Projects draft table

- Domain column + clone idea + inline domain repair.
- Tests: `DraftItemTableDomainAndCloneContractTest`.
- Doc: `CONTENT_PROJECTS.md`.

## 7. Batch 2026-09-03 — Keyword Cannibalization removed

- **Removed** seo-ops route `/keywords/cannibalization`, sidebar/workspace nav, KI detect/persist (`seo_keyword_cannibalization_issues` drop migration), agent/MCP `get_cannibalization` / `review_cannibalization` / `domain.keyword_cannibalization`.
- Keywords nav remaining: dictionary / focus / Topics / **anchor-audit** (Sửa Link Chết) / AI discovery.
- **Kept:** GSC `possible_cannibalization` (query×page competition evidence for planning/MCP) — not a Keywords module.
- Docs: `SEO_AUDIT_AND_KEYWORDS.md`, `AGENT_AND_MCP_CONTRACTS.md`.
- Anchor-audit eager load: `articles.wp_post_id` → `wordpress_article_links` via `sourceArticle.wordpressLink`.

## 8. Batch 2026-09-03 code → docs catch-up (pass 2026-09-04)

Phạm vi: `omnichannel-addons` **0.3.0** + `omnichannel-client` **0.3.1** (commit 3/9; **không** có commit ngày 4/9). Docs pass ngày 4/9 bổ sung phần còn thiếu ngoài cannibalization.

### Prompt budget / outbound gate — `ai-prompt`

- `PromptBudgetPreflightService` + `AiOutboundBudgetGate` + `ModelContextCapabilityResolver`.
- Split: `PromptSplitClass` + strategies (`DirectFit`, long-form / HTML-safe / keyword-discovery semantic split) + `SemanticSplitExecutor`.
- Doc: `PROMPTS_AND_AI.md` §12.

### Content Projects planner / New Content

- One-click recovery: `NewContentAutoContinuationPolicy` (+ batch / cross-batch / auto DNA).
- Audit Notes target alloc: `AuditNoteTargetAllocator`; DNA `placement` in planner snapshots.
- Cross-domain **config** clone: `PlannerPlanCloneService` (không clone content).
- Writer monthly capacity settings: `ContentProjectWriterCapacitySettingsService`.
- Doc: `CONTENT_PROJECTS.md`.

### Keywords DNA + Topics repair

- Column `seo_keyword_dna.placement` (`before`\|`after`) via `DnaPlacement`.
- Background Fix Keywords: `ReconcileTopicMembershipJob`.
- Doc: `SEO_AUDIT_AND_KEYWORDS.md`.

## 9. Fast re-entry

1. `docs/README.md`
2. `git status --short` (+ log client + addons nếu docs lag)
3. Module doc cho vùng đang sửa
4. Code + test gần nhất
5. deploy-diff cho application-code changes
6. Verification focused hoặc báo skip có lý do
