# Prompts and AI

> Status: Canonical  
> Owner: `ai-prompt` (runtime) + `seo-content-ai-compat` (Filament page/views/lang)  
> Last verified: 2026-09-05  
> Supersedes: `docs/MAP_SEO_SETTINGS.md` (prompts/settings/AI slices), `docs/archive/maps/MAP_SEO_SETTINGS.md`, `docs/archive/prompts/*`, `docs/archive/automation/prompt/*` (durable ownership/runtime only — not phase rollout dumps), `docs/archive/extension-sdk/AI_PROVIDER_SDK.md`

## 1. Purpose

Canonical map for **prompt ownership**, **hook contracts**, **AI provider resolve**, Filament settings/API surfaces, and forbidden dual-write.

Layers (must stay separate):

| Layer | Owns |
|-------|------|
| **Hook** | Capability/contract (`key@version`); input/output schema; templates locale; normalize |
| **Settings binding** | `prompt_hook_bindings` map `hook_key → prompt_id` (runtime SoT for settings-visible hooks) |
| **Task-owned prompt** | `prompt_id` on workflow graph Prompt Block / SeoTask node |
| **Provider** | `AiProviderResolver` → registry drivers (no vendor hard-code in PromptRunner) |
| **Domain write** | Business Action / Workflow action node / UI apply — **never** Hook Engine |
| **Domain prompt context** | `SiteDomainPromptContextService` — official Site MCP JSON (`seo_domain_prompt_context`) |
| **WP field sync** | `DomainPromptContextWordPressFieldSyncService` — explicit pull of `company_short_identity` / `short_description`; **not** Site Sync |

Unassigned Prompt (no Hook, no binding, no Task ref) is valid storage — does **not** auto-run.

**Article Editor (Phase 6C.4):** in-editor AI Chat (image/video generate + selection) is React runtime module `article-editor.ai`. Prompt configuration, AI history, billing/audit stay Filament/Laravel shell. See [`ARTICLE_EDITOR_RUNTIME_COMPLETION.md`](../architecture/ARTICLE_EDITOR_RUNTIME_COMPLETION.md). Legacy ModuleHost cleanup: [`ARTICLE_EDITOR_LEGACY_CLEANUP.md`](../architecture/ARTICLE_EDITOR_LEGACY_CLEANUP.md).

## 2. Canonical routes

Panel prefix: `/seo/{connection_hash}/`

| Path | Page / role |
|------|-------------|
| `settings` | `SeoSettings` redirect → overview |
| `settings/overview` | `SeoSettingsOverview` — model status, team chat limits |
| `settings/workflows` | `SeoSettingsWorkflows` — task workflows + prompt hook bindings |
| `settings/ai-advanced` | `SeoSettingsAiAdvanced` — image/typography/video routing |
| `settings/editor` | `SeoSettingsEditor` — local draft interval, undo, FAQ catch |
| `settings/keywords` | `SeoSettingsKeywords` — CTA blacklist, review reasons |
| `settings/prompt` | `SeoSettingsPrompt` — default prompts / system prompts |
| `settings/scoring` | `SeoSettingsScoring` — SEO scoring rule overrides |
| `settings/recommendations` | `SeoSettingsRecommendations` — docs-only (no runtime) |
| `settings/api` | `AiConnectionResource` — API connections (AI + SEO providers) |
| `settings/ai` | Legacy redirect → `settings/api` |
| `settings/ai-center` | `SeoSettingsAiCenter` — Models + Routing (Text/Image/Video) |
| `prompts` | `PromptResource` — Prompt management |
| `extensions` | Extension states UI (provider health) |
| `image-optimization` | `ImageOptimizationSettings` — site-aware media opts |

**HTTP**

| Method | Path | Role |
|--------|------|------|
| POST | `/api/seo/prompt-hooks/{hookKey}/execute` | `PromptHookExecuteController` — AI only; **no** article/SEO/WP save |

Gates: Manager for most settings (`canAccessManagerFeatures`); Prompt CRUD planner + mutation (`canAccessPlannerFeatures` / `allowsSeoPanelMutation`).

## 3. Main components

| Concern | Class |
|---------|--------|
| Prompt model | `Models/SeoPrompt` (`prompts`, `omi_seo_ai`) |
| Prompt CRUD | `Filament/Resources/PromptResource` |
| Workflows + bindings | `SeoSettingsWorkflows` + `SeoCreateArticleSettingsService` |
| Binding resolve | `SettingsPromptBindingResolver` |
| Hook catalog (UI) | `PromptHookEditorCatalog` |
| Hook execute (Phase-1 path) | `PromptHookExecutionService` |
| Hook runtime engine | `PromptHooks/Runtime/*` (`PromptHookCallerBridge`, DefinitionLoader, …) |
| Hook binding runner | `PromptHookBindingRunner` → `PromptHookExplicitBindingExecutor` (DI bind in `AiPromptServiceProvider`) |
| Production route eligibility | `Support/AiProductionRouteEligibility` — DeepSeek allowed for keyword.* / KD longform; **not** TextReasoning Outline/Vocabulary or `article.*` longform |
| Manifest load | `resources/prompt-hooks/*.json` + `PromptHooks/` |
| Entity context | `ArticlePromptHookEntityResolver` (array context only) |
| AI run engine | `PromptRunnerService` |
| Model route/failover | `AiModelRouterService` + `GeminiModelVersionPolicy` |
| Model context window | `ModelContextCapabilityResolver` → `ModelContextCapability` (context/output ceilings + estimator family) |
| Prompt budget preflight | `PromptBudgetPreflightService` — dual-layer: task-planning estimate + final outbound invariant |
| Outbound budget gate | `AiOutboundBudgetGate` — verify at adapter boundary before HTTP (`verifyCompiled` / `verifyMessages`) |
| Token estimate | `PromptTokenEstimator` |
| Split strategies | `PromptBudget/*` + `PromptSplitStrategyRegistry` — classes `PromptSplitClass` (`direct_fit` \| `semantic_split` \| `compactable` \| `unsplittable` \| `business_split`) |
| Semantic split exec | `SemanticSplitExecutor` + strategy-specific splitters/mergers (long-form article, HTML-safe rewrite, keyword discovery) |
| Failure classify | `AiProviderFailureClassifier` → `AiFailureDecision` / `AiFailureClass` (route skip via `AiRouteCapabilitySkipException`) |
| Provider resolve | `Extension/…/AiProviderResolver` + `AiProviderRegistry` |
| Builtin AI drivers | `Extension/Builtin/AiProviders/` (`GeminiAiTextProvider`, `ClaudeAiTextProvider`) |
| Claude exec | `AiExecutionService` |
| Image gen | `MediaGenerationService`, `ImageRoutingStrategy` |
| Image output mode | `ImageOutputModePromptInjector` |
| PromptResult attach | `PromptResultAttachService` / action `prompt_result.attach` |
| Typed workflow artifacts | `WorkflowArtifactType` + `WorkflowTypedArtifact` + `ArtifactReusePolicy` — PromptResult is audit only; domain write consumes declared typed deps |
| Usage / delete safety | `PromptUsageLocator`, `PromptDeleteGuard` |
| Prompt Editor — Runtime Rules UI | `PromptRuntimeRulesPresenter` — readonly panel from Hook definition + `PromptOutputContractCatalog` (no compose, no user markdown). Bound via `SeoContentAiServiceProvider` (`PromptOutputContractCatalog` / `PromptOutputContractResolver` singletons). |
| Prompt Editor — preview toggle | `PromptCompositionSummaryPresenter` — default Runtime Rules; `Show Effective Prompt (Debug)` ON → full compose via `PromptHookCompositionPreviewService`. |
| Prompt Editor form | `PromptResource` — MarkdownEditor `minHeight(280px)` + `maxHeight(600px)` (EasyMDE single scrollbar); section title Runtime Rules (Built-in). |
| Default comment | `DefaultCommentPromptInstaller` + hook `article.comment.generate` — `{{comment_count}}` default **3** (align `ProductReviewCreationPolicy::DEFAULT_TARGET_COUNT`) |
| Vocabulary research persist | Workflow action `save_vocabulary_research` / BusinessAction `keyword.vocabulary.save` (`SaveKeywordVocabularyAction`) → `WorkflowKeywordResearchService::ingestVocabularySuggestGroupsSafe` |
| Split outline / vocabulary | Hooks `article.outline.structure.generate` + `article.vocabulary.generate` via `ArticleOutlineVocabularySplitExecutor` — **markerless direct output** (migrations `2026_09_04_120000_*` / `140000_*` refresh contracts); installer `DefaultSplitOutlinePromptsInstaller`; business SoT after Save is keyword suggest rows, **not** Prompt History |
| API connections list | `ApiConnectionsListService` + `AiConnectionResource` |
| SEO provider matrix | `SeoProviderRegistry` + `SeoProviderCapabilityResolver` |
| Outline input any-of | `article.outline.generate` — `post_title` and `keyword` individually optional; `metadata.require_any_of` enforced by `PromptHookRequireAnyOf` / ExplicitBinding. Project item requires at least one of keyword or post_title. Both may be provided. AI outline/article generation may generate or optimize the final title. |
| Outline output normalize | `MarkdownSectionsOutputParser::normalizeProviderRaw` — strip BOM, unwrap outer fence, drop short prologue/epilogue; still reject between-section prose, duplicates, missing markers, undeclared task markers. Downstream writing skipped (not failed) when outline fails. |
| Topic input (schema-gated) | `PromptHookExplicitBindingExecutor::enrichTopicInput` + `mapInput` whitelist — injects runtime `topic` only when **current** hook `input_schema` declares `topic`. Legacy compile uses schema-whitelisted `$input` only (no shared-payload merge). Must not leak into `article.content.generate` / comment / FAQ → `Unknown input key [topic]`. |
| Outline fail → write skip | `TaskWorkflowTestRunner::run` marks content/write steps `skipped` with “Không chạy vì bước Dàn ý thất bại.” — not Failed missing-outline. |
| Content fail → persist block | Persist action `blocked` when no valid `article_content` artifact — never fallback to outline / latest PromptResult. |
| Domain context patch | `SiteDomainPromptContextService::patchForSite()` — partial update used by WP field sync + form save |
| WP sync access gate | `WordPressFieldSyncAccessGate` — manager/planner + site binding before profile pull |

### WordPress → Domain Prompt Context field sync (2026-09-01)

Owner: `ai-prompt` service + `search-foundation` Edit Domain UI (`SyncsDomainPromptContextFromWordPress`).

- Reads WP via `site-sync` `WordPressSiteProfileReader` (`GET /omi-seo-ai/v1/sync/v2/profile`).
- Writes only whitelisted keys through `SiteDomainPromptContextService::patchForSite()`.
- **Forbidden:** treating this as Site Sync, keyword scrape, or full profile replace.
- Tests: `DomainPromptContextWordPressFieldSyncTest`, `DomainPromptContextWordPressSyncUiContractTest`, `SiteDomainPromptContextPatchTest`.

## 4. Data ownership

| Store | Connection | SoT |
|-------|------------|-----|
| `prompts` | `omi_seo_ai` | Prompt body, variables, `ai_connection_id`, optional hook key |
| Settings option (workflows) | site/option JSON | `prompt_hook_bindings` **runtime SoT**; task keys `KEY_*_TASK` |
| Legacy option keys (`article_title_suggestion_prompt_id`, …) | same JSON | Rollback / migrate-on-read **only** — not runtime write SoT |
| `prompt_results` / `seo_prompt_result_links` | `omi_seo_ai` | Audit artifacts + domain attach |
| `api_connections` / `seo_ai_models` | core `mysql` | AI credentials + synced models |
| GSC / DataForSEO / SERP / extended | core `mysql` | External SEO provider rows |
| Hook JSON manifests | disk | Contract schemas; **no secrets** |
| Locale templates | `lang/{vi,en}/prompt_hooks.php` | Labels + template text |

**Hook ownership**

| Flag | Meaning |
|------|---------|
| `settings_visible=true` | Global Settings binding slot |
| `settings_visible=false` | Task/editor only (e.g. `article.content.generate`, `article.content.rewrite`) |

Hook does **not** activate a Prompt; Settings or Task reference does.

## 5. Read path

1. UI/Settings: `PromptHookEditorCatalog::settingsVisibleHooks()` → bindings via `SeoCreateArticleSettingsService::getBoundPromptId`.
2. Resolve Prompt: `SettingsPromptBindingResolver` / Task node `prompt_id` — **never** “find active prompt by hook”.
3. Hook execute: Registry + InputResolver + SettingsResolver → Assembler → `PromptRunnerService` → OutputNormalizer.
4. Provider: `AiProviderResolver::assertTextReady($providerId)` fail-closed (`ai_provider.not_configured|not_registered|disabled`).
5. Model Status / Overview: `AiModelRouterService::overviewForUser()` + `GeminiModelVersionPolicy::routingDecision()` (major ≥ 3 for auto-route).

Form encoding: Filament `.` nesting — form keys `article__title_suggestion` decode to `article.title_suggestion` on save.

## 6. Write path

```text
Settings save
  → SeoCreateArticleSettingsService
  → prompt_hook_bindings (SoT)
  → migrate-on-read legacy keys if present (read only)

Prompt Hook execute
  → PromptHookExecutionService | PromptHookCallerBridge (legacy|shadow|hook)
  → PromptRunnerService → AiProviderResolver → provider
  → optional PromptResult row
  → UI apply OR caller → Business Action / Workflow action
  → prompt_result.attach (domain) — not Hook Engine

Task / Content Project workflow
  → TaskWorkflowTestRunner prompt nodes → PromptRunnerService / ExplicitBinding
  → outline role node → ArticleOutlineVocabularySplitExecutor (structure + vocabulary = 2 PromptResults)
  → each successful prompt registers typed artifact (article_outline | article_content | …)
  → action nodes consume only declared typed dependencies (PromptTestPublishService)
  → partial rerun: flushPendingArticleContentIfNeeded() when content artifact exists but save_article skipped
  → WP outbound only via wordpress.* / Publishing module
```

**Split outline hooks (canonical pair):**

| Hook | Role |
|------|------|
| `article.outline.structure.generate` | Task 1 — structure |
| `article.vocabulary.generate` | Task 2 — vocabulary |
| `keyword.discovery.structured@0.1.0` | Content Project New Content Planner — structured Draft suggestions (Post or Product). `model.structured_output: true`; callers validate JSON before import. Canonical markdown + OUTPUT CONTRACT in Hook JSON; runtime brief injects content-type contract via `{{brief}}`. DB Prompt markdown is not auto-overwritten (installer `restoreCanonical` only). |

Legacy combined hook `article.outline.generate` still resolves on outline nodes via `isOutlineRoleNode()`. Install default split prompts: `php artisan seo:prompt:install-split-outline-prompts`. Inspector overlay: [ARTICLE_EXECUTION_HISTORY.md](./ARTICLE_EXECUTION_HISTORY.md).

**New Content Planner output contract (caller-owned, not Hook Engine domain write):**

- Prefer raw JSON **array** of suggestion objects. Envelope keys accepted by importer: `items` \| `keywords` \| `suggestions` \| `results` \| `data`.
- Post item: `keyword`, `suggested_title`, `description`, `suggestion_reason`, `source_signal`.
- Product item: same + `product_type`, `gallery_description`.
- `source_signal`: `keyword_gap` \| `cluster_gap` \| `mcp_signal` \| `gsc_signal` \| `related_topic` \| `manual_note` \| `manual_focus`.
- Gate/repair: `content-projects` `NewContentSuggestionStructuredResult` + one repair retry in `NewContentSuggestionPlannerService::discoverOnce`. Do not scrape prose for embedded JSON as the primary fix.

**Vocabulary Suggest persistence (decoupled from Prompt History):**

```text
Outline Task 2 markers [START_TASK_2_VOCABULARY]…[END_TASK_2_VOCABULARY]
  → workflow Save vocabulary research (`save_vocabulary_research` / `keyword.vocabulary.save`)
  → WorkflowKeywordResearchService::ingestVocabularySuggestGroupsSafe
  → seo_article_keywords + Keyword Suggest staging (`TYPE_SUGGEST`)
```

Vocabulary Suggest UI / Planner Idea Candidates read **staging rows**, not “latest PromptResult for vocabulary.” Outline-only CP graphs (`skipContentWriting`) still run Extract keywords + Save vocabulary research. Do not rebuild Suggest from Prompt History timestamps alone.

**Artifact ownership (canonical):**

1. `PromptResult` row = audit — **not** automatically a domain artifact.
2. Every workflow prompt success has `artifact_type` + producer node identity.
3. Domain action consumes only declared typed dependencies (`article_content` for body write).
4. `article_outline` can **never** satisfy `article_content`.
5. Hook inputs are schema-whitelisted per current hook (`mapInput` allowed keys only).
6. No shared-payload leakage (e.g. `topic`) across hooks.
7. Failed prompt → its domain write action is blocked; no previous-node / latest-result fallback.
8. **Manual AI History** (`ArticleAiHistoryApplicationService`): users may list/preview/apply/delete PromptResult + typed artifact history per article. Only valid typed (or fail-closed legacy-classified) `article_outline` / `article_content` may apply — via domain/editor draft services, **never** Hook Engine. Legacy classification is fail-closed (`ArticleAiHistoryLegacyClassifier`). Deleting history tombstones audit rows and unlinks PromptResult; does **not** delete article body/outline, project task/run, or editor revisions. Shared PromptResult hard-clear only when orphaned.

**Modes (migration bridge):** `legacy` (default) | `shadow` (legacy SoT, no double AI) | `hook` (engine + provider once). Rollback any → `legacy`. Jump `legacy` → `hook` **forbidden** (must shadow first).

## 7. Public capabilities

- Settings-visible hook binding CRUD (manager).
- Prompt CRUD (planner); Test Prompt; Sync models on connection.
- Execute hook API for editor fields (title/meta/FAQ/… per catalog).
- Article Editor FAQ AI: `article.faq.generate` via `generatePreview` (no auto body write); Apply uses FAQ snapshot + owning editor session — see [`ARTICLE_EDITOR_WIDGETS_OWNERSHIP.md`](../architecture/ARTICLE_EDITOR_WIDGETS_OWNERSHIP.md).
- Explicit editor binding may run Hook Runtime for one Prompt without flipping global migration mode.
- API Connections: AI (`gemini`/`claude`) + SEO providers via unified list.
- Extension AI providers listed/health via Extensions UI.

## 8. Internal-only capabilities

| Item | Notes |
|------|-------|
| `PromptRunnerService` internals | compile / callProvider / chain steps |
| `AiModelRouterService` failover | marks unavailable; not Agent-facing |
| `prompt_result.attach` | Business Action / attach service after AI |
| Image post-processing | Quick split / resize / output-mode inject |
| `SeoProviderCapabilityResolver` | Matrix for list UI |
| Hook Spec validators / fixtures | Spec engine; production loader remains manifest schema |

## 9. Authorization and confirmation

- Settings: manager+.
- Prompt mutate: planner + panel mutation gate; tenant `user_id` scope via `getEloquentQuery`.
- Hook entity resolvers authorize article/site then return **array** context — no Eloquent to caller.
- Secrets: encrypted `api_key` on connections; **never** in hook JSON / events / logs.
- Delete Prompt blocked when Settings binding or Task Prompt Block references it; Hook change blocked while Settings still bound to old hook.

## 10. Queue and scheduler ownership

| Path | Queue / schedule |
|------|------------------|
| Media / image jobs | `media_generation` / `seo` as existing GenerateMediaJob wiring |
| SEO analyze after content | scoring jobs (Action may defer) |
| Prompt hooks themselves | Sync HTTP for editor execute; no dedicated hook cron |

No Prompt Hook schedule owner — workflows/CP/Agent own cadence.

## 11. Transactions and side effects

- Hook: build request, validate/normalize output — **no** Eloquent save Article/Keyword/Task; **no** WP sync; **no** Business Action call from Hook Engine.
- Domain write after AI = Workflow action **or** caller → Business Action **or** user save.
- `PromptResult` row = audit; attach to Article/Task/Project = `prompt_result.attach` only.
- Image compile may inject Runtime Image Output Mode block (not stored as Manual Hook template).
- Scoring rule settings save does **not** bulk rewrite `article_meta.seo_rule_violations`.

## 12. Retry and recovery

- Workflow owns multi-step retry; Hook may declare `retry.max` for empty/invalid **before** domain write.
- Model failover via `AiModelRouterService` (exhausted / provider unavailable).
- Mode `hook`: after provider cost — **no** silent legacy fallback.
- Invalid output: fail closed — no silent accept.

### Prompt budget / context overflow (2026-09-03)

Outbound AI must fit the **routed model** context window. Runtime path:

```text
PromptRunner / planner
  → PromptBudgetPreflightService::plan (strategy by hook)
  → (optional) SemanticSplitExecutor when plan requires split
  → AiOutboundBudgetGate before provider HTTP
  → PromptBudgetException if still over budget (no silent truncate of safety margins)
```

| Strategy family | Typical hooks | Behavior |
|-----------------|---------------|----------|
| `DirectFit` | most short hooks | Fit in one call or fail |
| `SemanticSplit` | long-form write / HTML rewrite / `keyword.discovery.*` | Chunk → call → merge (`LongFormArticle*`, `HtmlSafe*`, `KeywordDiscoveryBudgetStrategy`) |
| `BusinessSplit` | selected business hooks | Registry-reserved headroom |
| `Unsplittable` / `Compactable` | registry map | Fail closed or compact-only |

**Invariant:** planning estimate must not double-count continuation/schema already inlined into the compiled prompt. Final gate asserts the **exact** outbound messages/tools payload (`OutboundAiRequest`).

## 13. Compatibility paths

- Legacy option prompt_id fields: migrate-on-read into bindings; keep for rollback.
- `legacy_prompt_content` template source: SeoPrompt DB markdown SoT; Hook JSON = contract.
- Phase-1 `PromptHookExecutionService` may orchestrate attach via `PromptResultAttachService` (orchestrator role) — Runtime Engine path does not attach.
- Catalog alias `project.prompt_result.attach` → canonical `prompt_result.attach`.
- Gemini 2.x models may sync/display — excluded from auto-routing (major ≥ 3).

## 14. Forbidden paths

- **Dual-write SoT:** write/activate both legacy per-field prompt_id keys **and** `prompt_hook_bindings` as competing runtime sources; runtime reads bindings only.
- Resolve Prompt by “active/`is_active` for hook” (`is_active` legacy column not a runtime gate).
- Hard-code OpenAI/Gemini/Claude vendor inside PromptRunner — use `AiProviderResolver`.
- Secrets / `api_key` in hook JSON or templates.
- PHP/`eval` in templates; Filament/WP sync imports inside Hook Spec/Normalizer.
- Hook Engine → Eloquent domain save or WP HTTP.
- Silent legacy fallback after hook provider already charged.
- Jump migration `legacy` → `hook` without `shadow`.
- Silent truncate / skip `AiOutboundBudgetGate` when compiled prompt exceeds model context.

## 15. AI Center (Models / Routing / Resilience / Health)

Filament page: `Omnichannel\Addons\AiPrompt\Filament\Pages\SeoSettingsAiCenter`  
Blade/lang shell: `seo-content-ai-compat` (`resources/views/filament/pages/seo-settings-ai-center.blade.php`).

Main tabs (locked order): **Models | Routing | Resilience | Health**.

### Product ownership (locked)

| Surface | Owns |
|---------|------|
| **Models** | Enabled targets per area (Text/Image/Video), display order / priority |
| **Routing** | Per execution profile: **Automatic** vs **Custom** filter only |
| **Resilience** | Fallback attempt budgets only (`max_ai_attempts` / `max_free_attempts`) |
| **Health** | Operational connection/model status after real provider requests (presentation only) |
| Provider short code (`[OR]`, …) | **Display only** — never execution identity |

Canonical execution identity: `connectionId|familyKey` (example: `3|openai.gpt54_mini`).  
Custom Allowed Models store `allowed_execution_keys` (full keys) + derived family keys; order still comes from Models priority.

**Text Routing cards** show that order follows Models (`text_routing_follows_models`). They do **not** expose a “Manage model order” shortcut — reorder only on the **Models** tab.

### UI lifecycle (do not regress)

- Main tab / capability state is **Alpine-only** on `#ai-center-root` (`wire:ignore.self`). Do not reintroduce Livewire `queryString` tab sync or dual CSS-hidden panels — that remounts Alpine and stacks Models+Routing.
- First open of Routing may `loadPanel('routing')` once; subsequent Models↔Routing switches must not spam Livewire.
- Sortable draft is local until **Save order** on the **Models** tab; Routing Text profiles do not deep-link into that reorder UI.
- Tick/Custom edits use existing unsaved-changes + **Save settings** (no auto-save-on-tick).
- Unknown catalog rows appear in Models only after explicit **Add** (`omi_areas` / `AiModelPriorityService::isExplicitlyAreaEnabled`).

### OpenRouter curated Text catalog

Idempotent apply (no second registry):

- Service: `OpenRouterTextRoutingCatalog`
- Command: `php artisan seo:ai:ensure-openrouter-text-routing [--user=]`
- Registered from `seo-content-ai-compat` `SeoContentAiServiceProvider`

Ensures these OpenRouter `raw_model_name` rows (badge `[OR]`), with `display_name` below; does **not** remove direct Gemini/DeepSeek/Claude connections:

| Model ID | Display |
|----------|---------|
| `openai/gpt-5.4` | GPT-5.4 |
| `openai/gpt-5.4-mini` | GPT-5.4 Mini |
| `openai/gpt-5.4-nano` | GPT-5.4 Nano |
| `anthropic/claude-sonnet-4.6` | Claude Sonnet 4.6 |
| `anthropic/claude-haiku-4.5` | Claude Haiku 4.5 |
| `google/gemini-3.5-flash` | Gemini 3.5 Flash |
| `google/gemini-3.5-flash-lite` | Gemini 3.5 Flash Lite |
| `google/gemini-3.1-pro-preview` | Gemini 3.1 Pro |
| `deepseek/deepseek-v3.2` | DeepSeek V3.2 |
| `qwen/qwen3.6-flash` | Qwen 3.6 Flash |

Family mapping lives in `AiModelFamilyCatalog` (distinct families where Routing must tick separately — e.g. `gemini.flash` vs `gemini.flash_lite`, GPT nano/mini/full).  
`ModelCapabilityRegistry::fromOpenRouterGateway` maps `google/` / `openai/` / `anthropic/` / `deepseek/` / `qwen/` OpenRouter slugs into text (+ reasoning where needed) capabilities.

### Text Routing Custom pools

Command sets **Fast / Long-form / Reasoning** to Custom and merges:

```text
existing non-curated allowed keys
+
profile-specific OpenRouter pool
```

Curated OpenRouter keys on the wrong profile are replaced by that profile’s pool (idempotent repair). Image/Video profiles are **not** written by this catalog.

| Profile | OpenRouter pool (raw ids) |
|---------|---------------------------|
| `text.fast` | gpt-5.4-nano, gpt-5.4-mini, claude-haiku-4.5, gemini-3.5-flash-lite, gemini-3.5-flash, deepseek-v3.2, qwen3.6-flash |
| `text.longform` | claude-sonnet-4.6, gemini-3.5-flash, gpt-5.4-mini, gpt-5.4, deepseek-v3.2, qwen3.6-flash |
| `text.reasoning` | gpt-5.4, claude-sonnet-4.6, gemini-3.1-pro-preview, gemini-3.5-flash, gpt-5.4-mini, deepseek-v3.2 |

Persist via `AiRoutingTargetService::saveSimplifiedSelection` (`allowed_execution_keys` + family keys). Refresh must reload Custom + Allowed Models.

**Image / Video routing** remains owned by existing Image/Video strategy paths — AI Center Text catalog work must not mutate those profiles.

## 16. Tests and invariants

| Area | Tests / evidence |
|------|------------------|
| Prompt ownership / bindings | Prompt ownership model tests; `seo:workflow:doctor` labels |
| Hook boundaries | Hook Spec / Runtime unit suites under `PromptHooks` |
| Extension AI resolve | `ExtensionArchitectureFreezeTest`, `ExtensionSdkFoundationTest` |
| RuntimeLogger (HTTP AI controllers) | `RuntimeLoggerWebAppChannelTest` |
| Domain WP field sync | `DomainPromptContextWordPressFieldSyncTest`, `DomainPromptContextWordPressSyncUiContractTest` |
| AI Center OR Text catalog | `OpenRouterTextRoutingCatalogTest`, `AiModelFamilyUxTest`, `AiRuntimeRoutingRefactorTest`, `AiRoutingUxTest`, `AiModelsUnifiedTableTest` |
| Prompt budget / outbound | `PromptBudgetPreflightServiceTest`, `PromptBudgetBoundedExecutionTest`, `OutboundBudgetInvariantContractTest` |
| Split outline markerless | `SplitOutlineDirectOutputContractTest`, `SplitOutlineInputContractAndDeepSeekEligibilityTest`, `ArticlePromptRunHistorySplitPresentationTest` |
| OpenAI-compatible extract | `OpenAiCompatibleTextExtractionAndCheckpointTest` |
| Runtime failover / health | `AiRuntimeFallbackTest`, `AiRuntimeHealthStateTest`, `AiProviderFailureClassifierTest` |

**Invariants:** bindings SoT; Task-owned prompts separate; Hook ≠ domain write; provider via resolver; no dual-write legacy+bindings; fail-closed provider/output; AI Center Custom identity = `connectionId|familyKey`; no duplicate `provider + model_id` on OpenRouter catalog ensure; outbound payload must pass `AiOutboundBudgetGate` (no silent over-context send).

## 17. Related documents

- `docs/modules/EXTENSION_SDK.md`
- `docs/contracts/EXTENSION_AND_REGISTRY_CONTRACTS.md`
- `docs/modules/AUTOMATION.md`
- `docs/modules/CONTENT_PROJECTS.md`
- `docs/modules/ARTICLE_EXECUTION_HISTORY.md`
- `docs/modules/PUBLISHING.md`
- `docs/modules/OPERATIONS_AND_OBSERVABILITY.md`
- `docs/operations/TESTING.md`
)
