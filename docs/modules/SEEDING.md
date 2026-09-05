# Seeding Topic V2 / Link Intelligence

> Status: Canonical  
> Owner: `seeding` (peer addon)  
> Last verified: 2026-09-05  
> Capabilities: `seeding.topic`, `link.intelligence` (optional consumer: `social.profile`)

## 1. Purpose

Social **Seeding Topic** workspace per site: paste/post text + optional social URL, extract outbound links into shared **Link Intelligence** resources, archive topics without deleting history.

- Filament shell mounts a **React island** (local-first drafts); CRUD persists via JSON API.
- **Not** Content Project planning, GSC MCP share actions, or article comment-hook seeding prompts.
- Link fetch/enrichment (`fetch_status`) is schema-ready; fetch pipeline not required this sprint.

## 2. Canonical surfaces

Panel prefix: `/seo/{connection_hash}/`

| Path / surface | Class / entry | Role |
|----------------|---------------|------|
| `seeding-topics` | `SeedingTopicsPage` | Workspace shell + Vite React mount |
| `seeding-topic-manage` | `ManageSeedingTopicPage` | Redirect/compat manage slug |
| SEO Workspace nav | `SeoUserNavigation` / `SeoPanelRoutes` | Planner+; Filament `shouldRegisterNavigation = false` on pages |

**HTTP** (`seo-content-ai-compat` `SeoPanelProvider` — route registration only):

| Method | Path | Controller |
|--------|------|------------|
| GET/POST | `/api/seo/seeding-topics` | `SeedingTopicController@index` / `store` |
| GET/PATCH/DELETE | `/api/seo/seeding-topics/{topic}` | `show` / `update` / `destroy` |

Gates: `SeoAccessControl::canAccessPlannerFeatures()`; mutate requires `canMutateInSeoPanel()` + `canAccessSite($siteId)`.

Vite: `addons/seeding/resources/js/seeding-workspace.jsx` (+ CSS). Client `config/addons.php` lists `seeding`.

## 3. Main components

| Concern | Class / path |
|---------|----------------|
| Provider | `SeedingServiceProvider` — migrations, views, lang, capability markers |
| Topic service | `Services/SeedingTopicService` |
| Presenter | `Support/SeedingTopicPresenter` |
| Platform detect | `Services/SeedingSocialPlatformDetector` + `Enums/SeedingSocialPlatform` |
| Status | `Enums/SeedingTopicStatus` (`draft` default) |
| Model | `Models/SeedingTopic` (`omi_seo_ai`.`seeding_topics`) |
| Link extract | `LinkIntelligence/LinkExtractor` + `Dto/ExtractedLink` |
| URL normalize | `LinkIntelligence/UrlNormalizer` |
| Link resources | `LinkIntelligence/LinkResourceService` + `Models/LinkResource` |
| React workspace | `resources/js/seeding/SeedingWorkspace.jsx` (+ `api.js`, `storage.js`) |

## 4. Data ownership

**DB:** `omi_seo_ai` (no FK to core `sites` / `users`).

| Table | Role |
|-------|------|
| `seeding_topics` | Site-scoped topic text, optional `social_url` / `social_platform`, `status`, `published_at`, `archived_at` |
| `link_resources` | Deduped URLs (`normalized_url_hash` unique), domain, optional title/description/fetch fields |
| `seeding_topic_links` | Topic ↔ link_resource pivot |

Archive filter: `archived_at` null = active list; archived list + `archived_count` via service.

## 5. Read / write path

1. Page resolves `site_id` from Global SEO bar / accessible sites; dispatches `seeding-site-changed` on domain switch.
2. React loads `GET /api/seo/seeding-topics?site_id=` (optional `archived=1`).
3. Create/update extracts links from `full_text` / `source_html` → upsert `link_resources` + pivot.
4. Empty `full_text` allowed for local-first draft create; server still requires valid `site_id`.

## 6. Forbidden paths

- Business logic in `seo-content-ai-compat` beyond route/nav/lang wiring.
- Treating Seeding as Content Project items or GSC MCP share SoT.
- Hard-deleting `link_resources` shared by other topics without checking pivot usage.
- Sibling imports from `social` / `content-projects` implementations (capability/DTO only).

## 7. Tests

| Test | Invariant |
|------|-----------|
| `SeedingProviderBootstrapContractTest` | Provider + capability registration |
| `SeedingSeoNavContractTest` | Panel routes / nav labels |
| `SeedingWorkspaceContractTest` | API routes + Vite entry + Blade mount |
| `SeedingTopicServiceTest` | Create/list/archive + link attach |
| `LinkExtractorTest` / `UrlNormalizerTest` | Extract + normalize contracts |

## 8. Related documents

- [SITE_MCP_AND_DOMAINS.md](SITE_MCP_AND_DOMAINS.md) — domain / Global SEO bar context
- [CONTENT_PROJECTS.md](CONTENT_PROJECTS.md) — separate planning/production surface
- [ADDON_ARCHITECTURE.md](../architecture/ADDON_ARCHITECTURE.md) — peer rules
- [NEW_AGENT_HANDOFF.md](../architecture/NEW_AGENT_HANDOFF.md) — feature → owner
