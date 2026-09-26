# Domain Context Gateways

> Status: Canonical  
> Owner: `seo` (+ `search-intelligence` read models)  
> Last verified: 2026-09-26  
> Related: [`KEYWORD_MCP.md`](KEYWORD_MCP.md), [`SITE_MCP_AND_DOMAINS.md`](../modules/SITE_MCP_AND_DOMAINS.md), [`AGENT_AND_MCP_CONTRACTS.md`](AGENT_AND_MCP_CONTRACTS.md)

## Purpose

Standardize SEO **domain context** application boundaries so future HTTP API and MCP adapters can share one in-process implementation.

**This is not the unified HTTP API.** Controllers may later wrap these gateways; they must not duplicate business logic.

## Architecture

```text
Domain models / storage
        ↓
Domain ReadModel / Builder / Readers
        ↓
Canonical Context Gateway
        ↓
Canonical DTO / typed payload
       /        |        \
internal      future     future
consumer       HTTP       MCP
               API       adapter
```

### Rules

| Rule | Meaning |
|------|---------|
| Domain Context ≠ MCP transport | MCP schemas may remain as persisted ids; architecture treats MCP as a consumer/adapter |
| One gateway per context | `SiteContextGateway`, `KeywordLandscapeGateway`, `KeywordRelationshipGateway`, `GscContextGateway` |
| No HTTP loopback | In-process Laravel modules call gateways directly — never `HTTP → /api/v1/... → same app` |
| Consumers depend on gateways | Not foreign Eloquent / ReadModel / raw meta for these context surfaces |
| `DomainSeoMcpService` is not canonical | Legacy capability facade; delegates to gateways where possible |

## Canonical contexts

| Context | Gateway | Schema (compat) | Snapshot |
|---------|---------|-----------------|----------|
| Site Intelligence | `SiteContextGateway` | `site.mcp.v1` | Monthly source `site` |
| Keyword Landscape | `KeywordLandscapeGateway` | `keywords.mcp.v2` | Monthly source `keywords` |
| Keyword Relationship | `KeywordRelationshipGateway` | `keyword.relationship.v1` | **On-demand only** — never monthly snapshots |
| GSC | `GscContextGateway` | `gsc.mcp.v1` | Monthly source `gsc` |

### Site Knowledge Profile vs Site Intelligence Context

| Concept | Meaning | Code home |
|---------|---------|-----------|
| **Site Knowledge Profile** | User-managed prompt knowledge: tone, description, CTA, links, draft | `search-foundation` `SiteMcp*` / Domain Edit form |
| **Site Intelligence Context** | Runtime intelligence: health, content stats, links, publishing, SEO findings, sync freshness | `seo` `SiteContextGateway` / monthly `site.mcp.v1` |

Do **not** merge these concepts.

## Shared envelope (future API readiness)

Gateways expose `envelope(...)` / `ContextEnvelope` with metadata equivalent to:

```json
{
  "schema": "...",
  "version": 1,
  "scope": { "site_ref": "site:123" },
  "generated_at": "...",
  "source_updated_at": "...",
  "stale": false,
  "available": true,
  "data": {}
}
```

- Shared metadata semantics are consistent across contexts.  
- Each context’s `data` remains domain-specific (no giant untyped universal schema).  
- Persisted monthly snapshot column schemas stay compatible (`site.mcp.v1`, `keywords.mcp.v2`, `gsc.mcp.v1`).

Shared helpers: `seo` `ContextEnvelopeBuilder`, `ContextEnvelope` interface.

## Monthly MCP

Monthly MCP is an **aggregation/snapshot consumer** of context gateways:

```text
Context Gateway → canonical DTO → Monthly MCP Source adapter → seo_mcp_source_snapshots
```

Official monthly source keys: `site`, `keywords`, `gsc`. Keyword Relationship stays outside snapshots.

## Future HTTP (not implemented here)

Conceptual routes:

```text
GET /api/v1/contexts/sites/{site_ref}
GET /api/v1/contexts/sites/{site_ref}/keywords
GET /api/v1/contexts/sites/{site_ref}/keywords/{keyword_ref}
GET /api/v1/contexts/sites/{site_ref}/gsc
```

```text
HTTP → auth/site-scope → ContextGateway → DTO → JSON
```

## Implementation notes

- `GscMcpContextBuilder` remains an implementation detail behind `GscContextGateway` (persisted GSC facts only).  
- `SiteMcpContextBuilder` is a thin deprecated adapter over `SiteContextGateway`.  
- Site Intelligence assembly: `SiteContextAssembler` + domain readers (`SiteSeoHealthReader`, `SiteContentContextReader`, `SiteSyncContextReader`, `SiteLinkContextReader`, `SitePublishingContextReader`).
