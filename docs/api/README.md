# API documentation

HTTP-facing contracts for SEO Ops client Service APIs.

| Doc | Status |
|-----|--------|
| [SEO_ACCESS_API.md](SEO_ACCESS_API.md) | **Canonical** — unified SEO Access read (`seo:read`) + temporary site-bound access (`site` / `keywords` / `gsc`) |
| [CONTENT_PROJECT_SERVICE_API.md](CONTENT_PROJECT_SERVICE_API.md) | **Canonical** — Shared Planning Draft intake write (`content-projects:draft:write`) |
| [SEO_SERVICE_API.md](SEO_SERVICE_API.md) | **Retired** — former MCP HTTP surface; see SEO Access |

External Agent read contract: `SEO_ACCESS_API.md`.  
Internal MCP/Context composition: `docs/modules/SEO_MCP_ROUTER.md` + `docs/contracts/CONTEXT_GATEWAYS.md`.  
Content Project domain: `docs/modules/CONTENT_PROJECTS.md`.
