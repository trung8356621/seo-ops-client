# Topic Semantic Grouping — Operational Runbook (TASK 6)

## Architecture (final)

```text
Laravel Analyze
  → seo-ops-semantic HTTP (proposal only)
  → seo_topic_grouping_runs.proposal_ready
Preview / Apply Plan (Laravel only; no semantic HTTP)
  → plan_hash + input_hash + business snapshot
Explicit Apply (transaction)
  → metadata → persistResolvedClusters → MCP policy
  → Topic Core business tables
```

Semantic PostgreSQL / vectors / analysis history = **disposable**.
Laravel Topic Core = **business truth**.

## Config (Laravel)

| Key | Safe default | Notes |
| --- | --- | --- |
| `SEMANTIC_ENABLED` | `false` | Feature flag for semantic HTTP health/doctor |
| `SEMANTIC_URL` | `http://127.0.0.1:8088` | Localhost only |
| `SEMANTIC_TIMEOUT` | `30` | Seconds |
| `TOPIC_GROUPING_PROVIDER` | `legacy` | `legacy` \| `semantic_http` |
| `SEMANTIC_TOPIC_PROVIDER` | `legacy` | Legacy alias for provider |
| `TOPIC_GROUPING_LIVE_ACCEPTANCE` | unset | Dev-only gate for live Apply script |

Docker running does **not** auto-enable semantic provider.

## Semantic runtime

| Key | Default |
| --- | --- |
| Embedding | FastEmbed ONNX MiniLM multilingual |
| `TOPIC_CLUSTER_ALGORITHM` | `average_linkage` → `cosine_average_linkage_v1` |
| Threshold | `0.74` |
| Assignment floor | `0.74` |
| `UVICORN_WORKERS` | **1** (do not raise) |
| Model cache volume | `model_cache` → `/models` |
| Postgres volume | `postgres_data` |

## Network / deployment

**PUBLIC INTERNET EXPOSURE = NOT SUPPORTED**

Compose binds:

- API: `127.0.0.1:8088`
- Postgres: `127.0.0.1:5433`

No auth architecture. Keep ports on localhost / private Docker network only.

## Start semantic

```bash
cd D:/work/seo-ops-semantic
docker compose up -d --build
docker compose exec semantic-api python -m app.diagnostics.doctor
```

Laravel:

```bash
php artisan semantic:doctor
```

## Analyze → Preview → Apply

1. Set `TOPIC_GROUPING_PROVIDER=semantic_http` (explicit).
2. Trigger Analyze from Topic Cluster UI (or analysis service).
3. Wait `proposal_ready`.
4. Open **Apply Plan Preview**.
5. Confirm business-state section + warnings (Focus identity change = warning only).
6. Apply only after explicit confirmation.

After `proposal_ready`, semantic API may be stopped: Preview/Apply use Laravel `proposal_payload` only.

## Failure modes

| Failure | Effect |
| --- | --- |
| Semantic down during Analyze | run `failed`; Topics unchanged |
| Semantic down after proposal_ready | Preview/Apply still work |
| `input_hash` / `plan_hash` mismatch | `stale`; no mutation |
| Mid-Apply exception | full TX rollback; `apply_failed` |
| Second Apply | `already_applied` |

## Reset semantic storage

```bash
docker compose down        # stop containers; volumes kept
docker compose down -v     # DESTROYS postgres_data + model_cache
```

`-v` destroys:

- semantic Postgres data
- semantic analysis history
- model cache (re-download on next ready)

`-v` does **NOT** destroy Laravel Topic business data.

## Fallback

```env
TOPIC_GROUPING_PROVIDER=legacy
```

Legacy Analyze/Recluster remains available. Fallback ≠ automatic rollback of a semantic cutover.

## Live acceptance (dev only)

```bash
# READ-ONLY readiness + snapshot
php scripts/dev/topic-grouping-final-acceptance.php 4

# Controlled live Apply (operator must set flag)
set TOPIC_GROUPING_LIVE_ACCEPTANCE=1
php scripts/dev/topic-grouping-final-acceptance.php 4
```

Snapshots land under `storage/app/tmp/topic-grouping-acceptance/` (ignored).

## Cleanup / history

`seo_topic_grouping_runs` rows (`failed` / `discarded` / `applied`) are historical.
Current Topic truth = business tables only.
Manual prune of old runs is optional; no scheduled cleanup required.
