# SEO MCP Router

> Status: Canonical  
> Owner: `seo` addon (`Services\Mcp`)  
> Last verified: 2026-09-26  
> Related: [`CONTEXT_GATEWAYS.md`](../contracts/CONTEXT_GATEWAYS.md), [`KEYWORD_MCP.md`](../contracts/KEYWORD_MCP.md), [`AGENT_AND_MCP_CONTRACTS.md`](../contracts/AGENT_AND_MCP_CONTRACTS.md)  
> HTTP/API: [`SEO_ACCESS_API.md`](../api/SEO_ACCESS_API.md) — **canonical external read**  
> This document: **internal** MCP Router / Context parts composition only.

## Purpose

Internal **discovery + selective read** layer on top of the canonical Context Registry.

**MCP Router / Context parts are internal composition details.**  
The canonical external Agent read contract is the **SEO Access API** (`site` / `content` / `keywords` / `gsc`).

```text
Internal consumers (SEO Access composers, Audit, Planning)
   ↓
McpRouterReader → ContextRegistry::format(...)
   ↓
structured context returned
```

**Not an AI planner.** Not the public HTTP Agent contract.

## Dependency rules

```text
domain readers / gateways
        ↓
ContextSliceProvider
        ↓
ContextRegistry          ← canonical slice registry (unchanged role)
        ↓
MCP Router layer         ← this document (internal)
        ↓
SEO Access API composers (docs/api/SEO_ACCESS_API.md)
(curated public resources — not 1:1 router/parts)
```

- MCP Router **must** call ContextRegistry (no Eloquent / Http:: / Agent reverse dependency).
- Monthly MCP snapshot layer is **retired** — routers/composers read Context directly.
- HTTP auth/routes belong in `docs/api/**`, not here.

## Vocabulary

| Term | Meaning |
|------|---------|
| **Router** | AI-discoverable context area (`site`, `keywords`, `gsc`, …) |
| **Part** | Selectable read unit inside a router (maps 1:1 to a Context slice key) |
| **Manifest / README** | Code-generated discovery surface for AI (`seo.mcp.router.v1`) |
| **View** | Context detail level: `summary` / `standard` / `detail` |
| **Size hint** | Non-binding `small` / `medium` / `large` for selection preference |

Part = **what** to load. View = **how much** detail.

## Router catalog

| Router | Parts | Context keys |
|--------|-------|--------------|
| `site` | `health`, `sync` | `site.health`, `site.sync` |
| `content` | `inventory`, `distribution` | `content.inventory`, `content.distribution` |
| `seo` | `findings`, `internal_links` | `seo.findings`, `seo.internal_links` |
| `publishing` | `status` | `publishing.status` |
| `keywords` | `landscape`, `relationship` | `keywords.landscape`, `keywords.relationship` |
| `gsc` | `performance`, `opportunities`, `cannibalization` | `gsc.performance`, `gsc.opportunities`, `gsc.cannibalization` |

Only working registered Context capabilities are exposed.

**Exclusion:** `site.indexability` is intentionally **not** an AI/MCP part. Local `seo_article_profiles.is_indexable` is human/workflow state and must not be read as Google index coverage. Future trustworthy coverage (e.g. Search Console) would use a different capability name such as `gsc.index_coverage`.

## Manifest schema

```json
{
  "schema": "seo.mcp.router.v1",
  "routers": [
    {
      "key": "keywords",
      "title": "Keywords",
      "description": "...",
      "when_to_use": "...",
      "scope": "site",
      "parts": [
        {
          "key": "landscape",
          "context_key": "keywords.landscape",
          "description": "...",
          "when_to_use": "...",
          "views": ["summary", "standard", "detail"],
          "default_view": "summary",
          "required_parameters": [],
          "optional_parameters": ["limit"],
          "period_aware": false,
          "size_hint": "medium"
        }
      ]
    }
  ]
}
```

Views / params / period_aware / description inherit from `ContextRegistry::definition(context_key)` at build time. MCP-only fields: part key, `when_to_use`, `size_hint`, router grouping.

Markdown presenter: `McpManifestMarkdownPresenter` — compact README for AI, no payloads.

Application entry:

```php
$registry->manifest();
app(McpManifestMarkdownPresenter::class)->present($registry);
```

## Selective read contract

Request (per-part view + parameters — no global bleed):

```json
{
  "router": "keywords",
  "parts": {
    "landscape": {
      "view": "summary",
      "parameters": { "limit": 5 }
    },
    "relationship": {
      "view": "standard",
      "parameters": {
        "keyword_ref": "keyword:123",
        "sections": ["keyword", "topics", "focus_articles"]
      }
    }
  }
}
```

Response:

```json
{
  "schema": "seo.mcp.router.read.v1",
  "router": "keywords",
  "scope": { "site_ref": "site:123" },
  "parts": {
    "landscape": { /* ContextFormatter envelope */ },
    "relationship": { /* ContextFormatter envelope */ }
  }
}
```

Unselected parts are absent. Each part keeps the Context formatter envelope (`key`, `scope`, `available`, `data`, …).

### Failure policy

| Condition | Behavior |
|-----------|----------|
| Unknown router / part | Whole request fails (`InvalidArgumentException`) |
| Invalid view / unknown or missing required param | Whole request fails (ContextRegistry authoritative) |
| Valid part, data unavailable | Part remains; `available: false` |

## `keywords.relationship` sections

Gateway loads one relationship DTO; splitting into many Context providers would not avoid substantial work.

**Decision:** keep one Context slice; optional allowlisted `sections` parameter projects after view selection.

Allowlist: `keyword`, `topics`, `focus_articles`, `related_keywords`, `internal_links`, `gsc`, `meta`.

Invalid section names → reject.

## Lifecycle / memoization

- `GscContextSource` and `ContextRegistry` remain **scoped** (request/job).
- Multi-part GSC reads (`performance` + `opportunities` + `cannibalization`) share one `GscContextSource::load` for the same site/period.

## Future Agent interaction (conceptual)

1. AI reads root MCP manifest  
2. AI chooses router (optionally inspects parts)  
3. AI selects minimum necessary parts + views  
4. Router returns structured context  
5. AI reasons  
6. Writes/actions use **separate** command/tool APIs  

The MCP router does **not** decide intent and does **not** plan.

## Code map

| Piece | Location |
|-------|----------|
| Catalog | `seo/src/Services/Mcp/Catalog/SeoMcpRouterCatalog.php` |
| Registry / Reader | `seo/src/Services/Mcp/Router/*` |
| Manifest | `seo/src/Services/Mcp/Manifest/*` |
| Context SSOT | `seo/src/Services/Context/**` |

## Out of scope

- New Agent implementation  
- HTTP Service API routes/auth (see `docs/api/`)  
- Write tools / command bus  
- Empty speculative routers  
