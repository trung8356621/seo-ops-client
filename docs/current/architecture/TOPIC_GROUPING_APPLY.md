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
- `TopicGroupingIdentityMatcher` — membership-overlap identity (1:1 / split / merge) after seed anchors
- `TopicGroupingApplyService` — preview / apply / discard
- `TopicReclusterService::persistResolvedClusters` — **single** mutation engine (legacy recluster + semantic apply)

## Identity reconciliation (TASK 5.1)

Apply-path order:

1. `TopicSeedIdentityResolver` (seed keyword → topic_id)
2. `TopicGroupingIdentityMatcher` (Jaccard / existing_coverage / proposed_coverage; continuity-first)

Manual / locked Topics are excluded from the matcher inventory. Preview surfaces `identity_migration` (reused / new / dissolved / splits / merges / no_successor / focus_dissolved) separately from membership move counts.

Thresholds are centralized constants on `TopicGroupingIdentityMatcher` (calibrated from site_id=4 overlap distribution).

## Stale protection

- `input_hash` — semantic input (keywords/seeds/locks inventory used at analyze time)
- `plan_hash` — hash of effective Apply Plan (includes business snapshot of topics/membership/locks/manual/`mcp_excluded`/tag assignments + identity + business-state migrations)
- On Apply: reload run → `lockForUpdate` → rebuild plan → compare previewed `plan_hash` → else `stale`
- Invariant: **previewed effective plan == transaction-time effective plan**

## Business-state preservation (TASK 5.2 / 5.2a)

- Manual tags: migrate on clear merge successor; otherwise hard-block
- MCP exclusion: protect inventory; merge/split propagate via `topic_id` or `group_key` (never by Topic name)
- Focus Article: keyword-owned (`keyword_meta`); Topic dissolve does not delete Focus
- Persistence returns `topic_ids_by_group_key` for new-group policy correlation

Operational runbook: `docs/current/operations/TOPIC_GROUPING_SEMANTIC_RUNBOOK.md`.

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
