# Agent Runtime

> Status: Canonical for the new Agent  
> Owner: `omnichannel-addons/agent-runtime`  
> Last verified: 2026-09-27  
> Legacy workspace: [AGENT_WORKSPACE.md](AGENT_WORKSPACE.md) remains historical / reference-only

## What this is

Agent Runtime is a new React app plus a thin backend. It is not a refactor of Agent Workspace.

```text
User
→ Routing / Decision Runtime
→ RetrievalDecision
→ Retrieval Plan
→ SEO Access HTTP executor
→ RetrievalBundle
→ Answer Runtime
→ AgentResponse
→ React renderer
```

`addons/agent` stays skipped (`ADDON_SKIP_SLUGS` includes `agent`). Do not import `Omnichannel\Addons\Agent\...` into this runtime. Do not recreate `seo_agent_*` tables. Chat Workspace is a separate module and is unchanged.

## Decision Models

Decision Models is the AI Settings area. It is not Text, Reasoning, or Long-form.

| UI | Internal |
|----|----------|
| Decision Models area | `AiModelArea::Decision` (`decision`) |
| Decision routing profile | `AiExecutionProfile::DecisionRoute` (`decision.route`) |
| Capabilities | `decision.choice`, `decision.score`, `decision.probability` |

Jev is discovered from supported provider catalogs. OpenRouter's default `GET /api/v1/models` returns text-output models only. Sync also requests `GET /api/v1/models?output_modalities=decisions` and stores Jev ids that catalog returns. `typesafe/jev-router` is text output on the default catalog and is not a Decision model.

Current Decisions ids:

- `typesafe/jev-latest` (alias `~typesafe/jev-latest`)
- `typesafe/jev-1.13` (dated pins such as `typesafe/jev-1.13-20260917`)

The catalog does not insert `SeoAiModel` rows. Execution uses `POST https://openrouter.ai/api/alpha/decisions` with the OpenRouter connection key. It does not call chat completions.

Laya is not currently callable or discoverable through existing AI Connections. Laya support requires a dedicated Jev-compatible/self-host Decisions connection transport. `JevCompatibleDecisionTransport` is that boundary and stays unavailable (`laya_connection_unsupported`).

Agent Runtime does not name these models. It calls:

- `DecisionModelSource` — enabled models in AI Settings priority order
- `DecisionModelCompletion` — Decisions API result mapped back to routing JSON
- `DecisionModelGateway` — Agent-facing adapter

Enable, disable, and order use the existing AI Models table and the Decision routing card. There is no Prompt Admin record for this stage.

If no Decision Model is enabled, the turn stops before SEO fetch.

## Two stages

Both stages are runtime instructions in code, not editable Prompt records.

### Routing

Input: project scope, user message, compact history, resource catalog, the facts that global access and GSC force-sync are unsupported.

Output: `RetrievalDecision`

```json
{
  "intent": "september traffic",
  "needs": { "site": 0.2, "keywords": 0.4, "gsc": 0.9 },
  "parameters": { "period": "2026-09" },
  "requires_parameter_extraction": false,
  "requires_user_confirmation": false
}
```

Need scores are probabilities from 0 to 1. A resource is fetched only when its score is at least `agent-runtime.need_threshold` (default **0.5**, env `AGENT_RUNTIME_NEED_THRESHOLD`). Invalid JSON does not fall through to “fetch everything”.

`requires_parameter_extraction` does not start a second extraction model. The planner keeps only parameters it can validate (`YYYY-MM`, `topic:{id}`) and marks `parameter_extraction_deferred`.

### Retrieval

The system performs HTTP. The model does not.

Site scope uses the current SEO Access contract:

1. `POST /api/v1/services/seo/access` body `{ "site_id": N }`
2. `GET /api/v1/access/{token}/site|keywords|gsc`

Allowed resources: `site`, `keywords`, `gsc`, plus `keywords/topics/topic:{id}` when the decision named a topic ref.

The executor rejects any URL that is not the mint endpoint or a subpath of the minted access URL. It rejects metadata hosts. The model cannot pass Authorization headers or arbitrary URLs. A 401 on a temporary URL remints once.

Permanent bearer: `AGENT_RUNTIME_SEO_ACCESS_BEARER` (server only). Raw `svc_live_…` keys are not recoverable from `service_api_credentials`. If the env value is empty, the bundle records `seo_access_credential_not_configured` and does not read SEO tables instead.

### RetrievalBundle

```json
{
  "scope": { "type": "site", "site_ref": "site:7" },
  "sources": [
    { "name": "gsc", "status": "unavailable", "reason": "no_synced_data", "data": {} }
  ],
  "warnings": []
}
```

`available: false`, `no_synced_data`, and `latest_available` are kept. They are not rewritten as zero clicks.

### Answer

Answer Runtime receives the user message, compact history, the bundle, and the scope. It may explain a missing period and mention `latest_available`. It must not invent measured values. Chart and table numbers are rejected unless they appear on a source with `status: ok`.

Text completion uses the existing Reasoning Text route through `AnswerTextCompletion` with runtime hook `agent.runtime.answer`. That hook is not in the Prompt Admin map.

## AgentResponse

```json
{
  "message": "...",
  "blocks": [],
  "actions": [],
  "sources": []
}
```

Block types: `markdown`, `chart` (`line` or `bar`), `table`, `warning`. The React app draws charts with ECharts. The model does not return HTML or SVG.

`sources` on the parsed response are taken from the bundle, not from the model.

## React

The Filament page is only a mount point. It hides the admin sidebar and keeps the shared top bar. The project list inside the app is:

1. All Sites (`type: global`)
2. Each active site

There is no Add Website control and no manual “fetch SEO Access” button. Copy and Send both use `AgentModelInputBuilder` → `PreparedModelInput`. Copy calls `/agent-runtime/model-input` and does not complete the answer model. The default clipboard payload is the answer-stage input. Diagnostics can copy the routing input.

## Project scope

```ts
type AgentProjectScope =
  | { type: 'global' }
  | { type: 'site'; siteId: number; siteRef: string }
```

All Sites does not loop sites and does not call SEO Access. The turn returns `global_access_unsupported`.

## Write boundary

The only future write name is `content_project.draft.intake` (`POST /api/v1/services/seo/content-projects/draft/intake`). V1 does not call it. `ContentProjectDraftIntakeTool::isConnected()` is false.

## API gaps requiring a separate API decision

These are not implemented here:

| Use case | Missing API | Why Agent needs it |
|----------|-------------|--------------------|
| All Sites overview | Global SEO Access: no site-less mint, no batch access | `{type:global}` cannot call `POST /access` because that body allows only `site_id` |
| Cross-site ranking | Same | Would require either a global read or N site mints, which this runtime will not invent |
| GSC sync now | No force-sync / fetch-now on `/gsc` | Answer copy may explain `no_synced_data` and `latest_available` only |
| Draft intake execution | Endpoint exists | Intentionally not connected in this slice |

Minimum global contract to discuss, not to build in this addon:

```http
POST /api/v1/services/seo/access
{ "scope": "global" }
```

```json
{
  "data": {
    "access_url": "https://host/api/v1/access/access_tmp_…",
    "scope": { "type": "global" },
    "expires_at": "…"
  }
}
```

```http
GET /api/v1/access/{token}/overview
```

```json
{
  "data": {
    "schema": "seo.access.overview.v1",
    "sites": [
      { "site_ref": "site:7", "domain": "example.com", "gsc": { "available": false, "reason": "no_synced_data" } }
    ]
  }
}
```

Unavailable periods must keep `available: false` and must not be zeros.

## Configuration

| Key | Default | Role |
|-----|---------|------|
| `AGENT_RUNTIME_NEED_THRESHOLD` | `0.5` | Minimum need score to fetch a resource |
| `AGENT_RUNTIME_SEO_ACCESS_BEARER` | empty | Server-only `svc_live_…` used to mint access |
| `AGENT_RUNTIME_SEO_ACCESS_BASE_URL` | `APP_URL` | Only origin the executor may call |
| `AGENT_RUNTIME_DECISION_MAX_OUTPUT` | `800` | Decision completion cap |
| `AGENT_RUNTIME_ANSWER_MAX_OUTPUT` | `2048` | Answer completion cap |
