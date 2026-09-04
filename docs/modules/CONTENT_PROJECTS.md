# Content Projects

> Status: Canonical  
> Owner: content-projects (assign drawer UI: content)  
> Last verified: 2026-09-04  
> Supersedes: `docs/MAP_SEO_PROJECTS.md` (architecture/routes/ownership/state — not historical phase dumps), `docs/archive/content-projects/CONTENT_PROJECT_CANONICAL_ARCHITECTURE.md`, `docs/archive/content-projects/CONTENT_PROJECT_BACKEND_FREEZE_V1.md`, `docs/archive/content-projects/CONTENT_PROJECT_COMMAND_BUS_CUTOVER.md` (command inventory), `docs/archive/content-projects/CONTENT_PROJECT_RUN_ENGINE_REFACTOR.md` (engine ownership invariants only), `docs/archive/content-projects/CONTENT_PROJECT_APPLICATION_API.md`, `docs/archive/content-projects/CONTENT_PROJECT_OPERATIONS.md` (dashboard/ops summary)

## 1. Purpose

Monthly content planning + production for one site/domain on connection `omi_seo_ai`.

- `SeoProject` — month plan (or archive kind).
- `SeoProjectTask` — item (create / rewrite / improve) ↔ optional `SeoArticle` (`article_id` unique).
- `SeoProjectRun` + `seo_project_run_items` — execution records owned by PHP Run Engine.
- Mutations go through `ContentProjectCommandBus::dispatch()` only.
- Item lifecycle reads go through `ContentProjectItemStateResolver` (+ `ContentProjectItemActionGuard`).

## 2. Canonical routes

Panel prefix: `/seo/{connection_hash}/`

| Path | Page / role |
|------|-------------|
| `content-projects` | `ListSeoProjects` |
| `content-projects/create` | `CreateSeoProject` |
| `content-projects/{record}` | `ViewSeoProject` — **operations workspace** (KPI + Project Items table) |
| `content-projects/{record}/edit` | `EditSeoProject` — settings + tasks sync |
| `content-projects/{record}/publishing-queue` | Compat redirect (`ContentProjectPublishingQueue`) → `publishing-queue?projectId={record}` |
| `publishing-queue` | `PublishingQueueHub` — Publishing Queue hub (route unchanged); optional `?projectId=` scopes to one project, otherwise cross-project (actions disabled per row). **Nav:** nested under Content Projects (`SeoProjectResource::getNavigationItems` + `parentItem`; hub `shouldRegisterNavigation = false`) |
| `content-operations` | `ContentProjectOperationsCenter` (manager+) |
| `/admin/content-operations` | Redirect → SEO ops |

**Legacy run URLs (redirect only → ViewSeoProject):**

- `content-projects/{record}/runs`
- `content-projects/runs/{run}`
- `content-projects/runs/{run}/items/{article}`

Resource: `Filament/Resources/SeoProjectResource.php` — slug `content-projects`, nav “Content projects”, group SEO Workspace.

Gates: `SeoAccessControl::canAccessContentFeatures` / `canAccessPlannerFeatures` / `canMutateContentProjects`.

REST: `/api/v1/content-projects*` → same commands via Application controllers. Agent/MCP: see `docs/contracts/AGENT_AND_MCP_CONTRACTS.md`.

## 3. Main components

| Concern | Class |
|---------|--------|
| Command dispatch | `Services/ContentProject/Application/ContentProjectCommandBus` |
| Command→handler map | `ContentProjectCommandBusRegistrar` |
| Item state | `Support/ContentProject/ContentProjectItemStateResolver` |
| Action eligibility | `Support/ContentProject/ContentProjectItemActionGuard` |
| Task status normalize | `Support/ContentProject/ContentProjectTaskStatusNormalizer` |
| Dashboard buckets | `Support/ContentProject/ContentProjectItemDashboardBucketMapper` |
| Review SoT | `Services/ArticleReviewService` (`articles.review_status`) |
| Run lifecycle | `Services/RunEngine/ContentProjectRunEngine` |
| Run seed | `Services/SeoProjectWorkflowRunService` (`startRun` + `prepareRunQueue`) |
| Article job | `Jobs/RunContentProjectArticleJob` |
| Rerun gate | `Application/Support/ContentProjectRerunEligibilityGuard` |
| Generate pending set | `ContentProjectItemGenerationClassifier` + `ContentProjectProjectGenerationGate` |
| Bulk generate partition | `ContentProjectBulkGenerationPlanner` (first-generate vs keyword-restart) |
| Generation keyword resolver | `Support/ContentProject/ContentProjectGenerationKeyword` |
| Keyword override command | `SetItemGenerationKeywordOverrideCommand` → `SetItemGenerationKeywordOverrideHandler` |
| Ops read model | `ContentProjectItemOperationsReadModel` |
| Publishing Queue read model | `ContentProjectPublishingQueueReadModel` (`forProject` scoped, `forHub` cross-project) |
| Generation Needs Review read-state | `ContentProjectGenerationReadStateStore` + `seo_content_project_item_generation_read_states` (per user/item `viewed_generation_completed_at`) |
| Needs Review definition | `Support/ContentProject/ContentProjectRecentlyCompletedDefinition` (presentation filter key `recently_completed`) |
| In Review reporting definition | `Support/ContentProject/ContentProjectInReviewReportingDefinition` (filter key `in_review_reporting`; stamp columns on `seo_project_tasks`) |
| Ops counter transition map | `Support/ContentProject/ContentProjectOpsCounterTransitionMap` (optimistic presentation deltas only) |
| Locks | `Application/Support/ContentProjectBusinessLock` |
| Idempotency | `Application/Support/ContentProjectIdempotencyStore` |
| Audit / op log | `ContentProjectBusinessAuditor`, `ContentProjectOperationLogger` |
| Capabilities | `ContentProjectCapabilityRegistry` + `CanonicalCapabilityRegistry` |
| Agent build | `Agent/ContentProjectAgentCommandFactory` |
| Project archive | `ArchiveContentProjectService` |
| Archive preview UI | `ContentProjectArchivePreview` + `ArchivePreviewArticlePresenter` |
| Manual Index marker (checklist) | `ArticleManualIndexMarkerService` — `articles.indexed_at` / `previous_indexed_at` (+ patch archive `article_snapshot`); not GSC/Indexing API |
| Archive Excel export | `ContentProjectArchiveExportService` (includes Index gần nhất / Index lần trước + social evidence child rows) |
| Archived month workbook | `ContentProjectArchivedMonthExportService` — Summary + per-writer sheets; social rows via `ContentProjectArchiveSocialExportRowExpander` |
| Archive social reporting | `ArticleSocialLinkService` (canonical counts/links; migrated from archive-item social rows) |
| Draft planning domain column | `DraftItemDomainRepairService` + inline domain edit in shared draft table (`content-project-draft-items`) |
| Draft clone idea | `CloneDraftCreateIdeaService` — duplicate CREATE row within draft |
| Dashboard month charts | `DashboardDomainArticlesChartWidget` / `DashboardWriterArticlesChartWidget` + `ResolvesContentProjectMonthDashboardCharts` |
| Item archive | `SeoProjectArchiveService` (via `ArchiveProjectItemsHandler`) |
| `seo_projects.status` policy | `Support/ContentProject/ContentProjectStatusDecision` |
| Assign UI contract | `Support/AssignToContentProject/AssignToContentProjectContract` |
| Assign Filament adapters | `AssignToContentProjectActionFactory` (open only — no form/modal schema) |
| Assign drawer (canonical UI) | `content` Livewire `AssignToContentProjectDrawer` + `content::livewire.assign-to-content-project-drawer` |
| SEO Audit / New Content Planner page | `Filament/Pages/ContentProjectSeoAuditPlanner` |
| Draft planning items read model | `ContentProjectDraftPlanningItemsReadModel` |
| AI New Content suggestions | `Services/ContentProject/NewContent/NewContentSuggestionPlannerService` (+ Parser / StructuredResult / Intelligence brief) |
| New Content auto-continuation | `NewContentAutoContinuationPolicy` + `NewContentCrossBatchContinuationPolicy` + `NewContentGenerationBatchPolicy` / `NewContentPlanningSlotSplitter` |
| New Content auto DNA fill | `NewContentAutoDnaPolicy` (system-owned; not Prompt Management content) |
| New Content run outcome | `NewContentPlannerRunOutcome` + readiness `NewContentGenerationReadinessService` |
| Planner plan clone (config only) | `Planner/PlannerPlanCloneService` + `PlannerPlanCloneAllowlist` / `PlannerPlanCloneResult` (+ Livewire `InteractsWithPlannerPlanClone`) |
| Planner post_type correction | `UpdateContentProjectItemCommand` → `UpdateContentProjectItemHandler::applyPlannerPostTypeUpdate` |
| Draft → Execution split | `SplitDraftContentProjectCommand` → `SplitDraftContentProjectHandler` → `Draft/SplitDraftContentProjectService` (+ Livewire `InteractsWithDraftSplit`) |
| Writer fair allocation | `Support/ContentProject/ContentProjectWriterAllocator` |
| Execution packing (max 30) | `ContentProjectExecutionPackingService` + `ContentProjectExecutionLimits::MAX_EXECUTION_PROJECT_ITEMS` |
| Writer monthly capacity settings | `ContentProjectWriterCapacitySettingsService` — global WpOption + per-user meta override (default 30; independent of EP pack size) |
| Writer month workload (display) | `ContentProjectWriterMonthlyCapacityService` — display/allocator input; not a hard monthly gate |
| Writer assignment display | `ContentProjectWriterAssignment` — System User id = unassigned |
| Archive export reviewed_at | `Support/ContentProject/ContentProjectExportReviewedAtResolver` |
| Projects list buckets | `Support/ContentProject/ContentProjectListBucket` (`all` \| `draft` \| `project` \| `archived`) |
| SEO Audit Notes (cluster→DNA) | `AuditNotes/AuditNoteClusterSuggestionQuery` + `AuditNoteDnaNormalizer` + `AuditNotePromptSectionBuilder` + `AuditNoteTargetAllocator` (+ `InteractsWithAuditNotes`) |
| Idea Candidates (Vocabulary Suggest → Draft) | `IdeaCandidates/*` + Livewire `InteractsWithIdeaCandidates` → `AddIdeaCandidatesCommand` |
| EP naming / packing repair | `seo:repair-execution-project-naming` / `seo:repair-execution-project-packing` |

## 4. Data ownership

**DB:** `omi_seo_ai`. Cross-DB site/user via `BelongsToOnDefaultConnection` (no FK across DBs).

| State | Source of truth | Not SoT |
|-------|-----------------|---------|
| Item lifecycle / actions | `ContentProjectItemStateResolver` / `ContentProjectItemActionGuard` | Raw column heuristics |
| Task generation status | `seo_project_tasks.status` via `ContentProjectTaskStatusNormalizer` | Literal string compares outside normalizer |
| Review | `articles.review_status` | Dropped `articles.is_reviewed` |
| Content archive (item/project) | `seo_project_tasks.archived_at` / normalized `archived`, `seo_projects.archived_at` | `review_status = archived` |
| Manual Index checklist | `articles.indexed_at` + `articles.previous_indexed_at` (2 latest only; preview may patch `article_snapshot`) | Google index status / GSC |
| Publish queue | `publish_queue_status` + `publish_published_at` + `scheduled_publish_at` | Task `status` alone |
| Run | `seo_project_runs.status` via Run Engine mappers | Client Alpine `processingRows` (instant feedback only; table SoT after refresh) |
| Project workflow flag | `seo_projects.status` — **non-authoritative for items** (Class A/B/C in `ContentProjectStatusDecision`) | Item phase/counters |

Task types: `create` \| `rewrite` \| `improve`. Post types (create): `article`, `product`, `category`, `product_category`. Identity: `UNIQUE(project_id, source_key)`.

**AI New Content → Draft CREATE persist (Product-aware):**

| Candidate field | `SeoProjectTask` column | Draft UI read-model field |
|-----------------|-------------------------|---------------------------|
| `description` (planning brief) | `secondary_description` | `description` (global) |
| `gallery_description` (Product only) | `description` | `product_description` (only when `post_type=product` and non-empty) |
| `product_type` (Product only) | `loai_san_pham` | (not shown in Draft table by default) |
| content type | `post_type` = `article` \| `product` (`post` → `article`) | `post_type` / `post_type_label` |

Inline Draft edit of Title/Keyword/Description writes global brief to `secondary_description` for CREATE items (`applyPlanningDescription`); Product gallery text on `description` is preserved when correcting `post_type` Product ↔ Post (non-destructive).

**Generation keyword (item-level):** `seo_project_tasks.keyword` = original project input (immutable by override/regen). Optional `seo_project_tasks.generation_keyword_override` = per-item operational correction. Effective input = `ContentProjectGenerationKeyword::effective()` (`override ?: keyword`). Run-level `generation_keyword_override` in `seo_project_runs.settings` remains the fresh-keyword-restart execution stamp only — do not conflate with the task column.

**Item identity (create/rewrite):** Project item requires at least one of keyword or post_title. Both may be provided. Canonical validator: `Support/ContentProject/ContentProjectItemIdentity` (`filled(keyword) || filled(post_title)`). Used by Filament form, sync normalizer, Command Bus add/update, MCP/Agent, generation guards. AI outline/article generation may generate or optimize the final title — do not invent a fake keyword/title only to pass validators, and do not persist topic fallback as if the user entered `post_title`.

## 5. Read path

1. Resolve task (+ article) from DB.
2. `ContentProjectItemStateResolver::resolve()` → `ContentProjectItemState` dimensions + `availableActions` + `blockingReason`.
3. UI/API/Agent read models (`ContentProjectItemOperationsReadModel`, Agent read service, dashboard stats) **must** use resolver/guard — never re-derive lifecycle.
4. Dashboard KPI filters use `ContentProjectItemDashboardBucket` via `ContentProjectItemDashboardBucketMapper`.
5. MCP/Agent reads: `ContentProjectAgentReadService` / MCP read tools — not CommandBus.

`can_generate` / `can_regen` flags = membership of `Generate` / `Rerun` in `availableActions` only.

## 6. Write path

**Single door:** build `ContentProjectCommand` → `ContentProjectCommandBus::dispatch(ActorContext)`.

```text
Filament / REST  ──► CommandBus ──► Handler ──► domain services / RunEngine
Agent/MCP        ──► Gateway → Registry → CommandFactory ──► CommandBus
Scheduler        ──► ProcessScheduledProjectItemPublish (internal) ──► CommandBus
Assign UI        ──► shared drawer ──► existing domain assign services (not Agent add_items)
```

### Assign to Content Project (shared UI)

User-facing “Assign to Content Project” is **one right-side drawer**, not a centered Filament modal. `AssignToContentProjectModal` / Livewire tag `assign-to-content-project-modal` are **aliases** of the same drawer. Architecture rule: [`ADDON_ARCHITECTURE.md`](../architecture/ADDON_ARCHITECTURE.md) § Assign UI; changelog: [`CONTENT_PROJECT_ASSIGN_UI_2026_08.md`](../architecture/CONTENT_PROJECT_ASSIGN_UI_2026_08.md).

SEO panel mounts **once**: `@livewire('assign-to-content-project-drawer')` (`SeoPanelProvider` — consume only; Blade SoT stays in `content`).

| Layer | Owner | Path |
|-------|--------|------|
| Contract + ActionFactory | content-projects | `content-projects/src/Support/AssignToContentProject/` |
| Drawer class + Blade + trigger | content | `AssignToContentProjectDrawer`, `assign-to-content-project-drawer.blade.php`, `x-content::assign-to-content-project-trigger` |
| React opener | content | `openAssignToContentProject` in `assignToContentProject.js` (JS contract mirror: `assignToContentProjectContract.js`) |

**Open contract:** `assign-content-project:open` + `AssignToContentProjectContract::normalizePayload()`. Alpine opens the shell immediately (`shellOpen`, skeleton, `body.assign-drawer-open`, `z-[10050]`, `inset-y-0 right-0`); Livewire `prepare()` hydrates after. Overlapping prepare calls drop stale completions (`prepareRequestId`). Extra trigger clicks merge into **one** Alpine `x-on:click` (duplicate attributes drop `dispatchEvent`).

Other events: `assign-content-project:success` / `:close` / `:shell-open` / `:shell-close`. Keyword detail listens to shell-open/close so its own drawer does not fight z-index; it must **not** `mountAction('assignToContentProject')`.

**Modes → domain backends** (ONE UI ≠ ONE service; `source` is context/refresh only):

| Mode | Backend |
|------|---------|
| `article` | `ArticleResource::assignArticlesFromFormData` |
| `keyword` | `KeywordResource::executeAssignKeywordsToContentProjects` |
| `pending_link` | `ArticlePendingInternalLinkService::assignFromEditor` |
| `vocabulary_items` | `KeywordProjectAssignmentService::assignPhrases` (`TYPE_CREATE` only; no auto-generate) |

Agent/MCP `content_project.add_items` is a **separate** product surface — not this UI.

**Callers (`source`):**

| Surface | Opener | `source` |
|---------|--------|----------|
| Article list row / bulk | ActionFactory | `article_table` |
| Article Editor overflow | Blade trigger | `article_editor` |
| SEO Audit row | Blade trigger | `seo_audit` |
| Keyword list row / bulk | ActionFactory | `keyword_table` |
| Keyword detail panel | `window` `assign-content-project:open` | `keyword_detail` |
| Keyword link-map / dictionary | ActionFactory page actions | `keyword_detail_link_map` / `keyword_dictionary_drawer` |
| Link edit bubble | React `openAssignToContentProject` | `link_edit_bubble` (`pending_link`) |

**Exception (intentional):** Article Editor **Vocabulary** sidebar assigns inline via `EditArticle::assignVocabularyItemsToContentProject` + project `<select>` (`wp-article-vocabulary-project-select`). It must **not** open the canonical drawer. `MODE_VOCABULARY_ITEMS` remains on the contract for other/future callers.

Laravel-only articles (`wp_post_id` null) remain assignable — assignment must not require WordPress. Article already in an active project: list/editor assign trigger hidden (`articleIsInContentProject`). Keyword mode hydrates project options only for currently selected sites.

### SEO Audit Draft / New Content Planner (AI suggestions)

Page: `ContentProjectSeoAuditPlanner` — SEO Audit + **Create new content with AI** (New Content Planner). Hook: `keyword.discovery.structured@0.1.0` (see `PROMPTS_AND_AI.md`). Domain persist: `NewContentSuggestionPlannerService` → Draft CREATE `SeoProjectTask` only (no Keyword Intelligence upsert, no article/product create at planning stage).

**Structured output gate (importable JSON only):**

1. Planning brief (`ContentPlanningIntelligenceService::renderBrief`) ends with **OUTPUT CONTRACT — STRICT** (JSON array root `[`…`]`; Post vs Product item shape).
2. After provider response: `NewContentSuggestionStructuredResult::decode` (trim → optional one ```json fence → first char must be `[`/`{` → `json_decode`). **No** prose scrape for the first `[...]`.
3. Importer SSOT: `NewContentSuggestionParser` (array or envelope `items|keywords|suggestions|results|data`).
4. On invalid/incomplete: **at most one** format repair retry (`repairBrief`) — no new SEO research. Still invalid → fail run (`structured_output_*`); do not import.
5. Truncated / incomplete JSON must not import. Restart queue workers after PHP changes so jobs load new code.

**One-click Keyword Discovery recovery (2026-09-03):** `NewContentAutoContinuationPolicy` bounds automatic continuation slices (max 12), no-progress escalations, soft job time budget (~700s), and provider-transient retries (backoff). Levels escalate refresh → coverage slice → oversample → reduce batch → fresh batch. `consecutive_no_progress` is a recovery signal, not a terminal stop. Cross-batch continuation: `NewContentCrossBatchContinuationPolicy`. Batch sizing: `NewContentGenerationBatchPolicy` + `NewContentPlanningSlotSplitter`. DNA slot fill policy: `NewContentAutoDnaPolicy` (injected at runtime; never persisted into `SeoPrompt`).

**Draft table UI (compat Blade `content-project-draft-items`):**

- Read model: `ContentProjectDraftPlanningItemsReadModel` — `description` = global brief; `product_description` = Product gallery text when `post_type=product`.
- Post type column: plain text by default; **double-click** enters compact select (`article`/`product`) for CREATE-editable rows (`can_edit_post_type`); save via `updateDraftPlanningItem` → `UpdateContentProjectItemCommand`. Escape / blur without change exits edit. Non-editable rows stay plain text.
- **Domain column (2026-09-01):** shared CREATE/Rewrite table shows site/domain per row; double-click inline edit via `DraftItemDomainRepairService` + `startDomainEdit` (Alpine `rootEl()` scoping — no bare `$root.querySelector`).
- **Clone idea:** duplicate CREATE planning row via `CloneDraftCreateIdeaService` (actions column).
- Product rows: show **Mô tả sản phẩm:** under global description when `product_description` non-empty; hide when Post (stored gallery/`loai_san_pham` not wiped on Product→Post).
- After AI run completes: `cp-ops-refresh` + `draftPlanningRefreshNonce` remounts Draft payload — **no** `location.reload`.

### Idea Candidates (Vocabulary Suggest → Draft)

Planner / SEO Audit / View project (`InteractsWithIdeaCandidates`): user **picks ideas first** (no AI in this step).

- Source catalog: `IdeaCandidateSourceCatalog` — currently **`vocabulary_suggest` only** (hook-ready for later sources). **Not** GSC MCP / Social Top 10.
- Read: `IdeaCandidateQueryService` → `VocabularySuggestStagingQuery::forSite` (`TYPE_SUGGEST` + `ai_generated` staging rows). No separate Ideas table.
- Actions Create / Rewrite / Improve → Draft via `AddIdeaCandidatesCommand` → `IdeaCandidateDraftPlannerService`.
- Dismiss: `DismissVocabularySuggestCandidateService` (does not touch GSC MCP).

### SEO Audit Notes (cluster → topic → DNA)

Planning notes on SEO Audit / Planner (`InteractsWithAuditNotes`):

1. Suggestions = Cluster SSOT (`KeywordClusterQuery` + `SiteMcpClusterTopicalProfileBuilder`); sort **MCP share ASC** (then name). List is lightweight (DNA counts only). Filters: `all` \| `mcp_low` \| `<5%` \| `has_focus` \| `no_focus`. Page size 25.
2. Select cluster → hydrate DNA (limit 30) into note snapshot (`cluster_ref`, name/share snapshots, `dna[]`). DNA edits are **planning override only** — never mutate Cluster DNA SSOT. Sources: `cluster` \| `manual`. Cap 50 DNA/note. DNA rows carry `placement` (`before` \| `after`) aligned with Keywords SSOT (`DnaPlacement`).
3. Prompt section: `AuditNotePromptSectionBuilder` orders DNA by weight DESC for semantic priority; MCP share is suggestion priority only.
4. Manual topics allowed via `manual:{normalized}` — does not invent KI clusters.
5. **Target allocation (2026-09-03):** `AuditNoteTargetAllocator` distributes `target_dna_count` across selected Topics — MANUAL topics keep their target; AUTO topics share remaining quantity by relative MCP share (largest-remainder). Specified DNA slots floor every target. System planning logic — not Prompt Management content.

### Planner plan clone (cross-domain config)

`PlannerPlanCloneService` (+ UI `InteractsWithPlannerPlanClone`): clone **planner configuration** (note items / content type / mode) from a source site to destination site IDs. **Never** clones generated Draft content. Exact topic match via `AuditNotePlannerExactTopicMatcher` / `PlannerExactTopicMatcher`. Allowlist: `PlannerPlanCloneAllowlist`. Result DTO: `PlannerPlanCloneResult`.

### Draft Reviewed → Create Execution Project (split)

Canonical path: UI `InteractsWithDraftSplit` → CommandBus `content_project.split_draft` → `SplitDraftContentProjectService`.

```text
Draft (planning) + Reviewed items
  → Manager picks writers (real USER ids)
  → fair-allocate items across writers
  → pack each writer into reusable max-30 Execution Projects (current month)
  → MOVE same task rows (preserve id / article_id / item site_id / origins)
```

**Invariants:**

- Only `isDraftPlanning()` Drafts. Blocked while a planner materialization run is queued/running.
- Eligible items = `planning_reviewed_at IS NOT NULL` (column missing → fail-closed empty). Unreviewed never split.
- Target month = **current calendar month only** (`Carbon::now()->startOfMonth()`). Not multi-month pack; not month metadata on a single project.
- Creates/reuses **real** `SeoProject` rows (`status=pending`, `kind=monthly`, `user_id=writer`, `site_id=null`, `source_draft_project_id`). Domain/site is **not** a packing key — item-level site stays on the task.
- Packing: fill free slots on reusable writer+month EPs first, then new chunks of ≤30 (`ContentProjectExecutionLimits::MAX_EXECUTION_PROJECT_ITEMS`). Reusable = not draft/archived; not `running|completed|paused`; never started execution. One writer+month may get **multiple** EPs when over 30 items.
- Naming: `project n/Y`, then `-2`, `-3`… scoped per writer+month.
- Every touched EP has a **real writer** `user_id` — never `SeoOpsSystemUser`, actor, or Draft owner. System user is FK placeholder only; CP gates treat it as unassigned.
- Does **not** call AI / auto-generate. Selection modes: `first_n` \| `all` \| `selected` (API/refs).
- Ops repair: `seo:repair-execution-project-naming`, `seo:repair-execution-project-packing` (`--month=`, `--user=`, `--dry-run`).

### Projects list buckets + loading UX

- High-level filter: `ContentProjectListBucket` — `all` \| `draft` \| `project` \| `archived` (legacy raw status query params normalize → `project`). Draft never requires execution month; archived/project may filter by month.
- Projects **list** has **no** bulk select / bulk delete (`bulkActions([])`); row actions only. (Item-level bulk on View/ops is separate.)
- List/ops/archive/draft/planner/queue tables use compat `list-table-loading-shell` (Article-style dim + spinner; keep table visible; targeted `wire:target`s — not hide-table skeletons).

### Generate

`GenerateProjectItemsCommand` → `GenerateProjectItemsHandler`:

1. Tenant + reject archived project.
2. Pipeline validate; resolve item set (explicit refs or pending via classifier; fail-closed full-project unless technical confirm).
3. Re-evaluate eligibility server-side (`classifier->preview` + improve-manual-only filter). Skip ineligible items; do not require uniform project state.
4. Project-level **Generate working items** is blocked only by a *live* multi-item `MODE_FULL` run (stale/expired runs do not count). One item `Processing` does not disable the bulk action.
5. **Test run** is gated independently (`MODE_TEST` live run only) — not by unrelated item state or Publishing Queue.
6. `ActionGuard::assertCan(Generate)`.
7. Under `BusinessLock::projectGenerate`: `startRun` + `prepareRunQueue` (both required).
8. Outside lock: `ContentProjectRunEngine::start($run)` (idempotent kick). Web returns immediately.

**Generate working items:** bulk over *currently eligible* working-set items (mixed Generated / Pending / Needs Review / Publishing Queue / Published is allowed). Rewrite/improve `article_id` is the source article — it does not mean “already generated”. Publishing Queue items are out of the working set and are not selected.

**Test run:** independently gated; not blocked by unrelated item processing or mixed project state.

**Operator skip generation (not `skip_publish`):** durable columns on `seo_project_tasks` — `generation_blocked_at` / `generation_blocked_by` / `generation_block_reason`. Canonical scope `SeoProjectTask::eligibleForGeneration()` (+ `isGenerationBlocked()`). Classifier preview uses the scope; `classifySnapshot` skips with reason `generation_blocked`; rerun/resume/Generate fail closed with message `Item đã được đánh dấu bỏ qua tạo bài.` Commands: `BlockProjectItemGenerationCommand` / `UnblockProjectItemGenerationCommand` (planner/manager via `canAccessContentProjectRun`). UI: **Bỏ qua tạo bài** / **Cho phép tạo lại** + badge Skipped. Does not delete article/content.

Archived project note: Article Editor FAQ/CTA body mutations blocked via session `assertArticleEditable` — see [`ARTICLE_EDITOR_WIDGETS_OWNERSHIP.md`](../architecture/ARTICLE_EDITOR_WIDGETS_OWNERSHIP.md).

### Rerun / Resume

| Command | Handler | Guard |
|---------|---------|-------|
| `ResumeProjectItemFromFailedStepCommand` | `ResumeProjectItemFromFailedStepHandler` | fail-closed resolve via `ContentProjectFailedStepResumeResolver` then `validateStep()` |
| `RerunProjectItemStepCommand` | `RerunProjectItemStepHandler` | `validateStep()` |
| `RerunProjectItemsCommand` | `RerunProjectItemsHandler` | `validateFull()` — **explicit full rerun from start** |

**Default Failed-row Retry = resume, not full rerun.** Primary UI action «Tiếp tục từ bước lỗi» and Agent/MCP `content_project.resume_failed_step` resolve the first retryable failed step from the **latest run-item attempt**, set `rerun_from_step`, reuse valid upstream artifacts (`ArtifactReusePolicy`), invalidate failed + downstream. Fail closed when `failed_step_key` cannot be resolved. Empty `item_refs` never expands to project-wide. Menu «Chạy lại từ đầu» is the separate full-rerun action (cost warning).

### «Chạy lại với từ khóa» (fresh keyword restart)

Canonical: `ContentProjectFreshKeywordRestart` (`generation_mode = fresh_keyword_restart`) + `ContentProjectFreshKeywordWorkspaceResetService`.

- Isolated generation input: stamps `generation_keyword_override`; does **not** inherit previous generation / existing outline.
- UI label: `item_action_restart_with_keyword` → «Chạy lại với từ khóa».
- Distinct from Failed-row resume and full «Chạy lại từ đầu».

**Per-item generation keyword override (inline table edit):**

- Original `seo_project_tasks.keyword` is **never** overwritten by override or bulk regen.
- Optional column `generation_keyword_override` — canonical resolver `ContentProjectGenerationKeyword::effective()`.
- Inline double-click on Keywords column → save via `SetItemGenerationKeywordOverrideCommand` (no workspace reset).
- Display: original (muted/strike) → effective override + badge «Đã đổi»; dirty badge when last successful run input ≠ effective keyword.
- **Generate working items** includes dirty generated items; routes them through canonical `RestartGenerationWithKeywordCommand` (not normal first-generate). Never-generated items with override use normal generate with effective keyword.
- After successful fresh-keyword run, override persists; `commitCanonicalKeyword` updates override + article `seo_focus_keyword`, not project keyword catalog.
- Migration: `2026_08_22_100000_add_generation_keyword_override_to_seo_project_tasks.php` on `omi_seo_ai`.

**Improve lifecycle:** `improve` tasks are **manual-only** by default for Publishing Queue handoff — see [`PUBLISHING.md`](PUBLISHING.md) (`PublishingQueueHandoffEligibility`). No generation-completion prerequisite; requires unpublished changes and must not be already queued/scheduled.

**Dismiss stale Failed overlay (no AI):** when content/lifecycle already OK (e.g. Published) but Generation still shows Failed from a soft domain-write error, UI «Bỏ qua lỗi (giữ nội dung)» / `content_project.acknowledge_generation_error` marks latest failed run-item `success`, clears `error_message`, and may flip sticky task `failed|writing|processing` → `completed` if `article_id` present. Does **not** regenerate. Prefer this CTA over resume when lifecycle ∈ published/approved/review/waiting_publish and article exists.

Require explicit `item_refs` (fail-closed — empty selection never expands to all pending). Stale recovery first. Eligibility **before** any run/queue mutation. Same lock → seed → `RunEngine::start` shape as Generate. Step carries `rerun_from_step` / downstream / optional `source_article_id`.

**Typed workflow artifacts:** Successful prompt nodes emit typed artifacts (`article_outline`, `article_content`, `product_gallery`, `product_review`) with identity (`project_task_id`, `run_id`/`run_item_id`/`attempt`, `workflow_node_id`, `producer_hook_key`, fingerprint, status). Domain article write consumes **only** `article_content` from the declared dependency node — never `article_outline`, never latest `PromptResult` / `lastPromptOutput` fallback. Content fail → persist action `blocked` (body unchanged, Generation stays Failed).

**Upstream reuse:** `Services/Workflow/ArtifactReusePolicy` — same task, compatible graph version, matching generation input fingerprint, succeeded + not invalidated. Keyword/title/description/post_type changes invalidate outline; publishing schedule alone does not.

**Run settings isolation:** `ContentProjectRunSettings::snapshotForRun()` persists operational keys (`task_ids`, `rerun`, `rerun_*`) on `seo_project_runs.settings`. `prepareRunQueue()` reads those keys — must not fall back to project-wide pending when selection was explicit. On accept, `prepareOperation` marks the task `pending` and clears the latest run-item error so Failed-only filters/cards drop the row immediately.

**Outline → article:** outline parse fail stops writing; full graph marks content steps `skipped` (`Không chạy vì bước Dàn ý thất bại.`). Content fail blocks article persist (`Không ghi nội dung vì bước Viết bài chưa tạo được article_content artifact hợp lệ.`). Parser pre-normalizes BOM / outer fence / short prologue before `TEXT_OUTSIDE_DECLARED_SECTIONS`. Outline markers never persist into `articles.body`.

**Generation vs lifecycle:** Generation status reads the **latest run-item attempt** (independent from article lifecycle / published). Lifecycle may stay `Published` while Generation = `Failed`. Generation = Generated only when mandatory generation graph succeeded **and** `article_content` was domain-persisted. Run/item cannot be Completed when mandatory Write article failed. Optional product-only nodes on Article = `SKIPPED_NOT_APPLICABLE` (do not fail Article generation).

**Ops filters (latest attempt):** Failed-only matches current generation failed/stale only — excludes latest run-item `pending`/`processing` and genuinely running rows. Summary cards use the same display generation status as the table.

**Roles (Content Project only — no new roles):**

| Role | Capability |
|------|------------|
| **planner** | Canonical business actor. Full CP workflow via `SeoAccessControl::canManageContentProjectWorkflow()`. |
| **manager** | Planner-equivalent capabilities **inside Content Project only** (same `canManageContentProjectWorkflow`). Not the canonical actor; does not imply Prompt / user / system settings rights. |
| **content_manager** | Edit assigned AI output in Article Editor; canonical Save stamps reporting In Review only. No generate / rerun / approve / schedule / publish / archive bulk. |

**Workflow:**

1. Planner/Manager: **Draft** (never generated) → Generate → **Pending** (AI queued/running) → **Needs Review** (reporting)
2. Content Manager: Needs Review → Open → Edit → **canonical Save** → **stop** (no Approve / Schedule / Publish / Rerun)
3. Planner/Manager may **Schedule** from Needs Review **or** In Review **or** Approved (Approved optional marker, not hard gate)
4. Optional: Planner Approve → Approved → Schedule — also valid (Approved **not** an active summary card)

**Normal (UI label, ex-"Draft" card):** whole Content Project **working set** — every item with `publishing_queued_at IS NULL`, regardless of generation/review/lifecycle state (never-generated, Pending, Generated, Needs Review, In Review, Failed all count). **Not** WordPress draft, **not** a lifecycle state, **not** limited to never-generated items. Clicking the Normal card clears the workflow filter (shows the full working set). Backed by `stats['normal']` / `stats['working_set']` in `ContentProjectItemOperationsReadModel`; `'draft'` kept only as a legacy filter/query alias with the same match-all semantics (`ContentProjectOpsStateClassifier::matchesSummaryFilter`).

**Draft (internal ops bucket, distinct from the Normal card):** never generated AI, no active execution, no canonical generation result — used by the Generate-eligibility classifier only, not shown as its own KPI card. Definition: `ContentProjectDraftOpsDefinition`.

**Pending (ops):** AI queued or running — between Draft and Needs Review. Definition: `ContentProjectPendingOpsDefinition`.

**Needs Review (reporting / presentation only):** AI finished AND Content Manager has **not** stamped canonical Save (`content_manager_reviewed_at` null). Unread for viewer still applies. Filter key `recently_completed`. **Not** lifecycle. **Not** Schedule gate.

**In Review (reporting / presentation only):** Content Manager stamped `content_manager_reviewed_at` (+ `content_manager_reviewed_by`) once via `ContentProjectContentManagerHandoffService`. Filter key `in_review_reporting`. Legacy residue `pending_review` / task `reviewing` still counts. **Not** lifecycle handoff. **Not** Schedule/Approve gate. Does **not** call `SubmitReview` / change generation status.

**KPI / summary cards (SSOT):** Normal → Pending → Needs Review → In Review → Failed. **No Scheduled/Published/Approved cards** — those belong to **Publishing Queue**. Title badge shows project **total_items** (working set + Publishing Queue); a muted subtitle breaks it down as ":working in workspace · :queue in Publishing Queue". Handoff: Planner/Manager **Send to Publishing Queue** (`publishing_queued_at`) → item leaves CP working set as Unscheduled (no WP, no auto schedule).

**Module boundary:** Content Project = content production only (workspace = the Normal/working-set items). Publishing Queue = schedule + WordPress publication, owned by **Publishing Queue Hub** (`PublishingQueueHub`, slug `publishing-queue`, nested under Content Projects nav; optional `?projectId=`). Legacy nested `content-projects/{id}/publishing-queue` remains a compat redirect. `publishing_queued_at` ownership unchanged.

**Shared ops UI (CP ↔ PQ):** both pages reuse `content-project-ops-styles`, `content-project-summary-cards`, `content-project-filter-toolbar` (`variant`), `content-project-bulk-selection-toolbar` (`variant`), `content-project-items-list` (`variant`), thumbnail/meta/status-badge. CP actions: `content-project-item-actions-menu` + `ContentProjectItemActionsPresenter`. PQ actions: `publishing-queue-item-actions-menu` + `PublishingQueueItemActionsPresenter` (includes **View on WordPress** when publish state = published and stored `wp_permalink` is a valid URL). Edit-article anchors use real `href` + `target="_blank"` / `rel="noopener noreferrer"` (claim Needs Review is side-effect only — no `preventDefault` navigation).

**Ops table realtime refresh (generation):** Client-only optimistic row state (`processingRows` + `cp-ops-row-processing`) gives instant **Running** feedback on row actions / bulk generate — **not** authoritative. After bulk **Generate working items** succeeds, `ViewSeoProject::dispatchGenerate()` invalidates ops cache and dispatches `cp-ops-generation-started` with affected task IDs. Alpine `startGenerationTablePoll()` polls `doLazyRefresh(true)` → `manualRefreshOps()` (full table remorph) on an interval until summary `running` returns to 0; toolbar shows `ops_lazy_refreshing` («Checking updates…» / «Đang kiểm tra cập nhật…») while `lazyBusy`. On failure / zero eligible items, `cp-ops-generation-failed` clears optimistic processing. Modal «Chạy lại với từ khóa» uses the same refresh pattern on terminal completion (`waitRestartKeywordTerminal` → `doLazyRefresh(true)`). Summary-only lazy fetch (`fetchOpsSummaryOnly` + `skipRender`) updates KPI cards only — never remorphs the item table.

**Keyword column (inline edit):** component `content-project-keyword-cell` — double-click Keywords cell; save/revert via `SetItemGenerationKeywordOverrideCommand`. Display partial shows original → override + dirty badge when generation input changed.

**Counters (reporting):** CM Save `needs_review−1/review+1`; enqueue Draft→Pending `draft−1/pending+1`; approve from Needs Review `needs_review−1/approved+1`; approve In Review `review−1/approved+1`; self-edit after viewed `approved+1`; schedule from Approved `approved−1/scheduled+1`; schedule from Needs Review `needs_review−1/scheduled+1`; schedule from In Review `review−1/scheduled+1`.

**Schedule:** allowed from Review lifecycle (Needs Review or In Review reporting) **or** Approved / WaitingPublish. Does **not** require In Review or Approved. Blocks only: capability, project/item validity, AI/queue busy, invalid datetime, enqueue failure. Never Schedule CTA on Published (republish separate). False-Published recovery bypasses Schedule guard (debug flag + residue).

### Presentation Layer States

| Kind | Examples | Notes |
|------|----------|-------|
| Generation | Pending, Running, Generated, Failed | AI run only — never Needs Review / In Review |
| Workflow / publishing | Scheduled, Published, Failed | Column Workflow; Draft/Pending empty (—); Approved not shown as active workflow badge |
| Reporting | Needs Review, In Review (Reviewed by Content Manager) | Workload only — auto-hide after Approve/Schedule/Publish; not Schedule gate |

Needs Review definition:

AI completed + unread + `content_manager_reviewed_at` IS NULL (and not legacy pending_review/reviewing)

In Review definition:

`content_manager_reviewed_at` IS NOT NULL (or legacy pending_review/reviewing) AND not Approved/Scheduled/Published

Content Manager:
Needs Review → Edit → canonical Save → reporting In Review stamp → stop (`ContentProjectContentManagerHandoffService`).

Ops presentation for Content Manager (`SeoAccessControl::usesContentManagerOpsPresentation`): Total badge beside title; KPI cards Normal / Needs Review / In Review only; workflow filter All+Normal+reporting; no Generate/Queue/Retry/Approve/Schedule/debug.

**Debug lifecycle override** (dev/recovery only):

- Flag: `CONTENT_PROJECT_DEBUG_LIFECYCLE_OVERRIDE` → `config('seo-content-ai.content_project.debug_lifecycle_override')` (default `false`).
- Capability: `SeoAccessControl::canDebugContentProjectLifecycle()` (Planner-equivalent + flag).
- Command: `content_project.debug_override_lifecycle` / `DebugOverrideProjectItemLifecycleCommand`.
- Allowed: Approved ↔ Scheduled ↔ Published. No WordPress API, no publisher dispatch.
- Published → Scheduled clears fake `publish_published_at`, sets Waiting queue + future `scheduled_publish_at` (required if missing). Keeps `articles.wp_post_id`.
- → Published debug stamps `last_publish_error = DEBUG_LIFECYCLE_OVERRIDE:not_wordpress_publish` — not real publisher success.
- Audit via `ContentProjectBusinessAuditor` metadata (`reason=debug_recovery`).

Do not call it AI Inbox / Inbox / Mailbox / Notification Queue. Do not use “staff” for this workflow — use content_manager.

**SSOT ops Summary/List:** `ContentProjectOpsStateClassifier` + per-state Definitions (`Draft` / `Pending` / `Published` / `Scheduled` / `Approved` internal / `NeedsReview`=`RecentlyCompleted` / `InReviewReporting` / `Failed`). Ops card counts = `countSummary(mapped rows)` — same predicates as list filters (`workflowFilter` / failure quick filter). Published ignores `articles.status`. Reporting chips auto-hide after Approve/Schedule/Publish. Table columns: Generation | Workflow (not Lifecycle) | reporting chip under Item. Approved removed from active UI cards/filters.


### Review / approve

- Content Manager canonical Save → `ContentProjectContentManagerHandoffService` stamps `content_manager_reviewed_at` / `content_manager_reviewed_by` once (reporting In Review). **No** `SubmitReview`, **no** task `reviewing`, **no** lifecycle transition.
- `StartReviewCommand` — task status → reviewing (completed/pending only); does **not** write `review_status` (legacy/manual path).
- `ApproveProjectItemsCommand` → `ArticleReviewService::ensureApproved()` — SoT `review_status = approved` (from draft or pending_review). Planner + manager via `canApproveArticleReview`. Optional before Schedule.
- Article submit/approve/archive/reopen owned only by `ArticleReviewService::performAction()`.

### Archive

| Concept | Owner | Command |
|---------|-------|---------|
| Review archive | `ArticleReviewService` | Filament review action |
| Item content archive | `ArchiveProjectItemsHandler` | `ArchiveProjectItemsCommand` |
| Project Destroy AI Workspace | `ArchiveContentProjectHandler` | `ArchiveContentProjectCommand` |

No item-level restore (`ContentProjectItemAction::Restore` removed). Project restore: `RestoreContentProjectCommand` (`workspace_reused = false`). Archive revokes active Article Editor sessions for project articles, snapshots old articles for reports/history, then resets active project tasks to a fresh pending flow (`article_id = null`, publish/review handoff cleared). Restore does not restore old sessions or reuse the old workspace; the next Generate creates new articles/workspace.

**Archive Content Project ≠ archive Article.** Archiving ends project execution/workspace lifecycle only. Articles return to normal standalone Article behavior (editor open/save/Sync WP). Historical associations (`seo_project_archive_items.article_id`, snapshots, preview stats) remain for reports. Active-project ownership gates (`ContentProjectArticleMembership::belongsToContentProject` / `assignedTaskForArticle`, `ArticleResource::articleIsInContentProject`) apply only while the project is not archived (`archived_at` null). Leftover `seo_project_tasks.article_id` on an archived project must not block the editor.

**Active CP article ↔ Sync WP:** while membership is active, Article Editor **hides all manual Sync WP chrome** (toolbar / overflow / page actions) — UI-only; first WordPress create stays on Publishing Queue. After archive, standalone Sync WP is allowed again. See [`ARTICLE_EDITOR.md`](ARTICLE_EDITOR.md) + [`PUBLISHING.md`](PUBLISHING.md).

**Archive social reporting (2026-09-01):** Preview (`ContentProjectArchivePreview` / `ArchivePreviewArticlePresenter`) and Excel exports show **social link counts** from `ArticleSocialLinkService` (`seo_article_social_links`). Monthly workbook `ContentProjectArchivedMonthExportService` emits parent article rows plus **social evidence child rows** (`ContentProjectArchiveSocialExportRowExpander`). Reporting only — not share-action buttons (those remain on GSC MCP drawer).

### Publish writes

See [PUBLISHING.md](PUBLISHING.md). All schedule/publish/retry/skip/cancel via CommandBus handlers → `ContentProjectPublishingQueueService` + transition guard.

## 7. Public capabilities

Public `content_project.*` commands (Capability Registry + Factory arm + CommandBus):

| Capability | Command | Confirm |
|------------|---------|---------|
| `create` / `update` | Create/UpdateContentProject | No |
| `add_items` / `update_item` | Add/Update item | No |
| `generate` | GenerateProjectItems | No (pending safety confirm when needed) |
| `rerun` | RerunProjectItems | No |
| `resume_failed_step` | ResumeProjectItemFromFailedStep | No |
| `acknowledge_generation_error` | AcknowledgeProjectItemGenerationError | No — soft-clear Failed overlay |
| step rerun (Agent app path; no dedicated MCP tool) | RerunProjectItemStep | No |
| `start_review` / `approve` | StartReview / Approve | No |
| `schedule` / `auto_schedule` / `unschedule` / `move_schedule` | Schedule* | schedule dry-run preview |
| `publish_now` / `retry_publish` / `skip_publish` / `cancel_publish` | Publish* | publish_now / skip / cancel: Yes |
| `archive` / `restore` (project) | Archive/RestoreContentProject | Yes |
| `archive_items` | ArchiveProjectItems | Yes |
| `stop_execution` / `resume_execution` | Stop/ResumeProjectExecution | stop: Yes (**Agent only**, not MCP) |

MCP write surface ⊂ Agent writes. Automation workflow map may **label** generate/rerun nodes — no Automation Action dispatches CP commands today.

Result contract: `ContentProjectActionResult` + `ContentProjectActionCodes` — branch on `code`, not `message`.

## 8. Internal-only capabilities

| Command / path | Notes |
|----------------|-------|
| `SyncContentProjectItemsCommand` | Edit/Create sync; `isAgentWriteExposed=false`; not MCP |
| `ProcessScheduledProjectItemPublishCommand` | Scheduler/queue only; not a capability |
| Demoted KI write caps | On bus but not Agent/MCP advertised |
| Run Engine recovery CLI | `ContentProjectRunRecoverCommand` / status — ops tooling |
| Stale generation recover | `seo:content-project:recover-stale-generation --apply` (schedule) |

## 9. Authorization and confirmation

- Tenant: `ContentProjectTenantGuard` / `SeoAccessControl` on Filament + API.
- Action gate: `ContentProjectItemActionGuard::assertCan()` in handlers (same class as read `availableActions`).
- Confirmation tokens bind tenant, actor, action, project_ref, item_refs, input hash, state fingerprint, `expires_at`. Codes: `confirmation.required|invalid|expired|stale`.
- Quota: `ContentProjectQuotaGuard` → `quota.denied`.
- Lock busy → `concurrency.lock_busy`.

## 10. Queue and scheduler ownership

See [QUEUE_SCHEDULER_AND_IDEMPOTENCY.md](../contracts/QUEUE_SCHEDULER_AND_IDEMPOTENCY.md).

Summary for CP:

- Generate/rerun queue ownership: `ContentProjectRunEngine` + `RunContentProjectArticleJob` (`ShouldBeUnique`, queue from `ContentProjectRunEngineFeature::queueName()`).
- Publish due sweep: single schedule `seo:publish-scheduled-articles` → `ScheduledArticlePublishRunner` → CP runner → CommandBus.
- Stale gen: `seo:content-project:recover-stale-generation --apply` every 10m (`withoutOverlapping`).

## 11. Transactions and side effects

- Generate/rerun seed under project generate lock; engine start **outside** lock (safe retry if `engine_started: false`).
- Domain event `ContentProjectGenerationRequested` after commit of seed.
- Business audit + operation log on every `dispatch()` (no AI prompt/output in business audit).
- Project archive snapshots old business articles for reports/history, deletes AI workspace / prompt history / execution / local media / SaaS revisions, and resets active tasks so the restored project behaves like a fresh flow. Old articles are no longer the active project workspace.
- Articles associated with archived Content Projects return to normal standalone Article behavior while historical project associations remain available for reporting/archive preview. Edit/save/Sync WP must not restore the project, recreate workspace, or resume workflow.
- Archived project preview stays read-only for workflow/content, except manual Index marker (`ContentProjectArchivePreview::markArticleIndexed` → `ArticleManualIndexMarkerService`) and title/copy using stored public WP permalink (`wp_permalink` / snapshot `wordpress_url`). Marker does not restore/unarchive or recreate workspace.
- Item archive keeps WP post; cleans workspace artifacts; blocked while generating or publish-queue active.

## 12. Retry and recovery

- Engine start failure after seed: retry `RunEngine::start` / another generate/rerun — do not orphan by rolling back queue rows.
- Article job: `tries = 1`, timeout 900s; uniqueness per run/item/attempt.
- Stale generation: recovery service + scheduled `--apply` command.
- **Default item Retry:** `ResumeProjectItemFromFailedStep` → first failed retryable step; preserve valid upstream artifacts; do not silently restart from Outline.
- **Full rerun:** `RerunProjectItems` only — explicit confirm/cost warning in UI.
- Step rerun: validate eligibility first; rejected items in `metadata.rejected` — no partial start.
- Agent/MCP use the same resume/step commands — no separate retry logic in Filament/Agent layers.
- Publish retry: `RetryProjectItemPublishingCommand` → queue status `retrying`/`waiting` per transition guard.
- **Manual artifact apply** (Article AI History) is **not** retry/rerun: does not create runs, clear failures, or change Generation status. Future Agent capabilities must call the same `ArticleAiHistoryApplicationService` / command DTOs if exposed — not Hook Engine.

## 13. Compatibility paths

- Redirect-only run history Filament pages.
- Publishing-queue URL → filtered ViewSeoProject.
- `SeoProjectWorkflowRunService` seed/consolidate — callers only handlers + engine.
- Workflow executors (`CreateArticlesFromTaskService`, prompt runners, step retry) invoked from article job — not public entry points.
- Class C `seo_projects.status` consumers (Keyword/Article create filters, restore stamp) — project-level only; do not extend for item lifecycle.
- `CONTENT_PROJECT_PHP_ENGINE` flag: engine vs legacy JS orchestration for runs (prefer PHP; do not add new JS orchestration).

## 14. Forbidden paths

1. Mutate `SeoProject` / `SeoProjectTask` / `SeoArticle` / `SeoProjectRun` business columns from controller/Filament/Agent without CommandBus.
2. Call `startRun` / `prepareRunQueue` / `RunEngine::start|resume|requestStop` outside handlers (or approved recovery CLI).
3. Re-derive item lifecycle / `available_actions` from raw columns outside resolver/guard.
4. Compare `seo_project_tasks.status` literals for lifecycle outside `ContentProjectTaskStatusNormalizer`.
5. Use or reintroduce `articles.is_reviewed`.
6. Conflate content archive with `review_status = archived`.
7. Gate item lifecycle on `seo_projects.status`.
8. Call handler `handle()` bypassing `dispatch()` (skips idempotency/audit/op-log).
9. Stamp `scheduled_publish_at` / `publish_queue_status` outside publish handlers / queue service.
10. Expose `sync_items` / `process_scheduled_publish` / stop-resume as MCP tools; expose `sync_items` as Agent write.
11. Item-level restore action.
12. Direct `ContentPublisher` / queue mutate from Filament callbacks.
13. Second cron for CP publish dispatcher.
14. Second assign drawer / centered assign modal / Filament Action `form()` for assign / new open-event name / caller-specific assign schema. Reuse Contract + ActionFactory + drawer.
15. Import New Content Planner AI prose / truncated JSON into Draft without `NewContentSuggestionStructuredResult` validation (or scrape the first `[...]` from commentary as the primary fix).
16. Wipe Product planning columns (`description` gallery / `loai_san_pham`) when correcting Draft `post_type` Product ↔ Post.

## 15. Tests and invariants

Primary contracts (remote `$PHP_BIN vendor/bin/phpunit --filter=...`):

| Test | Invariant |
|------|-----------|
| `ContentProjectItemStateContractTest` / `ContentProjectItemStateResolverTest` | Resolver SoT + precedence |
| `ContentProjectTaskStatusNormalizerTest` | Legacy status map |
| `ContentProjectApprovalSotTest` / `ArticleReviewServiceTest` | `review_status` SoT; no `is_reviewed` |
| `ContentProjectIsReviewedCutoverMigrationTest` | Column cutover |
| `ContentProjectRerunUnifyTest` / `ContentProjectBulkRerunPhase20Test` / `ContentProjectStepRerunPhase20Test` | Rerun CommandBus-only; deleted bulk/step services absent |
| `WorkflowArtifactOwnershipTest` / `ContentProjectFailedStepResumeTest` | Typed artifacts; no outline→body; resume ≠ full rerun |
| `AcknowledgeProjectItemGenerationErrorTest` | Soft-clear Failed overlay without AI; UI prefer-acknowledge CTA |
| `OutlineAsContentDetectorTest` | Outline-as-content diagnostic |
| `ContentProjectGenerateParityTest` / `ContentProjectGeneratePendingSafetyTest` | Generate path + fail-closed pending |
| `ContentProjectRunEnginePhase1Test` / `ContentProjectActiveExecutionLifecycleTest` | Engine ownership |
| `ContentProjectPublicCapabilityContractTest` | Caps + Factory + archive_items wiring |
| `ContentProjectCommandBusCutoverTest` | Bus entry cutover |
| `ContentProjectStaleGenerationRecoveryTest` | Recovery |
| `ArticleEditorArchivedContentProjectStandaloneTest` | Archived CP → standalone Article editor/sync; historical archive items kept |
| `AssignToContentProjectUiArchitectureGuardTest` | One drawer + one open event; modal alias; no legacy events; Vocabulary sidebar stays inline |
| `AssignToContentProjectDrawerRoutingTest` | Mode → submit routing; payload normalize; `prepareRequestId` |
| `ArticleEditorSyncWpVisibilityTest` | Active CP editor: no Sync WP chrome |
| `ArchitectureHardeningLockContractTest` | Related uniqueness contracts |
| `PublishScheduledArticlesCanonicalRunnerContractTest` | Single publish scheduler shell |
| `NewContentSuggestionStructuredResultTest` / `DraftPlanningPostTypeAndRefreshTest` / `NewContentProductPlanningBriefTest` | Planner JSON gate + Draft post_type dblclick UX + Product brief/persist |
| `NewContentAutoDnaPolicyTest` / `NewContentGenerationBatchingTest` / `NewContentOneClickAutoContinuationContractTest` / `NewContentQty50CompletionContractTest` | Auto DNA + batching + one-click continuation bounds |
| `PlannerPlanCloneContractTest` | Cross-domain planner config clone (no content clone) |
| `AuditNoteTargetAllocatorTest` / `AuditNoteManualDnaUxContractTest` / `DnaPlacementContractTest` | Target allocation + manual DNA UX + placement |
| `ContentProjectWriterCapacitySettingsTest` | Global/per-user writer monthly capacity settings |
| `DraftItemTableDomainAndCloneContractTest` | Shared draft table Domain column + clone idea + safe JS root |
| `ContentProjectArchiveSocialColumnTest` / `ContentProjectArchivedMonthSocialExportTest` | Archive preview/export social reporting via `ArticleSocialLinkService` |
| `ContentProjectArchivedMonthExportContractTest` | Month workbook shape + social child rows |
| `ContentProjectExportReviewedAtResolverTest` | Archive export reviewed_at resolution |

Freeze grep invariants: no production `ContentProjectBulkRerunService`, `ContentProjectStepRerunService`, `RerunArticlePipelineJob`, Filament direct `RunEngine::start`, `ContentProjectItemAction::Restore`.

## 16. Related documents

- [PUBLISHING.md](PUBLISHING.md) — publisher registry, schedule, WP vs Site Sync
- [QUEUE_SCHEDULER_AND_IDEMPOTENCY.md](../contracts/QUEUE_SCHEDULER_AND_IDEMPOTENCY.md)
- [AGENT_AND_MCP_CONTRACTS.md](../contracts/AGENT_AND_MCP_CONTRACTS.md) — Agent/MCP surface (owned elsewhere)
- [SITE_SYNC.md](SITE_SYNC.md) — catalog sync ≠ publish
- [ARTICLE_EDITOR.md](ARTICLE_EDITOR.md) — editor save vs CP publish; Sync WP hidden while in active CP
- [ARTICLE_EXECUTION_HISTORY.md](ARTICLE_EXECUTION_HISTORY.md) — per-article Workflow tab (canvas + AI Calls overlay)
- [SEO_AUDIT_AND_KEYWORDS.md](SEO_AUDIT_AND_KEYWORDS.md) — Audit / KI assign callers
- [ADDON_ARCHITECTURE.md](../architecture/ADDON_ARCHITECTURE.md) — Assign UI contract (CLOSED)
- [CONTENT_PROJECT_ASSIGN_UI_2026_08.md](../architecture/CONTENT_PROJECT_ASSIGN_UI_2026_08.md) — 2026-08 assign consolidation
- [ARTICLE_EDITOR_JSON_PERSISTENCE.md](../architecture/ARTICLE_EDITOR_JSON_PERSISTENCE.md) — CP body writers must invalidate/update `editor_document` (not HTML-only silent)
- Architecture freeze: `docs/architecture/ARCHITECTURE_FREEZE_V1.md` / `ARCHITECTURE_DECISIONS.md`
- Historical detail: `docs/archive/content-projects/*`

### Item state dimensions (quick ref)

| Dimension | Enum |
|-----------|------|
| `lifecycleState` | `ContentProjectLifecyclePhase`: draft, generating, review, approved, waiting_publish, published, failed, archived |
| `generationState` | `ContentProjectItemGenerationState` |
| `reviewState` | `ContentProjectItemReviewState` |
| `publishState` | `ContentProjectItemPublishState` |
| `executionState` | `ContentProjectItemExecutionState` |
| `archiveState` | `ContentProjectItemArchiveState` |
| `availableActions` | `ContentProjectItemAction` (no Restore) |

**Lifecycle precedence** (highest first): content archive → sticky published → queued/scheduled waiting_publish → publish failed → active generation → gen failed → approved → review → draft.

**UI badges:** `ContentProjectStatusBadgePresenter` labels via `seo-content-ai::filament.projects.badge_*` (vi/en). Generation Failed overlay SoT = latest run-item attempt (independent of lifecycle).

**Dashboard buckets:** `waiting_ai`, `ai_running`, `waiting_review`, `approved`, `waiting_publish`, `published`, `failed`, `archived`, `other`.

### Public CP CommandBus map (core)

```
CreateContentProject, UpdateContentProject, SyncContentProjectItems,
AddContentProjectItems, UpdateContentProjectItem, SplitDraftContentProject, AddIdeaCandidates,
GenerateProjectItems, RerunProjectItems, RerunProjectItemStep, ResumeProjectItemFromFailedStep,
AcknowledgeProjectItemGenerationError, BlockProjectItemGeneration, UnblockProjectItemGeneration,
StartReview, ApproveProjectItems,
ScheduleProjectItems, AutoScheduleProjectItems, UnscheduleProjectItems,
MoveProjectItemSchedule, PublishProjectItemsNow, ProcessScheduledProjectItemPublish,
Retry/Skip/Cancel ProjectItemPublishing,
StopProjectExecution, ResumeProjectExecution,
ArchiveContentProject, ArchiveProjectItems, RestoreContentProject
```

