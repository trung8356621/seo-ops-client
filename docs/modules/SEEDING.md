# Seeding Service

> Status: Canonical  
> Owner: `seeding` (peer service / addon)  
> Last verified: 2026-09-12  
> Capabilities: `seeding.workspace`, `seeding.topic`, `link.intelligence`  
> Related: [SERVICE_ARCHITECTURE.md](../architecture/SERVICE_ARCHITECTURE.md)

## 1. Purpose

Independent **Seeding** product plane on the client — peer to SEO, not nested under it.

- Canonical UI: `/seeding` (Filament panel `seeding`)
- Service APIs: `/api/seeding/*` (bootstrap, health, feed, share, reports, comment generate, link-preview)
- **Shared-topic SoT (2026-09-09+):** `omi_seeding` tables `seeding_topics` + `seeding_reports`
- **Local draft SoT:** browser localStorage (`seeding:v3:…`) until author presses **Chia sẻ**

Not Content Project planning, GSC MCP share actions, or article comment-hook seeding prompts.

## 2. Architecture

```
Core (User, SiteAccess, Service catalog, services.apply)
        ↓
Seeding service (panel, config, health, bootstrap)
        ├── localStorage  → draft workspace (pre-share)
        └── omi_seeding   → shared topics + reports (post-share)
```

Activation: Core `services` row `slug=seeding` via ops-server `services.apply` (`service_key` + config).  
DB credentials: Core `ServiceDatabaseConnection` → logical `omi_seeding` (Admin: `/admin/services/seeding`).

Share commits a topic + requirement snapshot; seeders consume **feed** (DB), not localStorage of other users.

## 3. Surfaces

| Surface | Role |
|---------|------|
| `GET /seeding` | React workspace — `SeedingTopicsPage` + `SeedingWorkspace.jsx` |
| `GET /seeding/service` | Service status — `SeedingServiceStatusPage` |
| `GET /api/seeding/bootstrap` | User/sites/installation namespace |
| `GET /api/seeding/health` | Service + DB plane readiness |
| `GET /api/seeding/feed` | Eligible shared topics for seeders — `SeedingFeedController` |
| `POST /api/seeding/topics/share` | Commit draft → `seeding_topics` — `SeedingShareTopicController` |
| `POST /api/seeding/reports` | Proof + comment commit — `SeedingReportController` |
| `POST /api/seeding/comments/generate` | Comment AI assist — `SeedingCommentGenerateController` |
| `POST /api/seeding/link-preview` | Outbound URL preview — `SeedingLinkPreviewController` |
| SEO nav “Seeding” | Shortcut → `/seeding` only |
| Legacy SEO UI paths | Redirect → `/seeding` (query preserved) |
| Legacy topic CRUD | `GET|POST /api/seeding/topics*` + `/api/seo/seeding-topics*` → **410 Gone** |

Vite: `addons/seeding/resources/js/seeding-workspace.jsx` (+ CSS). Client `config/addons.php` lists `seeding`.

## 4. Main components

| Concern | Class / path |
|---------|----------------|
| Provider | `SeedingServiceProvider` — views, lang, capabilities, routes (no `loadMigrationsFrom`) |
| Panel | `Providers/SeedingPanelProvider` — path `/seeding` |
| Access / resolve | `Support/SeedingAccess`, `SeedingServiceResolver`, `SeedingServiceConfig` |
| Health | `Support/SeedingServiceHealth`, `SeedingDatabaseHealth` |
| DB bootstrap | `Services/SeedingDatabaseConnectionService` → Core `ServiceDatabaseConnectionResolver` |
| Share / feed | `SeedingSharedTopicService` + `SeedingTopicPresenter` |
| Reports | `SeedingReportService` |
| Targets | `SeedingTargetCalculator` — max comments/day × member count → per-user requirement snapshot at share |
| Comment AI | `SeedingCommentGenerateService` |
| Link preview | `SeedingLinkPreviewService` + `SeedingOutboundUrlPolicy` |
| Models | `SeedingTopic`, `SeedingReport` (`omi_seeding`) |
| Status enum | `Enums/SeedingTopicStatus` |
| Settings contrib | `Settings/SeedingSettingsSectionContributor` |
| CLI | `Console/SeedingDbCheckCommand` |
| React workspace | `resources/js/seeding/SeedingWorkspace.jsx` (+ `api.js`, `shareFeed.js`, `copyComment.js`, `storage.js`) |
| Link extract | `LinkIntelligence/*` — pool/UI helpers; share persists `links_json` |

## 5. Share → feed → report flow

1. Author drafts in React (localStorage).  
2. **Chia sẻ** → `POST /topics/share` → row in `seeding_topics` with `status=shared`, `shared_at`, and snapshot fields (`max_comments_target`, `member_count_at_share`, `required_comments_per_user`).  
3. Seeders load `GET /feed` (installation-scoped).  
4. After external social post: `POST /reports` stores comment + optional seed link + proof meta in `seeding_reports`.

## 6. localStorage document (draft only)

Key: `seeding:v3:{installationId}:{userId}:{siteId}:doc`

```json
{
  "schema_version": 1,
  "updated_at": "...",
  "topics": [],
  "workspace": { "selectedTopicId": null, "search": "", "showArchived": false }
}
```

Shared topics are **not** SoT in localStorage after share — feed/API is.

## 7. Database

| Plane | Connection | Status |
|-------|------------|--------|
| Seeding | `omi_seeding` | Active business schema |
| SEO | `omi_seo_ai` | Must not receive Seeding business writes |

| Table | Role |
|-------|------|
| `seeding_topics` | Shared topics + requirement snapshot at share (`installation_id`, `full_text`, `links_json`, `social_url`, targets, `shared_at` / `archived_at`) |
| `seeding_reports` | Report commit (`topic_id`, `user_id`, `comment_text`, seed link, proof path/meta, `reported_at`) |

Env: `SEEDING_DB_*` (see `.env.example`). Never fall back DB name to `omi_seo_ai`.

Active migrations: `addons/seeding/database/migrations` (owned via `config/addon_migration_ownership.php` → `omi_seeding`).  
Legacy experimental V2 (targeted `omi_seo_ai`): `addons/seeding/database/legacy-experimental/` — **not registered**.

## 8. Forbidden

- Seeding → SEO DB / SEO models for workspace
- Topic CRUD via retired `/api/seeding/topics*` (410 only)
- Auto CREATE/DROP production DBs on web boot
- Business logic in `seo-content-ai-compat` beyond nav/lang wiring
- Sibling implementation imports from `social` / `content-projects`
- Unconditional null `site_id` style leaks (N/A here — use `installation_id` namespace)

## 9. Tests

| Test | Invariant |
|------|-----------|
| `SeedingProviderBootstrapContractTest` | Provider + capability registration |
| `SeedingSeoNavContractTest` | SEO nav points at `/seeding` |
| `SeedingWorkspaceContractTest` | Vite entry + storage key; React-first surface |
| `SeedingReactFirstContractTest` | Share/feed/report wiring |
| `SeedingFlexibleSeedingContractTest` | Target calculator + share snapshot |
| `SeedingFeedUxContractTest` / `SeedingLinkPreviewAndAuthContractTest` / `SeedingCommentLinkPreviewContractTest` | Feed / preview / auth |
| `SeedingTargetCalculatorTest` | Per-user requirement math |
| Client `SeedingSurfaceExtractionTest` / `Seeding/*` unit | Panel / DB plane isolation |

## 10. Related

- [SERVICE_ARCHITECTURE.md](../architecture/SERVICE_ARCHITECTURE.md)
- [ADDON_ARCHITECTURE.md](../architecture/ADDON_ARCHITECTURE.md)
- [NEW_AGENT_HANDOFF.md](../architecture/NEW_AGENT_HANDOFF.md)
- [SITE_MCP_AND_DOMAINS.md](SITE_MCP_AND_DOMAINS.md) — domain / Global SEO bar context (shortcut only)
