# Topic grouping Apply (TASK 5)

## Critical invariants

1. **Semantic proposal ≠ business mutation.** Analysis only persists evidence into `seo_topic_grouping_runs`.
2. **Preview Plan == Apply Plan.** Both call `TopicGroupingApplyPlanBuilder`. Apply rebuilds the plan and compares `plan_hash`.
3. **After `proposal_ready`, semantic Docker may be offline.** Preview/Apply hydrate `proposal_payload` from Laravel only — no semantic HTTP.

## Phases

```
ANALYZE  → TopicGroupingAnalysisService → proposal_ready (Laravel JSON)
PREVIEW  → TopicGroupingApplyService::preview → Apply Plan (no mutation)
APPLY    → freshness (input_hash + plan_hash) → transaction → persistResolvedClusters
```

## Ownership

| Layer | Owns |
| --- | --- |
| seo-ops-semantic | vectors, clustering, disposable analysis |
| Laravel run row | proposal, review/apply state, hashes |
| Laravel Topic Core | Topics, memberships, locks, manual, Focus (via keyword identity), DNA, cache |

## Services

- `TopicGroupingApplyPlanBuilder` — deterministic plan from proposal + current business state
- `TopicGroupingApplyService` — preview / apply / discard
- `TopicReclusterService::persistResolvedClusters` — **single** mutation engine (legacy recluster + semantic apply)

## Stale protection

- `input_hash` — semantic input (keywords/seeds/locks inventory used at analyze time)
- `plan_hash` — hash of effective Apply Plan (includes business snapshot of topics/membership/locks/manual)
- On Apply: reload run → `lockForUpdate` → rebuild plan → compare previewed `plan_hash` → else `stale`

## Run statuses

`queued` → `analyzing` → `proposal_ready` → `applying` → `applied`  
Failures: `failed` (analysis) · `apply_failed` (mutation) · `stale` · `discarded`

## Protection rules (Laravel, not semantic scores)

- Manual Topics (`source=manual`) frozen
- Topic lock / keyword membership lock preserved
- MCP-excluded topics retained
- Unassigned unlocked memberships dropped (legacy recluster semantics); locked memberships kept
- Focus Article bindings ride on keyword identity — topic_id reuse preserves them; Apply does not delete articles

## Legacy

`TOPIC_GROUPING_PROVIDER=legacy` still runs analyze+apply via `TopicReclusterService::recluster`, which now ends in the same `persistResolvedClusters` path.
