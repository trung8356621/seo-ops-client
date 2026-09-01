# New Agent Handoff (post-refactor)

> Status: Canonical operational handoff  
> Last verified: 2026-09-01  
> Authority: [ADDON_ARCHITECTURE.md](ADDON_ARCHITECTURE.md) · Shell: [SEO_CONTENT_AI_COMPAT_SHELL.md](SEO_CONTENT_AI_COMPAT_SHELL.md)  
> Manual debt: [POST_REFACTOR_MANUAL_CHECKLIST.md](POST_REFACTOR_MANUAL_CHECKLIST.md)  
> Editor locks: [ARTICLE_EDITOR_WIDGET_LOCKS.md](ARTICLE_EDITOR_WIDGET_LOCKS.md)

**Do not inherit SeoContentAi-monolith assumptions.** Architecture refactor is **CLOSED**.  
**Task 12–13:** canonical workspace at `D:\work\` (client + addons + wp-seo-ai) — see [REPO_SPLIT.md](REPO_SPLIT.md). **2026-08-18:** `omnichannel-client-core` merged into `app/Core` (retired as standalone package).

---

## A. What changed

Old `App\Addons\SeoContentAi` monolith was split into **peer addons**, then physically extracted:

| Repo | Role |
|------|------|
| `omnichannel-client` | Thin Laravel shell + embedded `App\Core` runtime |
| `omnichannel-addons` | Peer business addons + `seo-content-ai-compat` |
| `wp-seo-ai` | WordPress plugin (unchanged) |

---

## B. Where to look (feature → owner path)

| Feature | Owner path |
|---------|------------|
| Article / editor bug | `omnichannel-addons/content` |
| Domain link list (Links panel match/locate) | Client `domainLink*.js` + SEO catalog resolver — [`ARTICLE_EDITOR_DOMAIN_LINK_LIST.md`](ARTICLE_EDITOR_DOMAIN_LINK_LIST.md) |
| Editor widget locks (featured/images/publishing/status) | Manifest `content/editor-widget-locks.json` — see client `docs/architecture/ARTICLE_EDITOR_WIDGET_LOCKS.md` |
| Featured / gallery | `omnichannel-addons/media` |
| WP sync / bridge | `omnichannel-addons/wordpress` |
| Publishing schedule/queue | `omnichannel-addons/publishing` |
| SEO scoring / audit | `omnichannel-addons/seo` |
| Performance Hub / GSC | `omnichannel-addons/search-intelligence` |
| Content Project | `omnichannel-addons/content-projects` |
| Draft → Execution Project split / packing | `SplitDraftContentProjectService` + `ContentProjectExecutionPackingService` — [`CONTENT_PROJECTS.md`](../modules/CONTENT_PROJECTS.md) |
| Assign to Content Project UI | Contract/ActionFactory: `content-projects/Support/AssignToContentProject`. Drawer/trigger/React opener: `content`. Compat mounts only. **No** second modal. See [`CONTENT_PROJECTS.md`](../modules/CONTENT_PROJECTS.md) |
| Topics / Keyword DNA / Recluster | `omnichannel-addons/search-intelligence` — UI label Topics; see [`SEO_AUDIT_AND_KEYWORDS.md`](../modules/SEO_AUDIT_AND_KEYWORDS.md) |
| Product review create / AI history | `omnichannel-addons/commerce` (`ProductReviewCreationPolicy`, `ProductReviewGenerationHistoryRecorder`) |
| Prompt / provider | `omnichannel-addons/ai-prompt` |
| Site Sync | `omnichannel-addons/site-sync` |
| Site Sync V3 (protocol 3) | `site-sync` — `SiteSyncV3Schema`, `RunSiteSyncV3Orchestrator`, `SiteSyncProtocolRouter` — see [`SITE_SYNC.md`](../modules/SITE_SYNC.md) §17 |
| Contextual Help (in-app) | Client `app/Help/*` + `resources/help-seed/` — see [`CONTEXTUAL_HELP.md`](../modules/CONTEXTUAL_HELP.md) |
| Article meta inventory / WP body cache | `content` `ArticleMetaKeyCatalog`; `wordpress` `ArticleWpContentCacheService` — see [`ARTICLE_EDITOR.md`](../modules/ARTICLE_EDITOR.md), [`WORDPRESS_BRIDGE.md`](../modules/WORDPRESS_BRIDGE.md) |
| Agent / MCP | `omnichannel-addons/agent` |
| Social Profile / manual share | `omnichannel-addons/social` |
| SEO DB connection bootstrap | `omnichannel-addons/search-foundation` |
| System User placeholder (not a writer) | Client `App\Services\Users\SeoOpsSystemUser` |
| Save transport / SaveCoordinator | `omnichannel-client/resources/js/client-core` |
| Filament Blade `seo-content-ai::` | `omnichannel-addons/seo-content-ai-compat` |

Column ownership detail: [ARTICLE_COLUMN_OWNERSHIP.json](ARTICLE_COLUMN_OWNERSHIP.json).

---

## C. Database

| Kind | Names / command |
|------|-----------------|
| **Protected** | `omi_client`, `omi_seo_ai` — never destroy / never `fresh` |
| **Retired** | `omi_channel` → renamed `omi_channel__pre_client_split_backup` (not runtime) |
| **Non-destructive verify** | `php artisan refactor:migrate --verify --via-mysql` (safe to re-run; expect idempotent) |
| **Disposable** | `*_test` DBs only |
| **Fresh test DB** | only with `--confirm-destroy-test-db` |

Do **not** run destructive fresh against protected DBs.

| Connection | Physical | Role |
|------------|----------|------|
| `mysql` / core | `omi_client` | Client shell + automation runtime |
| `omi_seo_ai` | `omi_seo_ai` | SEO/content peer-addon business tables |

SEO runtime connection name: `omi_seo_ai` (bootstrapped from core `seo_database_connections` via Search Foundation).
Automation runtime: `AUTOMATION_DB_CONNECTION=mysql` (never `omi_seo_ai`).

---

## D. Article SoT (tables)

| Concern | SoT table / owner |
|---------|-------------------|
| Document (title, slug, body, `editor_document`, …) | `articles` — **Content** only |
| Sparse misc meta | `article_meta` — sparse only |
| SEO article state | `seo_article_profiles` |
| WP article state | `wordpress_article_links` |
| Media article state | `article_media_states` (+ canonical media relation) |
| Publishing state | `publishing_article_states` |
| Project archive | `seo_content_archive_items` |

Sibling addons **must not** add business columns to `articles`.

---

## E. React SoT

| Domain | Path |
|--------|------|
| Content facts / document | `addons/content/resources/js/editor/domains/content/` |
| Media facts | `addons/media/resources/js/editor/domains/media/` |
| SEO facts | `addons/seo/resources/js/editor/domains/seo/` |
| Publishing facts | `addons/publishing/resources/js/editor/domains/publishing/` |
| WordPress facts | `addons/wordpress/resources/js/editor/domains/wordpress/` |
| Host / save owners registration | `addons/content/resources/js/editor/domains/registerDomainSaveOwners.js` |
| Shell entry | `addons/content/resources/js/article-editor.jsx`, `SeoArticleEditor.jsx` |
| Server sectioned view | `addons/content/src/Services/ArticleEditorView.php` |

One React state owner per fact. Global Save = SaveCoordinator. Payload: missing = untouched; `null` = clear; value = set.

---

## F. Important do-nots

- Don’t add columns to `articles` from a sibling addon  
- Don’t restore Article compatibility accessors / facade  
- Don’t add business code to SeoContentAi  
- Don’t use `window` bridge for mutations (diagnostics only)  
- Don’t use `useEffect` for business workflow  
- Don’t build mega mutation payloads  
- Don’t `fresh` protected DB  
- Don’t redesign architecture / invent new stores or capabilities for aesthetics  

---

## G. Current known verification debt

Documented debt — **do not** start another architecture wave for these:

| Item | Status |
|------|--------|
| Manual browser regression | **PENDING** (USER checklist) |
| Real WP E2E | **PENDING** — no local WP runtime in this environment |
| Key contracts (Ownership, WP harness, Publishing invariants, ExtensionCutover, RuntimeLogger, SaveCoordinator) | **PASS** this session |
| Broad addon suites | Residual **B** failures: stale test FQCN / Mockery / env (sqlite vs SEO MySQL). Fix test config when touching that area — **not** architecture |
| `WordPressPermalinkBuilderTest` | Residual Mockery/siteInfo errors — class **B/C** |
| Codebase-memory | Fast reindex: `D-work-omnichannel-client`, `D-work-omnichannel-addons`, `D-work-wp-seo-ai`. Retire trust in `D-work-omnichannel-backend`. Always re-check symbols against disk. |

Closure report: `docs/architecture/TASK9_CLOSURE_REPORT.json`

---

## H. First commands for a new agent

Safe, non-destructive starting set (remote-first; do not use `php artisan test` as project standard):

```text
composer dump-autoload -o

npm run build

node --test resources/js/client-core/__tests__/saveCoordinator.test.mjs

php artisan refactor:migrate --verify --via-mysql
php artisan refactor:migrate --verify --via-mysql

$PHP_BIN vendor/bin/phpunit --filter=ArticleExtensionOwnershipContractTest
$PHP_BIN vendor/bin/phpunit --testsuite ContentAddon
```

Then read:

1. `docs/architecture/ADDON_ARCHITECTURE.md`  
2. `docs/architecture/SEO_CONTENT_AI_COMPAT_SHELL.md`  
3. `docs/architecture/POST_REFACTOR_MANUAL_CHECKLIST.md`  
4. Relevant `docs/modules/*` for the feature under change  

Deploy tracking (backend only): `.secure/deploy-diff.ps1` with a kebab-case `-Id`. Plugin repo `wp-seo-ai` is **not** covered by that script.
