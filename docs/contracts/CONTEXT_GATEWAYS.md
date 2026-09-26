# Domain Context Gateways

> Status: Canonical  
> Owner: `seo` (+ `search-intelligence` read models)  
> Last verified: 2026-09-26  
> Related: [`KEYWORD_MCP.md`](KEYWORD_MCP.md), [`SITE_MCP_AND_DOMAINS.md`](../modules/SITE_MCP_AND_DOMAINS.md), [`AGENT_AND_MCP_CONTRACTS.md`](AGENT_AND_MCP_CONTRACTS.md)

## Purpose

Standardize SEO **domain context** as MCP-independent application infrastructure, with reusable **context slices**, a **Context Registry**, and a shared **projection/formatter** layer.

**Not implemented here:** unified HTTP API, AI Context Planner, AI tool calling, new Agent, new MCP sources, Planning Context.

## Architecture

```text
Domain readers / read models
          ↓
Canonical Context Slice Providers
          ↓
Context Registry
          ↓
Context Projection / Formatter
          ↓
Canonical Context Format
       /             \
large presets      individual slices
       ↓
MCP adapters (compatibility)
```

Future (deferred):

```text
Context Registry → AI Context Planner → selected slices → formatter → AI
```

### Dependency direction (enforced)

```text
Domain → Readers/ReadModels → Slice Providers → Registry/Projection → DTOs → Consumers
```

Consumers include: UI/internal, Monthly MCP compatibility, future HTTP API, future AI Context Planner.

**Never:** `Context → MonthlyMcp`, `Context → Agent`, `Context → HTTP`.

**Never:** request one slice by assembling a giant composite then extracting one field, when a direct reader exists.

### Vocabulary

| Term | Meaning |
|------|---------|
| **Context Gateway / preset** | Large composition (`SiteContext`, landscape, GSC full) for current consumers |
| **Context Slice** | Reusable read-only data capability (`site.indexability`, `gsc.opportunities`, …) |
| **Context Registry** | Allowlisted read capabilities + providers (not an AI planner) |
| **Projection** | View level: `summary` / `standard` / `detail` (+ list limits) |
| **Formatter** | Structured representation (facts, no AI prose) |
| **MCP** | Compatibility / snapshot consumer of neutral context |
| **AI Planner** | Future consumer — not implemented |

## Site Knowledge Profile vs Site Intelligence

| Concept | Meaning | Code |
|---------|---------|------|
| **Site Knowledge Profile** | Prompt tone / CTA / links / draft | `search-foundation` `SiteMcp*` |
| **Site Intelligence Context** | Runtime health / content / links / findings / sync | `seo` `SiteContext*` + slices |

Do **not** merge these.

## Canonical gateways (presets)

| Preset | Gateway | Schema id (compat) | Snapshot |
|--------|---------|-------------------|----------|
| Site Intelligence | `SiteContextGateway` | `site.mcp.v1` | monthly `site` |
| Keyword Landscape | `KeywordLandscapeGateway` | `keywords.mcp.v2` | monthly `keywords` |
| Keyword Relationship | `KeywordRelationshipGateway` | `keyword.relationship.v1` | **on-demand only** |
| GSC | `GscContextGateway` | `gsc.mcp.v1` | monthly `gsc` |

`SiteContext` is a **preset composition** of site/content/seo/publishing slices — not the only way to read site intelligence.

## Registered context slices

| Key | Meaning | Period |
|-----|---------|--------|
| `site.health` | Heartbeat health | current |
| `site.indexability` | Indexable / noindex counts | current |
| `site.sync` | Sync freshness | current |
| `content.inventory` | Article counts | current |
| `content.distribution` | Content-type distribution | current |
| `seo.findings` | Open findings | current |
| `seo.internal_links` | Internal linking / link analysis | current |
| `publishing.status` | Publishing status counts | current |
| `keywords.landscape` | Topic landscape | current |
| `keywords.relationship` | One-keyword graph (`keyword_ref` required) | on-demand |
| `gsc.performance` | Totals / comparison / tops | period-aware |
| `gsc.opportunities` | Rising / CTR / near-page-one / decay / new-content | period-aware |
| `gsc.cannibalization` | Cannibalization signals | period-aware |

Unknown keys are rejected. Parameters are allowlisted (e.g. `period`, `limit`, `keyword_ref`).

Views: `summary` (default for most), `standard`, `detail`.

## Neutral Context helpers

| Class | Role |
|-------|------|
| `ContextFreshness` | Staleness / max timestamps (Monthly MCP delegates) |
| `ContextDataQuality` | Site quality warnings (Monthly MCP may wrap) |
| `ContextEnvelopeBuilder` | Outer envelope metadata |
| `ContextSlice` | Slice result contract |
| `ContextRegistry` | Allowlisted providers |
| `ContextProjection` / `ContextFormatter` | Views + structured output |

Canonical Context **must not** import `Services\MonthlyMcp`.

## Formatting rules

- Structured facts, not prose (“Your site currently has…”).
- Omit null / empty optional padding; keep meaningful zeros.
- Readable keys (not cryptic `a`/`b`/`c`).
- Large lists use `{ items, returned, total, truncated }`.
- GSC `ai_lines` stay MCP/UI compatibility only — not in canonical GSC slices.

## Monthly MCP compatibility

```text
neutral domain context → Monthly MCP Source adapter → seo_mcp_source_snapshots
```

- Source keys remain: `site`, `keywords`, `gsc`
- Schema ids remain: `site.mcp.v1`, `keywords.mcp.v2`, `gsc.mcp.v1`
- Adapters own `MonthlyMcpSourcePayload` conversion
- Do **not** add per-slice MCP snapshot families

## Future HTTP (deferred)

```text
GET /api/v1/contexts/sites/{site_ref}
GET /api/v1/contexts/sites/{site_ref}/keywords
GET /api/v1/contexts/sites/{site_ref}/keywords/{keyword_ref}
GET /api/v1/contexts/sites/{site_ref}/gsc
(+ slice endpoints later)
```

```text
HTTP → auth/site-scope → ContextRegistry / Gateway → DTO → JSON
```

## Implementation notes

- `GscMcpContextBuilder` = implementation detail behind `GscContextGateway` (persisted facts only).
- `GscContextSource` = request-scoped memoization for multiple GSC slices.
- `SiteMcpContextBuilder` = deprecated thin MCP adapter.
- `DomainSeoMcpService` = legacy facade — not the context API.
