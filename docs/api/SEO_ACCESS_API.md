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

Canonical public resources: **site**, **keywords**, **gsc** only.

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

## A. Service Access Index

```http
GET /api/v1/services/seo/access
Authorization: Bearer svc_live_…
```

Compact Site rows only — no credentials, no Site configuration dump.

## B. Mint temporary site-bound access

```http
POST /api/v1/services/seo/access
Authorization: Bearer svc_live_…
Content-Type: application/json

{ "site_id": 7 }
```

Allowed body field: **`site_id` only**. TTL **900 seconds (15 minutes)**.

```json
{
  "data": {
    "access_url": "https://host/api/v1/access/access_tmp_…",
    "site_ref": "site:7",
    "expires_at": "2026-09-26T12:15:00+00:00"
  }
}
```

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
| SEO addon | `SeoAccessController`, `TemporarySeoAccessController`, composers, `EnsureSeoServiceApi` |

Internal MCP Router / ContextRegistry may remain as composition helpers — they are **not** the public contract.
