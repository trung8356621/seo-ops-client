# System Overview

> Status: Canonical  
> Owner: Core + peer addons (`omnichannel-addons`)  
> Last verified: 2026-09-05  
> Supersedes: `docs/archive/maps/MAP_CORE.md`, `FEATURE_MAP_FULL.md` (high-level only — not route/controller dumps)  
> Authority: [ADDON_ARCHITECTURE.md](ADDON_ARCHITECTURE.md) · [SERVICE_ARCHITECTURE.md](SERVICE_ARCHITECTURE.md)

## Shape

Omnichannel SaaS client: **Laravel 12 / PHP 8.2+ / Filament v3 / MySQL multi-connection**.

| Plane | Path | Role |
|-------|------|------|
| **Core** | `app/Core`, `app/Filament` admin | Identity, tenancy, Service catalog (`service_key` + `ServiceDatabaseConnection`), addon discovery, Settings/Members registries |
| **Peer addons** | `addons/{slug}` → `omnichannel-addons` | SEO, content, media, WP, publishing, site-sync, AI, seeding, … |
| **Compat shell** | `seo-content-ai-compat` | Filament views/lang/panel bootstrap only — no new business |
| **WP plugin** | external `wp-seo-ai` / `omi-seo-ai-bridge` | Live WP public content + Site Sync provider |

**Addon installed ≠ Service active.** Entitlement comes from ops-server `services.apply`. Slugs in `config/addons.php` → `skip_slugs` are ignored even if discovered.

## Panels

| Panel | Mount | Audience |
|-------|-------|----------|
| Admin | `/admin` | Core admin (`AdminPanelProvider`); **Dịch vụ** `/admin/services` |
| SEO | `/seo/{connection_hash}` | SEO Filament (`SeoPanelProvider`) — connection-scoped |
| Seeding | `/seeding` | Independent Seeding Filament panel |
| Tools | `/tools` | Public-ish SEO tools page (no full SEO tenant) |

## Core responsibilities

- **Identity:** User roles `admin|owner|manager|staff` + SEO `seo_role`; hierarchy `parent_id` / `manager_id`.
- **Tenancy surface:** `Site` + `SiteMeta` + `SiteService` bindings (binding ≠ entitlement).
- **Service catalog:** `services` + encrypted `service_key`; child `service_database_connections` (≤1 per Service).
- **Addon discovery:** `AddonManager` / `AddonEnablement`.
- **Settings / Members hubs:** `App\Core\Settings\*`, `App\Core\Members\*` (contributors from addons).
- **Legacy SEO credentials:** `seo_database_connections` retained for hash-route adapters; canonical Service DB via `ServiceDatabaseConnectionResolver`.
- **Bridge plugin updates:** GitHub Releases only. Laravel observes version; no ZIP hosting.

## Product modules

Canonical maps under `docs/modules/` (Content Projects, Publishing, Site Sync, WordPress Bridge, Article Editor, Media, SEO Audit/Keywords, Site MCP/Domains, Seeding, Prompts/AI, Agent, Automation, Contextual Help, …).

Cross-cutting: `docs/contracts/`. Ops: `docs/operations/`. Freeze: `ARCHITECTURE_FREEZE_V1.md` + ADR in `ARCHITECTURE_DECISIONS.md`.

## Runtime sketch

```text
Browser / Filament / React
  → HTTP (web_app logs via RuntimeLogger)
  → Core auth + SeoAccessControl / SeedingAccess
  → Connection hash middleware bootstraps omi_seo_ai (SEO)
  → ServiceDatabaseConnectionResolver bootstraps omi_seeding (Seeding)
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
| `mysql` (default) | Users, sites, metas, `services`, `service_database_connections`, wallets, legacy `seo_database_connections`, GSC OAuth masters |
| `omi_seo_ai` | Articles, projects, media, keyword/GSC/SERP facts, runs |
| `omi_seeding` | Seeding infrastructure plane (empty / ready; business SoT = localStorage this phase) |

No cross-DB foreign keys — logical IDs + application guards. Detail: [DATA_AND_RUNTIME_BOUNDARIES.md](DATA_AND_RUNTIME_BOUNDARIES.md).

## Non-goals of this doc

- Exhaustive route tables, every controller method, every job timeout.
- Phase handoffs and playbooks (see `docs/archive/`).
- Extension SDK method signatures (see freeze + Extension docs in archive/contracts as applicable).

## Related

- [DATA_AND_RUNTIME_BOUNDARIES.md](DATA_AND_RUNTIME_BOUNDARIES.md)
- [SERVICE_ARCHITECTURE.md](SERVICE_ARCHITECTURE.md)
- [ARCHITECTURE_FREEZE_V1.md](ARCHITECTURE_FREEZE_V1.md)
- [ARCHITECTURE_DECISIONS.md](ARCHITECTURE_DECISIONS.md)
- [docs/README.md](../README.md)
