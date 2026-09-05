# Service Architecture (canonical)

**Last verified:** 2026-09-05

## A. Addon vs Service

| Concept | Meaning |
|--------|---------|
| **Addon** | Installed code/capability package under `addons/{slug}` (discovery). |
| **Service** | Ops-server-provisioned runtime entitlement/instance in Core `services`. |

**Addon installed ≠ Service active.** Discovery must not grant product entitlement.

## B. Ops-server ownership

**Only ops-server** may provision / deprovision Services (signed `services.apply`).

Client Admin **must not** expose: Create / Install / Activate / Deactivate / Delete Service.

Local `php artisan service:simulate` is a **dev fixture only** (local/testing), not a product feature.

### Control Server (`/admin/control-server`)

Connects this installation to ops-server. Does **not** manage Services.

```
Owner enters one-time Enrollment API Key
        ↓
Client POST /api/control/v1/enrollments
        ↓
raw API key discarded (never persisted)
        ↓
installation_secret encrypted locally
        ↓
ops-server sends signed services.apply
        ↓
Service rows + service_key updated
```

### Four credential concepts

| Secret | Role | Storage |
|--------|------|---------|
| Enrollment API Key | One-time connect | **Never persisted** |
| `installation_secret` | Control-channel HMAC | `client_control_state` encrypted |
| `service_key` | Per-Service provisioned secret | `services.service_key` encrypted |
| DB password | Service DB infrastructure | `service_database_connections.password` encrypted **or null** |

DB password may be empty/null (valid for local MySQL root). Canonical Admin health uses **only** `service_database_connections` (`connection_source=canonical`) — never env/legacy false-positive.

## C. `services.apply` lifecycle

```
ops-server
   │ signed command services.apply (mode=replace)
   ▼
Client Core
   ├── activate listed catalog slugs
   ├── store service_key + config
   └── deactivate omitted slugs (clear service_key)
```

Unknown slugs → `unknown_slugs` report; never load arbitrary classes.

## D. `service_key`

Canonical payload field: **`service_key`** (sibling of `slug` / `config`).

- Column: `services.service_key` (nullable during migration)
- Encrypted Eloquent cast, `$hidden`, never in `config` JSON, never Admin raw, never localStorage
- Distinct from DB password and from legacy `SiteService.settings.api_key`

Legacy snapshots without `service_key` remain accepted (existing key retained).  
Revocation (removed from active snapshot) **clears** `service_key`.

## E. Service config

`services.config` = non-secret runtime metadata (version, flags, …).  
Never store secrets here.

## F. ServiceDatabaseConnection

Table: `service_database_connections`  
Model: `App\Models\ServiceDatabaseConnection`  
Rule: **UNIQUE(service_id)** → one optional DB connection per Service.

Physical credentials (host/port/database/username/password encrypted **or null**).  
Logical Laravel name stays on `services.db_connection` (`omi_seo_ai`, `omi_seeding`).

Resolver: `App\Services\ServiceDatabaseConnectionResolver` (Core-only; no SEO/Seeding business knowledge).

Password form semantics:
- **New** + blank → intentional empty password
- **Existing** + blank → keep stored password
- `clear_password` → force null
- Form **Kiểm tra kết nối** tests the draft only (no env/legacy fallback)

## G. Service vs SiteService

- **Service** = installation-level provisioned product (ops-server snapshot).
- **SiteService** = optional binding of a provisioned Service to site/user — not the entitlement source.

## H. DB connection as child infrastructure

```
Service[seo] ──1:0..1──► ServiceDatabaseConnection ──► DB::connection('omi_seo_ai')
Service[seeding] ─1:0..1► ServiceDatabaseConnection ──► DB::connection('omi_seeding')
```

Not primary product resources. No connection list/CRUD as top-level Admin product.

## I. Admin UX

- Nav: **Dịch vụ** → `/admin/services` (read-only entitlement status)
- Detail: `/admin/services/{seo|seeding}` — status + DB upsert/test only
- Dashboard quick cards → `/seo`, `/seeding` (+ Cấu hình)
- Legacy `/admin/seo-database-connections` and `/admin/seeding-database-connections` redirect to Service detail

## J. DB planes

| Plane | Logical | Physical |
|-------|---------|----------|
| Core | `mysql` / `core_connection` | `omi_client` |
| SEO Service | `omi_seo_ai` | via ServiceDatabaseConnection |
| Seeding Service | `omi_seeding` | via ServiceDatabaseConnection |

## K. Security rules

1. Only ops-server provisions Services.  
2. Client cannot create/activate entitlement.  
3. Addon installed ≠ Service active.  
4. ≤1 DB connection per Service.  
5. DB connection ≠ product identity.  
6. `service_key` never in JSON config.  
7. `service_key` and DB password encrypted separately.  
8. Core owns Service + ServiceDatabaseConnection.  
9. Addons own business models/migrations/maintenance.  
10. No new addon-specific DB connection models.  
11. SiteService is binding only.  
12. Logical name on `services.db_connection`.

## L. Local/testing fixture policy

Local/testing may seed fake `service_key` values (migration / `service:simulate`).  
Never show raw keys in UI. Not a production entitlement path.

## Diagram

```
ops-server
   │
   │ signed services.apply
   ▼
Client Core
   │
   ├── Service SEO (slug seo-content-ai|seo)
   │      ├── service_key
   │      └── DB → omi_seo_ai
   │
   └── Service Seeding (slug seeding)
          ├── service_key
          └── DB → omi_seeding
```

## Legacy debt (retained)

- `seo_database_connections` + pivots (hash routes / owner mapping) — read/adapters; drop later  
- `seeding_database_connections` — obsolete after migrate; drop later  
- SEO Export/Import/Run migrations remain SEO-specific (future contributor on Service page)

## Related

- [SEEDING.md](../modules/SEEDING.md)
- [ADDON_ARCHITECTURE.md](ADDON_ARCHITECTURE.md)
- [FINAL_DATABASE_ARCHITECTURE.md](FINAL_DATABASE_ARCHITECTURE.md)
- [DB_REPOSITORY_OWNERSHIP.json](DB_REPOSITORY_OWNERSHIP.json)
- [NEW_AGENT_HANDOFF.md](NEW_AGENT_HANDOFF.md)
