# System Overview

> Status: Canonical  
> Owner: Core + peer addons (`omnichannel-addons`)  
> Last verified: 2026-09-22  
> Supersedes: `docs/archive/maps/MAP_CORE.md`, `FEATURE_MAP_FULL.md` (high-level only — not route/controller dumps)  
> Authority: [ADDON_ARCHITECTURE.md](ADDON_ARCHITECTURE.md) · [SERVICE_ARCHITECTURE.md](SERVICE_ARCHITECTURE.md) · [API_AND_AUTHORIZATION.md](../contracts/API_AND_AUTHORIZATION.md)

## Shape

Omnichannel SaaS client: **Laravel 12 / PHP 8.2+ / Filament v3 / MySQL multi-connection**.

| Plane | Path | Role |
|-------|------|------|
| **Core** | `app/Core`, `app/Filament` admin | Identity, tenancy, Service catalog (`service_key` + `ServiceDatabaseConnection`), addon discovery, Settings/Members registries, **canonical browser auth** + Access Hub |
| **Peer addons** | `addons/{slug}` → `omnichannel-addons` | SEO, content, media, WP, publishing, site-sync, AI, seeding, … |
| **Compat shell** | `seo-content-ai-compat` | Filament views/lang/panel bootstrap only — no new business |
| **WP plugin** | external `wp-seo-ai` / `omi-seo-ai-bridge` | Live WP public content + Site Sync provider |

**Addon installed ≠ Service active.** Entitlement comes from ops-server `services.apply`. Slugs in `config/addons.php` → `skip_slugs` are ignored even if discovered.

## Authentication + Access Hub

| Piece | Canonical |
|-------|-----------|
| Login | `GET/POST /login` (one User, one `web` session) |
| Legacy panel logins | `/admin|/seo|/seeding|/tools/login` (+ SEO hash login) → `/login` |
| Google OAuth | `/auth/google` → same post-login redirector |
| Access Hub | `GET /workspace` — `WorkspaceDestinationRegistry` (addons register cards) |
| Post-login | Staff → `/workspace`; Owner/admin → `/admin` |

Addons **authorize + bootstrap** only — they do not own login pages or separate guards. Detail: [API_AND_AUTHORIZATION.md](../contracts/API_AND_AUTHORIZATION.md).

## Panels

| Panel | Mount | Audience |
|-------|-------|----------|
| Admin | `/admin` | Core admin (`AdminPanelProvider`); **Dịch vụ** `/admin/services` |
| SEO (short) | `/seo` | Main Service Filament (`seo-main`) — preferred entry |
| SEO (hash) | `/seo/{connection_hash}` | Same shared SEO DB; hash = route/session/bootstrap context only |
| Seeding | `/seeding` | Independent Seeding Filament panel |
| Tools | `/tools` | Public-ish SEO tools page (no full SEO tenant) |

Panels do **not** register Filament login pages. Guests → `/login`.

## Core responsibilities

- **Identity:** User roles `admin|owner|staff` (+ deprecated `manager`) ; SEO ranks via Spatie `seo.*` (`SeoRoleAssignment`). Hierarchy: Staff `parent_id` → Owner (`accountOwnerId()`).
- **Browser auth:** Canonical `/login`, Google OAuth, logout → `/login`; Access Hub `/workspace`.
- **Tenancy surface:** `Site` + `SiteMeta` + `SiteService` bindings (binding ≠ entitlement).
- **Service catalog:** `services` + encrypted `service_key`; child `service_database_connections` (≤1 per Service) — installation-level, not per-staff instances.
- **Addon discovery:** `AddonManager` / `AddonEnablement`.
- **Settings / Members hubs:** `App\Core\Settings\*`, `App\Core\Members\*` (contributors from addons).
- **SEO credentials:** canonical Service DB via `ServiceDatabaseConnectionResolver` (`service_database_connections`). Legacy `seo_database_connections` retired.
- **Bridge plugin updates:** GitHub Releases only. Laravel observes version; no ZIP hosting.

## Product modules

Canonical maps under `docs/modules/` (Content Projects, Publishing, Site Sync, WordPress Bridge, Article Editor, Media, SEO Audit/Keywords, Site MCP/Domains, Seeding, Prompts/AI, Agent, Automation, Contextual Help, …).

Cross-cutting: `docs/contracts/`. Ops: `docs/operations/`. Freeze: `ARCHITECTURE_FREEZE_V1.md` + ADR in `ARCHITECTURE_DECISIONS.md`.

## Runtime sketch

```text
Browser
  → Guest? → /login (credentials | Google)
  → Staff? → /workspace (Access Hub) → pick registered destination
  → Owner/admin? → /admin (or hub when navigating addons)

Authenticated Filament / React
  → HTTP (web_app logs via RuntimeLogger)
  → SeoAccessControl / SeedingAccess / AddonAuthorization
  → SEO: hash middleware → bootstrapByHash → shared omi_seo_ai
  → Seeding: ServiceDatabaseConnectionResolver → omi_seeding
  → Addon services / CommandBus / jobs

WordPress plugin
  → Bridge Bearer token APIs / Site Sync outbox
  → Laravel inbound jobs

CLI / cron / queue workers
  → default Log channels (laravel.log / queue-cron)
  → scheduled publish, sync, scoring, rank checks
```

## Data plane (summary)

| Connection | Owns |
|------------|------|
| `mysql` (default) | Users, sites, metas, `services`, `service_database_connections`, wallets, GSC OAuth masters, sessions |
| `omi_seo_ai` | Articles, projects, media, keyword/GSC/SERP facts, runs |
| `omi_seeding` | Seeding shared topics/reports (+ draft SoT = localStorage where applicable) |

No cross-DB foreign keys — logical IDs + application guards. Detail: [DATA_AND_RUNTIME_BOUNDARIES.md](DATA_AND_RUNTIME_BOUNDARIES.md).

## Non-goals of this doc

- Exhaustive route tables, every controller method, every job timeout.
- Phase handoffs and playbooks (see `docs/archive/`).
- Extension SDK method signatures (see freeze + Extension docs in archive/contracts as applicable).

## Related

- [API_AND_AUTHORIZATION.md](../contracts/API_AND_AUTHORIZATION.md)
- [DATA_AND_RUNTIME_BOUNDARIES.md](DATA_AND_RUNTIME_BOUNDARIES.md)
- [SERVICE_ARCHITECTURE.md](SERVICE_ARCHITECTURE.md)
- [ARCHITECTURE_FREEZE_V1.md](ARCHITECTURE_FREEZE_V1.md)
- [ARCHITECTURE_DECISIONS.md](ARCHITECTURE_DECISIONS.md)
- [docs/README.md](../README.md)
