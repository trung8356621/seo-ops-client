# Site MCP and Domains

> Status: Canonical  
> Owner: SeoContentAi (+ core User hierarchy)  
> Last verified: 2026-09-05  
> Supersedes: `docs/archive/maps/MAP_SEO_DOMAIN.md`, `MAP_SEO_TEAM.md` (high-level — discard prompt dumps / exhaustive file indexes)

## 1. Purpose

Domain (Site) management in SEO panel + **Site MCP Knowledge Profile** (tone/CTA/links/topics for prompts) + SEO team RBAC.

Clarify names:

| Term | Meaning |
|------|---------|
| **Site MCP Knowledge Profile** | Official domain prompt context (+ draft generator) — Edit Domain |
| **Developer / Agent MCP docs** | `ViewDomainMcp` — `CanonicalCapabilityRegistry` reference — **not** Knowledge Profile |
| **Site Sync** | Catalog sync with WordPress — separate module |

Model: Filament `DomainResource` → core `Site` (`mysql`).

## 2. Canonical routes

Prefix: `/seo/{connection_hash}/`

| Path | Page |
|------|------|
| `domains` | `ListDomains` |
| `domains/create` | `CreateDomain` |
| `domains/{record}/edit` | `EditDomain` — official Site MCP form + draft drawer |
| `domains/{record}/general` | `GeneralDomain` — overview / tokens / sync stats / scoring queue |
| `domains/{record}/mcp` | `ViewDomainMcp` — Agent MCP HTML docs (manager) |
| `domains/{record}/internal-links` | `ListDomainInternalLinks` |
| `domains/settings` | `DomainGlobalCtaSettings` |
| `domains/{record}/info` | Redirect → edit |
| `seo/team` | `SeoTeam` (manager) |
| `social` | `SocialProfilesPage` — per-site Social Profile CRUD (nav often via Performance Hub link; `shouldRegisterNavigation` may be false) |

Widgets: All-domains list / projects / team productivity.

## 3. Main components

| Concern | Class |
|---------|--------|
| Resource | `Filament/Resources/DomainResource` |
| Official profile | `SiteDomainPromptContextService` + `DomainTechnicalSeoForm` |
| WP field sync (explicit) | `DomainPromptContextWordPressFieldSyncService` + `WordPressFieldSyncAccessGate` — reads WP profile; **not** Site Sync run |
| WP profile reader | `site-sync` `WordPressSiteProfileReader` — lightweight GET `/sync/v2/profile` via `WordPressSiteSyncClient::fetchProfile()` |
| Edit Domain sync UI | `SyncsDomainPromptContextFromWordPress` on `EditDomain` — per-field actions with `wire:loading` on `company_short_identity` / `short_description` |
| Persist form | `PersistsDomainPromptContext` / `PersistsSeoDomainMetas` |
| Site MCP draft | `Services/SiteMcp/*` (`SiteMcpDraft`, `Discovery`, `Generator`, `OfficialGuard`, …) |
| CTA / links editor | `DomainCtaEditorService`, `DomainLinkListEditorService` |
| Global CTA | `SeoDomainCtaGlobalSettingsService` |
| Overview | `DomainOverviewService` |
| All-domains dash | `AllDomainsDashboardService` |
| Main domain flag | `SeoMainDomainService` |
| Link list → keywords | `DomainLinkListKeywordSyncService` |
| Content sync | `SyncDomainContentService` + Incremental/Metadata runners |
| Keyword resync | `KeywordDomainResyncService` |
| Plugin versions | `WordPressPluginDomainsOverviewService` |
| RBAC | `Support/SeoAccessControl` |
| Org hierarchy | `User` + `UserHierarchyService` (core) |
| Team UI | `Filament/Pages/SeoTeam` |
| Team messages | `TeamMessageController` + SSE transport |
| Social Profile (per site) | `social/` — `SocialProfile` + `SocialProfilesPage` (slug `social`) + `SocialProfileReadService` |
| Article social evidence | `social/` — `SeoArticleSocialLink` + `ArticleSocialLinkService` + `ArticleSocialLinkController` (API) |
| Panel nav (WP-style modules) | `Seo\Support\SeoUserNavigation` + `SeoPanelRoutes` |

## 4. Data ownership

| Layer | Storage | Fields |
|-------|---------|--------|
| Global CTA | WpOption `seo_domain_cta_global_settings` | default intro, working_hours, address, social |
| Official Site MCP | Site meta `seo_domain_prompt_context` | tone, short_description (≤300 words), cta_intro, cta[], links[] |
| Draft Site MCP | Site meta `site_mcp_draft` | topics/pages draft JSON — **never** auto-apply over official |
| Main domain | user/site meta `seo_is_main` | per-user primary site |
| Sync progress | Incremental/Metadata/Keyword resync caches | state machines + stale TTLs |
| Taxonomy parents | Article/term meta `wp_parent_id` including `"0"` | Site MCP fail-closed if wiped |
| Social Profile | `social_profiles` (site-scoped) | platform / display_name / profile_url / `is_active` — identity for manual share; **not** Electron auto-post |
| Article social links | `seo_article_social_links` (article-scoped) | url / domain / source (`manual`\|`api`) / `recorded_at` — share evidence per article; unique `(article_id, url_hash)` |

**Save Domain Settings** persists tone/CTA/links only — no keyword full-site HTML scrape, no Site Sync run (see SITE_SYNC).

**Social Profile:** CRUD on `SocialProfilesPage`; read DTO via `SocialProfileReadService`. Used with Performance Hub **GSC Social Top 10** (`GscSocialTop10Builder` — deterministic from GSC MCP, no AI). Does **not** feed SEO Audit Idea Candidates / Vocabulary Suggest. Invariant: GSC MCP ≠ SEO Audit Idea Suggest ≠ Vocabulary Suggest.

**Article social links (2026-09-01):** Canonical storage moved from archive-item rows to `seo_article_social_links` (`social` addon). Migration `2026_09_01_140100_migrate_archive_social_links_to_article_level` is forward-only. API (panel web middleware):

| Method | Path | Role |
|--------|------|------|
| GET | `/api/seo/articles/{article}/social-links` | List links (`seo.articles.social-links.index`) |
| POST | `/api/seo/articles/{article}/social-links` | Batch save (`seo.articles.social-links.store`) |

Normalize via `SocialUrlNormalizer`; supported domains via `SocialSupportedDomainService`. Archive preview / Excel exports read counts via `ArticleSocialLinkService` — reporting only, not share-action chrome (GSC MCP drawer still uses `social-share-actions`).

Draft Main Topics: live WP `product_cat` roots (`term_id>0`, `parent_term_id===0`) preferred over heuristics.

## 5. Read path

- GeneralDomain: overview service → tokens (protected), score distribution, sync stats, top keywords/links, official MCP summary.
- EditDomain: load official form; draft drawer reads `site_mcp_draft` only.
- ViewDomainMcp: capability registry docs for managers.
- Editor: CTA/link list services format insertable items for article UI.
- Global site header: `SeoAccessControl::globalSiteId()` for list/dashboard scope — not detail auth.

## 6. Write path

### Official profile

```text
Create/Edit Domain form submit
  → PersistsDomainPromptContext
  → SiteDomainPromptContextService::saveForSite()
  → DomainLinkListKeywordSyncService::syncLinks()
```

### WordPress field sync (Domain Prompt Context)

Explicit per-field pull on **Edit Domain** for WordPress sites only (`DomainTechnicalSeoForm` suffix actions). Does **not** start Site Sync orchestration.

```text
EditDomain action (company_short_identity | short_description)
  → DomainPromptContextWordPressFieldSyncService
  → WordPressSiteProfileReader::read()
  → SiteDomainPromptContextService::patchForSite()
```

| Field | WP source | Rules |
|-------|-----------|-------|
| `company_short_identity` | `site_name` (fallback `schema_org.name`) | Clamped to 80 chars; notifies when clamped; requires bridge ≥ **1.0.87** for canonical `site_name` |
| `short_description` | `short_description` | Rejects empty or > `MAX_SHORT_DESCRIPTION_WORDS` |

Gate: `WordPressFieldSyncAccessGate::canSync()`. Endpoint: `GET /omi-seo-ai/v1/sync/v2/profile` (read token).

### Draft Site MCP

Generate/Regenerate → `SiteMcpGenerator` modes (`news_manual` / `production_catalog` / `ecommerce_catalog`) → write `site_mcp_draft`. Apply to official only via explicit user action + `OfficialGuard`.

### Domain ops (General)

| Action | Job / runner |
|--------|----------------|
| Incremental sync | `RunIncrementalDomainSyncJob` → `IncrementalDomainSyncRunner` |
| Metadata resync | `RunMetadataDomainSyncJob` → `MetadataDomainSyncRunner` |
| Keyword rescrape | `RunKeywordDomainResyncJob` → `KeywordDomainResyncService` |
| Score backfill | `SeoArticleScoringQueueService` / `AnalyzeArticleSeoJob` |
| Link health | `AuditLinkStatusJob` on queue `seo-audit` via `LinkMapStatusAuditService` (not WP publish worker) |

Site Sync V2 button/flow: see `SITE_SYNC.md` (not legacy incremental alone).

## 7. Public capabilities

Agent/MCP site context reads may expose Knowledge Profile variables via prompt context helpers — capability catalog owned in contracts doc.

**Datetime contract (Agent/MCP presentation):** machine fields UTC ISO-8601; user-facing labels via `SystemDateTime` / shared `window.__SYSTEM_DATETIME__` config (`timezone`, `preset` vi|en). WordPress site timezone is not schedule SoT.

Domain CRUD is Filament UI (manager/planner per page gates).

## 8. Internal-only capabilities

- Sync cache comparators / stale detection.
- Clear-domain articles service (destructive ops).
- Site MCP contact/keyword extractors.
- Team SSE message fan-out internals.

## 9. Authorization and confirmation

### System roles (`users.role`)

`admin` | `owner` | `manager` | `staff` — hierarchy via `parent_id` / `manager_id` (does **not** replace `seo_role`).

### SEO roles (`users.seo_role`)

| Role | Rank | Notes |
|------|------|-------|
| `content_manager` | 1 | Write content; no AI/global site; own projects |
| `planner` | 2 | Plan, global site/project, sync WP |
| `manager` | 3 | Settings, team, full mutate |

Simulation: manager/planner may simulate lower SEO role via session. `effectiveRole()` vs `actualRole()`.

Admin on foreign connection: `isSeoPanelReadOnly()` — view only.

Key gates: `canAccessContentFeatures` / `Planner` / `Manager` / `canMutateInSeoPanel` / `canMutateContentProjects` / `canSyncArticlesToWordPress` / `canDeleteSeoMedia`.

Content Manager: only assigned data; no global site picker. Planner/Manager: global site cookie for lists — detail edit still `canAccessSite`.

SeoTeam page: manager features only.

## 10. Queue and scheduler ownership

Domain sync jobs unique ~2h per site/user where marked. Queue name historically `default`/`seo` — ops workers must cover configured queues. Link audit: short timeout, tries=2. Scoring jobs unique per article.

No Filament Queue Manager UI.

## 11. Transactions and side effects

- Saving links upserts keyword table rows for domain context.
- Draft generation never overwrites `seo_domain_prompt_context` without explicit apply.
- Taxonomy sync must keep `wp_parent_id=0` (not delete) for Site MCP root detection.
- Team hierarchy changes detach staff when manager removed (`UserHierarchyService`).

## 12. Retry and recovery

- Incremental/metadata caches: resumable / stale-after windows (~120s).
- Keyword resync orphan detection (~180s).
- Scoring retry failed from GeneralDomain.
- Plugin version overview for bridge mismatch diagnosis.

## 13. Compatibility paths

- `/info` → `/edit` redirect.
- Legacy domain sync jobs coexist with Site Sync V2 — do not confuse Save / Sync / Publish / Rebuild.
- Global CTA WpOption shared across domains.
- Role simulation cookie/session — not a second RBAC system.

## 14. Forbidden paths

1. Treat ViewDomainMcp as Site MCP Knowledge Profile editor.
2. Auto-apply `site_mcp_draft` onto official profile.
3. Save Domain Settings triggering Site Sync or full HTML site parse.
4. Use global site header as sole authorization for edit/detail.
5. Wipe `wp_parent_id` when parent is 0.
6. Content Manager mutating manager-only settings/team.
7. Admin read-only panel mutations (`guardSeoPanelMutation`).

## 15. Tests and invariants

| Area | Invariant |
|------|-----------|
| SeoAccessControl consumers | Rank gates + read-only admin |
| Site MCP product_cat | Roots only `parent_term_id===0` with live/staging preference |
| Domain link sync | Official links ↔ keyword rows (catalog SoT) |
| Domain link list (Article Editor) | Client soft match/locate; see [`ARTICLE_EDITOR_DOMAIN_LINK_LIST.md`](../architecture/ARTICLE_EDITOR_DOMAIN_LINK_LIST.md) |
| WP field sync UI | `DomainPromptContextWordPressSyncUiContractTest` — Edit Domain actions + loading states |
| Article social links API | `ArticleSocialLinkApiContractTest` / `ArticleSocialLinkServiceTest` |
| User hierarchy | Owner/Manager/Staff column rules |

## 16. Related documents

- [SITE_SYNC.md](SITE_SYNC.md) — Sync button / catalog SoT
- [WORDPRESS_BRIDGE.md](WORDPRESS_BRIDGE.md)
- [CONTENT_PROJECTS.md](CONTENT_PROJECTS.md) — project scoping by role
- [ARTICLE_EDITOR.md](ARTICLE_EDITOR.md) — CTA/link insert; Domain link list → [`ARTICLE_EDITOR_DOMAIN_LINK_LIST.md`](../architecture/ARTICLE_EDITOR_DOMAIN_LINK_LIST.md)
- [AGENT_AND_MCP_CONTRACTS.md](../contracts/AGENT_AND_MCP_CONTRACTS.md)
- [SYSTEM_OVERVIEW.md](../architecture/SYSTEM_OVERVIEW.md)
- Archive: `docs/archive/maps/MAP_SEO_DOMAIN.md`, `MAP_SEO_TEAM.md`

### Permission matrix (compressed)

| Gate | CM | Planner | Manager | Admin viewer |
|------|----|---------|---------|--------------|
| Content features | Y | Y | Y | Y |
| Planner features | N | Y | Y | Y |
| Manager features | N | N | Y | N |
| Mutate panel | Y | Y | Y | N |
| Mutate CP | N | Y | Y | N |
| Sync WP | N | Y | Y | N |
