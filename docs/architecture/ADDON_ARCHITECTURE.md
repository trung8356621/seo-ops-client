# Addon Architecture (authoritative)

> Status: Canonical after Task 9 refactor closure  
> Last verified: 2026-08-10  
> Handoff: [NEW_AGENT_HANDOFF.md](NEW_AGENT_HANDOFF.md) · Shell: [SEO_CONTENT_AI_COMPAT_SHELL.md](SEO_CONTENT_AI_COMPAT_SHELL.md)  
> Column map: [ARTICLE_COLUMN_OWNERSHIP.json](ARTICLE_COLUMN_OWNERSHIP.json)

## Core

**Core = protocol / runtime only.**

Core owns identity, tenancy, billing, addon discovery/registry, SEO DB credential rows, shared HTTP/logging/queue plumbing, and frontend **transport/runtime** (e.g. `resources/js/core/saveCoordinator.js`).  
Core does **not** own SEO/content business models, article domain state, or peer-addon Filament product pages.

## Peer addons

All product domains live under `/addons/{slug}` as **peers** (no parent/child hierarchy):

| Slug | Role (short) |
|------|----------------|
| `search-foundation` | SEO DB connection bootstrap, shared search infra |
| `seo` | SEO score/meta, audit, GlobalSeoBar, SEO editor domain |
| `search-intelligence` | GSC / Performance Hub |
| `ai-prompt` | Prompts, prompt hooks, AI result UX |
| `content` | Articles table, editor document, ArticleEditorView |
| `content-projects` | Projects/tasks, archive mirror |
| `media` | Media library, featured/gallery, media editor domain |
| `wordpress` | WP bridge write/sync, wordpress_article_links |
| `publishing` | Publish queue/schedule, publishing_article_states |
| `site-sync` | Site Sync v2 orchestration |
| `agent` | Agent/MCP, Extension Builtin discovery |
| `social` | Social domain |
| `commerce` | Commerce domain |

## SeoContentAi = compatibility only

`app/Addons/SeoContentAi/` is a **compatibility shell** (Filament view namespace, panel bootstrap, config/lang merge, route shim).  
**No new business code** there. See [SEO_CONTENT_AI_COMPAT_SHELL.md](SEO_CONTENT_AI_COMPAT_SHELL.md).

Active business code lives in `/addons/*`.

## Database planes (post cutover)

| Plane | Laravel connection | Physical DB | Owns |
|-------|--------------------|-------------|------|
| Client core | `mysql` (default / `core_connection`) | `omi_client` | SaaS core, automation `automation_*` + `business_events`, SEO credentials |
| SEO addon | `omi_seo_ai` | `omi_seo_ai` | Articles, keywords, prompts, media, projects, `seo_link_maps`, … |
| WP Headless | `wp_headless` | (addon local) | Headless site tables |

`omi_channel` is **retired** (renamed to `omi_channel__pre_client_split_backup`). Do not target it for runtime or `migrate:fresh`.

See [FINAL_DATABASE_ARCHITECTURE.md](FINAL_DATABASE_ARCHITECTURE.md) and [DB_REPOSITORY_OWNERSHIP.json](DB_REPOSITORY_OWNERSHIP.json).

## Hard rules (1–20)

1. All addons are peers.
2. No parent/child addon hierarchy.
3. Addon cannot add business columns to another addon's table.
4. Cross-addon communication: capability / command / event / stable DTO.
5. No sibling implementation imports.
6. `articles` belongs Content only.
7. `article_meta` is sparse metadata only.
8. SEO Article state → `seo_article_profiles`.
9. WP Article state → `wordpress_article_links`.
10. Media Article state → `article_media_states` / canonical media relation.
11. Publishing → `publishing_article_states`.
12. Project archive → `seo_content_archive_items`.
13. React state has one owner.
14. No `useEffect` business workflows.
15. Core frontend owns transport/runtime only.
16. Global Save is SaveCoordinator orchestration.
17. missing payload field = untouched.
18. `null` = intentional clear.
19. value = set/update.
20. SeoContentAi is compatibility-only; no new business code.

## Canonical handoff for new agents

Start here:

1. [NEW_AGENT_HANDOFF.md](NEW_AGENT_HANDOFF.md) — where to look, SoT maps, do-nots, first commands  
2. [SEO_CONTENT_AI_COMPAT_SHELL.md](SEO_CONTENT_AI_COMPAT_SHELL.md) — what remains under SeoContentAi and why  
3. [POST_REFACTOR_MANUAL_CHECKLIST.md](POST_REFACTOR_MANUAL_CHECKLIST.md) — USER browser / WP verification debt  

**Architecture refactor is CLOSED.** No Task 10 redesign wave.

## Assign to Content Project UI Contract

> **CANONICAL / ARCHITECTURE RULE** — shared cross-module capability. Feature status: **CLOSED** after consolidation.

1. Assign to Content Project is a shared cross-module capability (articles, keywords, SEO audit, editor, link bubble, vocabulary planning).

2. There is exactly **ONE** canonical **right-side** workspace/sidebar implementation:
   - Livewire: `Omnichannel\Addons\Content\Livewire\AssignToContentProjectDrawer`
   - View: `content::livewire.assign-to-content-project-drawer`
     (`content/resources/views/livewire/assign-to-content-project-drawer.blade.php`)
   - Layout: `inset-y-0 right-0` (not left, not centered modal)
   - Ownership: **content** addon (not `seo-content-ai-compat`). Compat may mount/consume the drawer; it MUST NOT own the canonical Blade source of truth.
   - `AssignToContentProjectModal` / modal Livewire tag are compatibility aliases only — they render the same drawer.

3. There is exactly **ONE** canonical trigger identity/presentation abstraction:
   - Blade: `x-content::assign-to-content-project-trigger`
     (`content/resources/views/components/assign-to-content-project-trigger.blade.php`)
   - Filament adapters: `AssignToContentProjectActionFactory`
   - React opener: `content/resources/js/utils/assignToContentProject.js` (`openAssignToContentProject`)
   - Contract: `AssignToContentProjectContract` (`content-projects/.../AssignToContentProject/`)

4. There is exactly **ONE** canonical open contract: `assign-content-project:open`.

5. New modules **MUST** reuse:
   - canonical trigger / ActionFactory / React opener
   - open event `assign-content-project:open`
   - `AssignToContentProjectDrawer`

6. New modules **MUST NOT** create:
   - an assign modal (centered or Filament)
   - a second assign drawer
   - Filament assign form/schema on the Action
   - separate React/Alpine assign form
   - new open event name
   - new icon/presentation for the same action
   - caller-specific assign forms/schemas

7. Domain differences MUST go through `mode` / normalized context / `options` / capabilities.
   - `source` is for context, debugging, and completion/refresh only — not backend routing.
   - Backend domain paths may remain separate. ONE UI ≠ ONE backend service.

8. Allowed modes currently:
   - `article`
   - `keyword`
   - `pending_link`
   - `vocabulary_items` (batch planning phrases; creates TYPE_CREATE items only)

9. If a future mode cannot fit: EXTEND the canonical contract/component + tests/docs. Do **not** create a parallel UI.

10. Row visual canonical = Article table assign action (`heroicon-o-folder-plus`, `warning`, icon-only).

11. Bulk presentation may use a toolbar/bulk-bar button but MUST share the same icon, label, open contract, and drawer.

12. Keyword detail opens via `window` `assign-content-project:open` (`source=keyword_detail`). Do **not** `mountAction('assignToContentProject')`.

13. **Exception (intentional):** Article Editor Vocabulary sidebar assigns **inline** (`EditArticle::assignVocabularyItemsToContentProject` + project select). It must not call `openAssignToContentProject`. `MODE_VOCABULARY_ITEMS` stays on the contract for other/future callers.

Domain backends stay separate (do not over-unify). Drawer submit paths:

- article → `ArticleResource::assignArticlesFromFormData`
- keyword → `KeywordResource::executeAssignKeywordsToContentProjects`
- vocabulary_items → `KeywordProjectAssignmentService::assignPhrases` (planning items only; no auto-generation)
- pending_link → `ArticlePendingInternalLinkService::assignFromEditor`
- Agent/MCP `content_project.add_items` is a separate product surface (not this UI contract)
- SEO issue / automation assignment (`SeoIssueProjectTaskAssignmentService`, `AssignmentCallerBridge`) stay on their own product surfaces — not this drawer

Callers: article table, Article Editor overflow, SEO Audit, keyword table/detail/link-map, link bubble. See [`CONTENT_PROJECTS.md`](../modules/CONTENT_PROJECTS.md) § Assign UI and [`CONTENT_PROJECT_ASSIGN_UI_2026_08.md`](CONTENT_PROJECT_ASSIGN_UI_2026_08.md).

