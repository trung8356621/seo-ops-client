# Quick Documentation Summary

> Status: working summary, not canonical source of truth  
> Updated: 2026-08-26  
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

## 2. Recent Conversation Themes

### New Content Planner / Draft UI (2026-08-26)

- **JSON-only AI result:** `keyword.discovery.structured` + `NewContentSuggestionStructuredResult` / Parser; OUTPUT CONTRACT on planning brief; one format repair retry; no prose scrape. Docs: `CONTENT_PROJECTS.md` § SEO Audit Draft / New Content Planner; `PROMPTS_AND_AI.md` hook row.
- **Product planning persist:** brief → `secondary_description`; gallery → `description`; type → `loai_san_pham`. Read model: `description` + `product_description`.
- **Draft Post type:** plain text + double-click select; `UpdateContentProjectItemHandler` CREATE-only; Product↔Post non-destructive. Reactive remount via `cp-ops-refresh` / `draftPlanningRefreshNonce` (no F5).
- Ops: restart queue workers after planner PHP changes.

### Article Editor

- The editor is treated as a dedicated island: Livewire shell plus React/TipTap runtime.
- 2026-08 fixes (canonical changelog: `docs/architecture/ARTICLE_EDITOR_FIXES_2026_08.md`): Outline heading rename local-first; AI media placeholder/hang/double-image; Featured/Gallery/Outline/AI locale via `content/resources/js/utils/i18n.js`.
- **2026-08-26:** Domain link list — soft lexical match, in-article `(n)`, hide 0, click locate via expand+scroll. Doc: `ARTICLE_EDITOR_DOMAIN_LINK_LIST.md`.
- **2026-08-21/22:** Editor widget locks + FAQ/Reviews UX — see below.
- Active Content Project article: editor hides **all** manual Sync WP chrome (UI-only). First WP create stays on Publishing Queue.
- Any change must preserve session lock, `document_version`, TipTap JSON document model, command layer, media snapshot ownership, and WordPress sync separation.
- WordPress sync must not be conflated with local editor save; conflict policy and field ownership need to remain explicit.

### Editor widget locks + FAQ/Reviews (2026-08-21/22)

- Stable widgets frozen: `featured`, `images`, `publishing`, `status` (display **Trạng thái**; runtime `panelId` = `article`).
- Manifest SoT: `omnichannel-addons/content/editor-widget-locks.json`. Guard never hard-codes IDs.
- Client docs: `docs/architecture/ARTICLE_EDITOR_WIDGET_LOCKS.md`. Rule: `.cursor/rules/editor-widget-locks.mdc`.
- Commands (from `omnichannel-client`): `npm run widget-lock -- status|unlock|lock|seal`, `npm run check:editor-widget-locks`.
- FAQ content ≠ FAQ schema: `seo/.../articleFaqCanonicalState.js` → `faq_missing` vs `faq_schema_missing`. Lazy mount must pass `initialFaqs={undefined}`, not `[]`.
- Reviews: load attempt always resolves (success or failure); no endless spinner on API fail.
- Dock **Search assistants** UI removed; chips render directly (`ARTICLE_EDITOR_SHELL_BOUNDARY.md`).
- Panel-filter mode: panel body owns vertical scroll (`min-height: 0` flex).

### Content Projects

- Content Project lifecycle writes should continue through `ContentProjectCommandBus`.
- **Assign UI (CLOSED):** one right-side drawer, event `assign-content-project:open`. “Modal” aliases render the same drawer. Docs: `CONTENT_PROJECTS.md` § Assign UI + `docs/architecture/CONTENT_PROJECT_ASSIGN_UI_2026_08.md`.
- Callers: article list, editor overflow, SEO Audit, keyword list/detail, link bubble. Vocabulary sidebar stays **inline** (not the drawer).
- Laravel-only articles (`wp_post_id` null) remain assignable.
- Recent work also touched archive/restore/reset, rerun, queue states, workspace cleanup, and task sync.
- Generated/AI workspace cleanup must avoid deleting user-owned or canonical media accidentally.

### Publishing And Queue Runtime

- Laravel remains the source of truth for publishing schedule and queue state.
- WordPress receives publish/sync outcomes; it is not the schedule owner.
- Recent incidents and fixes centered on dispatch stalls, retry/idempotency, stuck recovery, immediate publish rewrite, and queue UI clarity.
- Publishing Queue behavior should be checked against `docs/modules/PUBLISHING.md` and `docs/contracts/QUEUE_SCHEDULER_AND_IDEMPOTENCY.md`.

### WordPress Bridge And Sync

- Backend/plugin REST contracts require cross-repo inspection with `../wp-seo-ai`.
- Recent context includes preserving WordPress slugs, manual WordPress ID linking, post-publish sync contract, Site Sync stuck recovery, and WordPress field conflict policy.
- Token-based bridge auth, tenant boundaries, and narrow CSRF exceptions must be preserved.
- Backend deploy-diff tracking does not cover the sibling WordPress plugin repo.

### Media, Gallery, And Images

- Recent work touched media picker behavior, source classification, local media cleanup, WebP minimum width, attachment rename/slug fixes, and image optimization tests.
- Editor Featured/Gallery labels go through `i18n.js`; AI media hang/placeholder/double-image documented in `ARTICLE_EDITOR_FIXES_2026_08.md`.
- Media ownership must distinguish local article media, WordPress-origin media, generated media, and gallery/featured snapshots.
- Article Editor media snapshot rules remain the primary guardrail for UI/runtime changes.

### Google Search Console And SEO Audit

- Recent work included GSC missing-table handling, connection service tests, SEO audit scan fixes, and reason/metrics syntax fixes.
- Verification should cover service-level tests when touching GSC or audit scan behavior.

### UI And Admin UX

- Recent UI work included article list, pagination/block-all, publish/sidebar controls, prompt editor UX, queue bulk UI, lock-at-capacity, and assorted Filament/React cleanup.
- Blade select boxes should use `<x-select>`.
- SEO React controls should use `SeoSelect`.
- Modals, drawers, and popovers should open/close immediately through Alpine/JavaScript; Livewire should handle loading, validation, persistence, and server actions.

### AI Center (Models / Routing / Resilience / Health)

- Canonical module doc: `docs/modules/PROMPTS_AND_AI.md` § **AI Center (Models / Routing / Resilience / Health)**.
- Tabs: Models | Routing | Resilience | Health.
- Models = enable/order; Routing = Automatic vs Custom filter; Resilience = attempt budgets; Health = operational status UI.
- Text Routing has **no** “Manage model order” link — reorder only on Models.
- Short code `[OR]` is display-only. Execution identity: `connectionId|familyKey`. Persist Custom via `AiRoutingTargetService::saveSimplifiedSelection`.
- Curated OpenRouter Text catalog: `OpenRouterTextRoutingCatalog` + `php artisan seo:ai:ensure-openrouter-text-routing [--user=]` (idempotent). Does not touch Image/Video routing.
- Tab state Alpine-only on `#ai-center-root`; do not restore Livewire tab `queryString` dual-panel sync.

## 3. Current Working-Tree Caution

Inspect `git status --short` before editing; do not revert unrelated changes. New Content Planner / Draft UI changes live primarily under `omnichannel-addons` (`content-projects`, `ai-prompt` hook JSON, `seo-content-ai-compat` Draft Blade/lang). Canonical docs updated in `omnichannel-client/docs`.

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
