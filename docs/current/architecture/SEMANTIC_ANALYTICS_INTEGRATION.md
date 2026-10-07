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
| `SEMANTIC_CONNECT_TIMEOUT` | `5` | Connect timeout seconds (fail fast if unreachable) |
| `SEMANTIC_TIMEOUT` | `120` | Total request timeout seconds (cold start / analysis) |
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

Statuses: `queued` → `analyzing` → `proposal_ready` → `applying` → `applied`  
Also: `failed` (analysis) · `apply_failed` · `stale` · `discarded`

## Orchestration

```
semantic_http:
  Job/UI → TopicGroupingAnalysisService::analyzeSite
        → TopicGroupingProvider::analyze
        → persist seo_topic_grouping_runs (proposal_ready)
        → STOP
  UI Preview → TopicGroupingApplyService::preview (Laravel plan only)
  UI Apply   → TopicGroupingApplyService::apply
            → TopicReclusterService::persistResolvedClusters
            → applied

legacy:
  Job/UI → TopicReclusterService::recluster
        → analyze + identity + persistResolvedClusters (same mutation engine)
```

See `TOPIC_GROUPING_APPLY.md` for Preview/Apply invariants (`input_hash` + `plan_hash`, Docker-off after proposal_ready).

## Failure rule

`semantic unavailable` ≠ `Topic unavailable`.

Manual create / rename / move / split / locks / list / detail remain available when Docker is down. Analyze fails safely with `status=failed` and diagnostics — zero Topic writes. Preview/Apply after `proposal_ready` also do not require semantic UP.
