# API and Authorization Contracts

> Status: Canonical  
> Owner: Core (auth) + peer addons (panel authorization)  
> Last verified: 2026-09-22  
> Supersedes: auth slices formerly scattered across MAP_SEO_TEAM, CONTENT_PROJECT_APPLICATION_API, Agent security satellites; multi-panel Filament login pages (retired 2026-09-22)

## 1. Purpose

Public HTTP / Sanctum / Filament access boundaries for SEO + Seeding product surfaces. Complements Agent confirmation rules in [AGENT_AND_MCP_CONTRACTS.md](AGENT_AND_MCP_CONTRACTS.md).

## 2. Authentication SSOT (browser)

**Invariant:** one canonical `User` · one `web` session guard · one login flow.

| Concern | Canonical | Notes |
|---------|-----------|-------|
| Login UI | `GET /login` | `App\Http\Controllers\Auth\LoginController` + `resources/views/auth/login.blade.php` |
| Login submit | `POST /login` (`login.store`) | Rate-limited via `LoginRequest`; forgets `url.intended` |
| Google OAuth | `/auth/google` (+ callback) | `GoogleController` → same `PostLoginRedirector` (no return_url / intended) |
| Logout | `POST /logout` + Filament panel `*/logout` | Filament logout response → `/login` (`CanonicalLogoutResponse`) |
| Guest redirect | `bootstrap/app.php` → `route('login')` | All panels / addon paths |

**Legacy panel login URLs** (bookmarks only) → **302 `/login`:**

- `/admin/login`, `/seeding/login`, `/tools/login`
- `/seo/login`, `/seo/{connection_hash}/login`

**Do not:**

- Add per-addon Filament `->login()` pages or SEO/Seeding-owned auth controllers
- Create seo/seeding/addon guards
- Preserve hash / intended / return_url across login
- Treat `connection_hash` as a second identity plane

**Post-login destinations** (`App\Services\Auth\PostLoginRedirector`):

| Role | Destination |
|------|-------------|
| Staff (and legacy manager) | `/workspace` |
| Owner / admin | `/admin` |
| Other | `/workspace` |

Admin Filament **registration / password-reset / email-verification** remain under `/admin/...` (not duplicate login).

## 3. Access Hub (`/workspace`)

Authenticated launcher only — not an admin panel.

- Route: `GET /workspace` (`workspace.hub`) → `WorkspaceHubController`
- Registry: `App\Core\Workspace\WorkspaceDestinationRegistry`
- Addons register cards (SEO, Seeding, Tools, …) via `WorkspaceDestination` — **Core does not hard-code SEO/Seeding cards**
- Visibility: `WorkspaceDestination::allows($user)` / panel access closures

Staff hitting `/admin` home are redirected to `/workspace` (`RedirectStaffFromAdminPanel`).

## 4. Surfaces (authorization after auth)

| Surface | Auth | Notes |
|---------|------|-------|
| Filament SEO short `/seo/…` | Session + `SeoAccessControl` | Main Service context via `ResolveSeoMainServiceContext` |
| Filament SEO hash `/seo/{connection_hash}/…` | Session + hash bootstrap | Hash = route/session/bootstrap context only → canonical shared `omi_seo_ai` |
| Filament Seeding `/seeding` | Session + `SeedingAccess` / Service active | No panel-owned login |
| Filament Admin `/admin` | Session; owner/admin panel access | Staff blocked from admin home → hub |
| Filament Tools `/tools` | Session | No panel-owned login |
| Seeding APIs `/api/seeding/bootstrap\|health` | Session auth | Namespace only — no topic CRUD on canonical UI |
| Agent MCP HTTP `/api/v1/agent/mcp/*` | Sanctum | Read token cannot write |
| Agent execute `/api/v1/agent/execute` | Sanctum | Capability + confirmation gated |
| WordPress bridge REST | Site token / HMAC (bridge) | See [WORDPRESS_BRIDGE.md](../modules/WORDPRESS_BRIDGE.md) |
| Site Sync inbound callback | Signed callback | See [SITE_SYNC.md](../modules/SITE_SYNC.md) |
| Admin Service pages `/admin/services*` | Admin session | Read entitlement + DB upsert only — no Create/Activate |

### Failure policy (SEO context)

| Condition | Result |
|-----------|--------|
| Guest on protected addon/admin route | → `/login` |
| Authenticated but no SEO panel access / invalid hash / ACL fail | → `/workspace` (not addon login, not silent fallback to another hash) |

## 5. Account scope (owner / staff)

Unchanged business model (not multi-instance staff↔service assignment):

| Concept | Source |
|---------|--------|
| Account owner | `User::accountOwnerId()` — Owner self, Staff → `parent_id` |
| Staff membership | `users.parent_id` → Owner |
| SEO panel entry | Owner always; Staff needs `parent_id` + Spatie `seo.*` (`SeoRoleAssignment`) |
| Coarse addon gate | `App\Core\Permissions\AddonAuthorization` |
| Sites | `SiteAccess` — sites of account owner |
| Service catalog | Installation-level `services` + `ServiceIdentity` (SEO / Seeding) — **not** per-staff service instances |

## 6. Tenant and connection

- SEO models use runtime connection `omi_seo_ai` — canonical via Core `service_database_connections` + `ServiceDatabaseConnectionResolver` (Search Foundation adapter for panel bootstrap).
- `bootstrapByHash($hash)` stamps hash onto the **canonical shared** Service DB adapter — it does **not** select a separate tenant DB by hash.
- Seeding logical connection `omi_seeding` via Core `ServiceDatabaseConnection` (never SEO DB).
- Fail closed when connection hash / site scope missing or mismatched → hub (see §4).
- Non-admin Filament queries must scope by permitted owner/site/domain (`SeoAccessControl` / policies).
- **Addon installed ≠ Service active** — see [SERVICE_ARCHITECTURE.md](../architecture/SERVICE_ARCHITECTURE.md).

## 7. Capability vs UI permission

- Filament role/menu visibility ≠ Agent Gateway write authority.
- Writes go `CanonicalCapabilityRegistry` → policy → confirmation → CommandBus.
- Wildcard UI scopes do not bypass Gateway enforcement.

## 8. Confirmation and destructive actions

- Confirm-required writes need one-time preview token (hash stored only).
- Archive / destroy workspace / force full rebuild: elevated confirmation; Agent never auto-confirms.
- Dry-run must not mutate durable domain state.

## 9. Secrets and logging

- Never log tokens, passwords, API keys, Authorization headers, or full sensitive payloads.
- HTTP paths use `RuntimeLogger` / `web_app` channel — not default `laravel.log` (see [DATA_AND_RUNTIME_BOUNDARIES.md](../architecture/DATA_AND_RUNTIME_BOUNDARIES.md)).

## 10. Forbidden

- Cross-tenant reads/writes via raw IDs without scope checks.
- Exposing numeric internal IDs as Agent/MCP public refs.
- CSRF exceptions outside narrowly scoped webhook/callback routes.
- Treating archive docs or addon README stub as auth SoT.
- Reintroducing per-addon login pages or second session guards for browser auth.

## 11. Related documents

- [SYSTEM_OVERVIEW.md](../architecture/SYSTEM_OVERVIEW.md)
- [DATA_AND_RUNTIME_BOUNDARIES.md](../architecture/DATA_AND_RUNTIME_BOUNDARIES.md)
- [AGENT_AND_MCP_CONTRACTS.md](AGENT_AND_MCP_CONTRACTS.md)
- [EXTENSION_AND_REGISTRY_CONTRACTS.md](EXTENSION_AND_REGISTRY_CONTRACTS.md)
- [SITE_MCP_AND_DOMAINS.md](../modules/SITE_MCP_AND_DOMAINS.md)
- [WORDPRESS_BRIDGE.md](../modules/WORDPRESS_BRIDGE.md)
- [ARCHITECTURE_FREEZE_V1.md](../architecture/ARCHITECTURE_FREEZE_V1.md)
