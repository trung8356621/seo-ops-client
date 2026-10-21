# SEO-OPS Client — Golden Configuration Package

Canonical default configuration package for bootstrapping new SEO-OPS Clients.

---

## 1. Package Overview

This package encapsulates the canonical baseline configuration of the SEO-OPS Client. It serves as the distributable seed to initialize freshly installed Clients without carrying local development artifacts, credentials, or transient execution state.

### Directory Structure

```text
resources/golden-configuration/
│
├── manifest.json
│
├── payload/
│   ├── full-configuration.json
│   ├── settings.json
│   ├── prompts.json
│   ├── workflows.json
│   └── provider-templates.json
│
├── metadata/
│   ├── prompt-index.json
│   ├── workflow-index.json
│   └── checksums.json
│
└── README.md
```

---

## 2. Authoritative Payload & Split Inspection Artifacts

### `payload/full-configuration.json` (Authoritative Entrypoint)

- **Package Type**: `seo_configuration_bundle`
- **Schema Version**: `1.1`
- **Purpose**: Authoritative single-file bundle accepted directly by `SeoSettingsBundleService::apply()` / `parseAndPlan()` or `/admin/settings/configuration`.
- **Contents**:
  - `settings`: 8 distributable configuration groups (`general`, `date_time`, `workflows`, `ai`, `article_editor`, `keywords`, `seo_scoring`, `prompt_runtime`).
  - `prompts`: 30 portable prompt definitions with content, tools, routing, and variable bindings.
  - `tasks`: 3 portable workflow definitions (`Quy trình đăng bài viết`, `Review product`, `Image flow`).
  - `provider_templates`: 3 standard provider templates (`gemini`, `deepseek`, `openrouter`).
  - `_excluded`: System exclusion metadata notice.

### Split Inspection Artifacts (`payload/*.json`)

The four secondary files in `payload/` are inspection extractions derived strictly from the authoritative payload:
1. **`settings.json`**: Exact extraction of the 8 distributable settings groups.
2. **`prompts.json`**: Prompt pack containing the 30 canonical prompt definitions.
3. **`workflows.json`**: Workflow array containing the 3 serialized flow graphs.
4. **`provider-templates.json`**: Array of 3 built-in provider definitions.

> [!NOTE]
> The split payload files are for human inspection and auditing. They must not be modified independently of `full-configuration.json`.

---

## 3. Package Generation Process

The package was generated via deterministic serialization using existing system services:
- **Prompts**: Serialized via `Omnichannel\Addons\AiPrompt\Services\PromptPack\PromptPackService::serialize()`. Local primary keys are stripped; identity is guaranteed via `PromptPortableIdentity`.
- **Workflows**: Serialized via `Omnichannel\Addons\AiPrompt\Services\PromptPack\TaskPackService::serialize()`. Workflow nodes replace local prompt IDs (`promptId`, `prompt_id`) with `prompt_ref` (`portable_uuid` and `name`). Runtime test results and execution state are pruned.
- **Workflow Bindings**: Serialized via `Omnichannel\Addons\Seo\Services\SettingsTransfer\WorkflowBindingsSection`. All 14 prompt hook bindings map to canonical `portable_uuid` references.
- **AI Center Settings**: Sanitized via `AiCenterSettingsSection::sanitizeConnectionMetadata()`. All credentials (`api_key`, `token`, `secret`, `password`) are pruned.
- **Independent Settings**: Serialized via `ArrayOptionSection` across `general`, `date_time`, `article_editor`, `keywords`, `seo_scoring`, and `prompt_runtime`.
- **Provider Templates**: Sourced via `Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\AiProviderTemplateCatalog::builtins()`.

---

## 4. Dependencies & Verified Environment

- **Client Application**: `omnichannel/client` (Laravel Framework `12.50.0`, PHP `^8.2`, Filament `v3.3.47`).
- **Addons Monorepo**: `omnichannel/addons` (`0.1.0`).
- **Core Modules Required**:
  - `seo`
  - `ai-prompt`
  - `content`
  - `content-projects`
  - `search-intelligence`
  - `search-foundation`
  - `media`
  - `agent` / `agent-runtime`
  - `seeding`
  - `seo-content-ai-compat`

### Automation JSON Dependency

Automation rule templates remain developer-owned infrastructure bundled with the source code:
- Location: `omnichannel-addons/agent/src/Automation/BusinessHook/Templates/`
- Seeded via: `Omnichannel\Addons\Agent\Automation\BusinessHook\Seed\AutomationDefaultRulesSeeder`
- Bundled templates:
  - `article-complete-wordpress.json`
  - `article-seo-wordpress.json`
  - `content-project-failure-notify.json`
  - `scheduled-maintenance.json`
  - `webhook-pipeline.json`

The Golden Package does not duplicate or alter Automation JSON definitions.

---

## 5. Default Initialization Assumptions & Lifecycle

1. **Host Setup**: Fresh Laravel Client installed with `omnichannel-addons` linked under `addons/`.
2. **Migrations**: Database migrations executed (`php artisan migrate`).
3. **Core Seeding**: Technical system records initialized (`SeoOpsSystemUserSeeder::run()`).
4. **Administrator Account**: First administrator/owner created.
5. **Configuration Ingestion**: Authoritative package ingested via `SeoSettingsBundleService::apply()` (or UI at `/admin/settings/configuration`).
6. **Execution Order**:
   - Step 1: Provider templates registered.
   - Step 2: Connection skeletons created (status: inactive, without API keys).
   - Step 3: Prompts imported and assigned to destination administrator.
   - Step 4: Workflows imported; prompt references in flow nodes dynamically resolved to destination prompt IDs via `portable_uuid`.
   - Step 5: Workflow bindings resolved to destination prompt IDs via `portable_uuid`.
   - Step 6: AI Center routing preferences and model family allowances saved.
   - Step 7: Independent settings sections committed.
7. **Operator Step**: Administrator provides operational API credentials for desired providers (e.g. OpenRouter, Gemini, DeepSeek).

---

## 6. Fingerprint Generation Rules

Fingerprints in `metadata/prompt-index.json` and `metadata/workflow-index.json` provide deterministic content verification:

### Prompt Content Fingerprint

Computed as `SHA-256` over recursively key-sorted canonical JSON containing exactly:
- `name` (string)
- `description` (string)
- `content` (markdown string)
- `hook` (string hook key)
- `hook_version` (string)
- `tool` (string tool identifier)
- `is_flow_prompt` (boolean)
- `execution` (`mode`, `profile`, `family_key`)
- `variables` (array of variable definitions)
- `hook_settings` (dictionary of hook configuration)

*Excluded*: `portable_uuid`, local `id`, `user_id`, timestamps, and transient UI tags.

### Workflow Fingerprint

Computed as `SHA-256` over recursively key-sorted canonical JSON containing exactly:
- `name` (string)
- `description` (string|null)
- `output_type` (string: `text`|`image`|`video`)
- `flow_data`:
  - `nodes`: list of nodes (`id`, `type`, `title`, and clean `data` with `prompt_ref['portable_uuid']`).
  - `edges`: list of graph connections (`id`, `sourceNode`, `sourcePort`, `targetNode`, `targetPort`).

*Excluded*: `portable_uuid`, local `id`, `user_id`, `promptId`, `prompt_id`, test results (`task_test_results`, `test_results`), and `runtime_state`.

---

## 7. Excluded Data & Security Audit

The package has been verified to be completely free of:
- API keys, provider credentials, and access tokens (verified via `assertNoSecrets` and regex scanner).
- Passwords and database credentials.
- License keys and tenant tokens.
- User accounts and personal identification records.
- Articles, generated content, and draft posts.
- Execution histories, queue payloads, and operational logs.
- Test runs and transient cache state.

---

## 8. Summary of Included Configuration Groups

| Group | Key Count | Description |
|---|---|---|
| `general` | 6 settings | Skip words, chat extensions/limits, social domains, content language (`vi`), analytics scope. |
| `date_time` | 2 settings | Timezone (`Asia/Ho_Chi_Minh`), preset (`vi`). |
| `workflows` | 14 prompt bindings, 3 media sources | Hook bindings to canonical prompt UUIDs, media source preferences (`none`). |
| `ai` | 7 config nodes, 1 connection | Usage mode (`economy`), resilience attempts, routing targets, image model families, connection skeleton (`new-ops`). |
| `article_editor` | 4 settings | History step (20), autosave interval (2s), wiki trust domains, FAQ keyword regex patterns. |
| `keywords` | 1 setting | CTA keyword blacklist. |
| `seo_scoring` | 17 rules | Audit penalty points and weights for SEO content quality. |
| `prompt_runtime` | 12 settings | Tone text, tone of voice list, length defaults, keyword density guidelines, snippet row bounds. |

---

## 9. Inventory of Included Entities

- **Prompts**: 30 canonical portable prompts (14 bound to system hooks, 5 flow/workflow prompts, 11 capability prompts).
- **Workflows**: 3 portable tasks:
  1. `Quy trình đăng bài viết` (UUID: `3a68f26a-371a-4d11-83da-407501a658cc`)
  2. `Review product` (UUID: `0603f414-29e8-42af-8a9b-b61a4d0d1969`)
  3. `Image flow` (UUID: `2bf21373-f8c9-433a-a778-4d7c46703456`)
- **Workflow Prompt Bindings**: 14 active hook bindings.
- **Provider Templates**: 3 templates (`gemini`, `deepseek`, `openrouter`).

---

## 10. Validation Results

All 32 automated integrity checks passed:
1. `manifest.json` conforms to schema and checksum algorithm `sha256`.
2. `payload/full-configuration.json` adheres to Full Configuration Bundle schema `1.1`.
3. All split files in `payload/` strictly match the authoritative payload.
4. All 30 prompts and 3 workflows have unique portable UUIDs with zero collisions.
5. All prompt node references inside workflows resolve to packaged prompt UUIDs.
6. All 14 workflow settings bindings resolve to packaged prompt UUIDs.
7. Zero installation-local database IDs remain in portable node definitions.
8. Zero secrets or sensitive token patterns detected across all files.
9. All SHA-256 file hashes match `metadata/checksums.json`.

---

## 11. Known Limitations & Owner Decisions Requiring Approval

1. **Development Test Copies Pruned**:
   - Prompts #18, #19, #20 (`gallery PLANNER`, `gallery PARENT`, `gallery CHILD`) were excluded in favor of the canonical suite #15, #16, #17 (`Product Gallery Planner`, `Product Gallery Parent Image`, `Product Gallery Child Image`).
   - Prompt #24 (`Content Planning Assistant`) was excluded in favor of canonical prompt #25 (`Keyword Discovery`).
   - *Status*: INFERRED from source installers and binding references. Requires owner acknowledgment.
2. **Task Slots Unbound**:
   - In DEV `seo_create_article_task`, slots `publish_article_task_id`, `create_image_task_id`, `create_video_task_id`, `post_review_task_id`, `create_typography_image_task_id` were not bound. They remain unbound in the package to preserve exact configuration state.
   - *Status*: VERIFIED IN SOURCE.
3. **Fresh Install Verification**:
   - Package validity was verified by test against the configuration parser, JSON guard, secret scanner, and dependency graph. An isolated, end-to-end import onto a completely blank database was not performed (test database guard protects local dev DB).
   - *Status*: VERIFIED BY TEST (static & parser contract). End-to-end fresh installation test remains UNVERIFIED.
