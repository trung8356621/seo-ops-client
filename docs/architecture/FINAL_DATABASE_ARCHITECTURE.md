# Final Database Architecture (Client cutover)

> Status: Canonical after 2026-08-10 DB plane cutover (+ 2026-09-05 Service / Seeding planes)  
> Last verified: 2026-09-05  
> Related: [ADDON_ARCHITECTURE.md](ADDON_ARCHITECTURE.md) · [SERVICE_ARCHITECTURE.md](SERVICE_ARCHITECTURE.md) · [NEW_AGENT_HANDOFF.md](NEW_AGENT_HANDOFF.md) · [DB_REPOSITORY_OWNERSHIP.json](DB_REPOSITORY_OWNERSHIP.json)

## Planes

| Plane | Connection | Physical DB | Role |
|-------|------------|-------------|------|
| Client Core | `mysql` (`database.default` / `core_connection`) | **`omi_client`** | Users, billing, sites, **Service catalog + ServiceDatabaseConnection**, queue/cache, automation |
| SEO Service | `omi_seo_ai` | **`omi_seo_ai`** (via ServiceDatabaseConnection) | Articles, keywords, prompts, media, projects, link maps, WP sync state |
| Seeding Service | `omi_seeding` | **`omi_seeding`** (via ServiceDatabaseConnection) | Infrastructure plane; business workspace = localStorage this phase |
| WP Headless | `wp_headless` | addon-local | Headless templates/sites |

Canonical: one Service → at most one `service_database_connections` row. Legacy `seo_database_connections` retained for hash-route adapters until explicit drop.

## Retired

- **`omi_channel`** — retired; renamed to `omi_channel__pre_client_split_backup`. Not a runtime connection target.
- Core table **`frontend_projects`** — dropped (zero consumers).
- Core table **`seo_connection_sites`** — replaced by `seo_connection_users` (historical migration only).
- SEO dead tables dropped: `tags`, `entities`, `entity_results`, `seo_settings`, `seo_domain_metas`, `domain_global_cta_settings`, `user_workspace_settings`, `seo_prompt_templates`, `seo_generated_images`, legacy `seo_links` / `keyword_link`.
- SEO copies of **`automation_*`** + **`business_events`** — dropped; SoT is core/`omi_client`.

## Automation

| Item | Value |
|------|--------|
| Runtime connection | `AUTOMATION_DB_CONNECTION=mysql` (default; never fall back to `omi_seo_ai`) |
| Tables | `business_events`, `automation_*` on core |
| Legacy upgrade CLI | `php artisan automation:migrate-to-core` — **LEGACY UPGRADE ONLY** after cutover |

## Link SoT

- Canonical write/read: **`seo_link_maps`** (via `ArticleLinkContextMapService` / reconcile services).
- Legacy models `SeoLink` / `KeywordLink` and tables `seo_links` / `keyword_link` are **deleted**; do not recreate. Keyword volume/difficulty SoT is `KeywordMetaRepository` (`getSiteSearchVolume` / `getSiteDifficulty`).

## Ownership inventory

- Shell map: `config/database_table_ownership.php`
- Compat declarations: `SeoContentAiServiceProvider::databaseTableOwnership()`
- Repo ↔ migration paths: `DB_REPOSITORY_OWNERSHIP.json`

## Safety

- Protected DBs: `omi_client`, `omi_seo_ai` — never `migrate:fresh` / destroy.
- Verify: `php artisan refactor:migrate --verify --via-mysql`
- Disposable only: `*_test`
