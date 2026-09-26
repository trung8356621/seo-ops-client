# SEO Service API

> Status: Canonical (implemented)  
> Owner: Core Service API foundation + `seo` addon HTTP adapter  
> Last verified: 2026-09-26  
> Application contract SoT: [`SEO_MCP_ROUTER.md`](../modules/SEO_MCP_ROUTER.md), [`CONTEXT_GATEWAYS.md`](../contracts/CONTEXT_GATEWAYS.md)  
> Auth SoT: [`API_AND_AUTHORIZATION.md`](../contracts/API_AND_AUTHORIZATION.md), [`SERVICE_ARCHITECTURE.md`](../architecture/SERVICE_ARCHITECTURE.md)

## Separation rule

| Document | Owns |
|----------|------|
| `docs/modules/SEO_MCP_ROUTER.md` | MCP routers, parts, manifest, selective read semantics |
| `docs/contracts/CONTEXT_GATEWAYS.md` | Context slices, registry, views, projection |
| **This file (`docs/api/**`)** | HTTP routes, auth, status codes, request/response wire format |

Do **not** put HTTP/auth details into MCP architecture docs.

## Two access modes

| Mode | Auth | Who | Site scope |
|------|------|-----|------------|
| **A. Permanent Service API** | Bearer `service_api_credentials` | Developers / integrations | Client sends `site_id` on POST read |
| **B. Temporary MCP capability** | Opaque URL token (15 min) | Agent / model | Site bound at mint; no `site_id` on reads |

Permanent API key stays **server/runtime-only**. Models receive only the temporary `access_url`.

---

## A. Permanent Service API

### Authentication

| Concern | Rule |
|---------|------|
| Plane | Core `service_api_credentials` via `AuthenticateServiceApi` |
| Header | `Authorization: Bearer svc_live_…` |
| Scope | **`mcp:read`** (required; `service:read` is insufficient) |
| Wildcard | Scope `*` allows MCP |
| Rate limit | `throttle:service-api` (credential-keyed) |
| Not used | `services.service_key`, `SiteService.settings.api_key`, session, Sanctum, legacy Agent auth |

Public/service APIs authenticate with **`service_api_credentials` only**. Ops-server `service_key` remains a separate provisioning secret.

### Service isolation

- Credential must belong to the route `{service}` (Core middleware).
- SEO MCP additionally requires the Service to be **SEO** (`seo` / `seo-content-ai`). A Seeding credential with `mcp:read` cannot call SEO MCP.

### Base path

```text
/api/v1/services/{service}
```

Canonical SEO public slug: `seo`.

### Endpoints

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/mcp` | Root AI discovery manifest (`seo.mcp.router.v1`) |
| `GET` | `/mcp/{router}` | One router + resolved parts metadata |
| `POST` | `/mcp/{router}/read` | Selective multi-part read (`seo.mcp.router.read.v1`) |
| `POST` | `/mcp/access` | Mint temporary site-bound MCP URL |

Middleware stack:

```text
service.api
throttle:service-api
EnsureSeoServiceApi
service.api.scope:mcp:read
```

### Root discovery

```http
GET /api/v1/services/seo/mcp
Authorization: Bearer svc_live_…
```

```json
{
  "data": {
    "schema": "seo.mcp.router.v1",
    "routers": [ /* site, content, seo, publishing, keywords, gsc */ ]
  }
}
```

Optional Markdown (AI/manual):

```http
GET /api/v1/services/seo/mcp?format=markdown
```

Also accepts `Accept: text/markdown`. Allowed `format`: `json` (default), `markdown`. Unknown format → `422`.

### Router discovery

```http
GET /api/v1/services/seo/mcp/keywords
Authorization: Bearer svc_live_…
```

Returns one resolved router (views/params inherited from Context definitions). Unknown router → `404`.

### Selective read

```http
POST /api/v1/services/seo/mcp/keywords/read
Authorization: Bearer svc_live_…
Content-Type: application/json
```

```json
{
  "site_id": 123,
  "parts": {
    "relationship": {
      "view": "summary",
      "parameters": {
        "keyword_ref": "keyword:123",
        "sections": ["keyword", "topics", "focus_articles"]
      }
    }
  }
}
```

Allowed body fields: **`site_id`**, **`parts`** only. Unknown fields → `422`.

```json
{
  "data": {
    "schema": "seo.mcp.router.read.v1",
    "router": "keywords",
    "scope": { "site_ref": "site:123" },
    "parts": {
      "relationship": {
        "key": "keywords.relationship",
        "scope": { "site_ref": "site:123" },
        "generated_at": "…",
        "source_updated_at": null,
        "stale": false,
        "available": true,
        "data": { }
      }
    }
  }
}
```

Parts are never flattened. Unselected parts are absent. Context formatter envelopes are preserved (`available`, `stale`, `source_updated_at`, `generated_at`, `scope`).

### Site input (permanent)

- Required: positive integer `site_id`.
- Validated against Core `sites` table existence.
- No domain lookup / connection-hash bypass in this adapter.

---

## B. Temporary site-bound MCP access

Intended for Agent/model usage. Mint with the permanent credential; hand the model only the temporary URL.

### Mint

```http
POST /api/v1/services/seo/mcp/access
Authorization: Bearer svc_live_…
Content-Type: application/json

{ "site_id": 123 }
```

Allowed body field: **`site_id`** only.

```json
{
  "data": {
    "access_url": "https://host/api/v1/mcp/access/mcp_tmp_…",
    "expires_at": "2026-09-26T12:15:00+00:00",
    "site_ref": "site:123"
  }
}
```

| Rule | Detail |
|------|--------|
| TTL | **900 seconds (15 minutes)**, server-controlled |
| Storage | Laravel Cache only — hashed token; raw token never persisted |
| Binding | `service_id` + `site_id` + source `credential_id` + `mcp:read` |
| Scopes | `mcp:read` only (no write) |
| Response | Raw temporary token appears **only** inside `access_url` |
| Headers | `Cache-Control: no-store` |

### Temporary endpoints

No `Authorization` header. Auth = opaque path token via `ResolveTemporaryMcpAccess`.

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/api/v1/mcp/access/{token}` | Root manifest + self/router `href`s + `site_ref` |
| `GET` | `/api/v1/mcp/access/{token}/{router}` | Router metadata + `self` / `read.href` |
| `POST` | `/api/v1/mcp/access/{token}/{router}/read` | Selective read (**no `site_id`**) |

Rate limit: `throttle:temporary-mcp` (keyed by temporary lookup id after resolve).

Middleware:

```text
ResolveTemporaryMcpAccess
throttle:temporary-mcp
```

### Temporary root

```http
GET /api/v1/mcp/access/mcp_tmp_…
```

```json
{
  "data": {
    "schema": "seo.mcp.router.v1",
    "site_ref": "site:123",
    "self": "/api/v1/mcp/access/mcp_tmp_…",
    "routers": [
      { "key": "keywords", "href": "/api/v1/mcp/access/mcp_tmp_…/keywords", "…": "…" }
    ]
  }
}
```

Markdown: `?format=markdown` or `Accept: text/markdown` includes `Site: site:123` near the top.

### Temporary router

```http
GET /api/v1/mcp/access/mcp_tmp_…/keywords
```

Adds transport links (`self`, `read.method` / `read.href`) without changing internal MCP registry schema.

### Temporary selective read

```http
POST /api/v1/mcp/access/mcp_tmp_…/keywords/read
Content-Type: application/json

{
  "parts": {
    "relationship": {
      "view": "summary",
      "parameters": {
        "keyword_ref": "keyword:123",
        "sections": ["keyword", "topics", "focus_articles"]
      }
    }
  }
}
```

Allowed body field: **`parts` only**. Including `site_id` → `422` unknown field. Site comes from the temporary token binding — the model cannot switch sites.

Response shape matches permanent selective read (`seo.mcp.router.read.v1`); `scope.site_ref` equals the bound site.

### Temporary failure modes

| Condition | Result |
|-----------|--------|
| Missing / wrong / expired token | `401` `service_api_temporary_access_invalid` |
| SEO Service inactive after mint | Same `401` (re-checked on each request) |
| Unknown router | `404` |
| `site_id` in temporary read body | `422` |

---

## Views / sections

| Concept | Role |
|---------|------|
| Router | Capability area |
| Part | Context unit to load |
| `sections` | Optional allowlisted subset inside `keywords.relationship` |
| View | `summary` / `standard` / `detail` |

Relationship section allowlist: `keyword`, `topics`, `focus_articles`, `related_keywords`, `internal_links`, `gsc`, `meta`.

MCP Router / ContextRegistry roles are unchanged; temporary access is an HTTP façade only.

## Status codes / errors

Canonical envelope:

```json
{ "error": { "code": "service_api_…", "message": "…" } }
```

| Status | Codes (examples) | When |
|--------|------------------|------|
| 401 | `service_api_unauthorized` | Missing/invalid permanent bearer |
| 401 | `service_api_temporary_access_invalid` | Invalid/expired temporary token (or inactive SEO after mint) |
| 403 | `service_api_forbidden`, `service_api_scope_denied`, `service_api_service_inactive` | Revoked/expired/scope/service mismatch/inactive |
| 404 | `service_api_not_found` | Unknown router (or unknown service at auth) |
| 422 | `service_api_validation_failed` | Unknown part/view/param, missing required, invalid sections/site/format/body |

ContextRegistry remains the validation authority for views/parameters.

## Ownership

| Layer | Owns |
|-------|------|
| Core | Auth, `ServiceApiContext`, temporary MCP token manager/resolver, error envelope, rate limits, route hooks |
| SEO addon | `SeoMcpController`, `TemporaryMcpAccessController`, `EnsureSeoServiceApi`, MCP routes |

## Flow

```text
Permanent:
  Bearer service_api_credentials (mcp:read)
        ↓
  EnsureSeoServiceApi
        ↓
  McpRouterRegistry / McpRouterReader
        ↓
  ContextRegistry::format

Temporary:
  POST …/mcp/access { site_id }  (permanent Bearer)
        ↓
  Cache-backed mcp_tmp_… token (15m, site-bound)
        ↓
  GET/POST /api/v1/mcp/access/{token}…  (no Bearer)
        ↓
  Same McpRouterRegistry / McpRouterReader / ContextRegistry
```

Monthly MCP and Agent Workspace are **not** on this path.

## Checklist

- [x] Permanent routes under Service API foundation  
- [x] Auth + `mcp:read` + SEO service gate  
- [x] Root / router / selective read live  
- [x] Temporary site-bound MCP access (mint + capability URLs)  
- [x] Docs match controllers  
