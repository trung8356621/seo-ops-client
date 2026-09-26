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

## Authentication

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

## Base path

```text
/api/v1/services/{service}
```

Canonical SEO public slug: `seo`.

## Endpoints

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/mcp` | Root AI discovery manifest (`seo.mcp.router.v1`) |
| `GET` | `/mcp/{router}` | One router + resolved parts metadata |
| `POST` | `/mcp/{router}/read` | Selective multi-part read (`seo.mcp.router.read.v1`) |

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

## Site input

- Required: positive integer `site_id`.
- Validated against Core `sites` table existence.
- No domain lookup / connection-hash bypass in this adapter.

## Views / sections

| Concept | Role |
|---------|------|
| Router | Capability area |
| Part | Context unit to load |
| `sections` | Optional allowlisted subset inside `keywords.relationship` |
| View | `summary` / `standard` / `detail` |

Relationship section allowlist: `keyword`, `topics`, `focus_articles`, `related_keywords`, `internal_links`, `gsc`, `meta`.

## Status codes / errors

Canonical envelope:

```json
{ "error": { "code": "service_api_…", "message": "…" } }
```

| Status | Codes (examples) | When |
|--------|------------------|------|
| 401 | `service_api_unauthorized` | Missing/invalid bearer |
| 403 | `service_api_forbidden`, `service_api_scope_denied`, `service_api_service_inactive` | Revoked/expired/scope/service mismatch/inactive |
| 404 | `service_api_not_found` | Unknown router (or unknown service at auth) |
| 422 | `service_api_validation_failed` | Unknown part/view/param, missing required, invalid sections/site/format/body |

ContextRegistry remains the validation authority for views/parameters.

## Ownership

| Layer | Owns |
|-------|------|
| Core | Auth, `ServiceApiContext`, error envelope, rate limit, `routes/api-services.php` transport hook |
| SEO addon | `SeoMcpController`, `EnsureSeoServiceApi`, MCP routes under `seo/routes/api-services.php` |

## Flow

```text
External Agent / Tool
        ↓
Bearer service_api_credentials (mcp:read)
        ↓
EnsureSeoServiceApi
        ↓
McpRouterRegistry / McpRouterReader
        ↓
ContextRegistry::format
        ↓
JSON (or Markdown manifest)
```

Monthly MCP and Agent Workspace are **not** on this path.

## Checklist

- [x] Routes registered under Service API foundation  
- [x] Auth + `mcp:read` + SEO service gate  
- [x] Root / router / selective read live  
- [x] Docs match controllers  
