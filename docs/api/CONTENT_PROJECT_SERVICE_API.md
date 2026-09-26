# Content Project Service API

> Status: Canonical (implemented)  
> Owner: Core Service API foundation + `content-projects` addon HTTP adapter  
> Last verified: 2026-09-26  
> Domain SoT: [`CONTENT_PROJECTS.md`](../modules/CONTENT_PROJECTS.md), addons `CONTENT_PROJECT_ARCHITECTURE.md`  
> Auth SoT: [`API_AND_AUTHORIZATION.md`](../contracts/API_AND_AUTHORIZATION.md)  
> Related (separate contract): [`SEO_SERVICE_API.md`](SEO_SERVICE_API.md) — MCP **read** only

## Purpose

Expose **exactly one** Agent/tool write capability for Content Projects:

```text
content_project.draft.intake
```

Meaning: submit planning proposals into the canonical **Shared Planning Draft**, then stop.

The Agent does **not** control execution project creation, assignment workflows beyond Draft intake, generation, review, approval, scheduling, publish, retry, or archive.

## Separation from SEO MCP Service API

| Concern | Doc |
|---------|-----|
| MCP read / temporary capability URLs | `SEO_SERVICE_API.md` |
| Content Project Draft planning **write** | **This file** |

Temporary MCP tokens (`mcp:read`) remain **read-only**. They must never call this write endpoint.

## Authentication

| Concern | Rule |
|---------|------|
| Plane | Core `service_api_credentials` via `AuthenticateServiceApi` |
| Header | `Authorization: Bearer svc_live_…` |
| Scope | **`content-projects:draft:write`** (required) |
| Wildcard | Scope `*` allows Draft intake |
| Rate limit | `throttle:service-api` |
| Service gate | `EnsureSeoServiceApi` (SEO Service only) |
| Not used | Sanctum, session, `services.service_key`, `SiteService.settings.api_key`, temporary MCP hash |

Insufficient scopes (`mcp:read`, `service:read` alone) → `403` `service_api_scope_denied`.

## Single endpoint

```http
POST /api/v1/services/{service}/content-projects/draft/intake
Authorization: Bearer svc_live_…
Content-Type: application/json
Idempotency-Key: <opaque stable request key>
```

Canonical `{service}` public slug: `seo`.

Middleware:

```text
service.api
throttle:service-api
EnsureSeoServiceApi
service.api.scope:content-projects:draft:write
```

Confirmation token is **not** required (non-destructive planning only).

## Request schema

```json
{
  "site_id": 123,
  "items": [
    {
      "keyword": "may balo theo yeu cau",
      "title": "May balo theo yêu cầu cần lưu ý gì?",
      "description": "Optional planning note",
      "type": "new",
      "source": {
        "type": "agent",
        "ref": "optional-stable-source-ref",
        "reason": "content_gap"
      }
    }
  ]
}
```

### Top-level

| Field | Required | Notes |
|-------|----------|-------|
| `site_id` | yes | Positive integer; must exist in Core `sites` |
| `items` | yes | Non-empty array; max **100** |

No `project_ref`. Backend always resolves the Shared Planning Draft (`status=draft`, `site_id IS NULL`).

Unknown top-level fields → `422`.

### Item fields

| Field | Required | Notes |
|-------|----------|-------|
| `keyword` / `title` | one of identity paths | At least keyword **or** title for raw proposals (`ContentProjectItemIdentity`) |
| `type` | no (default `new`) | Wire: `new` \| `rewrite` → internal `create` \| `rewrite` |
| `description` | no | Planning note (stored in origin `reason_codes`, not `source_content`) |
| `keyword_id` / `keyword_ref` | no | Existing Keyword → `PlanningDraftIntakeService::addKeywords()` |
| `article_id` / `article_ref` | no | Existing Article → `PlanningDraftIntakeService::addArticles()` |
| `source` | no | Provenance object |

Refs: `keyword:123`, `article:456` (or bare positive integers).

**Forbidden:** item-level `site_id`, unsupported types (`improve`, etc.), unknown item fields.

One request = **one site**. No cross-site mix.

### Source (provenance)

```json
{
  "type": "agent|seo_audit|draft_audit|gsc|manual_api",
  "ref": "optional stable source ref",
  "reason": "optional concise reason"
}
```

Persisted via existing `seo_content_project_item_origins` (`source_type`, `reason_codes` including `source_ref:…` / `planning_note:…`, `source_fingerprint`). No duplicate origin system.

## Intake modes (one HTTP POST)

| Mode | Trigger | Canonical path |
|------|---------|----------------|
| A | `keyword_id` / `keyword_ref` | `PlanningDraftIntakeService::addKeywords()` |
| B | `article_id` / `article_ref` | `PlanningDraftIntakeService::addArticles()` |
| C | raw keyword/title | Shared Draft + `AddContentProjectItemsCommand` |

Orchestration lives in `ServiceApiDraftIntakeService` (not the controller).

## Shared Planning Draft

- Destination: canonical Draft (`status=draft`, project `site_id IS NULL`)
- Items carry `site_id` from the request
- No new per-site Draft
- No month semantics on Draft planning
- No automatic execution project / generate / review / schedule / publish

## Idempotency

Header: `Idempotency-Key`

Reuses `ContentProjectIdempotencyStore` with action `content_project.draft.intake`.

Same key + successful prior result → replay payload (`idempotent_replay: true`), no duplicate items.

Also: stable `source.ref` and raw keyword/title duplicate detection skip re-adds as `already_in_draft`.

## Response

```json
{
  "data": {
    "draft_ref": "cpj_…",
    "site_ref": "site:123",
    "submitted": 5,
    "added": 3,
    "already_in_draft": 2,
    "failed": 0,
    "items": [
      { "input_index": 0, "status": "added", "item_ref": "cpi_…" },
      { "input_index": 1, "status": "already_in_draft", "item_ref": "cpi_…" }
    ],
    "idempotent_replay": false
  }
}
```

- `draft_ref` / `item_ref`: `ContentProjectPublicRef` (`cpj_*` / `cpi_*`)
- `site_ref`: Service API form `site:{id}`
- HTTP **201** when `added > 0`, else **200**
- Partial success is explicit (per-item status). Transport/schema errors fail the whole request (`422`).

## Errors

| Status | Code | When |
|--------|------|------|
| 401 | `service_api_unauthorized` | Missing/invalid bearer |
| 403 | `service_api_scope_denied` / `service_api_forbidden` / `service_api_service_inactive` | Scope / non-SEO / inactive |
| 422 | `service_api_validation_failed` | Schema, site, type, batch size, item-level site_id, etc. |

## Explicit NON-GOALS

Do **not** expose via Service API:

- generate / review / approve
- schedule / auto-schedule / publish-now
- retry / skip / cancel publish
- archive / restore
- Agent execute / temporary MCP write

Legacy `/api/v1/content-projects/*` and `/api/v1/agent/*` remain unchanged and separate.

## Agent flow

```text
Temporary MCP (mcp:read) — discover / selective read
        ↓
AI reasoning — propose content
        ↓
POST …/content-projects/draft/intake  (permanent service_api_credentials)
        ↓
Shared Planning Draft
        ↓
Human / system review → backend lifecycle (not Agent)
```

Future Agent write capability surface for Content Projects is **only**:

`content_project.draft.intake`

## Ownership

| Layer | Owns |
|-------|------|
| Core | Auth, scopes, throttle, `routes/api-services.php` include hook |
| content-projects | `ContentProjectDraftIntakeController`, `ServiceApiDraftIntakeService`, `routes/api-services.php` |
| seo | `EnsureSeoServiceApi` (shared SEO Service gate) |

## Checklist

- [x] Single POST Draft intake  
- [x] Scope `content-projects:draft:write`  
- [x] Shared Draft destination  
- [x] Bulk + idempotency + provenance  
- [x] Lifecycle commands not exposed  
- [x] Docs separate from SEO MCP Service API  
