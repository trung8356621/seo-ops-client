# Domain Context Gateways

> Status: Canonical  
> Owner: `seo` (+ `search-intelligence` read models)  
> Last verified: 2026-09-26  
> Related: [`KEYWORD_MCP.md`](KEYWORD_MCP.md), [`SITE_MCP_AND_DOMAINS.md`](../modules/SITE_MCP_AND_DOMAINS.md), [`AGENT_AND_MCP_CONTRACTS.md`](AGENT_AND_MCP_CONTRACTS.md), [`API_AND_AUTHORIZATION.md`](API_AND_AUTHORIZATION.md)

## Purpose

Canonical SoT for SEO **domain context** architecture: MCP-independent slices, a **strict Context Registry**, projection, and formatter.

**Not implemented here:** unified AI Context Planner inside Registry, AI tool calling, new Agent, new MCP sources, Planning Context.

AI discovery/selective read over this registry (internal): [`SEO_MCP_ROUTER.md`](../modules/SEO_MCP_ROUTER.md).  
Canonical external HTTP read: [`SEO_ACCESS_API.md`](../api/SEO_ACCESS_API.md).  
Retired MCP HTTP pointer: [`SEO_SERVICE_API.md`](../api/SEO_SERVICE_API.md).

## Architecture

```text
Domain Readers / ReadModels
        ↓
Context Slice Providers
        ↓
STRICT Context Registry
        ↓
Projection
        ↓
Canonical Formatter
        ↓
────────────────────────────
internal consumers
Monthly MCP compatibility
MCP Router (discovery + selective parts) — see SEO_MCP_ROUTER.md (internal)
SEO Access API (docs/api/SEO_ACCESS_API.md) — curated external Agent reads
future AI (consumes SEO Access — not a planner inside Registry)
```

### Dependency rules

```text
Context → never MCP
Context → never HTTP
Context → never Agent

MCP → may consume Context
HTTP → will consume Context
AI → will consume Context
```

### Vocabulary

| Term | Meaning |
|------|---------|
| **Context Slice** | Reusable read-only data capability |
| **Context Registry** | Strict allowlisted read capabilities + providers (not an AI planner) |
| **Context Projection** | View level: `summary` / `standard` / `detail` (+ list limits) |
| **Context Formatter** | Final structured representation (facts only) |
| **MCP** | Compatibility / monthly snapshot consumer only |
| **AI Planner** | Future consumer — not implemented |
| **Site Knowledge Profile** | Prompt tone/CTA/links (`search-foundation` `SiteMcp*`) — **≠** Site Intelligence |
| **Site Intelligence Context** | Runtime health/content/links/findings (`SiteContext*` + slices) |

## Registry invariants

- Unknown slice key → reject  
- Unknown parameter → reject  
- Parameter aliases are **slice-specific** (listed on that definition only)  
- Explicit invalid view → reject  
- Missing/null view → definition `default_view`  
- Registry is a strict allowlist / future security boundary  
- Formatter recursively removes presentation-only keys: `ai_lines`, `text`, `note`, `raw`  
- GSC memoization (`GscContextSource`) is **request/job scoped** (`scoped()`), not process-global  
- Context layer does not depend on `Services\MonthlyMcp`

## Registered slices

| Key | Views | Required params | Optional params | Period aware |
|-----|-------|-----------------|-----------------|--------------|
| `site.health` | summary, standard, detail | — | — | no |
| `site.sync` | summary, standard, detail | — | — | no |
| `content.inventory` | summary, standard, detail | — | — | no |
| `content.distribution` | summary, standard, detail | — | — | no |
| `seo.findings` | summary, standard, detail | — | `limit` | no |
| `seo.internal_links` | summary, standard, detail | — | `limit` | no |
| `publishing.status` | summary, standard, detail | — | — | no |
| `keywords.landscape` | summary, standard, detail | — | `limit` | no |
| `keywords.relationship` | summary, standard, detail | `keyword_ref` | `keyword_id` (compat alias), `sections` (allowlisted projection) | no |
| `gsc.performance` | summary, standard, detail | — | `period`, `period_key`, `limit` | yes |
| `gsc.opportunities` | summary, standard, detail | — | `period`, `period_key`, `limit` | yes |
| `gsc.cannibalization` | summary, standard, detail | — | `period`, `period_key`, `limit` | yes |

`site.indexability` is **excluded** from the Context Registry / MCP catalog: it reflected local configured/workflow indexability (`is_indexable`), not Google index coverage, and must not be treated as AI truth. Human Site Intelligence UI may still use that internal signal via `SiteSeoHealthReader`.

`keyword_id` satisfies `keyword_ref` requirement as a declared optional alias on `keywords.relationship` only.  
`sections` on `keywords.relationship` is an allowlisted projection filter (`keyword`, `topics`, `focus_articles`, `related_keywords`, `internal_links`, `gsc`, `meta`) — not a new Context slice family.  
`period_key` is a declared optional alias of `period` on GSC slices only.

## Canonical gateways (presets)

| Preset | Gateway | Schema id (compat) | Snapshot |
|--------|---------|-------------------|----------|
| Site Intelligence | `SiteContextGateway` | `site.mcp.v1` | monthly `site` |
| Keyword Landscape | `KeywordLandscapeGateway` | `keywords.mcp.v2` | monthly `keywords` |
| Keyword Relationship | `KeywordRelationshipGateway` | `keyword.relationship.v1` | on-demand only |
| GSC | `GscContextGateway` | `gsc.mcp.v1` | monthly `gsc` |

`SiteContext` is a **preset composition** of site/content/seo/publishing slices.

## Monthly MCP compatibility

```text
Neutral Context → Monthly MCP Source adapter → seo_mcp_source_snapshots
```

Keys remain `site` / `keywords` / `gsc`. Schemas remain `site.mcp.v1` / `keywords.mcp.v2` / `gsc.mcp.v1`.  
Adapters own `MonthlyMcpSourcePayload`. Do not add per-slice MCP families.

## HTTP Service API adapter

Preferred stack (live under [`SEO_ACCESS_API.md`](../api/SEO_ACCESS_API.md)):

```text
HTTP (docs/api/SEO_ACCESS_API.md)
 → authentication (service_api_credentials, scope seo:read) OR temporary Access token
 → site selection / site-bound temporary token
 → curated SEO Access composers (gateways / Context under the hood)
 → JSON resources: site, content, keywords, gsc
```

The public API must not expose every Context slice, must not require router/part/view vocabulary, and must not expose Monthly MCP payloads as the generic API.

Auth/transport SoT: [`API_AND_AUTHORIZATION.md`](API_AND_AUTHORIZATION.md).  
MCP router SoT (internal): [`SEO_MCP_ROUTER.md`](../modules/SEO_MCP_ROUTER.md).  
Context capability SoT: this document.

## HTTP API handoff

### READY now

- Strict slice registry (12 keys)  
- Validated views (fail-fast on explicit invalid)  
- Validated parameters (slice-specific allowlist + aliases)  
- Neutral DTO / `ContextSlice` results  
- Canonical recursive formatter  
- Site-scoped providers  
- Request/job-scoped GSC memoization  
- MCP-independent context layer  

### Next API phase owns

- Route design  
- Controller / resource layer  
- Authentication  
- Tenant/site authorization  
- Public `site_ref` resolution  
- HTTP errors / status codes  
- External pagination semantics (if any)  
- API contract tests  

### API MUST NOT

- Query domain models directly  
- Bypass `ContextRegistry`  
- Duplicate projection logic  
- Duplicate formatter logic  
- Expose Monthly MCP payload as the generic API  

## Implementation notes

- `GscMcpContextBuilder` = detail behind `GscContextGateway` (persisted facts only).  
- `DomainSeoMcpService` = legacy facade — not the context API.  
- Legacy Domain raw MCP page (`ViewDomainMcp` / `domains/{id}/mcp`) **removed** from Domain UX; Monthly MCP infrastructure remains.
