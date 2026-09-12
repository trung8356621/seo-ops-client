# AI Execution & Routing — Single Source of Truth

> Status: Canonical (stabilized AI execution layer)  
> Last verified: 2026-09-12  
> Owner addon: `omnichannel-addons/ai-prompt` (+ Content Project preference helpers in `content-projects`)  
> Module map: [`PROMPTS_AND_AI.md`](../modules/PROMPTS_AND_AI.md)  
> Related: [`AI_HISTORY_PROMPT_VERSION.md`](AI_HISTORY_PROMPT_VERSION.md) · [`CONTENT_PROJECT_AI_INTEGRATION.md`](CONTENT_PROJECT_AI_INTEGRATION.md) · [`operations/AI_DEBUG_PLAYBOOK.md`](../operations/AI_DEBUG_PLAYBOOK.md) · ADR-018 [`decisions/AI_CENTER_MODEL_AUTHORITY_ARTICLE_GENERATION.md`](decisions/AI_CENTER_MODEL_AUTHORITY_ARTICLE_GENERATION.md)

**This document is the authoritative contract for AI model execution order, eligibility, budget, fallback, validation, and failure taxonomy.**  
Future agents must not reintroduce bugs by misunderstanding these invariants.

---

## 1. Execution model

Hierarchy:

```
Prompt / Task
    ↓
Model Area / Capability
    ↓
Logical Model
    ↓
Physical Route
    ↓
API Connection / Provider Model
```

Example — logical model **DeepSeek Chat**:

```
DeepSeek Chat
    ├ DeepSeek Direct / deepseek-chat
    └ OpenRouter / deepseek/deepseek-chat
```

### Invariant

**A logical model is NOT a physical API route.**

Failure / skip / paid-lock of one physical route does **not** automatically fail or suppress sibling physical routes of the same logical model.

Physical identity (SoT key for attempt / suppress / health):

```text
connection_id|provider|provider_model
```

Examples: `12|openrouter|deepseek/deepseek-chat`, `34|deepseek|deepseek-chat`  
Implemented as `RoutedAiCandidate::physicalRouteKey()`.

Logical identity (`logicalModelKey()` / `AiCanonicalModelKey`) may group UI/trace only — **never** as the sole suppression key.

---

## 2. Model area

Current areas (`AiModelArea`):

| Area | Key | Typical use |
|------|-----|-------------|
| Fast Text | `fast_text` | Short / low-latency text |
| Long-form Text | `long_form_text` | Article content, KD longform |
| Reasoning Text | `reasoning_text` | Outline / Vocabulary / reasoning |
| Image | `image` | Image generation |
| Video | `video` | Video generation |

Candidate eligibility is resolved for the **required area** before execution.  
**Do not mix unrelated areas during fallback.**

---

## 3. Manual sortable order — highest priority contract

> **UI contract: «Thứ tự kéo tay luôn thắng.»**

AI Center sortable order is the **runtime model execution order**.

Example AI Center:

1. DeepSeek Chat  
2. OpenRouter Free Pool  
3. Claude Sonnet  
4. Gemini  

Runtime must evaluate (subject only to legitimate exclusion/skip):

```
DeepSeek → Free Pool → Claude → Gemini
```

**Cost class MUST NOT reorder candidates.**

### Historical bug (resolved)

`ItemGenerationRoutingPreference` previously reordered:

- `FastEconomy` → free models first  
- `BestQuality` → reversed candidates  

That bypassed manual sortable priority.

### Current behavior

| Symbol | Contract |
|--------|----------|
| `ItemGenerationRoutingPreference::orderCandidates` | **identity** (preserves caller order; mode unused) |
| `ArticleModelOrderAuthority::allowsGenerationModeReorder` | **always `false`** (every hook) |
| Cost / economy modes | do **not** imply free-first |

Logical priority source: `AiModelPriorityService::areaEnabledModels`.  
Physical route priority remains **per logical model** configured route priority.

ADR-018 originally scoped article content hooks; **stabilized runtime** applies manual-sortable authority globally via `ArticleModelOrderAuthority`.

---

## 4. Order policy vs cost policy

### ORDER POLICY

**Source:** AI Center sortable + physical route priority.  
**Controls:** which model/route is evaluated first.

### COST POLICY

**Controls:**

- allowed / forbidden cost class  
- `MAX_FREE_ATTEMPTS`  
- `MAX_AI_ATTEMPTS`  
- paid lock  
- FreeOnly  

**Cost policy does NOT control ordering.**

Explicit statements:

- **DEFAULT / Economy does NOT mean free-first.**
- **`MAX_FREE_ATTEMPTS` does NOT mean** “call free models before paid.”  
  It means: maximum number of **actual free API calls** if/when free candidates are reached **in sortable order**.

---

## 5. FREE_ONLY

`FREE_ONLY` (`AiExecutionRoutingMode::FreeOnly`) is the **only** explicit cost policy allowed to **remove paid routes**.

Example sortable: `1 DeepSeek paid` → `2 Free Pool`

Under FREE_ONLY:

1. DeepSeek → policy excluded  
2. Free Pool → first actual provider attempt  

FreeOnly **filters** eligibility; it does **not** reorder remaining free candidates.

Effective FreeOnly for a run may also come from connection / task policy (`EffectiveAiCostPolicyResolver`, `PromptTaskFreeOnlyPolicy`) — still a **filter**, never a reorder.

### Paid lock (connection)

`api_connections.paid_locked` + `paid_lock_reasons` (JSON list). Write authority: **`ConnectionPaidLockService` only** (Health must not own a parallel paid-lock writer).

| Reason (`PaidLockReason`) | Meaning |
|---------------------------|---------|
| `manual_free_only` | Operator toggled Free Only on the connection |
| `budget_limited` | Budget / quota policy locked paid lane |
| `admin_lock` | Admin explicit lock |

Invariant: `paid_locked === (reasons not empty)`. Runtime treats paid_locked routes as **health/eligibility skips** (zero API attempts), without reordering survivors. Tests: `ConnectionPaidLockSsotTest`.

### Generation shape (article body)

Independent of FreeOnly **routing** filter:

| First usable route `cost_class` | Shape |
|---------------------------------|-------|
| `free` | `ArticleGenerationShape::Sectioned` (multi-pass / sectioned-free pipeline) |
| `paid` | `ArticleGenerationShape::SinglePass` |

Authority: `GenerationShapeResolver` → immutable `GenerationShapeDecision` snapshotted for the run (`SOURCE_ROUTE_COST_AUTO`). Manual `WritingSplitPreference` / legacy preference helpers are **deprecated** — must not control new runs. Mid-run shape must not change. Tests: `RouteCostGenerationShapeContractTest`, `ArticleGenerationModeFreeOnlyContractTest`.

Sectioned pipeline owners (`ai-prompt`): `SectionedFree/*` (assemble owns headings), `WritingSectionPromptCompiler`, `WritingMultiplePassPromptIsolationGuard`, repair `SectionedFreeArticleRepairService` / `seo:repair-multiple-pass-article`. Outline handoff: `SplitOutlineContentSemanticBinder`. Result ownership: `ArticlePromptResultOwnershipResolver`.

---

## 6. Routing plan architecture

```
AiRoutingContext
    ↓
AiRoutingContextResolver
    ↓
AiCandidatePlanner
    ↓
AiRoutingPlan
    ↓
AiAttemptBudgetPolicy
    ↓
AiModelRouterService (physical execution)
    ↓
AiProviderFailureClassifier
    ↓
Health mutation
    ↓
Output validation
    ↓
Normalized result (AiPrimaryFailureSelector)
```

`AiRoutingPlan` may retain:

- `free_phase`  
- `paid_phase`  

**ONLY** for diagnostics / budget metadata.

These phases **MUST NOT** build runtime execution ordering.

Runtime order is **one stream**:

- `execution_order` / `ordered_routes`  

following manual logical priority, then physical route priority within each logical model.

---

## 7. Multi-route fallback

Example: DeepSeek Chat #1 with route priority `1 DS Direct` → `2 OR`, then Free Pool #2.

Execution:

```
DS Direct
  → health skip / failure
OR (same logical model)
  → health skip / failure
then Free Pool (next logical)
```

**Do not jump** to the next logical model merely because the first physical route failed.

A logical model is exhausted only after every **eligible** physical sibling route has been exhausted or correctly skipped.

---

## 8. Health vs static eligibility

### HEALTH SKIP

Examples: `model_cooldown`, `connection_cooldown`, auth issue, provider unavailable, `paid_locked` where applicable.

Semantics:

- `attempted = false`  
- actual API attempts += **0**  
- continue sortable order  

### STATIC EXCLUSION

Examples: wrong model area, capability mismatch, `AiProductionRouteEligibility`, explicit cost policy, disabled route/model.

Static exclusion is **NOT** Health.  
Do not display a static policy exclusion as a model health failure.

### Known boundary (do not redefine as Health)

DeepSeek is currently excluded by `AiProductionRouteEligibility` for **TextReasoning** (Outline / Vocabulary).

**Long-form Article Content allows DeepSeek** (`TextLongform` / non-reasoning profiles).

If AI Center still shows DeepSeek as normally sortable under Reasoning while runtime excludes it, that is a **known eligibility / UX contract gap** — follow-up decision, not a health bug.

---

## 9. Failure / health scope

| Signal | Scope / rule |
|--------|----------------|
| **402** | Connection paid/billing scope; may paid-lock **that** connection; must **not** suppress sibling route on another connection; DS Direct remains independent from OR paid lock |
| **429** | **Model-scoped by default**; do not widen to entire connection from vague words like “quota exceeded” / “usage limit”; connection/account scope requires account-wide evidence |
| **401 / 403** | Connection-level only where actual auth/permission applies |
| **404** | Normally route/model level |
| **408 / timeout / 5xx** | Transient; allow fallback; no permanent broad health poisoning from one transient failure |
| **Validation failure** | **NEVER** mutate provider/connection health |

Classifier: `AiProviderFailureClassifier` (+ tests `AiProviderFailureClassifierTest`).

---

## 10. Attempt budget

| Event | Attempts |
|-------|----------|
| One actual provider API call | **1** |
| Health skip | **0** |
| Policy / static exclusion | **0** |

- `MAX_AI_ATTEMPTS` — total actual provider calls  
- `MAX_FREE_ATTEMPTS` — actual **free** provider calls only  

### Prior broken reservation (resolved)

```text
reservedPaidSlots = min(attemptablePaidCount, MAX_AI)
```

could reduce free budget to zero when many paid physical candidates existed.

### Current intent (`AiAttemptBudgetPolicy`)

- Preserve at most the **required paid fallback opportunity** when appropriate (typically **one** reserved slot under free-first reserve modes)  
- Do **not** reserve one slot per paid physical candidate  
- Reclaim paid reserve when no paid remain (`reclaimPaidReserveWhenNoPaidRemain`)  
- **Budget must never reorder sortable candidates**

---

## 11. Routing event sequence vs API attempt

Persistence invariant on `prompt_result_routing_attempts`:

| Field | Meaning |
|-------|---------|
| `sequence` | Monotonic event order within a `PromptResult`: 1, 2, 3, … |
| API attempt | Increments **only** for real provider calls (`attempted = true`) |

Example:

| Event | sequence | attempted | API attempt |
|-------|----------|-----------|-------------|
| DeepSeek cooldown | 1 | false | null / n/a |
| Free A | 2 | true | 1 |

**Do NOT** reuse API attempt number as the unique routing event sequence.  
That previously caused duplicate `(prompt_result_id, sequence)`.

---

## 12. Output validation

Validation is a **separate stage** after provider response:

```
Provider Attempt → Provider Result → Output Validation → Execution Result
```

Example:

- DeepSeek: **PROVIDER SUCCESS**  
- Output: 434 words  
- Article validator: **FAIL** 434 / 501  
- Execution: **VALIDATION FAILED**  

This is **NOT** a DeepSeek provider failure.

Contracts are keyed by canonical prompt type via `OutputValidationContractRegistry`:

| Prompt family | Min-word article validators |
|---------------|----------------------------|
| `article.content.generate` / rewrite | allowed |
| Outline (`article.outline.*`) | **must NOT** inherit |
| Vocabulary | **must NOT** inherit |
| Meta / FAQ | own contracts |

---

## 13. Normalized failure taxonomy

Categories (`AiFailureCategory`):

| Category | Examples |
|----------|----------|
| **ROUTING** | no eligible route; all candidates blocked; attempt budget exhausted |
| **PROVIDER** | 402, 429, timeout, auth, empty response, provider invalid response |
| **VALIDATION** | too short; schema invalid; required section missing; format invalid |
| **SYSTEM** | PHP / internal persistence / context / prompt builder error |
| **WORKFLOW** | where applicable (workflow orchestration) |

`AI_ROUTES_EXHAUSTED` is **terminal routing metadata**.  
It must **not** overwrite a more specific primary root cause.

Selector: `AiPrimaryFailureSelector` — preserve `routing_terminal_reason` separately.

---

## 14. Primary failure selection

| Situation | Primary category |
|-----------|------------------|
| 0 provider calls | **ROUTING** |
| Provider produced output but contract failed | **VALIDATION** |
| Actual provider calls all failed | **PROVIDER** |
| Application / PHP failure | **SYSTEM** |

Preserve `routing_terminal_reason` separately from the primary user-facing failure.

---

## 15. Known resolved incidents (why invariants exist)

Concise architectural history — not a full changelog:

1. **Economy / BestQuality reordered sortable candidates** → Fix: manual sortable is runtime order (`orderCandidates` identity; `allowsGenerationModeReorder = false`).
2. **`AiProductionRouteEligibility` removed DeepSeek from Article Content** → Fix: long-form DeepSeek allowed; Reasoning exclusion remains separate static policy.
3. **OR 402 suppressed / bypassed DS sibling** → Fix: physical-route identity + connection-scoped paid lock.
4. **429 free quota incorrectly widened to connection scope** → Fix: narrow failure scope unless account-wide evidence.
5. **Free attempts consumed paid fallback opportunity** → Fix: budget reservation semantics (`AiAttemptBudgetPolicy`).
6. **Routing attempt persistence duplicate sequence** → Fix: route-event monotonic `sequence` separated from API attempt numbering.
7. **Prompt History loaded/stored large compiled prompts** → Fix: Prompt Version + lightweight execution + routing attempts table (see History doc).
8. **Generic `AI_ROUTES_EXHAUSTED` hid root cause** → Fix: normalized failure taxonomy + terminal reason separated.

---

## 16. Known open items — DO NOT “fix” without a separate task

- **Split Outline / Vocabulary final verification** — NEXT TASK.  
- DeepSeek Reasoning **static** eligibility vs AI Center UX (display vs runtime).  
- PromptResult Content Project correlation fields if still incomplete (see CP AI integration doc).  
- Validation success trace persistence if still incomplete.  
- Project archive cleanup for Prompt History.  
- Queue / worker dedicated queue configuration if unresolved.  
- Equal physical route priority tie-break semantics if still implicit.

---

## 17. Key symbols (code map)

| Concern | Primary symbol |
|---------|----------------|
| Logical order | `AiModelPriorityService::areaEnabledModels` |
| Order authority | `ArticleModelOrderAuthority` |
| Preference identity | `ItemGenerationRoutingPreference::orderCandidates` |
| Context / mode | `AiRoutingContext`, `AiRoutingContextResolver`, `AiExecutionRoutingMode` |
| Plan | `AiCandidatePlanner`, `AiRoutingPlan`, `AiPlannedRoute` |
| Budget | `AiAttemptBudgetPolicy` |
| Execute | `AiModelRouterService` |
| Static eligibility | `AiProductionRouteEligibility` |
| Classify | `AiProviderFailureClassifier` |
| Validate | `OutputValidationContractRegistry` (+ validators) |
| Primary failure | `AiPrimaryFailureSelector`, `AiFailureCategory`, `AiNormalizedFailure` |
| Physical key | `RoutedAiCandidate::physicalRouteKey` |
| Paid lock write | `ConnectionPaidLockService`, `PaidLockReason` |
| Cost policy resolve | `EffectiveAiCostPolicyResolver`, `PromptTaskFreeOnlyPolicy` |
| Generation shape | `GenerationShapeResolver`, `GenerationShapeDecision`, `ArticleGenerationShape` |
| First-attemptable route | `FirstAttemptableAiRouteResolver` |

Regression suites (non-exhaustive): `ManualSortableOrderRoutingTest`, `ArticleModelOrderAuthorityTest`, `LogicalModelFallbackArchitectureTest`, `LongFormRoutingContractTest`, `AiProviderFailureClassifierTest`, `AiExecutionLayerObservabilityRefactorTest`, `SplitOutlineInputContractAndDeepSeekEligibilityTest`, `ConnectionPaidLockSsotTest`, `RouteCostGenerationShapeContractTest`, `WritingSectionIsolationCompilerTest`, `MultiplePassHistoryAndAssembleAuthorityTest`.
