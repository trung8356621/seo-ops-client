# AI History & Prompt Version — Single Source of Truth

> Status: Canonical  
> Last verified: 2026-09-09  
> Owner: `omnichannel-addons/ai-prompt`  
> Routing SoT: [`AI_EXECUTION_ROUTING.md`](AI_EXECUTION_ROUTING.md)  
> Module map: [`PROMPTS_AND_AI.md`](../modules/PROMPTS_AND_AI.md) · [`ARTICLE_EXECUTION_HISTORY.md`](../modules/ARTICLE_EXECUTION_HISTORY.md)

---

## 1. History role

AI History is **not** primarily a prompt-text archive anymore.

Primary debugging hierarchy:

```
Prompt Type
    ↓
Execution (PromptResult)
    ↓
Routing Attempts
        ↓
Model / Route / Result / Error
    ↓
Validation
    ↓
Final execution result
```

Prompt content is **secondary detail** (reconstruct on demand).

---

## 2. Prompt Version

| Concept | Detail |
|---------|--------|
| Table | `prompt_versions` (`omi_seo_ai`) |
| Model | `PromptVersion` — **immutable** once created |
| Pointer | `prompts.current_prompt_version_id` |
| Service | `PromptVersionService` |

### Version labels

Format: `D.M.YY` (day.month.2-digit-year), e.g. `9.9.26`.

Same-day revisions:

```text
9.9.26
9.9.26-r2
9.9.26-r3
```

Internal identity remains `prompt_version_id` / sequence.  
A version is **NOT** merely formatted `updated_at`.  
A version represents an **execution-affecting** prompt definition.

---

## 3. Version fingerprint

Fields included in fingerprint (`PromptVersionService::VERSIONED_FIELDS` / `fingerprintPayload`):

- `markdown_content`  
- `hook_key`  
- `hook_version`  
- `hook_settings`  
- `tools`  
- `settings.post_processing` (as `post_processing` in payload)

Fields intentionally **not** versioned:

- `name`  
- `description`  
- `detected_tags`  
- counters  
- `last_used_at`  
- UI-only timestamps  

Old prompt version content is immutable. New fingerprint → new version row.

---

## 4. Compiled prompt storage

New `PromptResult` must **not** hot-store:

```text
input_snapshot.compiled_prompt
```

`PromptRunner` sanitize + `PromptExecutionPersistence` normalization strip it.

`PromptResult` stores:

- `compiled_prompt_hash`  
- `prompt_version_id`  
- correlation / failure metadata (see below)

### Reconstruction

```
PromptReconstructor
  = PromptVersion
  + execution inputs / context references
  + PromptCompiler
```

History **list** does not preload prompt/output blobs (`PromptResult::HOT_COLUMNS`).

Hash mismatch must be treated as **reconstruction mismatch**, not silently assumed exact.

---

## 5. Routing attempts table

Table: `prompt_result_routing_attempts`

- FK cascade by `prompt_result_id` → `prompt_results`  
- Unique `(prompt_result_id, sequence)`

Important fields:

| Field | Role |
|-------|------|
| `logical_model` | Trace / UI grouping |
| `physical_route` | Physical identity |
| `provider` / `connection_id` / `connection_name` | Connection context |
| `provider_model` | Provider model id |
| `cost_class` | free / paid metadata |
| `state` | outcome state |
| `attempted` | true only for real API calls |
| `skip_reason` | health / budget / policy skip |
| `http_status` | provider HTTP |
| `failure_category` / `failure_code` / `failure_scope` | normalized failure |
| `health_mutation` | whether health changed |
| `sequence` | monotonic routing **event** order |
| `token_usage` / `raw` | usage & raw attempt payload |
| `duration_ms` | timing |

**SKIPPED ≠ API ATTEMPT** (`attempted = false` → 0 attempts).

Model: `PromptResultRoutingAttempt`. Sync on save: `PromptExecutionPersistence::syncRoutingAttempts`.

---

## 6. History UX

Top-level grouping: **canonical prompt key** — not date/run.

- Latest execution first  
- “Latest only” defaults **ON**

History should surface:

- Prompt Type  
- Prompt Version (label)  
- Stage  
- Routing timeline  
- Provider result  
- Validation result  
- Normalized failure  

States must distinguish:

| State | Meaning |
|-------|---------|
| Provider attempted + failed | `attempted=true`, provider error |
| Provider skipped | health/budget/policy; `attempted=false` |
| No model attempted | zero API attempts → ROUTING |
| Provider success + validation failed | VALIDATION (not provider failure) |

Presenter / list: `ArticlePromptRunHistoryService` (and related Filament pages).

---

## 7. History lifecycle

Intended product lifecycle:

```
Content Project active
  → AI History useful for debugging / review

Project archive
  → AI execution / history / runtime data intended to be cleaned

Prompt Definitions / Prompt Versions remain
```

**Known issue:** archive cleanup integration for `PromptResult` / history is still a **follow-up**.  
Do not implement cleanup in an unrelated routing/docs task.

Reset tooling (admin/debug only, explicit): `AiHistoryResetService` — never use without approval.

---

## 8. PromptResult correlation columns (storage)

Present on `prompt_results` (migration `2026_09_09_100000_*`):

- `canonical_prompt_key`  
- `stage`  
- `compiled_prompt_hash`  
- `prompt_version_id`  
- `content_project_id`  
- `project_item_id`  
- `run_id`  
- `node_id`  
- `retry_attempt`  
- `correlation_id`  
- `failure_category` / `failure_code`  

See [`CONTENT_PROJECT_AI_INTEGRATION.md`](CONTENT_PROJECT_AI_INTEGRATION.md) for expected vs proven correlation completeness.

---

## 9. Related tests

- `PromptVersionAndHistoryStorageTest`  
- `AiExecutionLayerObservabilityRefactorTest`  
- `ArticlePromptRunHistorySplitPresentationTest`  
