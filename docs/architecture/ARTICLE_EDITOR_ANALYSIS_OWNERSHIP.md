# Article Editor Analysis Ownership (Phase 2B)

> Status: Implemented (Phase 2B)  
> Task ID: `article-editor-analysis-ownership-phase-2b`  
> Related: [`ARTICLE_EDITOR_MEDIA_SNAPSHOT.md`](ARTICLE_EDITOR_MEDIA_SNAPSHOT.md), [`ARTICLE_EDITOR_SESSION_LOCK.md`](ARTICLE_EDITOR_SESSION_LOCK.md)

## Ownership

| Concern | Owner |
|---------|--------|
| Immediate word/link/heading/keyword/image/table/list analysis | **React** (`createCurrentDraftAnalysisSnapshot` → `composeImmediateArticleAnalysis` → `computeSeoAnalysis` + policy) |
| Widget health presentation | React (`assistantWidgetHealth`) consuming composed analysis + media snapshot |
| Thresholds / reason registry / aliases | **Laravel** `ArticleEditorAnalysisPolicyService` |
| External facts (trust/wiki refresh flags) | Laravel `external_facts` |
| Canonical persist / publish validation | Laravel `SeoAnalyzerService` / `SeoScoringEngine` (unchanged) |
| Livewire `seo-analyze-result` | **Removed** as UI SoT (no Blade/Livewire bridge) |

## Policy bootstrap

Core bootstrap + settings lazy payload:

- `analysisPolicy` / `analysis_policy`
- `externalFacts` / `external_facts`

Client: `setAnalysisPolicy()` / `setExternalFacts()` → `window.__SEO_ANALYSIS_POLICY__` / `__SEO_EXTERNAL_FACTS__`.

Canonical thresholds (PHP constants consumed by policy):

- `SeoReasonPresentation::TARGET_WORDS_PER_IMAGE = 200`
- `AssistantWidgetHealthRules::MIN_VALID_HTTP_LINKS = 5`
- article length from `SeoPromptSettingsService`

## Input / output

Input (composed): current TipTap editor document + export HTML, focus keyword, article type, external facts, policy.

Output: `{ score, violations, metrics, reasons, analysis_owner: 'react_immediate', policy_version }`.

Word, heading, image, table, list and link counts share one current-draft `DocumentModel` snapshot. Persisted body and server media snapshots do not replace these live editor counts.

## Re-analyze semantics

Local checks (length, links, ratio, keyword and Featured Snippet structure) update live after a 450ms trailing debounce.

Re-analyze runs the same local snapshot immediately. It does not call the PHP preview scorer.

Persist/save still runs the PHP scorer for canonical server score, audit and sync consumers. Its result does not replace the unsaved live editor score.

## Widget severity

- Images: integrity = error; ALT = warning; `image_ratio_low` = **info** (not red badge as hard error)
- Links: `links_below_minimum` with `current/minimum/missing` params from policy
- Featured/Gallery: media snapshot Phase 2A + policy `featured.alt_required` / `gallery.required`

## Remaining Phase 2C

- FAQ / CTA ownership
- Full TipTap-JSON-only parser (no dual HTML/DOM paths)
- Browser E2E caret proof
- Optional: drop save-patch analyze nudge if redundant with local idle analyze

## Legacy cleanup

Orphan seo-editor-seo-analysis-updated producer removed. Analysis ownership unchanged. See [ARTICLE_EDITOR_LEGACY_CLEANUP.md](ARTICLE_EDITOR_LEGACY_CLEANUP.md).
