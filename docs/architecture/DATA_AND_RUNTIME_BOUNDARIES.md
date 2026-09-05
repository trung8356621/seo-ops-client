# Data and Runtime Boundaries

> Status: Canonical  
> Owner: Core + peer addons  
> Last verified: 2026-09-05  
> Supersedes: scattered MAP notes on multi-DB, logging, and addon isolation (durable rules only)  
> Related: [SERVICE_ARCHITECTURE.md](SERVICE_ARCHITECTURE.md) · [FINAL_DATABASE_ARCHITECTURE.md](FINAL_DATABASE_ARCHITECTURE.md)

## 1. Database connections

| Name | Config source | Typical owners |
|------|---------------|----------------|
| **`mysql`** (default) | Core `.env` | `User`, `Site`, `SiteMeta`, `Service`, `ServiceDatabaseConnection`, wallets/orders, legacy `SeoDatabaseConnection`, `ApiConnection`, GSC **OAuth** masters |
| **`omi_seo_ai`** | Canonical: `service_database_connections` via `ServiceDatabaseConnectionResolver`; legacy hash adapters: `seo_database_connections` + Search Foundation bootstrap | Articles, projects, media, keyword/SERP/GSC **facts**, runs, prompt results |
| **`omi_seeding`** | Canonical: `service_database_connections` (+ env `SEEDING_DB_*` fallback for local); never `omi_seo_ai` | Seeding infrastructure plane — business workspace = localStorage this phase |

Rules:

- Service DB credentials are **not** in addon.json. Prefer Core `service_database_connections` (1:1 with Service).
- No FK constraints across connections — store scalar IDs; enforce in Eloquent/app.
- SEO addon migrations target `omi_seo_ai` after bootstrap; Seeding ownership → `omi_seeding` via `config/addon_migration_ownership.php`.
- Other addons may use `RegistersAddonDatabase` + addon.json — do not assume they share `omi_seo_ai`.

## 2. Service + SEO connection bootstrap

**Canonical (Service infrastructure):** `App\Services\ServiceDatabaseConnectionResolver` — resolve/upsert/test/health for SEO + Seeding logical names. Admin: `/admin/services/{seo|seeding}`.

**Legacy SEO hash panel:** `Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService`  
Constant runtime name: `omi_seo_ai` (overridable via config).

| Method (Search Foundation) | Use |
|--------|-----|
| `bootstrapByHash(string $hashId)` | Panel/URL connection hash → active `SeoDatabaseConnection` |
| `bootstrapFromConnection(SeoDatabaseConnection)` | Already-loaded credential row |
| `bootstrapByConnectionId(int)` | By PK |
| `bootstrapBySiteId(int)` / `bootstrapLegacySharedConnection()` | Site-bound / shared paths |

Request path: SEO panel middleware must run before SEO models query.  
Seeding: `SeedingDatabaseConnectionService::bootstrap()` on provider boot (failures soft — health endpoints report).

Legacy Admin list URLs for SEO/Seeding DB connections redirect to Service detail pages.

## 3. Addon boundary

| Peer addons (`omnichannel-addons`) | Core (`omnichannel-client`) |
|----------------------------------|----------------|
| Business Filament pages, models, migrations, jobs, React islands | Auth users, sites, billing, Service catalog + DB credentials, addon registry, Settings/Members registries |
| Capability / command / DTO cross-addon | `bootstrap/app.php` only for true app middleware / narrow CSRF |

`seo-content-ai-compat` = views/lang/panel bootstrap only — no new business.

`config/addons.php` `skip_slugs`: discovered folders still skipped (e.g. retired `wp-headless`).

## 4. Logging — web vs cron

Production: cron/root often owns `storage/logs/laravel.log` (+ `queue-cron.log`, `watchdog.log`). PHP-FPM (`www`) writing default channel → `Permission denied` / 500.

| Runtime | API | File |
|---------|-----|------|
| HTTP / Livewire / Filament / browser REST / editor | `App\Support\RuntimeLogger` or `Log::channel('web_app')` | `storage/logs/web-app-YYYY-MM-DD.log` |
| CLI / cron / queue / artisan / watchdog | `Log::` default / job channels | `laravel.log`, `queue-cron.log`, … |

### Forbidden on HTTP paths

- `Log::info/warning/error/debug` on default channel
- `logger()->…`
- Bare `report($e)` in web controllers — use `RuntimeLogger::report`
- Setting `LOG_CHANNEL=web_app` in `.env` (breaks cron)
- `chown` / delete / rename root-owned `laravel.log` as “fix”
- Fallback from `web_app` to `single`/`laravel.log` on write failure

### Wiring

- `config/logging.php` → `channels.web_app` (daily; `WEB_APP_LOG_LEVEL` / `WEB_APP_LOG_DAYS`)
- `AppServiceProvider` HTTP boot may set `logging.default=web_app` when channel exists (stale config cache → skip)
- `bootstrap/app.php` exceptions: HTTP `RuntimeLogger::report` then `return false` (block default log)
- Missing `web_app` channel → RuntimeLogger uses `null` driver (no EMERGENCY spam to laravel.log)

Ops: `tail -f storage/logs/web-app-$(date +%F).log` for editor/panel errors.

## 5. Queue / worker boundary

- Prefer named queues (`seo`, `media_generation`) where jobs declare them — workers must listen those names.
- No Filament Queue Manager pause/resume UI (removed).
- Scheduler/cron ownership for publish: single artisan shell — see Publishing + `QUEUE_SCHEDULER_AND_IDEMPOTENCY` contracts.
- Jobs are process-isolated: they must bootstrap SEO connection themselves when not inherited.

## 6. AuthZ boundary (SEO)

- `SeoAccessControl` is the SEO panel gate SoT (effective role, site scope, mutate, read-only admin).
- Global site cookie scopes **lists/dashboards**, not detail authorization.
- Agent/MCP: opaque public refs + tenant guards — no raw numeric ID leakage on keyword/CP public surfaces.

## 7. Related documents

- [SYSTEM_OVERVIEW.md](SYSTEM_OVERVIEW.md)
- [SERVICE_ARCHITECTURE.md](SERVICE_ARCHITECTURE.md)
- [ARCHITECTURE_FREEZE_V1.md](ARCHITECTURE_FREEZE_V1.md)
- [ARCHITECTURE_DECISIONS.md](ARCHITECTURE_DECISIONS.md)
- Module maps under `docs/modules/`
- `.cursor/rules/web-app-logging.mdc` (editor rule mirror)
