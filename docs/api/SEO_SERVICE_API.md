# SEO Service API (legacy MCP HTTP — retired)

> Status: **Retired public HTTP surface**  
> Canonical external read contract: [`SEO_ACCESS_API.md`](SEO_ACCESS_API.md)  
> Canonical Agent write: [`CONTENT_PROJECT_SERVICE_API.md`](CONTENT_PROJECT_SERVICE_API.md)  
> Internal MCP / Context architecture: [`SEO_MCP_ROUTER.md`](../modules/SEO_MCP_ROUTER.md), [`CONTEXT_GATEWAYS.md`](../contracts/CONTEXT_GATEWAYS.md)

## What changed

The public Agent/integration contract no longer exposes MCP routers/parts.

| Old (retired HTTP) | New (canonical) |
|--------------------|-----------------|
| `GET/POST …/mcp…` | Removed from public routes |
| `POST …/mcp/access` | `POST /api/v1/services/seo/access` |
| `GET /api/v1/mcp/access/{token}…` | `GET /api/v1/access/{token}…` |
| Scope `mcp:read` | Scope **`seo:read`** |
| Token prefix `mcp_tmp_` | `access_tmp_` |

Internal ContextRegistry / McpRouterRegistry may still exist for composition — they are implementation details. Monthly MCP snapshot/report layer is **retired**.

Do not document old MCP HTTP paths as the future Agent contract.

## Where to go

1. Read SEO context → [`SEO_ACCESS_API.md`](SEO_ACCESS_API.md)
2. Submit planning proposals → [`CONTENT_PROJECT_SERVICE_API.md`](CONTENT_PROJECT_SERVICE_API.md)
3. Understand internal slices/routers → [`CONTEXT_GATEWAYS.md`](../contracts/CONTEXT_GATEWAYS.md) + [`SEO_MCP_ROUTER.md`](../modules/SEO_MCP_ROUTER.md)
