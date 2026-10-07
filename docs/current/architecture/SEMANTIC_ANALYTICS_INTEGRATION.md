# Semantic analytics integration (TASK 4)

## Boundary

| Owner | Responsibility |
| --- | --- |
| **Laravel** | Business authority: Topics, memberships, locks, manual ops, review/apply (Prompt 5), proposal persistence |
| **seo-ops-semantic** | Embedding, vectors (pgvector), clustering, scores, disposable analysis |

Laravel never queries semantic PostgreSQL directly and never shares DB connections.

## Provider configuration

Config file: `search-intelligence/config/semantic.php` (merged as `config('semantic.*')`).

| Env | Default | Meaning |
| --- | --- | --- |
| `SEMANTIC_ENABLED` | `false` | Feature flag (informational / future gates) |
| `SEMANTIC_URL` | `http://127.0.0.1:8088` | Base URL of semantic API |
| `SEMANTIC_TIMEOUT` | `30` | HTTP timeout seconds |
| `TOPIC_GROUPING_PROVIDER` | `legacy` | `legacy` \| `semantic_http` |
| `SEMANTIC_TOPIC_PROVIDER` | (fallback) | Alias if `TOPIC_GROUPING_PROVIDER` unset |

Default remains **legacy**. Docker existence does not auto-switch.

DI: `TopicGroupingProvider` → `LegacyTopicGroupingProvider` or `SemanticHttpTopicGroupingProvider` via `TopicGroupingProviderMode`.

## HTTP API used

- `GET /health/ready` — `php artisan semantic:doctor`
- `POST /v1/topic/analyses` — site recluster analysis
- `GET /v1/topic/analyses/{id}` — optional fetch (client supports)
- `DELETE /v1/topic/analyses/{id}` — optional dispose (client supports)

Membership scan (`topic_membership_scan`) stays on the **legacy** lexical path even when the binding is `semantic_http`.

## Proposal storage

Table `seo_topic_grouping_runs` (connection `omi_seo_ai`):

- proposal / job state only (JSON payload + diagnostics)
- **not** vectors, **not** business membership

Statuses: `queued` → `analyzing` → `proposal_ready` | `failed` | `stale` | `discarded`  
(`applying` / `applied` reserved for Prompt 5 — unused here)

## Orchestration

```
semantic_http:
  Job/UI → TopicGroupingAnalysisService::analyzeSite
        → TopicGroupingProvider::analyze
        → persist seo_topic_grouping_runs
        → STOP (no Topic mutation)

legacy:
  Job/UI → TopicReclusterService::recluster
        → analyze + identity + persistClusters (unchanged)
```

## Failure rule

`semantic unavailable` ≠ `Topic unavailable`.

Manual create / rename / move / split / locks / list / detail remain available when Docker is down. Analyze fails safely with `status=failed` and diagnostics — zero Topic writes.

## Apply

**NOT IMPLEMENTED IN TASK 4.**

Prompt 5 may build Proposal → Preview Diff → Apply using persisted runs + `input_hash` freshness (`TopicGroupingAnalysisService::markStaleIfHashChanged` / `currentInputHash`).
