# Keyword MCP

> Status: Canonical  
> Owner: `search-intelligence` (+ `seo` gateways / monthly MCP)  
> Last verified: 2026-09-24  
> Related: [`AGENT_AND_MCP_CONTRACTS.md`](AGENT_AND_MCP_CONTRACTS.md), [`SEO_AUDIT_AND_KEYWORDS.md`](../modules/SEO_AUDIT_AND_KEYWORDS.md), sibling addons [`TOPIC_CORE.md`](../../../omnichannel-addons/docs/modules/TOPIC_CORE.md)  
> Debt tracker: [`bugs/keyword-mcp-type-2-deferred.md`](../../bugs/keyword-mcp-type-2-deferred.md)

Keyword MCP is **two separate contracts**. Do not conflate them. Do not treat either as a generic public API.

| Type | Schema / capability | Shape | Snapshot |
|------|---------------------|-------|----------|
| **Type 1 — Landscape** | Snapshot schema `keywords.mcp.v2` (`McpSourceKey::Keywords`); also backs `domain.keyword_landscape` | Site-level Topic landscape | **May** persist via monthly MCP (`seo_mcp_source_snapshots`) |
| **Type 2 — Relationship** | Capability `keyword.relationship`, schema `keyword.relationship.v1` | One keyword, on-demand | **MUST NOT** write `seo_mcp_source_snapshots` |

Retired (do not restore): Agent/MCP `keyword_intelligence.*` (KI workspace / `cluster_key` era).

---

## Type 1 — Keyword Landscape (`keywords.mcp.v2`)

**Application boundary:** `KeywordLandscapeGateway` → `KeywordLandscapeReadModel` (Topic Core site-scoped).

### Approved consumers only

1. SEO Audit  
2. Prompt Generator  
3. Keywords / Topical Map  

Do **not** broaden Type 1 into a generic Agent/ACL surface or wire unrelated modules through this gateway.

Also backs monthly snapshot source key `keywords` and capability `domain.keyword_landscape`. HTTP MCP and in-process consumers share the same gateway (no HTTP loopback).

Type 1 architecture remains in force; this document does not redesign it.

---

## Type 2 — Keyword Relationship — **CLOSED — v1**

**Capability:** `keyword.relationship`  
**Schema:** `keyword.relationship.v1`  
**Application boundary:** `KeywordRelationshipGateway` → `KeywordRelationshipReadModel`  
**UI:** Keywords Relationship page (`KeywordRelationshipView`) + ECharts via `KeywordRelationshipGraphPresenter` (UI-only; ECharts config is **not** part of the MCP DTO)

### Approved consumers

1. Agent MCP capability `keyword.relationship`  
2. Keywords Relationship UI  

### Closure references (record only)

- addons: `a69d4ad9` — `fix(seo): close Keyword MCP type-2 blockers`  
- client: `5809d22` — `build(keywords): publish Keyword Relationship Vite manifest`  

### Sections (v1)

Payload sections / meta:

- `core` (keyword object)
- `topics`
- `focus_articles`
- `dna` (`topic_dna`)
- `related_keywords`
- `internal_links`
- `gsc`
- `planning`
- `relation_issues` (under `meta`)
- `available_sections` (under `meta`)

### Limits (v1)

| List | Limit |
|------|------:|
| related_keywords | 50 |
| internal_links (per direction) | 50 |
| gsc query_mappings | 50 |
| planning items | 20 |
| dna | 30 |

Each limited list envelope exposes: `total`, `returned`, `truncated`, `items`.

### Snapshot behavior

- On-demand read only.  
- **MUST NOT** insert/update `seo_mcp_source_snapshots`.  
- Not a site landscape (`keywords.mcp.v2`).

### Lock semantics

| Field | Source |
|-------|--------|
| `source_locked` | `Keyword.source_locked` |
| `membership_locked` | `SeoTopicKeyword.is_locked` when Topic membership exists; otherwise `null` |

The conflated public field `locked` is **removed** — do not document or reintroduce it.

### Internal-link semantics (v1)

Neighborhood = **Focus Article internal links only** (synced `seo_link_maps`; no WordPress crawl):

- `link_type` must be `internal`
- inbound: `target_article_id` = focus article
- outbound: `source_article_id` = focus article
- both directions site-scoped
- no Focus Article → section stays `available` (catalog present) with empty inbound/outbound slices — no fabricated edges
- external / `wiki_trust` rows are **excluded**

GraphPresenter (UI) attaches edges to the focus article node:

- inbound → `article:{focus}`
- `article:{focus}` → outbound

### Vite (Relationship UI)

- Entry in client `vite.config.js`: `addons/search-intelligence/resources/js/keyword-relationship-chart.js`
- Production build includes the entry; `public/build/manifest.json` lists it with `isEntry: true`
- Blade resolves via `@vite(['addons/search-intelligence/resources/js/keyword-relationship-chart.js'])`

### Verification status (v1 close)

**Implemented / verified**

- Contract: `KeywordRelationshipMcpBoundaryContractTest` PASS  
- UI boundary: `KeywordRelationshipUiBoundaryContractTest` PASS  
- Regression (Relationship + Landscape MCP + Topical Map Boundary/Audit): 48 tests, 289 assertions, PASS  
- Production Vite build PASS  

**MySQL integration proof — NOT EXECUTED**

- Real disposable MySQL tests A–D exist (`KeywordRelationshipReadModelIntegrationTest`)  
- Environment lacked `SEO_TEST_USE_MYSQL` + `SEO_TEST_DATABASE=*_test`  
- Result: **5 tests SKIPPED**  
- Documentation **must not** claim MySQL DB proof passed  

**Intentionally deferred** (not v1 blockers)

See [`bugs/keyword-mcp-type-2-deferred.md`](../../bugs/keyword-mcp-type-2-deferred.md): MySQL proof execution, GSC expansion, Tags, planning ID redesign, full `source_updated_at`, AI integration, additional charts.

Do **not** reopen Type 2 v1 solely because deferred items exist.

---

## Forbidden

1. Conflate Type 1 landscape with Type 2 relationship schemas or snapshot rules.  
2. Persist Relationship payloads into `seo_mcp_source_snapshots`.  
3. Broaden Type 1 consumers beyond SEO Audit / Prompt Generator / Keywords–Topical Map.  
4. Treat external / wiki_trust link maps as Type 2 `internal_links` edges.  
5. Reintroduce public keyword field `locked` instead of `source_locked` / `membership_locked`.  
6. Claim MySQL proof for Type 2 until disposable `*_test` suite is actually run and recorded.
