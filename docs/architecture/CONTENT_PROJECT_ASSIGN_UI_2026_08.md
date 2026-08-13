# Assign to Content Project UI (2026-08)

> Status: Changelog / handoff  
> Owner: content-projects (contract) + content (drawer)  
> Related: [CONTENT_PROJECTS.md](../modules/CONTENT_PROJECTS.md) · [ADDON_ARCHITECTURE.md](ADDON_ARCHITECTURE.md) § Assign UI  
> Rule: `.cursor/rules/content-project-assignment-ui.mdc`

## 1. Canonical surface

“Assign to Content Project” historically existed as left drawer, centered modal, Filament Action `form()`, and caller-specific Alpine/React forms.

**Now:** exactly **one right-side drawer**. The word “modal” in older tickets/aliases means this drawer.

| Piece | Location |
|-------|----------|
| Contract | `omnichannel-addons/content-projects/src/Support/AssignToContentProject/AssignToContentProjectContract.php` |
| Filament open adapters | `AssignToContentProjectActionFactory` — **no** `form()` / `modalHeading` |
| Livewire | `Omnichannel\Addons\Content\Livewire\AssignToContentProjectDrawer` |
| View | `content::livewire.assign-to-content-project-drawer` (`inset-y-0 right-0`) |
| Blade trigger | `x-content::assign-to-content-project-trigger` |
| React | `openAssignToContentProject` |
| Panel mount | `@livewire('assign-to-content-project-drawer')` in `SeoPanelProvider` |
| Alias | `AssignToContentProjectModal` + tag `assign-to-content-project-modal` → **same class** |

Open event: `assign-content-project:open`. Shell: Alpine `shellOpen` immediately + skeleton; Livewire `prepare()` after. `body.assign-drawer-open`, `z-[10050]`.

## 2. Callers

| Surface | Opener | `source` |
|---------|--------|----------|
| Article list | ActionFactory | `article_table` |
| Article Editor overflow | Blade trigger | `article_editor` |
| SEO Audit | Blade trigger | `seo_audit` |
| Keyword list | ActionFactory | `keyword_table` |
| Keyword detail | `window` event (not `mountAction`) | `keyword_detail` |
| Keyword link-map / dictionary | ActionFactory page actions | `keyword_detail_link_map` / `keyword_dictionary_drawer` |
| Link bubble | React opener, mode `pending_link` | `link_edit_bubble` |

**Exception:** Article Editor Vocabulary sidebar assigns **inline** (`EditArticle::assignVocabularyItemsToContentProject`). Must not call `openAssignToContentProject`.

Laravel-only articles (`wp_post_id` null) remain assignable.

## 3. Modes / backends

| Mode | Backend |
|------|---------|
| `article` | `ArticleResource::assignArticlesFromFormData` |
| `keyword` | `KeywordResource::executeAssignKeywordsToContentProjects` |
| `pending_link` | `ArticlePendingInternalLinkService::assignFromEditor` |
| `vocabulary_items` | `KeywordProjectAssignmentService::assignPhrases` (`TYPE_CREATE`, no auto-generate) |

`source` does not select backend. Agent/MCP `content_project.add_items` is a different surface.

## 4. Must not

- Second drawer / centered modal / Filament assign form
- Left-side assign drawer
- New open-event name
- New icon/color (`heroicon-o-folder-plus` / `warning`)
- Compat owning a parallel Blade copy

## 5. Verify

```text
$PHP_BIN vendor/bin/phpunit --filter=AssignToContentProjectUiArchitectureGuardTest
$PHP_BIN vendor/bin/phpunit --filter=AssignToContentProjectDrawerRoutingTest
$PHP_BIN vendor/bin/phpunit --filter=ArticleEditorVocabularyEndpointTest
```

Smoke: Article list, SEO Audit, Keyword list/detail, Editor overflow, Link bubble — same right drawer. Vocabulary sidebar stays inline.
