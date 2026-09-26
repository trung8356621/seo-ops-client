# SEO Access API

> Status: Canonical (implemented)  
> Owner: Core temporary Service Access + `seo` addon HTTP adapter  
> Last verified: 2026-09-26  
> Write contract (separate): [`CONTENT_PROJECT_SERVICE_API.md`](CONTENT_PROJECT_SERVICE_API.md)  
> Internal composition (not public): [`SEO_MCP_ROUTER.md`](../modules/SEO_MCP_ROUTER.md), [`CONTEXT_GATEWAYS.md`](../contracts/CONTEXT_GATEWAYS.md)

## Purpose

Unified **site-bound SEO read plane** for external Agents / integrations.

External callers MUST NOT need to understand MCP, ContextRegistry, routers, parts, ContextSlice, or addon ownership. Those remain internal.

```text
1. GET  /api/v1/services/seo/access          → list sites
2. POST /api/v1/services/seo/access          → mint temporary URL
3. GET  /api/v1/access/{token}               → resource index
4. GET  /api/v1/access/{token}/{resource}    → read SEO context
5. POST /api/v1/services/seo/content-projects/draft/intake  → only Agent write
```

## Authentication planes

| Mode | Auth | Scope | Site |
|------|------|-------|------|
| **A. Permanent Service API** | `Authorization: Bearer svc_live_…` (`service_api_credentials`) | **`seo:read`** | List / mint |
| **B. Temporary Access** | Opaque path token only (no Bearer) | Bound as `seo:read` at mint | Fixed at mint |

Permanent API key stays **server/runtime-only**. Models receive only the temporary `access_url`.

Wildcard scope `*` grants `seo:read`. Scope `mcp:read` does **not** authorize this contract.

Rate limits:

- Permanent: `throttle:service-api`
- Temporary: `throttle:temporary-access`

Middleware (permanent):

```text
service.api
throttle:service-api
EnsureSeoServiceApi
service.api.scope:seo:read
```

## A. Service Access Index

```http
GET /api/v1/services/seo/access
Authorization: Bearer svc_live_…
```

```json
{
  "data": {
    "service": "seo",
    "sites": [
      {
        "site_ref": "site:7",
        "domain": "example.com",
        "title": "Example"
      }
    ],
    "access": {
      "method": "POST",
      "href": "/api/v1/services/seo/access"
    }
  }
}
```

- Compact Site rows only — no credentials, no Site configuration dump.
- `title` may be `null` when unset.

## B. Mint temporary site-bound access

```http
POST /api/v1/services/seo/access
Authorization: Bearer svc_live_…
Content-Type: application/json

{ "site_id": 7 }
```

Allowed body field: **`site_id` only**.

```json
{
  "data": {
    "access_url": "https://host/api/v1/access/access_tmp_…",
    "site_ref": "site:7",
    "expires_at": "2026-09-26T12:15:00+00:00"
  }
}
```

| Rule | Detail |
|------|--------|
| TTL | **900 seconds (15 minutes)** |
| Storage | Laravel Cache — hashed token only; raw token never persisted |
| Binding | `service_id` + `site_id` + source `credential_id` + `seo:read` |
| Response | Raw temporary token appears **only** inside `access_url` |
| Headers | `Cache-Control: no-store` |

## C. Temporary Access root

```http
GET /api/v1/access/{token}
```

No `Authorization` header.

```json
{
  "data": {
    "schema": "seo.access.v1",
    "site_ref": "site:7",
    "site": { "domain": "example.com", "title": "Example" },
    "resources": [
      { "key": "site", "description": "…", "href": "/api/v1/access/{token}/site" },
      { "key": "content", "description": "…", "href": "/api/v1/access/{token}/content" },
      { "key": "keywords", "description": "…", "href": "/api/v1/access/{token}/keywords" },
      { "key": "gsc", "description": "…", "href": "/api/v1/access/{token}/gsc" }
    ]
  }
}
```

Canonical public resources: **site**, **content**, **keywords**, **gsc** only.

Not exposed: MCP, routers, parts, indexability, inventory, publishing, seo findings, full-site internal links, sync, health.

## D. Site resource

```http
GET /api/v1/access/{token}/site
```

Schema: `seo.access.site.v1`

Curated website knowledge (official Knowledge Profile preferred; draft Site MCP fills gaps only):

- `identity` — domain, site_title, website_type, brand, company_short_identity, short_description, cms
- `writing_context` — tone, business_summary, cta_instructions
- `contact`
- `important_pages`

`cms` resolves from Site Sync capability metadata when available; otherwise `null` (never invented).

**Not exposed:** local article `is_indexable` / indexability counters (not Google index coverage).

## E. Content resource

```http
GET /api/v1/access/{token}/content
```

Schema: `seo.access.content.v1`

High-signal **distribution only** (posts / pages / categories / products / product_categories / other).

**Not exposed:** content inventory totals or published/draft/scheduled/private workflow counts.

## F. Keywords resource

### Site landscape

```http
GET /api/v1/access/{token}/keywords
```

Schema: `seo.access.keywords.v1` — compact site Keyword Landscape.

### One-keyword relationship (read-only POST)

```http
POST /api/v1/access/{token}/keywords
Content-Type: application/json

{
  "keyword_ref": "keyword:123",
  "sections": ["keyword", "topics", "focus_articles", "gsc", "internal_links"]
}
```

Aliases: `keyword_id` accepted.

Section allowlist: `keyword`, `topics`, `focus_articles`, `related_keywords`, `internal_links`, `gsc`, `meta`.

No `router` / `part` / `view` fields.

## G. GSC resource

```http
GET /api/v1/access/{token}/gsc
GET /api/v1/access/{token}/gsc?period=2026-08&include=performance,opportunities
```

Optional read-only POST for complex filters:

```http
POST /api/v1/access/{token}/gsc
{ "period": "2026-08", "include": ["performance", "opportunities", "cannibalization"] }
```

Schema: `seo.access.gsc.v1`

One composed response: `performance`, `opportunities`, `cannibalization` (default all).  
One GSC load per site/period via request-scoped memoization.

Period: `YYYY-MM` (default current month).

## Read-only guarantee

All `/api/v1/access/{token}/*` endpoints are **read-only**, including POST variants used for query input.

They must never mutate Content Projects, publishing, generate, WordPress write, or Agent execution.

## Agent write (separate)

```http
POST /api/v1/services/seo/content-projects/draft/intake
Authorization: Bearer <key with content-projects:draft:write>
```

See [`CONTENT_PROJECT_SERVICE_API.md`](CONTENT_PROJECT_SERVICE_API.md).

- Temporary Access tokens cannot call it.
- `seo:read` alone cannot authorize draft write.

## Credential scopes (SEO)

| Scope | Purpose |
|-------|---------|
| `service:read` | Basic Service API status |
| `seo:read` | Access index + mint + (via temporary token) site-bound reads |
| `content-projects:draft:write` | Shared Draft intake |
| `*` | All scopes (testing/admin) |

Create-form defaults for SEO: `service:read`, `seo:read`, `content-projects:draft:write`.

## Errors

```json
{ "error": { "code": "service_api_…", "message": "…" } }
```

| Status | Codes | When |
|--------|-------|------|
| 401 | `service_api_unauthorized` | Missing/invalid permanent bearer |
| 401 | `service_api_temporary_access_invalid` | Invalid/expired temporary token (or inactive SEO after mint) |
| 403 | `service_api_forbidden`, `service_api_scope_denied`, `service_api_service_inactive` | Revoked/expired/scope/service mismatch/inactive |
| 422 | `service_api_validation_failed` | Invalid site_id / sections / period / body |

## Security checklist

- TTL 15 minutes
- Opaque high-entropy token (`access_tmp_…`)
- Hash only in cache
- Site-bound + service-bound + credential-bound
- Active SEO service re-checked on each temporary request
- `Cache-Control: no-store`
- Token cannot change site
- Permanent API key never in model-visible payloads

## Ownership

| Layer | Owns |
|-------|------|
| Core | Auth, temporary Service Access manager/resolver, error envelope, rate limits, route hook |
| SEO addon | `SeoAccessController`, `TemporarySeoAccessController`, composers, `EnsureSeoServiceApi` |

Internal MCP Router / ContextRegistry may remain as composition helpers — they are **not** the public contract.
