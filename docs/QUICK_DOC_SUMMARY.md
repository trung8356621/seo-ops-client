# Quick Documentation Summary

> Status: working summary, not canonical source of truth  
> Updated: 2026-08-30  
> Purpose: summarize recent conversation/work context so the next session can re-orient quickly. Canonical behavior still lives in `docs/README.md` and the linked architecture/module/contract docs.

## 1. Documentation Map

The current documentation set is organized as a canonical docs system for the Omnichannel Laravel backend.

- `docs/README.md` is the starting index and precedence guide.
- `docs/architecture/*` records system boundaries, frozen architecture rules, and ADRs.
- `docs/modules/*` describes each business module.
- `docs/contracts/*` records public contracts and invariants.
- `docs/operations/*` covers deploy, testing, scheduler/workers, and troubleshooting.
- `docs/audits/*` contains active audits.
- `docs/archive/*` is historical only and must not be treated as source of truth.

## 2. Recent Conversation Themes (2026-08-27 → 2026-08-30)

Canonical docs updated this pass: `CONTENT_PROJECTS.md`, `SEO_AUDIT_AND_KEYWORDS.md`, `ARTICLE_EDITOR.md`, `WORDPRESS_BRIDGE.md`, this file, light handoff/index touches.

### Content Projects — Draft → Execution Project

- Flow: **Draft Reviewed → Create Execution Project** via `SplitDraftContentProjectService` / CommandBus `content_project.split_draft`.
- Eligible = `planning_reviewed_at IS NOT NULL` only. Current calendar month only.
- Writers = real USER ids selected by Manager. **Never** `SeoOpsSystemUser` (FK placeholder; treated as unassigned).
- Fair-allocate then pack into reusable **max-30** Execution Projects per writer+month (`ContentProjectExecutionPackingService`). Multiple EPs allowed when over 30 — not “one project + month metadata.”
- MOVE same task rows (preserve ids/origins). No AI / no auto-generate on split.
- Repair: `seo:repair-execution-project-naming`, `seo:repair-execution-project-packing`.
- Projects list buckets: `all|draft|project|archived` (`ContentProjectListBucket`). No bulk select on Projects list.
- SEO Audit Notes: cluster suggestions by MCP share ASC → per-item DNA snapshot (planning override only).

### Topics / Keyword DNA / Focus reconcile

- UI rename: Topic Cluster → **Topics / Chủ đề** (code still `cluster_key`).
- Keyword Dictionary stays **flat**; grouping = Topics only. Legacy `KeywordRuleGroup*` / `parent_id` hierarchy retired.
- DNA: `KeywordDnaExtractor` / `KeywordDnaService` + `seo_keyword_dna`.
- Recluster: `ReclusterTopicClustersService` + job. Focus invariant: `ReconcileFocusArticleTopicsService` + `seo:topics:reconcile-focus` — Focus keywords never stay Unassigned.
- Keywords: Language filter on tab bar; domain context must follow GET `site_id`.
- Loading UX: Keywords + Content Projects share Article-style `list-table-loading-shell`.

### GSC / nav / pagination

- GSC prefer month-scoped view/sync; MCP open from GSC surfaces when bound.
- SEO user nav regrouped WordPress-style modules (`SeoUserNavigation` / `SeoPanelRoutes`).
- Client pagination: `PaginationWindow` — current-page-centric tokens (±2 desktop), not “1 2 3 4 … 25 26” only.

### Product reviews + WP sync

- `ProductReviewCreationPolicy`: must sync/check WP comment-reviews before gen; block when real WP reviews exist (default); target maintains unique AI count (default **3**).
- Generations recorded into Article AI History via `ProductReviewGenerationHistoryRecorder`.
- Manual WP sync may run create→sync review side effect under that policy (`WordPressManualSyncService`). Reviews sync ≠ CP publishing queue.

### Vocabulary Suggest + Planner Idea Candidates

- Persist: `START_TASK_2_VOCABULARY` → `save_vocabulary_research` / `keyword.vocabulary.save` → staging Suggest rows (not Prompt History SoT).
- Planner Idea Candidates: pick from Vocabulary Suggest only (`InteractsWithIdeaCandidates`) — no AI / no GSC in that picker phase.

### Social + nav

- Social Profiles (`social/`) + Hub GSC Social Top 10 (`GscSocialTop10Builder`). GSC MCP ∉ Audit/Planner ideas.
- SEO nav: `SeoUserNavigation` / `SeoPanelRoutes` (WP-style modules).

### Still relevant from 2026-08-26

- New Content Planner JSON-only gate + Draft Product persist — see `CONTENT_PROJECTS.md` § SEO Audit Draft / New Content Planner.
- Domain link list soft match — `ARTICLE_EDITOR_DOMAIN_LINK_LIST.md`.
- Editor widget locks — `ARTICLE_EDITOR_WIDGET_LOCKS.md`.

## 3. Current Working-Tree Caution

Inspect `git status --short` before editing; do not revert unrelated changes. Large Aug 27–29 work landed mainly in `omnichannel-addons` (`content-projects`, `search-intelligence`, `seo`, `seo-content-ai-compat`, `commerce`, `wordpress`) and light client (`SeoOpsSystemUser`, `PaginationWindow`). Canonical docs live in `omnichannel-client/docs`.

## 4. Operational Rules To Remember

- For non-trivial tasks, query `codebase-memory` near the start when available, then verify against code/docs.
- Before application-code edits, start or reuse a deploy-diff session.
- After meaningful application-code edits, run deploy-diff `track` for modified/deleted backend files.
- Do not deploy, commit, push, install dependencies, run migrations, alter databases, upload, or package the plugin unless explicitly asked.
- Use remote-first PHPUnit commands, normally `$PHP_BIN vendor/bin/phpunit --filter=...`.
- For JS/CSS changes, run or report the relevant build/check, normally `npm run build`.
- When the user says `XONG!`, run `$docs-update-on-xong` (canonical module docs). Skill: `.agents/skills/docs-update-on-xong/`; rule: `.cursor/rules/auto-update-docs.mdc`.

## 5. Documentation Update Policy

- This file is a lightweight conversation digest.
- It does not override canonical docs.
- When the user says `XONG!`, update the relevant canonical docs through the project docs workflow.
- Contract/API/auth/site-sync/publishing/article/media changes should update the matching module/contract docs only after behavior is verified in code.

## 6. Fast Re-Entry Checklist

1. Read `docs/README.md`.
2. Check `git status --short`.
3. Read the relevant module/contract docs for the touched area.
4. Inspect current code and nearby tests before editing.
5. Start/reuse deploy-diff for application-code changes.
6. Run focused verification or clearly report why it was skipped.
