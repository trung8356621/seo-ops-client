# ADR-018 — AI Center Model Authority for Article Generation

> Date: 2026-09-08  
> Status: Accepted  
> Scope: `article.content.generate` / `article.content.rewrite` routing

## Product statement

**User chooses the model. The system adapts the prompt to the selected model; it does not adapt the selected model to the prompt.**

Tiếng Việt: *Model do user quyết định. Hệ thống chỉ tạo hoặc tách prompt phù hợp với model đã được chọn.*

## Context

AI Center sortable order is the user's economic and quality preference. Content Project `generation_mode_override` (`FastEconomy` / `BestQuality`) previously reordered article candidates via `ItemGenerationRoutingPreference::orderCandidates()` (free-first / `array_reverse`). That silently overrode user authority.

## Decision

1. **AI Center sortable order** is the canonical model preference for article generation.
2. **User** controls model economics/quality by reordering AI Center (and reviewing provider cost after runs).
3. **Runtime may FILTER** unavailable models (inactive, health/cooldown, Free Only / `paid_locked`, credentials, required override, provider failure suppression) but **must not reorder** surviving candidates.
4. **Explicit per-item `model_override_id`** is the only deliberate runtime reorder (`prependPreferred` / required filter).
5. **`FastEconomy` / `BestQuality` must not reorder** article generation models. The DB/UI field may remain for other product meaning; it must not change article candidate order.
6. Prompt/system logic adapts to the selected model; it does not choose a different model to suit the prompt.
7. Cost tuning is user-driven: generate → review quality → check provider cost → reorder AI Center if needed.
8. Free/paid classification may later affect **prompt shape**, but only **after** authoritative primary model resolution.
9. No article **output throttling** is used to simulate cheaper execution.
10. Fallback may move to later candidates only according to existing failure / health rules (not quality estimators).

## Canonical boundary

`AiModelRouterService::applyItemRoutingPreferences()` gated by `ArticleModelOrderAuthority::allowsGenerationModeReorder($hookKey)`.

Article hooks (`article.content.generate`, `article.content.rewrite`): skip `orderCandidates`; still allow `prependPreferred` for real user override.

## Forbidden

- Reordering article candidates for FastEconomy / BestQuality / free-first / paid-first / capability or PromptBudget estimates.
- Fabricating `_item_model_override_id` from resolved primary (`ArticlePrimaryRoutingSnapshot` must not do this).

## Enforcement

- `ArticleModelOrderAuthorityTest`
- `ArticleModelOrderAuthority` + comment on `applyItemRoutingPreferences`

## Related

- [PROMPTS_AND_AI.md](../../modules/PROMPTS_AND_AI.md)
- **Full stabilized routing SoT:** [AI_EXECUTION_ROUTING.md](../AI_EXECUTION_ROUTING.md) (`ArticleModelOrderAuthority` now denies generation-mode reorder for every hook)
- Output ceiling quarantine remains separate from model order authority.
