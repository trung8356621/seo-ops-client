# Content Project ↔ AI Execution Integration

> Status: Canonical boundary doc  
> Last verified: 2026-09-21
> Owners: `content-projects` (workflow / domain) · `ai-prompt` (PromptResult / routing)  
> Routing SoT: [`AI_EXECUTION_ROUTING.md`](AI_EXECUTION_ROUTING.md)  
> History SoT: [`AI_HISTORY_PROMPT_VERSION.md`](AI_HISTORY_PROMPT_VERSION.md)  
> Module: [`CONTENT_PROJECTS.md`](../modules/CONTENT_PROJECTS.md)  
> **Site / domain ownership SSOT:** sibling `omnichannel-addons/docs/modules/CONTENT_PROJECT_ARCHITECTURE.md`

**Purpose:** Keep future AI work from accidentally changing Content Project sequencing or domain authority.

---

## 1. Current stage sequence (observed E2E)

Typical generation pipeline:

```
Workflow Outline node:
    article.outline.structure.generate
    article.vocabulary.generate

then:
    article.content.generate

then (if enabled):
    article.image.generate
```

### UI vs prompt stage

UI node label **“Outline”** may contain **both** structure + vocabulary child prompts.

The 2026-09-15 one-item live smoke completed both child prompts and `article.content.generate` (run #238, 0 failed items); the generated article body was nonempty. This verifies the split sequence for Direct DeepSeek in that fixture, not every future OpenRouter route or a browser-driven full-project run.

**Do not infer prompt stage from UI node label alone.**  
Use canonical hook / `canonical_prompt_key` / workflow action id.

Role registry reference: `WorkflowExecutionRoleRegistry` (maps hooks → execution roles).

---

## 2. Correlation metadata

Expected `PromptResult` correlation for debugging:

| Field | Intent |
|-------|--------|
| `content_project_id` | Project container |
| `project_item_id` | Task / item |
| `run_id` | Run |
| `run_item_id` | Run-item (job context; **may not** be a first-class PromptResult column) |
| `node_id` | Workflow node |
| `canonical_prompt_key` | Prompt type |
| `stage` | Execution stage |
| `retry_attempt` | Retry index |
| `correlation_id` | Cross-layer correlation |

Ideal History drill-down:

```
Project Item
  → Run
  → Node
  → Prompt
  → Route
  → Validation
  → Result
```

### Persistence status (do not over-claim)

Columns exist and writers populate several fields (`PromptExecutionPersistence`, `PromptRunnerService` variables).  

**Known open:** end-to-end completeness of every correlation field (especially dedicated `run_item_id` on PromptResult, and consistent population across all CP entry points) may still be incomplete. Treat as follow-up unless a current E2E run proves each field.

Do **not** claim full correlation is production-complete without evidence.

---

## 3. Content Project domain authority

Full ownership boundary (project domain-neutral; one project may hold multi-site tasks):  
`omnichannel-addons/docs/modules/CONTENT_PROJECT_ARCHITECTURE.md`.

CREATE / bind contract used by AI generation (summary only):

| Entity | Authority |
|--------|-----------|
| **Project** | Container / lifecycle / assignment (`site_id` may be null; **not** domain owner) |
| **Task / Item** | **Site / domain authority** (`task.site_id`) |
| **Article** | Must match `task.site_id` for CREATE generation |

### CREATE generation invariant

```text
article.site_id === task.site_id
```

**Do NOT** use legacy `project.site_id` to override `task.site_id`.

Legacy `project.site_id` mismatch vs item site is **allowed** (multi-domain / domain-neutral projects).

Evidence / tests:

- `ContentProjectBindArticleAuthority` / `ContentProjectBindArticleSiteAuthorityTest`
- `ContentProjectCreateGenerationGuard` / `ContentProjectCreateGenerationSiteAuthorityTest`  
- `ContentProjectLegacyTaskForensicContractTest`  
- related packing notes in `CONTENT_PROJECTS.md` (Execution Project `site_id=null`; item-level site on task)

---

## 4. Routing preferences from Content Project

`ItemGenerationRoutingPreference` (content-projects):

- `orderCandidates` = **identity** (does not reorder by FastEconomy / BestQuality)  
- `prependPreferred` = only intentional float for explicit `model_override_id`  

Cost / generation mode must not rewrite AI Center order. See routing SoT §3–4.

For the observed failure, OpenRouter's paid connection was skipped before HTTP (`connection_paid_locked`), while the independent DeepSeek Direct route returned HTTP 200. Its `thinking` tokens consumed a 2048-token output budget and yielded empty `content` with `finish_reason=length`; this was an output-budget/provider-response issue, not lack of DeepSeek funds. Direct `deepseek-v4-pro` split hooks now plan 8192 output tokens and disable thinking by default; other physical routes retain their own preflight ceilings. See `DeepSeekChatClient`, `PromptRunnerService`, and `ModelContextCapabilityResolver` in `ai-prompt`.

**Worker death vs heartbeat (2026-09-15+):** Client config `CONTENT_PROJECT_WORKER_DEATH_SECONDS` (0 ⇒ derive from TTL floor ≥960s) + `CONTENT_PROJECT_ARTICLE_JOB_TIMEOUT_SECONDS` (default 900). Watchdog declares `WORKER_LOST` only after hard lease — heartbeat stale alone must not kill. Resume re-queues the interrupted item; no auto-jump to next article. Lazy bulk decides generate/resume/restart/skip at claim (`lazy_bulk`). See [`CONTENT_PROJECTS.md`](../modules/CONTENT_PROJECTS.md) § Generate.

---

## 5. Known open items (CP × AI)

- Paid OpenRouter connection #2 live fallback and full-project browser workflow still require a separate test; Direct DeepSeek one-item split was verified 2026-09-15.
- PromptResult correlation completeness vs run engine metadata  
- Project archive → Prompt History cleanup  
- Dedicated queue / worker configuration for CP AI jobs if still unresolved  
- Browser-session visual verification of ops `lazyRefreshOps` / batch-waiting badges remains outstanding after 2026-09-15 Blade checks.

Do not mix these into unrelated routing or validation fixes.

---

## 6. Related documents

- [Explorable CP × AI Router workflow](content-project-ai-router-workflow.html) — Archify 9/9 showcase artifact checks, desktop browser containment passed, and light/dark screenshots reviewed 2026-09-15.
- [`CONTENT_PROJECTS.md`](../modules/CONTENT_PROJECTS.md)  
- [`AI_EXECUTION_ROUTING.md`](AI_EXECUTION_ROUTING.md)  
- [`AI_HISTORY_PROMPT_VERSION.md`](AI_HISTORY_PROMPT_VERSION.md)  
- [`operations/AI_DEBUG_PLAYBOOK.md`](../operations/AI_DEBUG_PLAYBOOK.md)  
