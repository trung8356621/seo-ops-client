# Seeding Service

> Status: Canonical  
> Owner: `seeding` (peer service / addon)  
> Last verified: 2026-09-05  
> Capabilities: `seeding.workspace`, `seeding.topic`, `link.intelligence`  
> Related: [SERVICE_ARCHITECTURE.md](../architecture/SERVICE_ARCHITECTURE.md)

## 1. Purpose

Independent **Seeding** product plane on the client — peer to SEO, not nested under it.

- Canonical UI: `/seeding` (Filament panel `seeding`)
- Canonical service APIs: `/api/seeding/bootstrap`, `/api/seeding/health`
- **Business workspace SoT (this phase): browser localStorage**
- Dedicated DB plane: `omi_seeding` (infrastructure-ready, **no business schema yet**)

Not Content Project planning, GSC MCP share actions, or article comment-hook seeding prompts.

## 2. Architecture

```
Core (User, SiteAccess, Service catalog, services.apply)
        ↓
Seeding service (panel, config, health, bootstrap)
        ├── localStorage  → topics / workspace (schema_version 1)
        └── omi_seeding   → empty ready plane (migrations later)
```

Activation: Core `services` row `slug=seeding` via ops-server `services.apply` (`service_key` + config).  
DB credentials: Core `ServiceDatabaseConnection` → logical `omi_seeding` (Admin: `/admin/services/seeding`).

Business workspace SoT remains **localStorage** this phase (`omi_seeding` may stay empty).

## 3. Surfaces

| Surface | Role |
|---------|------|
| `GET /seeding` | React workspace (local-only) — `SeedingTopicsPage` |
| `GET /seeding/service` | Service status — `SeedingServiceStatusPage` |
| `GET /api/seeding/bootstrap` | User/sites/installation namespace — no topics |
| `GET /api/seeding/health` | Service + DB plane readiness |
| SEO nav “Seeding” | Shortcut → `/seeding` only (`url('/seeding')`) |
| Legacy SEO UI paths | Redirect → `/seeding` (query preserved) |

Deprecated (unused by canonical UI): `/api/seeding/topics*`, `/api/seo/seeding-topics*`.  
Compat stub: `ManageSeedingTopicPage` → redirect.

Vite: `addons/seeding/resources/js/seeding-workspace.jsx` (+ CSS). Client `config/addons.php` lists `seeding`.

## 4. Main components

| Concern | Class / path |
|---------|----------------|
| Provider | `SeedingServiceProvider` — views, lang, capabilities, routes (no `loadMigrationsFrom`) |
| Panel | `Providers/SeedingPanelProvider` — path `/seeding` |
| Access / resolve | `Support/SeedingAccess`, `SeedingServiceResolver`, `SeedingServiceConfig` |
| Health | `Support/SeedingServiceHealth`, `SeedingDatabaseHealth` |
| DB bootstrap | `Services/SeedingDatabaseConnectionService` → Core `ServiceDatabaseConnectionResolver` |
| Settings contrib | `Settings/SeedingSettingsSectionContributor` |
| CLI | `Console/SeedingDbCheckCommand` |
| React workspace | `resources/js/seeding/SeedingWorkspace.jsx` (+ `api.js`, `storage.js`) |
| Link extract (legacy code) | `LinkIntelligence/*` — not wired to canonical localStorage UI |

## 5. localStorage document

Key: `seeding:v3:{installationId}:{userId}:{siteId}:doc`

```json
{
  "schema_version": 1,
  "updated_at": "...",
  "topics": [],
  "workspace": { "selectedTopicId": null, "search": "", "showArchived": false }
}
```

## 6. Database

| Plane | Connection | Status |
|-------|------------|--------|
| Seeding | `omi_seeding` | Ready / empty — health-checked |
| SEO | `omi_seo_ai` | Must not receive Seeding business writes |

Env: `SEEDING_DB_*` (see `.env.example`). Never fall back DB name to `omi_seo_ai`.

Active migrations path: `addons/seeding/database/migrations` (empty of business PHP; ownership via `config/addon_migration_ownership.php`).  
Legacy experimental V2 migrations: `addons/seeding/database/legacy-experimental/` (targeted `omi_seo_ai`; not registered).

## 7. Forbidden

- Seeding → SEO DB / SEO models for workspace
- Speculative business migrations before domain model freeze
- Topic CRUD from canonical React workspace
- Auto CREATE/DROP production DBs on web boot
- Business logic in `seo-content-ai-compat` beyond nav/lang wiring
- Sibling implementation imports from `social` / `content-projects`

## 8. Tests

| Test | Invariant |
|------|-----------|
| `SeedingProviderBootstrapContractTest` | Provider + capability registration |
| `SeedingSeoNavContractTest` | SEO nav points at `/seeding` |
| `SeedingWorkspaceContractTest` | Vite entry + `seeding:v3:` storage; no topic CRUD from workspace |
| Client `SeedingSurfaceExtractionTest` / `Seeding/*` unit | Panel / DB plane isolation |

## 9. Deferred

Domain model + business migrations · Redux · realtime · Node extraction · billing/ops-server UI

## 10. Related

- [SERVICE_ARCHITECTURE.md](../architecture/SERVICE_ARCHITECTURE.md)
- [ADDON_ARCHITECTURE.md](../architecture/ADDON_ARCHITECTURE.md)
- [NEW_AGENT_HANDOFF.md](../architecture/NEW_AGENT_HANDOFF.md)
- [SITE_MCP_AND_DOMAINS.md](SITE_MCP_AND_DOMAINS.md) — domain / Global SEO bar context (shortcut only)
