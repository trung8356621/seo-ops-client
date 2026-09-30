# SEO Access API

> Status: Canonical (implemented)  
> Owner: Core temporary Service Access + `seo` addon HTTP adapter  
> Last verified: 2026-09-26  
> Write contract (separate): [`CONTENT_PROJECT_SERVICE_API.md`](CONTENT_PROJECT_SERVICE_API.md)  
> Internal composition (not public): [`SEO_MCP_ROUTER.md`](../modules/SEO_MCP_ROUTER.md), [`CONTEXT_GATEWAYS.md`](../contracts/CONTEXT_GATEWAYS.md)

## Purpose

Unified **SEO read plane** for external Agents / integrations.

External callers MUST NOT need to understand MCP, ContextRegistry, routers, parts, ContextSlice, or addon ownership. Those remain internal.

```text
1. GET  /api/v1/services/seo/access          → list sites
2. POST /api/v1/services/seo/access          → mint temporary URL (scope: site | global)
3. GET  /api/v1/access/{token}               → resource index (site scope)
4. GET  /api/v1/access/{token}/{resource}    → read SEO context
5. POST /api/v1/services/seo/content-projects/draft/intake  → only Agent write
```

Canonical public resources:
- **Site-scoped**: `site`, `articles`, `internal-links`, `external-links`, `keywords`, `content-projects`, `gsc`
- **Global-scoped**: `site-network`

## Authentication planes

| Mode | Auth | Scope | Site |
|------|------|-------|------|
| **A. Permanent Service API** | `Authorization: Bearer svc_live_…` (`service_api_credentials`) | **`seo:read`** | List / mint |
| **B. Temporary Access (Site)** | Opaque path token only (no Bearer) | Bound as `scope=site` | Fixed at mint (`site_ref`) |
| **C. Temporary Access (Global)** | Opaque path token only (no Bearer) | Bound as `scope=global` | `null` (cross-site topology only) |

Permanent API key stays **server/runtime-only**. Models receive only the temporary `access_url`.

Wildcard scope `*` grants `seo:read`. Scope `mcp:read` does **not** authorize this contract.

Rate limits:

- Permanent: `throttle:service-api`
- Temporary: `throttle:temporary-access`

## A. Service Access Index

```http
GET /api/v1/services/seo/access
Authorization: Bearer svc_live_…
```

Compact Site rows only — no credentials, no Site configuration dump.

## B. Mint temporary access

Permanent credentials mint short-lived capability tokens via `POST /api/v1/services/seo/access`. Tokens expire after `ttl` (default **900 seconds / 15 minutes**).

### 1. Site-Scoped Temporary Token (Default)

Grants access to site-bound resources (`/site`, `/articles`, `/internal-links`, `/external-links`, `/keywords`, `/content-projects`, `/gsc`). Requires a valid, active `site_id`.

```http
POST /api/v1/services/seo/access
Authorization: Bearer svc_live_…
Content-Type: application/json

{ "site_id": 7 }
```

```json
{
  "data": {
    "scope": "site",
    "access_url": "https://host/api/v1/access/access_tmp_…",
    "site_ref": "site:7",
    "expires_at": "2026-09-28T12:15:00+00:00"
  }
}
```

### 2. Global-Scoped Temporary Token

Grants access strictly to cross-site topology (`/site-network`). Requires `scope: "global"` and **forbids `site_id`**.

```http
POST /api/v1/services/seo/access
Authorization: Bearer svc_live_…
Content-Type: application/json

{ "scope": "global" }
```

```json
{
  "data": {
    "scope": "global",
    "access_url": "https://host/api/v1/access/access_tmp_…/site-network",
    "site_ref": null,
    "expires_at": "2026-09-28T12:15:00+00:00"
  }
}
```

The global `access_url` points directly to `/api/v1/access/{token}/site-network` and is immediately usable with `GET`. No separate global root catalog currently exists because Site Network is the only global public resource.

## C. Temporary Access root (runtime README)

```http
GET /api/v1/access/{token}
```

This is the **single entry URL** for Agents. The response is a compact runtime README: purpose, recommended flow, and navigable resource catalog. It is not a documentation dump.

```json
{
  "data": {
    "schema": "seo.access.v1",
    "site_ref": "site:7",
    "site": { "domain": "example.com", "title": "Example" },
    "usage": {
      "purpose": "Read-only SEO context for this site.",
      "recommended_flow": [
        "Read site first to understand the business and website context.",
        "Read keywords to inspect topical coverage and choose Topics to investigate.",
        "Read gsc when search-performance evidence is needed.",
        "Follow returned href/detail_href links for deeper context."
      ]
    },
    "resources": [
      {
        "key": "site",
        "description": "Website identity, business context, important pages, and content distribution.",
        "when_to_use": "Read first before making content or SEO decisions.",
        "method": "GET",
        "href": "/api/v1/access/{token}/site"
      },
      {
        "key": "keywords",
        "description": "Topic landscape with MCP coverage scores.",
        "when_to_use": "Use to find weak or strong Topics and inspect Topic DNA and Focus Articles.",
        "method": "GET",
        "usage": {
          "mcp": "Topic coverage score from 0 to 100.",
          "default_order": "Lowest MCP first.",
          "weakest_topics": "?sort=mcp&direction=asc",
          "strongest_topics": "?sort=mcp&direction=desc",
          "pagination": "Use page/per_page. Maximum per_page is 100.",
          "detail": "Each Topic contains detail_href. Follow it for full DNA and Focus Articles."
        },
        "href": "/api/v1/access/{token}/keywords"
      },
      {
        "key": "gsc",
        "description": "Google Search Console performance and SEO opportunity data.",
        "when_to_use": "Use when decisions should be supported by actual search-performance data.",
        "method": "GET",
        "usage": {
          "period": "YYYY-MM. Defaults to current month.",
          "missing_data": "Missing synchronized data must not be interpreted as zero traffic.",
          "fallback": "When available, follow latest_available.href to inspect the most recent synchronized period."
        },
        "href": "/api/v1/access/{token}/gsc"
      }
    ]
  }
}
```

Not exposed: `content` (merged into site), MCP routers/parts, indexability, inventory, publishing, seo findings, sync, health.

Do not repeat full resource schemas on the root — use `when_to_use` / compact `usage` only.

## D. Site resource

```http
GET /api/v1/access/{token}/site
```

Schema: `seo.access.site.v2`

```json
{
  "data": {
    "schema": "seo.access.site.v2",
    "site_ref": "site:7",
    "identity": { "domain": "…", "site_title": "…", "website_type": "…", "brand": "…", "cms": "wordpress" },
    "writing_context": {
      "business_summary": "…",
      "cta_instructions": "…"
    },
    "contact": { "phones": [], "emails": [], "socials": [], "address": null },
    "important_pages": {
      "total": 83,
      "returned": 60,
      "truncated": true,
      "items": [
        {
          "url": "…",
          "title": "…",
          "seo_title": "…",
          "page_type": "product_category",
          "type": "product_category",
          "keyword": "…",
          "taxonomy": "product_cat",
          "term_id": 12,
          "parent_term_id": 0
        }
      ]
    },
    "content_distribution": {
      "posts": 600,
      "pages": 10,
      "categories": 6,
      "products": 514,
      "product_categories": 45,
      "other": 0,
      "available": true
    },
    "sitemaps": {
      "available": false,
      "urls": []
    }
  }
}
```

### Writing context

- Includes `business_summary` and `cta_instructions` only.
- **No `tone`.** Site/domain tone is retired from AI writing resolution (`SiteDomainPromptContextService::resolveToneForSite`). Runtime/item tone is authoritative elsewhere.
- Historical official/draft tone values may still exist in storage; Access ignores them.

### Important pages

- Items are capped (Site MCP generator: max 60 verified root `product_cat`).
- `total` prefers draft `counts.root_product_cat` for production/e-commerce catalog strategies (same verified population; **not** individual `product` counts).
- For news/manual strategies with no reliable verified total: `total` may be `null`, `truncated = false`.
- `truncated = total !== null && returned < total`.

### Sitemaps

- Only a **canonical verified** sitemap URL source would be exposed.
- There is currently **no** verified WP Bridge / Site Sync sitemap URL contract. Do not guess `/wp-sitemap.xml` or `/sitemap_index.xml`.
- Default: `{ "available": false, "urls": [] }`.
- Access does **not** crawl sitemap contents.

### Content distribution

- Former standalone `/content` resource is **retired** from the public contract.
- Distribution is merged into `/site` via `SiteContentDistributionAggregator` (no duplicated queries).

**Not exposed:** local article indexability counters, workflow published/draft/scheduled counts, site tone.

## E. Keywords resource

## E1. Article and link resources

- `GET /api/v1/access/{token}/articles?limit=30`: returns compact, site-scoped Article evidence.
  - Generic query (no `task`): returns existing-Article inventory ordered by oldest update, including identity, status, focus keyword, SEO score, link counts, and timestamps where available.
  - `task=improve`: reuses canonical Web SEO Audit / Articles Optimal logic to retrieve prioritized candidate articles needing revision (incorporating SEO score, reason labels/issues, focus keyword, and keyword review flags). Candidate discovery pool is bounded rather than blindly truncated by the answer limit before canonical ranking.
- `GET /api/v1/access/{token}/articles/{articleRef}`: returns exact Article evidence for a single Article strictly scoped to the token's Site (returns 404 for unknown or other-site articles). Never returns article bodies.
- `GET /api/v1/access/{token}/internal-links` returns same-site Article relationships.
- `GET /api/v1/access/{token}/external-links` separately returns external, trusted/reference, Needs Review, and Managed Cross-Site relationships.

## E2. Content Projects resource

`GET /api/v1/access/{token}/content-projects?period=YYYY-MM` is read-only and delegates to `ContentProjectAgentReadService`. It returns site projects and their existing item read DTOs so Agents can detect work already planned. No Content Project command bus or write capability is exposed.

### Site landscape

```http
GET /api/v1/access/{token}/keywords
GET /api/v1/access/{token}/keywords?page=1&per_page=50&sort=mcp&direction=asc&coverage=weak&status=active&has_focus_article=false
```

Schema: `seo.access.keywords.v2`

Query parameters (allowlisted):

| Param | Default | Notes |
|-------|---------|-------|
| `page` | `1` | ≥ 1 |
| `per_page` | `50` | max `100` |
| `sort` | `mcp` | `mcp`, `name`, `article_count`, `dna_count` |
| `direction` | `asc` | `asc` \| `desc` |
| `coverage` | — | exact coverage string when present |
| `status` | — | exact status string when present |
| `has_focus_article` | — | `true` \| `false` |

```json
{
  "data": {
    "schema": "seo.access.keywords.v2",
    "site_ref": "site:7",
    "source_updated_at": "…",
    "summary": { "topic_count": 137 },
    "topics": [
      {
        "topic_ref": "topic:123",
        "id": 123,
        "name": "…",
        "mcp": 72.0,
        "mcp_percent": 72,
        "dna_count": 8,
        "article_count": 4,
        "has_focus_article": true,
        "coverage": "strong",
        "status": "active",
        "detail_href": "/api/v1/access/{token}/keywords/topics/topic:123"
      }
    ],
    "pagination": {
      "page": 1,
      "per_page": 50,
      "total": 137,
      "total_pages": 3
    }
  }
}
```

- Canonical MCP score is `mcp` (Topic Core topical share, 0–100). `mcp_percent` is a rounded convenience integer.
- Landscape list does **not** include full DNA rows (use Topic Detail).
- There is **no** hard first-20 cut. Use pagination + sort (lowest MCP: `sort=mcp&direction=asc`).
- Compact MCP navigation semantics (`weakest_topics` / `strongest_topics` / `detail_href`) live on the Access root catalog entry — not repeated on every Topic row.

### Topic detail

```http
GET /api/v1/access/{token}/keywords/topics/{topicRef}
```

Example: `…/keywords/topics/topic:123`

Schema: `seo.access.keywords.topic.v1`

- Topic must belong to the token-bound Site (cross-site → 404).
- Returns full Topic DNA (`phrase`, `weight`) and compact Focus Articles (`article_ref`, `title`, `slug`, `status`, `focus_keyword`).
- No article body. No publishing pipeline counters.

Agent flow:

```text
GET /keywords → scan landscape → follow detail_href → Topic Detail
```

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

Remains separate from Topic Detail.

## F. GSC resource

```http
GET /api/v1/access/{token}/gsc
GET /api/v1/access/{token}/gsc?period=2026-08&include=performance,opportunities
POST /api/v1/access/{token}/gsc
{ "period": "2026-08", "include": ["performance", "opportunities", "cannibalization"] }
```

Schema: `seo.access.gsc.v1`

Period: `YYYY-MM` (default current month). Include allowlist: `performance`, `opportunities`, `cannibalization`.

### Unavailable vs measured zero

**Critical:** missing synced data must never look like zero performance.

When no usable GSC coverage exists for the period:

```json
{
  "data": {
    "schema": "seo.access.gsc.v1",
    "site_ref": "site:7",
    "period": "2026-09",
    "available": false,
    "reason": "no_synced_data",
    "message": "No GSC Search Performance data is synchronized for this site and period.",
    "latest_available": {
      "period": "2026-07",
      "message": "Latest synchronized GSC data is available for 2026-07.",
      "href": "/api/v1/access/{token}/gsc?period=2026-07"
    }
  }
}
```

Reasons:

| reason | message (concise) | `latest_available` |
|--------|-------------------|--------------------|
| `no_gsc_property` | No active GSC property is configured for this site. | **never** |
| `no_synced_data` | No GSC Search Performance data is synchronized for this site and period. | when an earlier/equal synced period exists |
| `invalid_period` | Invalid GSC period. Expected YYYY-MM. | never |

`latest_available` semantics:

- Present only for `reason=no_synced_data` when a synchronized period with persisted Search Performance rows exists on or before the requested period.
- Named `latest_available` (not `previous_month`) because gaps are allowed.
- Does **not** silently substitute that data into the requested period — `period` stays the requested key and `available` stays `false`.
- When no synchronized period exists at all: omit `latest_available`.
- Never inferred from GSC property existence alone.
- `href` reuses the same temporary access token.

Unavailable responses omit `performance` / `opportunities` / `cannibalization` and do **not** emit clicks/impressions/opportunity counts as `0`.

When persisted daily rows exist for the period and aggregation is genuinely zero: `available: true` and zeros are valid.

Evidence = persisted fact existence for the period (not merely property existence).

## G. Site Network resource (Global Scope)

Cross-site topology aggregate between managed sites. Requires a **global-scoped temporary access token** (`scope: "global"`). Site-scoped tokens cannot access this resource and receive `403 Forbidden`.

### 1. Overview graph

```http
GET /api/v1/access/{globalToken}/site-network
```

Schema: `seo.site_network.v1`

```json
{
  "data": {
    "schema": "seo.site_network.v1",
    "sites": [
      {
        "site_ref": "site:1",
        "site_id": 1,
        "domain": "alpha.example.com"
      },
      {
        "site_ref": "site:2",
        "site_id": 2,
        "domain": "beta.example.com"
      }
    ],
    "edges": [
      {
        "source_site_ref": "site:1",
        "target_site_ref": "site:2",
        "article_link_count": 14,
        "source_article_count": 6,
        "target_article_count": 4,
        "source_keyword_count": 8
      }
    ],
    "note": "Direction preserved. A→B and B→A are separate edges."
  }
}
```

### 2. Edge Metrics & Directionality

- **Strictly Directional**: An edge `A -> B` represents hyperlinks in articles on Site A linking to articles on Site B. `A -> B` and `B -> A` are separate edges and never collapsed.
- **`article_link_count`**: Total number of hyperlinks from articles on `source_site` pointing to `target_site`.
- **`source_article_count`**: Number of distinct source articles on `source_site` containing at least one link to `target_site`.
- **`target_article_count`**: Number of distinct target articles on `target_site` linked from `source_site`.
- **`source_keyword_count`**: Number of distinct source keywords (from `seo_link_maps.keyword_id`) originating the links.

### 3. Topics drilldown for site pair

```http
GET /api/v1/access/{globalToken}/site-network/topics?source_site={sourceSiteId}&target_site={targetSiteId}
```

Schema: `seo.site_network.topics.v1`

Query parameters:
- `source_site` (integer, required): ID of source site
- `target_site` (integer, required): ID of target site

```json
{
  "data": {
    "schema": "seo.site_network.topics.v1",
    "source_site_ref": "site:1",
    "target_site_ref": "site:2",
    "topics": [
      {
        "topic_id": 42,
        "topic_ref": "topic:42",
        "name": "Organic Coffee",
        "cross_site_link_count": 7
      }
    ]
  }
}
```

## Authorization Matrix

| Endpoint | Resource | Token Scope | Site Token (`scope: site`) | Global Token (`scope: global`) | Invalid / Expired Token |
|----------|----------|-------------|----------------------------|--------------------------------|-------------------------|
| `GET /api/v1/access/{token}/site` | Site Knowledge | Site | **200 OK** | **403 Forbidden** | 401 Unauthorized |
| `GET /api/v1/access/{token}/articles` | Article inventory | Site | **200 OK** | **403 Forbidden** | 401 Unauthorized |
| `GET /api/v1/access/{token}/articles/{ref}` | Article detail | Site | **200 OK** | **403 Forbidden** | 401 Unauthorized |
| `GET /api/v1/access/{token}/internal-links` | Internal links | Site | **200 OK** | **403 Forbidden** | 401 Unauthorized |
| `GET /api/v1/access/{token}/external-links` | External links | Site | **200 OK** | **403 Forbidden** | 401 Unauthorized |
| `GET /api/v1/access/{token}/keywords` | Keyword Landscape | Site | **200 OK** | **403 Forbidden** | 401 Unauthorized |
| `GET /api/v1/access/{token}/keywords/topics/{ref}` | Topic Detail | Site | **200 OK** | **403 Forbidden** | 401 Unauthorized |
| `POST /api/v1/access/{token}/keywords` | Relationship Read | Site | **200 OK** | **403 Forbidden** | 401 Unauthorized |
| `GET /api/v1/access/{token}/gsc` | GSC Performance | Site | **200 OK** | **403 Forbidden** | 401 Unauthorized |
| `POST /api/v1/access/{token}/gsc` | GSC Query | Site | **200 OK** | **403 Forbidden** | 401 Unauthorized |
| `GET /api/v1/access/{token}/content-projects` | Content Project reads | Site | **200 OK** | **403 Forbidden** | 401 Unauthorized |
| `GET /api/v1/access/{token}/site-network` | Site Network Overview | Global | **403 Forbidden** | **200 OK** | 401 Unauthorized |
| `GET /api/v1/access/{token}/site-network/topics` | Site Network Topics | Global | **403 Forbidden** | **200 OK** | 401 Unauthorized |

## Global Agent Retrieval Guard

Agent Runtime global retrieval remains **intentionally unsupported**. `SeoAccessExecutor` enforces site scope and rejects global retrieval requests with an explicit exception. Global Site Network data is consumed exclusively by overview APIs and the Topical Map interface.

## Read-only guarantee

All `/api/v1/access/{token}/*` endpoints are **read-only**, including POST variants used for query input.

## Agent write (separate)

```http
POST /api/v1/services/seo/content-projects/draft/intake
Authorization: Bearer <key with content-projects:draft:write>
```

See [`CONTENT_PROJECT_SERVICE_API.md`](CONTENT_PROJECT_SERVICE_API.md).

- Temporary Access tokens cannot call it.
- `seo:read` alone cannot authorize draft write.
- **Unchanged** by this Access refinement.

## Credential scopes (SEO)

| Scope | Purpose |
|-------|---------|
| `service:read` | Basic Service API status |
| `seo:read` | Access index + mint + (via temporary token) site-bound reads |
| `content-projects:draft:write` | Shared Draft intake |
| `*` | All scopes (testing/admin) |

## Errors

| Status | Codes | When |
|--------|-------|------|
| 401 | `service_api_unauthorized` | Missing/invalid permanent bearer |
| 401 | `service_api_temporary_access_invalid` | Invalid/expired temporary token |
| 403 | `service_api_forbidden`, `service_api_scope_denied`, `service_api_service_inactive` | Revoked/expired/scope/service mismatch/inactive |
| 404 | `service_api_not_found` | Unknown topic / cross-site topic |
| 422 | `service_api_validation_failed` | Invalid site_id / sections / period / sort / body |

## Ownership

| Layer | Owns |
|-------|------|
| Core | Auth, temporary Service Access manager/resolver, error envelope, rate limits, route hook |
| SEO addon | `SeoAccessController`, `TemporarySeoAccessController`, composers, `EnsureSeoServiceApi`, `SeoToolApiController`, `SeoToolExecutor`, `SeoToolRegistry` |

Internal MCP Router / ContextRegistry may remain as composition helpers — they are **not** the public contract.

---

## SEO Tool / Capability API

### Purpose

Provides a clean, modular Capability / Tool execution boundary for external Agents, automated pipelines, or future Agent Action Resolvers without direct coupling to internal PHP services or duplicate business logic.

```text
GET  /api/v1/services/seo/tools                     → List authorized tools for caller
POST /api/v1/services/seo/tools/{toolKey}/execute    → Execute tool with fail-closed policy
```

### Architectural Principles

1. **Zero duplicated SEO algorithms or scoring logic**: Tool handlers delegate directly to existing canonical module services (`SeoAuditAgentReadService`, `ServiceApiDraftIntakeService`, `TopicServiceApiWriteService`).
2. **Capability ownership follows business ownership**: Read operations for SEO Audit belong to `seo` (`seo_audit.list`), planning draft writes belong to `draft` (`draft.intake`), and topic writes belong to `topic` (`topic.create`).
3. **Fail-closed validation order**:
   1. Tool exists in registry (`404 tool_not_found`)
   2. Tool is exposed and enabled (`404 tool_not_exposed`)
   3. Caller possesses all required scopes (`403 scope_denied`; `*` satisfies all)
   4. Required execution context provided (`422 missing_context`)
   5. Site context resolves to an existing site (`404 site_not_found`)
   6. Input validated against tool JSON Schema (`422 validation_failed`)
   7. Confirmation policy checked (`422 confirmation_required` if write tool unconfirmed)
   8. Execute canonical handler (`200 OK` / `201 Created`)
4. **Safe public discovery**: `GET /api/v1/services/seo/tools` filters capabilities against the caller's scopes and NEVER leaks internal PHP classes, database table names, or credentials.

### Tool write boundary

The Tool API is NOT a second UI and is NOT a generic command bus.

AI-originated NEW BUSINESS DATA may be written ONLY into:
1. `draft.*` — AI-originated content planning data (enters Shared Planning Draft via `draft.intake`).
2. `topic.*` — AI-originated Topic data (site-scoped manual Topic entity creation / reuse via `topic.create`).

Everything else must remain under the existing product UI / workflow / internal application commands.

**Allowed public write namespaces:**
- `draft.*`
- `topic.*`

**Explicitly forbidden public write namespaces include:**
- `content_project.*` (Content Project is NOT an ingestion surface; items enter Shared Planning Draft first)
- `article.*`
- `wordpress.*`
- `publishing.*`
- `site.*`
- `site_sync.*`
- `serp.*`
- `internal_links.*`
- `external_links.*`
- `link_health.*`
- `gsc.*`
- `seo_audit.*`

> [!NOTE]
> This restriction applies strictly to **WRITE** tools. READ tools are completely unaffected. For example, `seo_audit.list` continues to operate as an authorized read tool under `seo:read`.

### Canonical Tools

| Capability Key | Module | Kind | Scopes | Required Context | Confirmation | Handler Delegation |
|---|---|---|---|---|---|---|
| `seo_audit.list` | `seo_audit` | `read` | `seo:read` | `site_ref` | `none` | `SeoAuditAgentReadService::listArticles()` |
| `draft.intake` | `draft` | `write` | `content-projects:draft:write` | `site_ref` | `required` | `ServiceApiDraftIntakeService::intake()` |
| `topic.create` | `topic` | `write` | `topics:write` | `site_ref` | `required` | `TopicServiceApiWriteService::create()` |

### 1. Discover Tools

```http
GET /api/v1/services/seo/tools
Authorization: Bearer <service_key>
```

#### Response (`200 OK`)
```json
{
  "data": {
    "service": "seo",
    "tools": [
      {
        "key": "seo_audit.list",
        "name": "List SEO Audit Articles",
        "description": "List articles with SEO audit scoring and optimization recommendations for a site.",
        "module": "seo_audit",
        "kind": "read",
        "required_context": ["site_ref"],
        "confirmation_policy": "none",
        "availability": { "available": false, "reason": "missing_site_context" },
        "input_schema": {
          "type": "object",
          "properties": {
            "post_type": { "type": "string" },
            "limit": { "type": "integer", "minimum": 1, "maximum": 100 },
            "rules": { "type": "array", "items": { "type": "string" } },
            "low_score": { "type": "boolean" }
          }
        }
      },
      {
        "key": "draft.intake",
        "name": "Intake Items into Planning Draft",
        "description": "Intake new or rewrite content items into the shared planning draft for a site.",
        "module": "draft",
        "kind": "write",
        "required_context": ["site_ref"],
        "confirmation_policy": "required",
        "availability": { "available": false, "reason": "missing_site_context" },
        "input_schema": {
          "type": "object",
          "properties": {
            "items": { "type": "array", "minItems": 1, "maxItems": 100 }
          },
          "required": ["items"],
          "additionalProperties": false
        }
      },
      {
        "key": "topic.create",
        "name": "Create Topic",
        "description": "Create or reuse a site-scoped manual topic.",
        "module": "topic",
        "kind": "write",
        "required_context": ["site_ref"],
        "confirmation_policy": "required",
        "availability": { "available": false, "reason": "missing_site_context" },
        "input_schema": {
          "type": "object",
          "properties": {
            "name": { "type": "string", "minLength": 1, "maxLength": 255 }
          },
          "required": ["name"],
          "additionalProperties": false
        }
      }
    ]
  }
}
```

### 2. Execute Read Tool (`seo_audit.list`)

```http
POST /api/v1/services/seo/tools/seo_audit.list/execute
Authorization: Bearer <key with seo:read>
X-Site-Ref: site:123
Content-Type: application/json

{
  "context": { "site_ref": "site:123" },
  "input": { "limit": 20, "low_score": true }
}
```

#### Response (`200 OK`)
```json
{
  "data": {
    "tool": "seo_audit.list",
    "result": {
      "items": [
        {
          "article_ref": "article:456",
          "title": "Optimizing Laravel Performance",
          "score": 58,
          "post_type": "post",
          "focus_keyword": "laravel performance",
          "reason_labels": ["Missing focus keyword in H2", "Low content length"]
        }
      ],
      "total": 1,
      "post_type": null
    }
  }
}
```

### 3. Execute Write Tool (`draft.intake`)

Write tools modify persistent state and enforce explicit confirmation. If `confirmed !== true`, execution is aborted with zero mutations.

#### Step 3a: Unconfirmed attempt (`422 Unprocessable Content`)
```http
POST /api/v1/services/seo/tools/draft.intake/execute
Authorization: Bearer <key with content-projects:draft:write>
Content-Type: application/json

{
  "context": { "site_ref": "site:123" },
  "input": { "items": [
    { "keyword": "laravel tips", "title": "10 Laravel Tips", "type": "new" }
  ] },
  "confirmed": false
}
```

Response:
```json
{
  "error": {
    "code": "confirmation_required",
    "message": "Tool 'draft.intake' modifies state and requires explicit confirmation before execution.",
    "meta": {
      "tool": "draft.intake",
      "confirmation_policy": "required",
      "kind": "write"
    }
  }
}
```

#### Step 3b: Confirmed execution (`200 OK` / `201 Created`)
```http
POST /api/v1/services/seo/tools/draft.intake/execute
Authorization: Bearer <key with content-projects:draft:write>
Content-Type: application/json

{
  "context": { "site_ref": "site:123" },
  "input": { "items": [
    { "keyword": "laravel tips", "title": "10 Laravel Tips", "type": "new" }
  ] },
  "confirmed": true
}
```

Response:
```json
{
  "data": {
    "tool": "draft.intake",
    "result": {
      "draft_ref": "project:10",
      "site_ref": "site:123",
      "submitted": 1,
      "added": 1,
      "already_in_draft": 0,
      "failed": 0,
      "items": [
        { "index": 0, "status": "added", "item_ref": "item:88" }
      ],
      "idempotent_replay": false
    },
    "meta": {
      "draft_ref": "project:10",
      "added_count": 1,
      "already_in_draft_count": 0,
      "failed_count": 0
    }
  }
}
```

### 4. Execute Write Tool (`topic.create`)

Topic creation is site-scoped. It creates a new Topic or reuses an existing same-site Topic without synthesizing synthetic Keywords.

#### Step 4a: Confirmed execution (`200 OK`)
```http
POST /api/v1/services/seo/tools/topic.create/execute
Authorization: Bearer <key with topics:write>
Content-Type: application/json

{
  "context": { "site_ref": "site:123" },
  "input": {
    "name": "Balo laptop"
  },
  "confirmed": true
}
```

Response:
```json
{
  "data": {
    "tool": "topic.create",
    "result": {
      "topic_ref": "topic:88",
      "topic_id": 88,
      "topic_name": "Balo laptop",
      "reused": false,
      "reconcile": {
        "checked": 4,
        "matched": 1,
        "attached": 1,
        "moved": 0,
        "skipped_locked": 0,
        "skipped_seed": 0
      }
    },
    "meta": {
      "topic_ref": "topic:88",
      "topic_id": 88,
      "reused": false
    }
  }
}
```
