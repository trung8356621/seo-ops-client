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
| `SEMANTIC_CONNECT_TIMEOUT` | `5` | TCP connect fail-fast (seconds) |
| `SEMANTIC_TIMEOUT` | `120` | Total HTTP request timeout (cold model + analysis) |
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
# READ-ONLY readiness + before snapshot (no mutation)
php scripts/dev/topic-grouping-final-acceptance.php 4

# Controlled live Apply (operator must set flag)
set TOPIC_GROUPING_LIVE_ACCEPTANCE=1
php scripts/dev/topic-grouping-final-acceptance.php 4

# Optional offline Apply proof — use TWO terminals

# Terminal A (harness; waits for Enter before Apply if offline flag set, then again after Apply for restart)
cd /d D:\work\omnichannel-client
set TOPIC_GROUPING_LIVE_ACCEPTANCE=1
set TOPIC_GROUPING_ACCEPTANCE_EXPECT_SEMANTIC_OFFLINE=1
php scripts/dev/topic-grouping-final-acceptance.php 4

# Terminal B — BEFORE Apply confirm on Terminal A:
cd /d D:\work\seo-ops-semantic
docker compose stop semantic-api

# Terminal B — AFTER harness prints:
#   OFFLINE APPLY VERIFIED — restart semantic-api now for convergence
docker compose start semantic-api
# Then press Enter on Terminal A (interactive) so second Analyze/convergence can run.

# Non-interactive offline + convergence wait (still does NOT start Docker):
set TOPIC_GROUPING_ACCEPTANCE_AUTO_CONFIRM=1
# After Apply, harness polls /health/ready up to 120s while you start semantic-api in another terminal.
```

Live mode: before/after snapshots, integrity checks (locked membership = same keyword_id+topic_id; MCP group_key via `topic_ids_by_group_key` only), Preview-vs-actual, then fresh second Analyze+Preview (never second Apply). Convergence classification uses move/create/dissolve ratios (STABLE ≤0.25, NOT_CONVERGING ≥0.5 on all three).

Snapshots land under `storage/app/tmp/topic-grouping-acceptance/` (ignored).

## Cleanup / history

`seo_topic_grouping_runs` rows (`failed` / `discarded` / `applied`) are historical.
Current Topic truth = business tables only.
Manual prune of old runs is optional; no scheduled cleanup required.
