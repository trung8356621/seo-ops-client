# SEO Service API

> Status: Skeleton / **NOT IMPLEMENTED**  
> Owner: Core Service API foundation + `seo` addon consumers  
> Last verified: 2026-09-26  
> Application contract SoT: [`SEO_MCP_ROUTER.md`](../modules/SEO_MCP_ROUTER.md), [`CONTEXT_GATEWAYS.md`](../contracts/CONTEXT_GATEWAYS.md)

## Separation rule

| Document | Owns |
|----------|------|
| `docs/modules/SEO_MCP_ROUTER.md` | MCP routers, parts, manifest, selective read semantics |
| `docs/contracts/CONTEXT_GATEWAYS.md` | Context slices, registry, views, projection |
| **This file (`docs/api/**`)** | HTTP routes, auth, status codes, request/response wire format |

Do **not** put HTTP/auth details into MCP architecture docs.

## Current state

HTTP endpoints that wrap the SEO MCP Router / Context Registry are **not implemented** in this phase.

When implemented, expected shape (illustrative only — **not live**):

```text
GET  /api/v1/services/{service}/mcp/manifest     — NOT IMPLEMENTED
POST /api/v1/services/{service}/mcp/routers/{router}/read — NOT IMPLEMENTED
```

Auth (when wired) must use Core `service_api_credentials` / `AuthenticateServiceApi` — never `services.service_key`.

## Intended flow (future)

```text
External Agent / Tool
        ↓
HTTP Service API (this document)
        ↓
AuthenticateServiceApi + scopes
        ↓
McpRouterReader / ContextRegistry (application layer)
        ↓
structured JSON
```

Until routes exist, in-process callers should use `McpRouterRegistry` / `McpRouterReader` directly.

## Checklist before marking implemented

- [ ] Routes registered under Service API foundation  
- [ ] Auth + scope enforcement documented with real status codes  
- [ ] Request/response examples match live controllers  
- [ ] Cross-link from MCP docs updated from “not implemented” to this file’s live sections  
