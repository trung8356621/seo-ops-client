# Article Execution History

> Status: Canonical  
> Owner: `content` (+ shared canvas: `content-projects`)  
> Last verified: 2026-09-05  
> Route: `/seo/{connection_hash}/articles/{article}/prompts` → tab **Workflow** (Execution History)

## 1. Purpose

Read-only view of **how a Content Project run executed** against an article: workflow graph from the publish task definition, per-node status overlay, and mapping to real `PromptResult` rows (AI Calls).

Complements the **AI Calls** tab (flat list grouped by run) on the same page.

**Not in scope:** editing workflow, re-running from this UI, WordPress sync, or mutating article body (except separate **Apply** flows in AI History).

## 2. UI

| Area | Behavior |
|------|----------|
| Tab **Workflow** | React mount `#article-execution-history-root` — `article-execution-history.jsx` |
| Canvas | Shared `TaskWorkflowCanvas` (`readOnly=true`) from `content-projects` |
| Toggle **Show full workflow** | Default **Simplified**: collapses `article` + initial `article_filter` into virtual node **Article context** |
| Inspector (right) | Node type, status, message, **AI Calls (n)**, preview links |
| Prompt node title | Appends call count when `ai_calls.length > 0`, e.g. `Khối Prompt (2)`, `Prompt block (1)` |

Virtual context node id: `__execution_article_context__` (presentation-only, never persisted).

## 3. Backend payload

**Entry:** `ArticleAiHistoryApplicationService::executionHistory()` → `ArticleExecutionHistoryService::build()`.

Per run item (grouped by `project_run_id`):

| Field | Meaning |
|-------|---------|
| `workflow` | Nodes/edges from current publish task (`SeoCreateArticleSettingsService::getPublishArticleTaskId()`), with optional run snapshot hash |
| `execution_by_node_id` | Overlay: status, message, hook_key, `ai_calls[]`, `prompt_result_ids[]` |
| `node_visibility` | `ExecutionHistoryNodeVisibility` — which nodes collapse in simplified view |
| `context_summary` | Article id, title, domain, keyword, routing lines |
| `has_execution_trace` | True when `output_snapshot.execution_trace` exists (vs legacy `steps` only) |

**AI call mapping (`buildAiCallsForNode`):**

- Candidate IDs: `trace.prompt_result_ids`, `trace.result_id`, `SeoPromptResultLink` for node + run.
- Loads `PromptResult` + `input_snapshot` (model, hook_key, `outline_subtask`).
- Sort: `outline` before `vocabulary` when subtask present.

**Site load fix:** only `site:id,domain` — do **not** select non-existent `url` / `name` on core `sites`.

## 4. Split outline → 2 AI calls

Outline logical step uses **two provider calls** when the workflow node is recognized as outline:

| Hook | Role |
|------|------|
| `article.outline.structure.generate` | Structure / Task 1 |
| `article.vocabulary.generate` | Vocabulary / Task 2 |

**Trigger (`TaskWorkflowTestRunner`):** `isOutlineRoleNode($node, $hookBinding->hookKey)` — includes legacy combined hook `article.outline.generate` on bound prompt (not only node title «Dàn ý»).

**Executor:** `ArticleOutlineVocabularySplitExecutor` — provider contract is **markerless** direct final content (2026-09-04); still exposes logical Task 1 / Task 2 ports for workflow canvas + history overlay. Presentation: `ArticlePromptRunHistoryService` split grouping.

**Bindings (Settings):** `SeoCreateArticleSettingsService::getBoundPromptId()` for structure + vocabulary hooks. Install defaults:

```bash
php artisan seo:prompt:install-split-outline-prompts
```

Legacy combined prompt (`article.outline.generate`, e.g. id=1) is **unchanged**; split uses separate prompt rows (e.g. 22/23).

**Trace:** successful split stores `prompt_result_ids: [outline_id, vocabulary_id]` and message `Split outline completed (structure + vocabulary).`

## 5. Content persist after partial rerun

**Problem:** Rerun from writing node completes `article.content.generate` but **skips** downstream `save_article` → editor keeps old body while PromptResult has new markdown.

**Fix:** `TaskWorkflowTestRunner::flushPendingArticleContentIfNeeded()` at end of `run()` and `runFromNodeId()`:

- If `article_content` typed artifact / `direct_publish_article_markdown` exists and `article_markdown_published` is false → `PromptTestPublishService::publishArticle()`.

Content hook path also registers artifact via `shouldRegisterArticleContentFromPrompt()` (content hooks, not only `mergeOutlineToSave`).

**Manual hotfix (ops):** load `PromptResult.output_text` + `publishArticle($article, $markdown)` — Laravel editor only, no WP sync.

## 6. Legacy vs full trace

| Source | `execution_trace` | Inspector notes |
|--------|-------------------|-----------------|
| New runs | Yes | Full node overlay |
| Older runs | Only `steps[]` | Merged via `WorkflowExecutionTrace::fromSteps`; may show **Unknown / Legacy** for unmapped nodes |

Runs before split-outline deploy may show **1 AI call** on outline node (legacy single hook) even when canvas ports show Task 1 / Task 2.

## 7. File map

| Layer | Path |
|-------|------|
| Filament page | `content/src/Filament/Resources/ArticleResource/Pages/ViewArticlePrompts.php` |
| History service | `content/src/Services/ArticleExecutionHistory/ArticleExecutionHistoryService.php` |
| Node visibility | `content/src/Services/ArticleExecutionHistory/ExecutionHistoryNodeVisibility.php` |
| Raw call detail | `content/src/Services/ArticleAiHistory/ArticleAiCallRawDetailService.php` |
| React UI | `content/resources/js/article-execution-history.jsx` |
| Graph projection | `content/resources/js/executionHistoryGraphProjection.js` |
| Prompt title `(n)` | `content/resources/js/executionHistoryNodeTitle.js` |
| Shared canvas | `content-projects/resources/js/components/TaskWorkflowCanvas.jsx` |
| Workflow runner | `ai-prompt/src/Services/TaskWorkflowTestRunner.php` |
| Split executor | `ai-prompt/src/Services/ArticleOutlineVocabularySplitExecutor.php` |
| Split installer | `ai-prompt/src/Services/PromptOwnership/DefaultSplitOutlinePromptsInstaller.php` |
| Publish body | `ai-prompt/src/Services/PromptTestPublishService.php` |

## 8. Tests & verify

```bash
# Execution History presentation (source + overlay contracts)
php vendor/bin/phpunit --filter=ArticleExecutionHistoryPresentationTest addons/content/tests/Unit/ArticleExecutionHistoryPresentationTest.php

# Split outline + runner wiring
php vendor/bin/phpunit --filter=ArticleOutlineVocabularySplitExecutorTest addons/ai-prompt/tests/Unit/ArticleOutlineVocabularySplitExecutorTest.php

# Content artifact / flush safety net
php vendor/bin/phpunit --filter=WorkflowArtifactOwnershipTest addons/content-projects/tests/Unit/WorkflowArtifactOwnershipTest.php

npm run build
```

## 9. Troubleshooting

| Symptom | Likely cause | Action |
|---------|--------------|--------|
| 500 on Workflow tab (`Unknown column 'url'`) | Stale `site:url` eager load | Fixed in `ArticleExecutionHistoryService` — pull latest |
| Outline node **AI Calls (1)** on new run | Legacy hook path or missing split bindings | Run `seo:prompt:install-split-outline-prompts`; ensure outline prompt uses `article.outline.generate` on node |
| Prompts OK, editor body old | Partial rerun skipped `save_article` | Re-run writing step (flush applies on new code) or manual `publishArticle` from latest `PromptResult` |
| Project item **Pending** | Never queued in Generate pending | Normal — run Generate pending for those task ids (not a post-run stuck state) |
| **Unpublished changes** badge | WP published revision ≠ current Laravel body | Expected after local content update; publish/sync when ready |

## 10. Related docs

- [ARTICLE_EDITOR.md](./ARTICLE_EDITOR.md) — `/articles/{id}/prompts` AI History apply (separate from Execution History)
- [CONTENT_PROJECTS.md](./CONTENT_PROJECTS.md) — runs, rerun, generate pending
- [PROMPTS_AND_AI.md](./PROMPTS_AND_AI.md) — hooks, routing, prompt bindings
