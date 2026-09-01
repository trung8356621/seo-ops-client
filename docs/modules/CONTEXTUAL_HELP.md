# Contextual Help

> Status: Canonical  
> Owner: omnichannel-client (`app/Help/*`)  
> Last verified: 2026-09-01  
> Human content SoT: `resources/help-seed/` (public repo `seo-ops-help`)

## 1. Purpose

In-app contextual Help for SEO Ops — **separate** from Prompt Guidance and Settings.

- End-user documentation opens from field/section hint buttons without Livewire round-trip.
- Global Help modal lists all groups (including empty groups).
- Admin can create/edit/publish topics; seed content ships in `resources/help-seed/`.
- **Not** a Settings page — Recommendations/docs do not live under Settings navigation.

## 2. Canonical surfaces

| Surface | Path / class | Role |
|---------|--------------|------|
| Field hint | `HelpUi::fieldHintAction()` | Icon button → `seo-global-help:open` CustomEvent |
| Section header | `HelpUi::sectionHeaderActions()` | Same affordance on Filament Section |
| Global modal | Frontend listener on `seo-global-help:open` | Resolve `contextKey` → topic payload |
| Admin list | `Filament/Pages/HelpTopicsAdmin` | Grouped topic management |
| Admin create/edit | `HelpTopicCreate`, `HelpTopicEdit` | Frontmatter + markdown body |
| CLI sync | `php artisan help:sync` (`HelpSyncCommand`) | Pull/publish from GitHub or local repo |
| Seed (dev) | `resources/help-seed/docs/{group}/*.md` | 48 topics at VERSION `2026.08.31.1` |

Config: `config/help.php` — groups, GitHub repo, local junction `.local/help-repo`.

## 3. Main components

| Component | Path | Role |
|-----------|------|------|
| Group registry | `app/Help/HelpGroupRegistry.php` | Canonical groups; sort from `groups.json` override |
| Context keys | `app/Help/HelpContextKeyRegistry.php` | Map UI `contextKey` → topic key |
| Context builder | `app/Help/HelpContextKeyBuilder.php` | Prefix from group `context_prefix` |
| Runtime payload | `app/Help/HelpRuntimePayloadBuilder.php` | Modal JSON: groups + topics + coverage |
| Publish | `app/Help/HelpPublishService.php` | Write topic files to help repo |
| Remote sync | `app/Help/HelpRemoteSyncService.php` | GitHub fetch + cache |
| Local repo | `app/Help/HelpLocalRepo.php` | Junction sibling repo reads |
| Cache | `app/Help/HelpCacheStore.php` | Filesystem cache under `storage/app/help-cache` |
| Markdown | `HelpMarkdownDocument`, `HelpMarkdownRenderer`, `HelpHtmlToMarkdownConverter` | Parse/render frontmatter body |
| Coverage | `app/Help/HelpCoverageService.php` | Missing context key diagnostics |
| Provider | `app/Help/HelpServiceProvider.php` | Bindings + routes |
| Filament form trait | `Filament/Pages/Concerns/InteractsWithHelpTopicForm.php` | Shared admin form fields |

## 4. Help groups (display order)

From `resources/help-seed/groups.json` + `config/help.php`:

`getting-started`, `overview`, `dashboard`, `websites-domains`, `content-planning`, `articles`, `article-editor`, `writing-posts`, `keywords-topics`, `seo`, `seo-indexing`, `media`, `publishing`, `sync-queue`, `settings`, `account-settings`.

Topic folders under `resources/help-seed/docs/` mirror group ids.

## 5. Topic frontmatter

Required fields per `resources/help-seed/README.md`:

`key`, `title`, `summary`, `group`, `sort_order`, `status`, `keywords`, `related`, `video`, `updated_at`.

## 6. Integration rules

- Open Help **client-side only** (`HelpUi` Alpine handler) — no Livewire for pure open/toggle.
- `contextKey` format: `{context_prefix}.{slug}` (built via `HelpContextKeyBuilder`).
- Prompt Guidance and Help are **different** stores — do not merge.
- Settings nav must not host standalone documentation pages.

## 7. Tests

`tests/Unit/Help/HelpSystemTest.php` — group registry, seed integrity, context prefix, publish round-trip.

```text
$PHP_BIN vendor/bin/phpunit --filter=HelpSystemTest
```

## 8. Related documents

- [ARTICLE_EDITOR.md](ARTICLE_EDITOR.md) — editor widgets with help context keys
- [PROMPTS_AND_AI.md](PROMPTS_AND_AI.md) — Prompt Guidance (not Help)
- [SITE_MCP_AND_DOMAINS.md](SITE_MCP_AND_DOMAINS.md) — domain settings surfaces
