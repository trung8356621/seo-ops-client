# AI Debug Playbook

> Status: Canonical operational playbook (developer-facing)  
> Last verified: 2026-09-15
> SoT: [`architecture/AI_EXECUTION_ROUTING.md`](../architecture/AI_EXECUTION_ROUTING.md)  
> History: [`architecture/AI_HISTORY_PROMPT_VERSION.md`](../architecture/AI_HISTORY_PROMPT_VERSION.md)  
> Discipline rule: `.cursor/rules/debug-fix-discipline.mdc`

Never debug only from the final red UI message.

---

## 1. Inspect order (AI routing bug)

1. Canonical prompt key  
2. Stage  
3. Model area  
4. AI Center sortable order (`AiModelPriorityService::areaEnabledModels`)  
5. Static candidate eligibility (`AiProductionRouteEligibility`, area/capability, FreeOnly)  
6. Ordered `AiRoutingPlan` (`execution_order` / `ordered_routes`)  
7. Health skips (`attempted=false`, cooldown / paid_lock / auth) — paid lock write = `ConnectionPaidLockService` only  
8. Budget skips (`AiAttemptBudgetPolicy`)  
9. Generation shape snapshot (`GenerationShapeDecision` — free→sectioned / paid→single; independent of FreeOnly reorder)  
10. Actual API attempts (`attempted=true`)  
11. Provider result (HTTP / classifier / health mutation)  
12. Validation (`OutputValidationContractRegistry`)  
13. Normalized final failure (`AiPrimaryFailureSelector`)

Prefer: PromptResult + `prompt_result_routing_attempts` + plan debug array over UI copy alone.

---

## 2. “Why didn’t model X run?”

Determine **exactly one**:

| Classification | Meaning |
|----------------|---------|
| Never in candidate plan | Not in areaEnabledModels / plan stream |
| Statically excluded | Eligibility / area / FreeOnly / disabled |
| Health skipped | Cooldown / lock / unavailable; 0 attempts |
| Budget skipped | Attempt budget exhausted before reaching X |
| Policy excluded | Explicit cost/policy filter |
| Attempted and failed | Provider failure on that physical route |
| Sibling route failed | Wrong — siblings are independent; check physical key |
| Provider succeeded but validation failed | VALIDATION, not “model didn’t run” |

Static exclusion ≠ Health.  
Logical model ≠ physical route.

---

## 3. Quick evidence checklist

| Question | Where to look |
|----------|----------------|
| What order was planned? | `AiRoutingPlan::toDebugArray()` / router diagnostics |
| Was it an API call? | `prompt_result_routing_attempts.attempted` |
| Event vs attempt numbering | `sequence` vs API attempt semantics |
| Primary vs terminal | `failure_category` / `failure_code` vs `routing_terminal_reason` |
| Compiled prompt bloat? | Must be hash + PromptVersion, not hot `compiled_prompt` |
| CP context? | `content_project_id`, `project_item_id`, `run_id`, `node_id`, `correlation_id` |

### HTTP 200, empty DeepSeek outline

For `article.outline.structure.generate` / `article.vocabulary.generate`, inspect the **physical route** and `prompt_result_routing_attempts` first. In the 2026-09-15 incident, OpenRouter was skipped (`connection_paid_locked`, `attempted=false`) and Direct DeepSeek was actually called (`attempted=true`). DeepSeek returned HTTP 200 with empty `content`, `finish_reason=length`, and reasoning tokens consuming the 2048-token output limit. Check `token_usage.budget.requested_max_output_tokens`, `provider_finish_reason`, and reasoning-token usage before blaming connection balance or output validation. The direct V4 Pro split-hook path now requests 8192 and disables thinking by default; an empty length-limited response is `OUTPUT_TRUNCATED`. Do not globally raise the split reserve: smaller-cap free/OpenRouter routes must remain eligible.

---

## 4. Discipline reminder

DEBUG ≠ FIX unless explicitly authorized.  
See `.cursor/rules/debug-fix-discipline.mdc`.

**Never make the system “run” by hiding why it was broken.**
