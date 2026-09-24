# Localization Audit

Generated from current local source (omnichannel-client + omnichannel-addons).
Scanner: `tools/i18n-audit-scan.py`. Guardrail: `tests/Unit/I18n/TranslationParityTest.php`.
Scope: user-visible Filament/Livewire/Blade/JSX UI strings. Excludes vendor, node_modules,
client `addons/` junction duplex, docs, help-seed, tests, prompts-as-content, DB user data.

**This pass is AUDIT + guardrail only — no mass translation rewrite.**

## Architecture

### Locale resolver / switcher
- Package: `bezhansalleh/filament-language-switch` (^3.1)
- Configured in `app/Providers/AppServiceProvider.php` via `LanguageSwitch::configureUsing`
- Supported locales: **`vi`, `en`** (switcher labels: Tiếng Việt / English)
- No custom `SetLocale` middleware in app code — Filament Language Switch persists locale
  (session/cookie) and Laravel/`App::getLocale()` follows

### Defaults / fallback
- `config/app.php`: `locale` = `env('APP_LOCALE', 'en')`
- `fallback_locale` = `env('APP_FALLBACK_LOCALE', 'en')`
- **Implication:** missing VI keys fall back to EN (good). Missing EN keys that only exist as
  Vietnamese hardcoded literals never translate when locale=EN (bad — primary EN leakage).

### Translation locations (SSOT)

| Layer | Location | Namespace / style | Role |
|---|---|---|---|
| Client JSON | `lang/en.json`, `lang/vi.json` | English phrase as key (`__('Display name')`) | Thin admin shell labels |
| Client PHP | `lang/{en,vi}/*.php` | Dot keys (`client_control.*`, `seo.*`, …) | Control plane + SEO rules |
| SEO panel | `seo-content-ai-compat/lang/{en,vi}/filament.php` (+ `common.php`, `prompt_hooks.php`) | `seo-content-ai::filament.*` | **Primary product UI SSOT** (~3.6k–3.8k keys) |
| Seeding | `seeding/resources/lang/{en,vi}/filament.php` | `seeding::filament.*` | Seeding UI |
| Filament vendor | `vendor/filament/*/resources/lang` | `filament-panels::*` etc. | Framework chrome (EN/VI via Filament) |

Registered via:
- `SeoPanelProvider` / `AiPromptServiceProvider`: `loadTranslationsFrom(..., 'seo-content-ai')`
- `SeedingServiceProvider`: `loadTranslationsFrom(..., 'seeding')`

### Filament localization
- Admin panel (`AdminPanelProvider`): hardcoded navigation groups `Quản lý`, `Hệ thống`, `Automation`
- SEO panel: `SeoUserNavigation::GROUP_SYSTEM = 'Hệ thống'` (**hardcoded VI constant**, shared)
- Module nav labels often correctly use `__('seo-content-ai::filament.nav.*')` via
  `getNavigationLabel()` / `SeoUserNavigation::module*()` — **but many Resources still keep dead**
  static `$navigationLabel` / `$modelLabel` English strings that are overridden at runtime
- Admin `UserResource` does **not** override `getModelLabel()` → static VI labels are live →
  Filament EN action prefix + VI modelLabel ⇒ **「New Thành viên」** (`FRAMEWORK_CUSTOM_MIX`)

### Blade / Livewire
- Prefer `__()` / `@lang` / `seo-content-ai::…` in views
- Large volume of **hardcoded Vietnamese** remains in `seo-content-ai-compat` Blade views

### Frontend JS/TS
- No project-wide `react-i18next` / ICU message catalog detected
- React widgets largely receive labels from Blade/Livewire props or hardcode EN/VI strings

### Custom helpers
- No dedicated translation service beyond Laravel `__()` / Filament + BezhanSalleh switcher
- `SeoEngineService::scoringMessagesForLocale()` — scoring copy locale helper (domain, not UI chrome)

## Summary

- **Total high-signal findings:** 1317
- **By severity:** {"High": 970, "Medium": 313, "Low": 19, "Critical": 15}
- **By category:** {"HARDCODED_VI": 743, "HARDCODED_EN": 346, "MISSING_TRANSLATION_KEY": 110, "TRANSLATED_LITERAL": 96, "MIXED_COMPOSITION": 22}

| Category | Count | Notes |
|---|---:|---|
| HARDCODED_VI | 743 | Vietnamese literals in UI source |
| HARDCODED_EN | 346 | English literals (includes dead static props overridden by get*Label) |
| MISSING_TRANSLATION_KEY | 110 | `__('English…')` with no `lang/*.json` entry → shows EN raw / no VI |
| TRANSLATED_LITERAL | 96 | `__('…')` using phrase/VI as key (JSON style or bad VI keys) |
| MIXED_COMPOSITION | 22 | Concat of translated + hardcoded fragments |
| FRAMEWORK_CUSTOM_MIX | (see Critical) | Filament EN chrome + VI `$modelLabel` — e.g. Users 「New Thành viên」 |
| FALLBACK_LEAK | (structural) | Missing VI lang files (`auth`/`pagination`/`passwords`/`validation`) → EN fallback |
| DEAD_KEYS | 194 candidates | VI-only keys in `seo-content-ai` `filament.php` — review before delete |

**Note:** `tests/Unit/Core/MembersTabsAndWorkspaceHubTest.php` currently **asserts** `Tab::make('Tài khoản')` — repair batch for Users must update that contract test when localizing.

### Counts by module

| Module | Total | HARDCODED_VI | HARDCODED_EN | MISSING | TRANSLATED_LITERAL | MIXED |
|---|---:|---:|---:|---:|---:|---:|
| seo-content-ai-compat | 413 | 283 | 0 | 60 | 67 | 3 |
| client/Filament | 205 | 98 | 38 | 43 | 25 | 1 |
| content | 146 | 95 | 44 | 0 | 0 | 7 |
| seeding | 144 | 144 | 0 | 0 | 0 | 0 |
| content-projects | 118 | 45 | 70 | 0 | 0 | 3 |
| ai-prompt | 83 | 17 | 66 | 0 | 0 | 0 |
| agent | 67 | 2 | 63 | 0 | 0 | 2 |
| search-foundation | 47 | 15 | 20 | 6 | 4 | 2 |
| media | 25 | 7 | 18 | 0 | 0 | 0 |
| commerce | 16 | 9 | 7 | 0 | 0 | 0 |
| client/resources | 13 | 12 | 0 | 1 | 0 | 0 |
| search-intelligence | 13 | 2 | 8 | 0 | 0 | 3 |
| seo | 13 | 1 | 11 | 0 | 0 | 1 |
| wordpress | 8 | 8 | 0 | 0 | 0 | 0 |
| publishing | 4 | 3 | 1 | 0 | 0 | 0 |
| client/Providers | 2 | 2 | 0 | 0 | 0 | 0 |

## Critical Global Problems

1. **Admin navigation groups hardcoded in Vietnamese**
   - `AdminPanelProvider`: `Quản lý`, `Hệ thống`
   - Multiple Resources/Pages: `$navigationGroup = 'Quản lý'|'Hệ thống'`
   - Affects every Admin screen when locale=EN

2. **`SeoUserNavigation::GROUP_SYSTEM = 'Hệ thống'`**
   - Shared constant; SEO panel “System” group never localizes
   - Comment in file even documents Vietnamese-only intent for this group

3. **FRAMEWORK_CUSTOM_MIX on Admin Users**
   - `UserResource` `$modelLabel = 'Thành viên'` without `getModelLabel()` override
   - Filament generates `New {modelLabel}` → **「New Thành viên」** in EN
   - Same class mixes `__('Account')` JSON keys with `__('Lưu')` / `__('Huỷ')` / `__('Đã copy email')`

4. **Dual translation conventions without complete coverage**
   - Shell: English-as-key JSON (`lang/*.json`) — only **16** keys; many `__('…')` calls missing
   - Product: semantic keys under `seo-content-ai::filament.*` — large but **EN/VI parity drift**
     (52 EN-only, 194 VI-only keys in `filament.php`)

5. **Dead static EN labels vs live `get*Label()` overrides (addons)**
   - e.g. `ArticleResource` static `$navigationLabel = 'Articles'` but
     `getNavigationLabel()` → `__('seo-content-ai::filament.nav.articles')`
   - Runtime often OK; static props still confuse audits and can leak if override removed

6. **Mass HARDCODED_VI in Blade (seo-content-ai-compat)**
   - Domain overview / internal links / MCP panels still embed Vietnamese copy in templates

## Findings by module

Representative High/Critical items (full machine list: `storage/app/i18n-audit-raw.json`).
Each row: severity · category · file · symbol · current · remediation.

### `client/Filament` (205 findings)

| Severity | Category | File | Symbol | Current | Fix |
|---|---|---|---|---|---|
| Critical | HARDCODED_VI | `omnichannel-client/app/Filament/Pages/ControlServer.php:29` | `$navigationGroup` | Hệ thống | Hardcoded VI; bypasses locale. Centralize nav group/label keys; wire AdminPanelProvider + SeoUserNavigation |
| Critical | HARDCODED_VI | `omnichannel-client/app/Filament/Pages/CoreSettingsHub.php:20` | `$navigationGroup` | Hệ thống | Hardcoded VI; bypasses locale. Centralize nav group/label keys; wire AdminPanelProvider + SeoUserNavigation |
| Critical | HARDCODED_VI | `omnichannel-client/app/Filament/Pages/CoreSettingsHub.php:22` | `$navigationLabel` | Cài đặt | Hardcoded VI; bypasses locale. Centralize nav group/label keys; wire AdminPanelProvider + SeoUserNavigation |
| Critical | HARDCODED_VI | `omnichannel-client/app/Filament/Pages/HelpTopicsAdmin.php:28` | `$navigationGroup` | Hệ thống | Hardcoded VI; bypasses locale. Centralize nav group/label keys; wire AdminPanelProvider + SeoUserNavigation |
| Critical | HARDCODED_VI | `omnichannel-client/app/Filament/Resources/SeoDatabaseConnectionResource.php:28` | `$navigationGroup` | Hệ thống | Hardcoded VI; bypasses locale. Centralize nav group/label keys; wire AdminPanelProvider + SeoUserNavigation |
| Critical | HARDCODED_VI | `omnichannel-client/app/Filament/Resources/SiteResource.php:27` | `$navigationGroup` | Quản lý | Hardcoded VI; bypasses locale. Centralize nav group/label keys; wire AdminPanelProvider + SeoUserNavigation |
| Critical | HARDCODED_VI | `omnichannel-client/app/Filament/Resources/SiteServiceResource.php:30` | `$navigationGroup` | Quản lý | Hardcoded VI; bypasses locale. Centralize nav group/label keys; wire AdminPanelProvider + SeoUserNavigation |
| Critical | HARDCODED_VI | `omnichannel-client/app/Filament/Resources/UserResource.php:25` | `$navigationGroup` | Quản lý | Hardcoded VI; bypasses locale. Centralize nav group/label keys; wire AdminPanelProvider + SeoUserNavigation |
| Critical | HARDCODED_VI | `omnichannel-client/app/Filament/Resources/UserResource.php:29` | `$navigationLabel` | Thành viên | Hardcoded VI; bypasses locale. Centralize nav group/label keys; wire AdminPanelProvider + SeoUserNavigation |
| Critical | HARDCODED_VI | `omnichannel-client/app/Filament/Resources/UserResource.php:31` | `$modelLabel` | Thành viên | Hardcoded VI; bypasses locale. Centralize nav group/label keys; wire AdminPanelProvider + SeoUserNavigation |
| Critical | HARDCODED_VI | `omnichannel-client/app/Filament/Resources/UserResource.php:33` | `$pluralModelLabel` | Thành viên | Hardcoded VI; bypasses locale. Centralize nav group/label keys; wire AdminPanelProvider + SeoUserNavigation |
| High | HARDCODED_VI | `omnichannel-client/app/Filament/Pages/CoreSettingsHub.php:24` | `$title` | Cài đặt | Hardcoded VI; bypasses locale. Centralize nav group/label keys; wire AdminPanelProvider + SeoUserNavigation |

### `search-foundation` (47 findings)

| Severity | Category | File | Symbol | Current | Fix |
|---|---|---|---|---|---|
| Critical | HARDCODED_VI | `omnichannel-addons/search-foundation/src/Filament/Pages/Statistics.php:23` | `$navigationLabel` | Thống kê | Hardcoded VI; bypasses locale. Centralize nav group/label keys; wire AdminPanelProvider + SeoUserNavigation |
| High | HARDCODED_EN | `omnichannel-addons/search-foundation/src/Filament/Pages/SeoTeam.php:36` | `$navigationLabel` | Members | Hardcoded EN; no VI path. Replace with namespaced key; add EN+VI |
| High | TRANSLATED_LITERAL | `omnichannel-addons/search-foundation/src/Filament/Pages/SeoTeam.php:168` | `->modalSubmitActionLabel()` | Lưu | Literal used as translation key. Use stable semantic key; keep VI only in lang files |
| High | TRANSLATED_LITERAL | `omnichannel-addons/search-foundation/src/Filament/Pages/SeoTeam.php:169` | `->modalCancelActionLabel()` | Huỷ | Literal used as translation key. Use stable semantic key; keep VI only in lang files |
| High | HARDCODED_VI | `omnichannel-addons/search-foundation/src/Filament/Pages/Statistics.php:25` | `$title` | Thống kê | Hardcoded VI; bypasses locale. Centralize nav group/label keys; wire AdminPanelProvider + SeoUserNavigation |
| High | HARDCODED_EN | `omnichannel-addons/search-foundation/src/Filament/Resources/DomainResource.php:39` | `$navigationLabel` | Domains | Hardcoded EN; no VI path. Replace with namespaced key; add EN+VI |
| High | HARDCODED_VI | `omnichannel-addons/search-foundation/src/Filament/Resources/DomainResource.php:268` | `->label()` | Xóa | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/search-foundation/src/Filament/Resources/DomainResource/Forms/DomainTechnicalSeoForm.php:251` | `->description()` | Tóm tắt catalog đồng bộ từ WordPress + liên kết thủ công. Không tải toàn bộ URL  | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/search-foundation/src/Filament/Resources/DomainResource/Forms/DomainTechnicalSeoForm.php:266` | `->label()` | Liên kết thủ công (prompt / override) | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/search-foundation/src/Filament/Resources/DomainResource/Forms/DomainTechnicalSeoForm.php:267` | `->helperText()` | Chỉ liên kết thủ công. Catalog WordPress không hiển thị ở đây. | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/search-foundation/src/Filament/Resources/DomainResource/Pages/Concerns/SyncsDomainPromptContextFromWordPress.php:41` | `->body()` | Giá trị đã được rút gọn xuống 80 ký tự. | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/search-foundation/src/Filament/Resources/DomainResource/Pages/Concerns/SyncsDomainPromptContextFromWordPress.php:58` | `->title()` | Không thể đọc thông tin website từ WordPress. | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |

### `ai-prompt` (83 findings)

| Severity | Category | File | Symbol | Current | Fix |
|---|---|---|---|---|---|
| High | HARDCODED_VI | `omnichannel-addons/ai-prompt/src/Filament/Concerns/InteractsWithAiUsageOverview.php:32` | `->title()` | Đã làm mới dữ liệu dashboard | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/ai-prompt/src/Filament/Concerns/InteractsWithAiUsageOverview.php:54` | `->title()` | Đã kiểm tra số dư nhà cung cấp | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/ai-prompt/src/Filament/Concerns/InteractsWithAiUsageOverview.php:55` | `->body()` | Thành công: {$successful}. Thất bại: {$failed}. | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/ai-prompt/src/Filament/Concerns/InteractsWithAiUsageOverview.php:71` | `->title()` | Không tìm thấy connection | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/ai-prompt/src/Filament/Concerns/InteractsWithAiUsageOverview.php:81` | `->title()` | Provider {$connection->provider} không hỗ trợ balance API | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/ai-prompt/src/Filament/Concerns/InteractsWithAiUsageOverview.php:90` | `->title()` | Đã cập nhật số dư {$connection->name} | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/ai-prompt/src/Filament/Concerns/InteractsWithAiUsageOverview.php:91` | `->body()` | Số dư hiện tại: {$result->currency}  | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/ai-prompt/src/Filament/Concerns/InteractsWithAiUsageOverview.php:96` | `->title()` | Không thể cập nhật số dư {$connection->name} | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/ai-prompt/src/Filament/Concerns/InteractsWithAiUsageOverview.php:131` | `->title()` | Đã lưu ngưỡng cảnh báo cho {$connection->name} | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/ai-prompt/src/Filament/Concerns/InteractsWithAiUsageOverview.php:132` | `->body()` | Ngưỡng mới: $ | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_EN | `omnichannel-addons/ai-prompt/src/Filament/Resources/AiConnectionResource.php:35` | `$modelLabel` | API connection | Hardcoded EN; no VI path. Replace with namespaced key; add EN+VI |
| High | HARDCODED_EN | `omnichannel-addons/ai-prompt/src/Filament/Resources/AiConnectionResource.php:37` | `$pluralModelLabel` | API Connections | Hardcoded EN; no VI path. Replace with namespaced key; add EN+VI |

### `content-projects` (118 findings)

| Severity | Category | File | Symbol | Current | Fix |
|---|---|---|---|---|---|
| High | HARDCODED_VI | `omnichannel-addons/content-projects/resources/js/components/ArticleFlowBuilder.jsx:88` | `quoted-vi` | Không gán vai trò | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/content-projects/resources/js/components/ArticleFlowBuilder.jsx:89` | `quoted-vi` | Tạo dàn ý | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/content-projects/resources/js/components/ArticleFlowBuilder.jsx:90` | `quoted-vi` | Viết bài | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/content-projects/resources/js/components/ArticleFlowBuilder.jsx:91` | `quoted-vi` | Cải thiện bài viết | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/content-projects/resources/js/components/ArticleFlowBuilder.jsx:92` | `quoted-vi` | Tạo hình ảnh | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/content-projects/resources/js/components/ArticleFlowBuilder.jsx:236` | `quoted-vi` | Tạo / cập nhật bài viết | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/content-projects/resources/js/components/ArticleFlowBuilder.jsx:438` | `quoted-vi` | Lọc bài viết | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/content-projects/resources/js/components/ArticleFlowBuilder.jsx:521` | `quoted-vi` | Lọc bài viết | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/content-projects/resources/js/components/ArticleFlowBuilder.jsx:654` | `quoted-vi` | Đang lưu... | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/content-projects/resources/js/components/ArticleFlowBuilder.jsx:654` | `quoted-vi` | Lưu Sơ Đồ Quy Trình | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/content-projects/resources/js/components/ArticleFlowBuilder.jsx:666` | `quoted-vi` | Lọc bài viết | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/content-projects/resources/js/components/ArticleFlowBuilder.jsx:914` | `quoted-vi` | Thu nhỏ | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |

### `content` (146 findings)

| Severity | Category | File | Symbol | Current | Fix |
|---|---|---|---|---|---|
| High | HARDCODED_VI | `omnichannel-addons/content/resources/js/article-editor.jsx:219` | `quoted-vi` | Không thể Sync WP khi đang mất kết nối. | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/content/resources/js/article-editor.jsx:220` | `quoted-vi` | Không thể lưu khi đang mất kết nối. | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/content/resources/js/article-editor.jsx:234` | `quoted-vi` | Editor chưa sẵn sàng — tải lại trang rồi thử lại. | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/content/resources/js/article-editor.jsx:244` | `quoted-vi` | Không thu thập được nội dung bài viết. | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/content/resources/js/article-editor.jsx:251` | `quoted-vi` | Không thể lưu lên server vì dữ liệu editor chưa hợp lệ. Các thay đổi đã được giữ | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/content/resources/js/article-editor.jsx:257` | `quoted-vi` | Không xác định được ID bài viết. | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/content/resources/js/article-editor.jsx:268` | `quoted-vi` | Phiên chỉnh sửa đang chỉ đọc — không Sync được. | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/content/resources/js/article-editor.jsx:271` | `quoted-vi` | Đang đưa vào hàng đợi… | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/content/resources/js/article-editor.jsx:281` | `quoted-vi` | Đang lưu rồi đóng… | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/content/resources/js/article-editor.jsx:282` | `quoted-vi` | Đang lưu bài viết… | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/content/resources/js/article-editor.jsx:286` | `quoted-vi` | Bài viết đang ở chế độ chỉ đọc — không lưu được. | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/content/resources/js/article-editor.jsx:388` | `quoted-vi` | Không thu thập được nội dung bài viết. | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |

### `media` (25 findings)

| Severity | Category | File | Symbol | Current | Fix |
|---|---|---|---|---|---|
| High | HARDCODED_VI | `omnichannel-addons/media/resources/js/components/ImageBlockEditor.jsx:795` | `quoted-vi` | Mở trong tab Hình ảnh | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_EN | `omnichannel-addons/media/resources/js/components/MagicEraserPanel.jsx:37` | `const` | rgba(239, 68, 68, 0.55) | Hardcoded EN; no VI path. Replace with namespaced key; add EN+VI |
| High | HARDCODED_EN | `omnichannel-addons/media/resources/js/components/MagicEraserPanel.jsx:38` | `const` | rgba(239, 68, 68, 0.9) | Hardcoded EN; no VI path. Replace with namespaced key; add EN+VI |
| High | HARDCODED_VI | `omnichannel-addons/media/resources/js/components/WatermarkOverlayPreviewPanel.jsx:127` | `quoted-vi` | Ghép lên ảnh mẫu | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/media/resources/js/components/WatermarkOverlayPreviewPanel.jsx:128` | `quoted-vi` | Chỉ overlay (nền caro) | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/media/resources/js/components/WatermarkOverlayPreviewPanel.jsx:139` | `quoted-vi` | Vừa khung | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/media/resources/js/components/WatermarkOverlayPreviewPanel.jsx:140` | `quoted-vi` | 50% kích thước thật | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/media/resources/js/editor/host/SharedMediaPicker.jsx:924` | `quoted-vi` | Nhập từ khóa rồi nhấn Enter để tìm... | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_EN | `omnichannel-addons/media/src/Filament/Pages/ImageOptimizationSettings.php:20` | `$navigationLabel` | Image optimization | Hardcoded EN; no VI path. Replace with namespaced key; add EN+VI |
| High | HARDCODED_EN | `omnichannel-addons/media/src/Filament/Pages/ImageProcessingPage.php:27` | `$navigationLabel` | Image processing | Hardcoded EN; no VI path. Replace with namespaced key; add EN+VI |
| High | HARDCODED_VI | `omnichannel-addons/media/src/Filament/Pages/MediaImageEditor.php:20` | `$title` | Chỉnh sửa ảnh | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_EN | `omnichannel-addons/media/src/Filament/Pages/MediaLibrary.php:36` | `$navigationLabel` | Media library | Hardcoded EN; no VI path. Replace with namespaced key; add EN+VI |

### `client/resources` (13 findings)

| Severity | Category | File | Symbol | Current | Fix |
|---|---|---|---|---|---|
| High | HARDCODED_VI | `omnichannel-client/resources/views/filament/pages/manage-services.blade.php:17` | `quoted-vi` | Đang bật | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-client/resources/views/filament/pages/manage-services.blade.php:17` | `quoted-vi` | Đang tắt | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-client/resources/views/filament/pages/manage-services.blade.php:21` | `quoted-vi` | Không có mô tả. | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-client/resources/views/filament/pages/manage-services.blade.php:29` | `quoted-vi` | Hủy kích hoạt | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-client/resources/views/filament/pages/manage-services.blade.php:29` | `quoted-vi` | Kích hoạt | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-client/resources/views/filament/pages/service-configure.blade.php:16` | `quoted-vi` | Chưa provision | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-client/resources/views/filament/pages/service-configure.blade.php:28` | `quoted-vi` | Chưa cấu hình | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-client/resources/views/filament/pages/service-configure.blade.php:41` | `quoted-vi` | Đã lưu (encrypted) | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-client/resources/views/filament/pages/service-configure.blade.php:41` | `quoted-vi` | Không dùng mật khẩu | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-client/resources/views/filament/widgets/operational-alerts-dashboard-widget.blade.php:43` | `quoted-vi` | Khẩn cấp | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-client/resources/views/filament/widgets/operational-alerts-dashboard-widget.blade.php:43` | `quoted-vi` | Cảnh báo | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-client/resources/views/filament/widgets/operational-alerts-dashboard-widget.blade.php:53` | `quoted-vi` | Vừa phát hiện | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |

### `seeding` (144 findings)

| Severity | Category | File | Symbol | Current | Fix |
|---|---|---|---|---|---|
| High | HARDCODED_VI | `omnichannel-addons/seeding/resources/js/seeding/SeedingWorkspace.jsx:367` | `quoted-vi` | Đã chia sẻ chủ đề | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/seeding/resources/js/seeding/SeedingWorkspace.jsx:372` | `quoted-vi` | Tạo chủ đề thất bại | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/seeding/resources/js/seeding/SeedingWorkspace.jsx:383` | `quoted-vi` | Không có quyền sửa chủ đề này. | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/seeding/resources/js/seeding/SeedingWorkspace.jsx:402` | `quoted-vi` | Đã cập nhật chủ đề | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/seeding/resources/js/seeding/SeedingWorkspace.jsx:455` | `quoted-vi` | Đã chia sẻ chủ đề | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/seeding/resources/js/seeding/SeedingWorkspace.jsx:465` | `quoted-vi` | Chia sẻ thất bại | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/seeding/resources/js/seeding/SeedingWorkspace.jsx:475` | `quoted-vi` | Không có quyền xóa. | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/seeding/resources/js/seeding/SeedingWorkspace.jsx:478` | `quoted-vi` | Xóa chủ đề này? | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/seeding/resources/js/seeding/SeedingWorkspace.jsx:484` | `quoted-vi` | Đã xóa chủ đề | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/seeding/resources/js/seeding/SeedingWorkspace.jsx:489` | `quoted-vi` | Chỉ sửa được nháp local của bạn. | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/seeding/resources/js/seeding/SeedingWorkspace.jsx:535` | `quoted-vi` | Gen thất bại | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/seeding/resources/js/seeding/SeedingWorkspace.jsx:561` | `quoted-vi` | Đã Gen lại | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |

### `seo-content-ai-compat` (413 findings)

| Severity | Category | File | Symbol | Current | Fix |
|---|---|---|---|---|---|
| High | HARDCODED_VI | `omnichannel-addons/seo-content-ai-compat/resources/views/articles/revisions-compare.blade.php:214` | `quoted-vi` | pastPreview.title \|\| '(Không có tiêu đề)' | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/seo-content-ai-compat/resources/views/articles/revisions-compare.blade.php:234` | `quoted-vi` | (Không có tiêu đề) | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/seo-content-ai-compat/resources/views/articles/revisions-compare.blade.php:310` | `quoted-vi` | Không tải được revision | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/seo-content-ai-compat/resources/views/components/ai-result.blade.php:2` | `quoted-vi` | Kết quả AI | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/seo-content-ai-compat/resources/views/components/ai-result.blade.php:54` | `quoted-vi` | copied ? 'Đã sao chép' : 'Sao chép kết quả' | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/seo-content-ai-compat/resources/views/components/ai-result.blade.php:63` | `quoted-vi` | copied ? 'Đã copy' : 'Copy' | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/seo-content-ai-compat/resources/views/components/content-project-bulk-selection-toolbar.blade.php:41` | `quoted-vi` | ($store.pqOpsUi?.selectedCount() \|\| 0) + ' đã chọn' | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/seo-content-ai-compat/resources/views/components/content-project-bulk-selection-toolbar.blade.php:79` | `quoted-vi` | $store.pqOpsUi.runBulk('bulkPublishNow', 'publishing', 'Xuất bản ngay các bài đã | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/seo-content-ai-compat/resources/views/components/content-project-bulk-selection-toolbar.blade.php:148` | `quoted-vi` | $store.pqOpsUi.runBulk('bulkCancelPublish', 'publishing', 'Bỏ các bài đã chọn kh | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/seo-content-ai-compat/resources/views/components/content-project-bulk-selection-toolbar.blade.php:154` | `quoted-vi` | $store.pqOpsUi.runBulk('bulkReturn', 'publishing', 'Trả các bài đã chọn về Conte | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | MIXED_COMPOSITION | `omnichannel-addons/seo-content-ai-compat/resources/views/components/content-project-bulk-selection-toolbar.blade.php:232` | `concat` | ' ('.__( | Do not concat fragments; one semantic phrase. Single translatable sentence/phrase |
| High | HARDCODED_VI | `omnichannel-addons/seo-content-ai-compat/resources/views/components/global-ai-chat.blade.php:123` | `quoted-vi` | Vui lòng chọn website trước khi mở Agent Workspace. | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |

### `search-intelligence` (13 findings)

| Severity | Category | File | Symbol | Current | Fix |
|---|---|---|---|---|---|
| High | HARDCODED_EN | `omnichannel-addons/search-intelligence/src/Filament/Pages/AiKeywordDiscovery.php:26` | `$navigationLabel` | AI Keyword Discovery | Hardcoded EN; no VI path. Replace with namespaced key; add EN+VI |
| High | HARDCODED_EN | `omnichannel-addons/search-intelligence/src/Filament/Pages/SeoPerformanceHub.php:49` | `$navigationLabel` | SEO Performance | Hardcoded EN; no VI path. Replace with namespaced key; add EN+VI |
| High | HARDCODED_EN | `omnichannel-addons/search-intelligence/src/Filament/Resources/KeywordResource.php:65` | `$navigationLabel` | Keywords | Hardcoded EN; no VI path. Replace with namespaced key; add EN+VI |
| High | HARDCODED_EN | `omnichannel-addons/search-intelligence/src/Filament/Resources/KeywordResource.php:67` | `$modelLabel` | Keyword | Hardcoded EN; no VI path. Replace with namespaced key; add EN+VI |
| High | HARDCODED_EN | `omnichannel-addons/search-intelligence/src/Filament/Resources/KeywordResource.php:69` | `$pluralModelLabel` | Keywords | Hardcoded EN; no VI path. Replace with namespaced key; add EN+VI |
| High | MIXED_COMPOSITION | `omnichannel-addons/search-intelligence/src/Filament/Resources/KeywordResource.php:279` | `concat` | __('seo-content-ai::filament.keyword.domain').': ' | Do not concat fragments; one semantic phrase. Single translatable sentence/phrase |
| High | MIXED_COMPOSITION | `omnichannel-addons/search-intelligence/src/Filament/Resources/KeywordResource.php:305` | `concat` | __('seo-content-ai::filament.keyword.operational_tags').': ' | Do not concat fragments; one semantic phrase. Single translatable sentence/phrase |
| High | MIXED_COMPOSITION | `omnichannel-addons/search-intelligence/src/Filament/Resources/KeywordResource.php:338` | `concat` | __('seo-content-ai::filament.keyword.legacy_type').': ' | Do not concat fragments; one semantic phrase. Single translatable sentence/phrase |
| High | HARDCODED_VI | `omnichannel-addons/search-intelligence/src/Filament/Resources/KeywordResource/Pages/ListKeywords.php:522` | `->helperText()` | Mỗi dòng là một keyword free (type=free), không tự gắn vào bài viết. | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/search-intelligence/src/Filament/Resources/KeywordResource/Pages/ListKeywords.php:573` | `->title()` | Đã thêm {$created} keyword free | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |

### `commerce` (16 findings)

| Severity | Category | File | Symbol | Current | Fix |
|---|---|---|---|---|---|
| High | HARDCODED_VI | `omnichannel-addons/commerce/src/Filament/Pages/ProductGalleryCanaryPage.php:93` | `Tab/Section::make` | Tạo dự án sản phẩm thử nghiệm | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/commerce/src/Filament/Pages/ProductGalleryCanaryPage.php:94` | `->description()` | Tạo Content Project + product article shell. Operator chọn 2–3 ảnh original từ M | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/commerce/src/Filament/Pages/ProductGalleryCanaryPage.php:111` | `->helperText()` | Ví dụ: 101,102,103 — tối thiểu 2 ID từ Media Library. | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/commerce/src/Filament/Pages/ProductGalleryCanaryPage.php:154` | `->title()` | Tạo canary thất bại | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/commerce/src/Filament/Pages/ProductGalleryCanaryPage.php:165` | `->title()` | Canary fixture sẵn sàng | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/commerce/src/Filament/Pages/ProductGalleryCanaryPage.php:169` | `->label()` | Mở editor | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/commerce/src/Filament/Pages/ProductGalleryCanaryPage.php:219` | `->title()` | Chưa có article canary | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/commerce/src/Filament/Pages/ProductGalleryCanaryPage.php:237` | `->title()` | Cleanup thất bại | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/commerce/src/Filament/Pages/ProductGalleryCanaryPage.php:245` | `->title()` | Đã discard generated canary | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |

### `wordpress` (8 findings)

| Severity | Category | File | Symbol | Current | Fix |
|---|---|---|---|---|---|
| High | HARDCODED_VI | `omnichannel-addons/wordpress/resources/js/components/WordPressMediaRenameModal.jsx:66` | `quoted-vi` | Không quét được usage. | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/wordpress/resources/js/components/WordPressMediaRenameModal.jsx:113` | `quoted-vi` | Đang quét usage… | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/wordpress/resources/js/components/WordPressMediaRenameModal.jsx:116` | `quoted-vi` | Đang đổi tên… | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/wordpress/resources/js/components/WordPressMediaRenameModal.jsx:119` | `quoted-vi` | Chưa có kết quả usage scan. | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/wordpress/resources/js/components/WordPressMediaRenameModal.jsx:125` | `quoted-vi` | Usage scan WordPress chưa hoàn thành — không đổi tên được. | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/wordpress/resources/js/components/WordPressMediaRenameModal.jsx:129` | `quoted-vi` | Nhập filename/slug mới. | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/wordpress/resources/js/components/WordPressMediaRenameModal.jsx:132` | `quoted-vi` | Cần tick xác nhận URL sẽ đổi. | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/wordpress/resources/js/components/WordPressMediaRenameModal.jsx:135` | `quoted-vi` | Nhập chính xác RENAME. | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |

### `agent` (67 findings)

| Severity | Category | File | Symbol | Current | Fix |
|---|---|---|---|---|---|
| High | HARDCODED_VI | `omnichannel-addons/agent/src/Filament/Pages/AgentWorkspacePage.php:1730` | `->title()` | Đã lưu kế hoạch — chưa chạy bước nào. | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/agent/src/Filament/Pages/AgentWorkspacePage.php:1743` | `->title()` | Đã hủy đề xuất. | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | MIXED_COMPOSITION | `omnichannel-addons/agent/src/Filament/Resources/AutomationExecutionResource.php:128` | `concat` | __('seo-content-ai::filament.automation.rule').' code' | Do not concat fragments; one semantic phrase. Single translatable sentence/phrase |
| High | MIXED_COMPOSITION | `omnichannel-addons/agent/src/Filament/Resources/AutomationRuleResource.php:247` | `concat` | __('seo-content-ai::filament.automation.settings').' (JSON)' | Do not concat fragments; one semantic phrase. Single translatable sentence/phrase |

### `seo` (13 findings)

| Severity | Category | File | Symbol | Current | Fix |
|---|---|---|---|---|---|
| Critical | HARDCODED_VI | `omnichannel-addons/seo/src/Support/SeoUserNavigation.php:17` | `const` | Hệ thống | Hardcoded VI; bypasses locale. Centralize nav group/label keys; wire AdminPanelProvider + SeoUserNavigation |
| High | HARDCODED_EN | `omnichannel-addons/seo/src/Filament/Pages/SeoSettings.php:18` | `$navigationLabel` | Settings | Hardcoded EN; no VI path. Replace with namespaced key; add EN+VI |
| High | MIXED_COMPOSITION | `omnichannel-addons/seo/src/Filament/Pages/SeoSettingsWorkflows.php:398` | `concat` | "\n".__( | Do not concat fragments; one semantic phrase. Single translatable sentence/phrase |

### `publishing` (4 findings)

| Severity | Category | File | Symbol | Current | Fix |
|---|---|---|---|---|---|
| High | HARDCODED_VI | `omnichannel-addons/publishing/src/Filament/Pages/PublishingQueueHub.php:519` | `->title()` | Chọn ít nhất một bài. | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/publishing/src/Filament/Pages/PublishingQueueHub.php:538` | `->title()` | Kiểm tra lại trạng thái | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |
| High | HARDCODED_VI | `omnichannel-addons/publishing/src/Filament/Pages/PublishingQueueHub.php:539` | `->body()` | Đã đối soát  | Hardcoded VI; bypasses locale. Move to `__('…')` / `seo-content-ai::…` + both locales |

### `client/Providers` (2 findings)

| Severity | Category | File | Symbol | Current | Fix |
|---|---|---|---|---|---|
| Critical | HARDCODED_VI | `omnichannel-client/app/Providers/Filament/AdminPanelProvider.php:50` | `NavigationGroup::make` | Quản lý | Hardcoded VI; bypasses locale. Centralize nav group/label keys; wire AdminPanelProvider + SeoUserNavigation |
| Critical | HARDCODED_VI | `omnichannel-client/app/Providers/Filament/AdminPanelProvider.php:51` | `NavigationGroup::make` | Hệ thống | Hardcoded VI; bypasses locale. Centralize nav group/label keys; wire AdminPanelProvider + SeoUserNavigation |

### Navigation / modelLabel inventory (all)

| Severity | Category | Module | File | Symbol | Current |
|---|---|---|---|---|---|
| Critical | HARDCODED_VI | client/Filament | `omnichannel-client/app/Filament/Pages/ControlServer.php:29` | `$navigationGroup` | Hệ thống |
| Critical | HARDCODED_VI | client/Filament | `omnichannel-client/app/Filament/Pages/CoreSettingsHub.php:20` | `$navigationGroup` | Hệ thống |
| Critical | HARDCODED_VI | client/Filament | `omnichannel-client/app/Filament/Pages/CoreSettingsHub.php:22` | `$navigationLabel` | Cài đặt |
| Critical | HARDCODED_VI | client/Filament | `omnichannel-client/app/Filament/Pages/HelpTopicsAdmin.php:28` | `$navigationGroup` | Hệ thống |
| Critical | HARDCODED_VI | client/Filament | `omnichannel-client/app/Filament/Resources/SeoDatabaseConnectionResource.php:28` | `$navigationGroup` | Hệ thống |
| Critical | HARDCODED_VI | client/Filament | `omnichannel-client/app/Filament/Resources/SiteResource.php:27` | `$navigationGroup` | Quản lý |
| Critical | HARDCODED_VI | client/Filament | `omnichannel-client/app/Filament/Resources/SiteServiceResource.php:30` | `$navigationGroup` | Quản lý |
| Critical | HARDCODED_VI | client/Filament | `omnichannel-client/app/Filament/Resources/UserResource.php:25` | `$navigationGroup` | Quản lý |
| Critical | HARDCODED_VI | client/Filament | `omnichannel-client/app/Filament/Resources/UserResource.php:29` | `$navigationLabel` | Thành viên |
| Critical | HARDCODED_VI | client/Filament | `omnichannel-client/app/Filament/Resources/UserResource.php:31` | `$modelLabel` | Thành viên |
| Critical | HARDCODED_VI | client/Filament | `omnichannel-client/app/Filament/Resources/UserResource.php:33` | `$pluralModelLabel` | Thành viên |
| Critical | HARDCODED_VI | client/Providers | `omnichannel-client/app/Providers/Filament/AdminPanelProvider.php:50` | `NavigationGroup::make` | Quản lý |
| Critical | HARDCODED_VI | client/Providers | `omnichannel-client/app/Providers/Filament/AdminPanelProvider.php:51` | `NavigationGroup::make` | Hệ thống |
| Critical | HARDCODED_VI | search-foundation | `omnichannel-addons/search-foundation/src/Filament/Pages/Statistics.php:23` | `$navigationLabel` | Thống kê |
| Critical | HARDCODED_VI | seo | `omnichannel-addons/seo/src/Support/SeoUserNavigation.php:17` | `const` | Hệ thống |
| High | HARDCODED_EN | ai-prompt | `omnichannel-addons/ai-prompt/src/Filament/Resources/AiConnectionResource.php:35` | `$modelLabel` | API connection |
| High | HARDCODED_EN | ai-prompt | `omnichannel-addons/ai-prompt/src/Filament/Resources/AiConnectionResource.php:37` | `$pluralModelLabel` | API Connections |
| High | HARDCODED_EN | ai-prompt | `omnichannel-addons/ai-prompt/src/Filament/Resources/PromptResource.php:42` | `$navigationLabel` | Prompts |
| High | HARDCODED_EN | ai-prompt | `omnichannel-addons/ai-prompt/src/Filament/Resources/PromptResource.php:44` | `$modelLabel` | Prompt |
| High | HARDCODED_EN | ai-prompt | `omnichannel-addons/ai-prompt/src/Filament/Resources/PromptResource.php:46` | `$pluralModelLabel` | Prompts |
| High | HARDCODED_EN | client/Filament | `omnichannel-client/app/Filament/Resources/SeedingDatabaseConnectionResource.php:23` | `$navigationLabel` | Seeding Database |
| High | HARDCODED_EN | client/Filament | `omnichannel-client/app/Filament/Resources/SeedingDatabaseConnectionResource.php:25` | `$modelLabel` | Seeding DB connection |
| High | HARDCODED_EN | client/Filament | `omnichannel-client/app/Filament/Resources/SeedingDatabaseConnectionResource.php:27` | `$pluralModelLabel` | Seeding DB connections |
| High | HARDCODED_EN | content-projects | `omnichannel-addons/content-projects/src/Filament/Resources/SeoProjectResource.php:72` | `$navigationLabel` | Projects |
| High | HARDCODED_EN | content-projects | `omnichannel-addons/content-projects/src/Filament/Resources/SeoProjectResource.php:74` | `$modelLabel` | Content project |
| High | HARDCODED_EN | content-projects | `omnichannel-addons/content-projects/src/Filament/Resources/SeoProjectResource.php:76` | `$pluralModelLabel` | Content projects |
| High | HARDCODED_EN | content-projects | `omnichannel-addons/content-projects/src/Filament/Resources/TaskResource.php:33` | `$navigationLabel` | Task workflows |
| High | HARDCODED_EN | content-projects | `omnichannel-addons/content-projects/src/Filament/Resources/TaskResource.php:35` | `$modelLabel` | Workflow |
| High | HARDCODED_EN | content-projects | `omnichannel-addons/content-projects/src/Filament/Resources/TaskResource.php:37` | `$pluralModelLabel` | Task workflows |
| High | HARDCODED_EN | content | `omnichannel-addons/content/src/Filament/Pages/ArticlesOptimal.php:40` | `$navigationLabel` | Article SEO audit |
| High | HARDCODED_EN | content | `omnichannel-addons/content/src/Filament/Resources/ArticleResource.php:70` | `$navigationLabel` | Articles |
| High | HARDCODED_EN | content | `omnichannel-addons/content/src/Filament/Resources/ArticleResource.php:72` | `$modelLabel` | Article |
| High | HARDCODED_EN | content | `omnichannel-addons/content/src/Filament/Resources/ArticleResource.php:74` | `$pluralModelLabel` | Articles |
| High | HARDCODED_EN | content | `omnichannel-addons/content/src/Filament/Resources/ArticleResource/Pages/ListArticlesTrash.php:20` | `$navigationLabel` | Trash |
| High | HARDCODED_EN | media | `omnichannel-addons/media/src/Filament/Pages/ImageOptimizationSettings.php:20` | `$navigationLabel` | Image optimization |
| High | HARDCODED_EN | media | `omnichannel-addons/media/src/Filament/Pages/ImageProcessingPage.php:27` | `$navigationLabel` | Image processing |
| High | HARDCODED_EN | media | `omnichannel-addons/media/src/Filament/Pages/MediaLibrary.php:36` | `$navigationLabel` | Media library |
| High | HARDCODED_EN | media | `omnichannel-addons/media/src/Filament/Pages/WatermarkEditor.php:21` | `$navigationLabel` | Watermark designer |
| High | HARDCODED_EN | search-foundation | `omnichannel-addons/search-foundation/src/Filament/Pages/SeoTeam.php:36` | `$navigationLabel` | Members |
| High | HARDCODED_EN | search-foundation | `omnichannel-addons/search-foundation/src/Filament/Resources/DomainResource.php:39` | `$navigationLabel` | Domains |
| High | HARDCODED_EN | search-intelligence | `omnichannel-addons/search-intelligence/src/Filament/Pages/AiKeywordDiscovery.php:26` | `$navigationLabel` | AI Keyword Discovery |
| High | HARDCODED_EN | search-intelligence | `omnichannel-addons/search-intelligence/src/Filament/Pages/SeoPerformanceHub.php:49` | `$navigationLabel` | SEO Performance |
| High | HARDCODED_EN | search-intelligence | `omnichannel-addons/search-intelligence/src/Filament/Resources/KeywordResource.php:65` | `$navigationLabel` | Keywords |
| High | HARDCODED_EN | search-intelligence | `omnichannel-addons/search-intelligence/src/Filament/Resources/KeywordResource.php:67` | `$modelLabel` | Keyword |
| High | HARDCODED_EN | search-intelligence | `omnichannel-addons/search-intelligence/src/Filament/Resources/KeywordResource.php:69` | `$pluralModelLabel` | Keywords |
| High | HARDCODED_EN | seo | `omnichannel-addons/seo/src/Filament/Pages/SeoSettings.php:18` | `$navigationLabel` | Settings |

## Translation parity

| File | EN keys | VI keys | EN-only | VI-only | Suspicious identical EN=VI |
|---|---:|---:|---:|---:|---:|
| `lang/*.json` | 16 | 16 | 0 | 0 | 0 |
| `lang/{locale}/auth.php` | 3 | 0 | 3 | 0 | 0 |
| `lang/{locale}/client_control.php` | 45 | 45 | 0 | 0 | 17 |
| `lang/{locale}/pagination.php` | 2 | 0 | 2 | 0 | 0 |
| `lang/{locale}/passwords.php` | 5 | 0 | 5 | 0 | 0 |
| `lang/{locale}/seo.php` | 13 | 13 | 0 | 0 | 0 |
| `lang/{locale}/seo_rules.php` | 28 | 28 | 0 | 0 | 0 |
| `lang/{locale}/site-service.php` | 31 | 31 | 0 | 0 | 0 |
| `lang/{locale}/validation.php` | 136 | 2 | 135 | 1 | 0 |
| `seeding/resources/lang/{locale}/filament.php` | 41 | 41 | 0 | 0 | 8 |
| `seo-content-ai-compat/lang/{locale}/common.php` | 7 | 7 | 0 | 0 | 0 |
| `seo-content-ai-compat/lang/{locale}/filament.php` | 3685 | 3827 | 52 | 194 | 605 |
| `seo-content-ai-compat/lang/{locale}/prompt_hooks.php` | 61 | 61 | 0 | 0 | 3 |

### `lang/{locale}/auth.php`

- **Missing VI file** (Laravel will fall back to EN / vendor).
- EN-only (3): `failed`, `password`, `throttle`

### `lang/{locale}/pagination.php`

- **Missing VI file** (Laravel will fall back to EN / vendor).
- EN-only (2): `next`, `previous`

### `lang/{locale}/passwords.php`

- **Missing VI file** (Laravel will fall back to EN / vendor).
- EN-only (5): `reset`, `sent`, `throttled`, `token`, `user`

### `lang/{locale}/validation.php`

- EN-only (135): `accepted`, `accepted_if`, `active_url`, `after`, `after_or_equal`, `alpha`, `alpha_dash`, `alpha_num`, `any_of`, `array`, `ascii`, `before`, `before_or_equal`, `between.array`, `between.file`, `between.numeric`, `between.string`, `boolean`, `can`, `confirmed`, `contains`, `current_password`, `custom.attribute-name.rule-name`, `date`, `date_equals`, `date_format`, `decimal`, `declined`, `declined_if`, `different`, `digits`, `digits_between`, `dimensions`, `distinct`, `doesnt_contain`, `doesnt_end_with`, `doesnt_start_with`, `email`, `encoding`, `ends_with`
  - … +95 more
- VI-only (1): `attributes.domain`

### `seo-content-ai-compat/lang/{locale}/filament.php`

- EN-only (52): `performance_hub.gsc_import_csv_label`, `performance_hub.gsc_import_duplicate`, `performance_hub.gsc_import_invalid`, `performance_hub.gsc_import_preview`, `performance_hub.gsc_import_previewing`, `performance_hub.gsc_import_total`, `performance_hub.gsc_import_valid`, `performance_hub.gsc_intelligence_heading`, `performance_hub.gsc_intelligence_hint`, `performance_hub.gsc_intelligence_opportunities_placeholder`, `performance_hub.gsc_intelligence_overview_placeholder`, `performance_hub.gsc_intelligence_pages_placeholder`, `performance_hub.gsc_intelligence_queries_placeholder`, `performance_hub.gsc_intelligence_sync_hint`, `performance_hub.gsc_intelligence_tabs_label`, `performance_hub.tab_gsc_legacy`, `projects.:remaining left',
        'move_target_archive_option`, `projects.Resume or Run again appears when eligible — AI does not start automatically.',
        'select_existing_article_success`, `projects.about :per_day articles/day in :month.',
        'draft_split_domain_warning_hint`, `projects.and AI data will not be reused.',
        'restart_with_keyword_cancel`, `projects.and generate new ideas with AI.',
        'content_planning_section_create`, `projects.and generate new ideas with AI.',
        'seo_audit_advanced_help`, `projects.and restore are available only on a Draft (planning) project.',
        'suggestions_create_draft`, `projects.both bulk and single-item actions.',
        'run_settings_start`, `projects.corporate gifts; avoid consumer canvas bag themes.',
        'new_topics_requires_topics`, `projects.failed :failed.',
        'run_started`, `projects.how to style outfits with a backpack',
        'audit_notes_manual_badge`, `projects.items in an active publish queue stay put when the move is not proven safe.',
        'compact_success_preview`, `projects.or Article ID.',
        'workspace_tabs`, `projects.or restore back to the active list.',
        'archive_dashboard_empty`, `projects.set Target DNA; enter required DNA and AI fills the rest.',
        'audit_notes_search_placeholder`, `projects.so items cannot all be returned to Draft by deleting it.',
        'delete_blocked_missing_source_draft`, `projects.suggestions are not filtered by language.',
        'suggestions_primary_language_missing_link`, `projects.sync WordPress site info so locale can be detected.',
        'planner_set_primary_language`, `projects.then create new Draft items.',
        'idea_candidate_tab_available`, `projects.then reload. Local images stay PNG; WordPress sync still converts to WebP per settings.',
        'image_driver_missing_title`, `projects.then the project is deleted. Projects that have already started cannot be deleted this way.',
        'delete_submit`, `projects.use Fill from SEO Audit on Content Planning.',
        'seo_audit_advanced_heading`, `projects.use «Stop» to halt after the current item.',
        'run_pending_count`, `projects.you may delete _HUONG_DAN then upload. Production export clones _WRITER_TEMPLATE / fills DATA.',
        'excel_tpl_begin_sheet_hint`
  - … +12 more
- VI-only (194): `ai_center.sync_all_done_title`, `ai_center.sync_coverage_body`, `api_connections.sync_all_done_title`, `api_connections.sync_all_models`, `projects.AI bổ sung phần còn thiếu.',
        'audit_notes_search_placeholder`, `projects.Content Project capability và AI pipeline đã đăng ký. Không chỉnh sửa — chỉ xem đường đi và trạng thái chạy gần nhất.',
            'intro_v2`, `projects.Products và dữ liệu nghiệp vụ khác KHÔNG bị ảnh hưởng.',
        'clear_cancel`, `projects.Publishing. Không cài đặt hay bật/tắt.',
        'col_id`, `projects.WP post ID hoặc Article ID.',
        'workspace_tabs`, `projects.bấm Convert lần nữa để xác nhận.',
        'map_version`, `projects.bấm «Dừng» để ngừng sau hạng mục hiện tại.',
        'run_pending_count`, `projects.bỏ qua tỉ lệ.',
        'resize_complete`, `projects.bổ sung bài cải thiện và gợi ý bài mới bằng AI.',
        'content_planning_section_create`, `projects.bổ sung bài cải thiện và gợi ý bài mới bằng AI.',
        'seo_audit_advanced_help`, `projects.chạy: php artisan automation:seed-rules',
            'select_prompt`, `projects.cách phối đồ với balo',
        'audit_notes_manual_badge`, `projects.còn :remaining',
        'move_target_archive_option`, `projects.có thể xóa sheet _HUONG_DAN rồi tải lên. Export production sẽ nhân bản _WRITER_TEMPLATE / đổ DATA.',
        'excel_tpl_begin_sheet_hint`, `projects.cả bulk và item lẻ.',
        'run_settings_start`, `projects.dismiss và restore chỉ khả dụng trên Draft (planning).',
        'suggestions_create_draft`, `projects.dùng Fill from SEO Audit trong Lập kế hoạch nội dung.',
        'seo_audit_advanced_heading`, `projects.gợi ý không lọc theo ngôn ngữ.',
        'suggestions_primary_language_missing_link`, `projects.hoặc khôi phục về danh sách đang hoạt động.',
        'archive_dashboard_empty`, `projects.hoặc sync đang chạy.',
        'manual_sync_queued_title`, `projects.hệ thống sẽ hiện Resume hoặc Chạy lại nếu đủ điều kiện — không tự chạy AI.',
        'select_existing_article_success`, `projects.khoảng :per_day bài/ngày trong :month.',
        'draft_split_domain_warning_hint`, `projects.không cần chọn domain. Chỉ tính bài đã generator xong và có content; bài từ SEO Audit / Project Planner chưa được xem là đã xong.',
        'compact_success_stat_projects`, `projects.không phải Business Hook rule.',
            'mapping.],
            'actions.wordpress.article.sync`, `projects.không phải Business Hook rule.',
            'mapping.],
            'capabilities.content_project.create`, `projects.không phải Business Hook rule.',
            'mapping.],
            'categories.business_hook`, `projects.không phải Business Hook rule.',
            'mapping.],
            'edge_types.next`, `projects.không phải Business Hook rule.',
            'mapping.],
            'events.article.completed`, `projects.không phải Business Hook rule.',
            'mapping.],
            'status.never`, `projects.không phải Business Hook rule.',
            'mapping.],
            'workflows.generate_article.description`, `projects.không phải Business Hook rule.',
            'mapping.],
            'workflows.generate_article.name`, `projects.không phải Business Hook rule.',
            'mapping.],
            'workflows.generate_article.nodes.content`, `projects.không phải Business Hook rule.',
            'mapping.],
            'workflows.generate_article.nodes.image`, `projects.không phải Business Hook rule.',
            'mapping.],
            'workflows.generate_article.nodes.outline`, `projects.không phải Business Hook rule.',
            'mapping.],
            'workflows.generate_article.nodes.pipeline`, `projects.không phải Business Hook rule.',
            'mapping.],
            'workflows.generate_article.nodes.rerun`
  - … +154 more

### Notable parity notes
- `lang/vi/auth.php`, `pagination.php`, `passwords.php`: **missing** (EN-only Laravel defaults)
- `lang/vi/validation.php`: nearly empty vs full EN validation catalog
- `lang/*.json`: key set parity OK (16/16) but coverage of shell UI is tiny
- `seo-content-ai-compat/.../filament.php`: largest drift — prioritize EN-only keys that are
  referenced at runtime; VI-only may be obsolete or ahead of EN
- `client_control.php`: many intentional identical product terms (Control Server, status enums)

### Dead keys
- Not auto-deleted. VI-only keys in `filament.php` (194) are **candidates** for dead-key review
  in a later batch (cross-check `__('seo-content-ai::…')` usage).

## Recommended repair batches

Do **not** mass-rewrite in one PR. Suggested order by leverage:

1. **Localization foundation / global navigation**
   - Translate Admin `NavigationGroup::make` + shared `SeoUserNavigation::GROUP_SYSTEM`
   - Introduce stable keys e.g. `nav.group.management`, `nav.group.system` in client + SEO SSOT
   - Align Admin vs SEO panel group naming

2. **Admin / users / settings / sites / services**
   - `UserResource` model/nav labels + Tab `Tài khoản` + `__('Lưu')`/`__('Huỷ')`
   - Expand `lang/en.json` + `lang/vi.json` **or** migrate shell to PHP namespaces
   - Fix Site/SiteService missing JSON keys

3. **Content project / articles**
   - Remove dead static EN labels or make them call the same keys as `get*Label()`
   - Blade leftovers in article-related compat views

4. **Keywords / topic / SEO audit / domains**
   - Domain Blade HARDCODED_VI clusters
   - `Statistics` nav `Thống kê`

5. **AI / history / prompts**
   - `ai-prompt` Filament resources + AI Center strings
   - filament.php EN/VI parity for prompt/AI sections

6. **Seeding**
   - Prefer `seeding::filament.*`; purge remaining hardcoded UI

7. **Automation / agent**
   - Agent Filament pages/resources EN hardcodes

8. **Remaining modules** (media, wordpress, publishing, commerce) + React widget strings

9. **Framework validation/auth lang files** — copy/publish Laravel `vi` lang packs

## Guardrail

- Scanner: `php`/`python` `tools/i18n-audit-scan.py`
- Allowlist: `tools/i18n-allowlist.json`
- PHPUnit: `tests/Unit/I18n/TranslationParityTest.php`
  - EN/VI key parity for known lang trees
  - Obvious hardcoded Vietnamese in Filament static nav/model props

## Highest-leverage fixes (start here)

1. `UserResource` `$navigationLabel` / `$modelLabel` / `$pluralModelLabel` / `$navigationGroup`
2. `AdminPanelProvider` navigation groups
3. `SeoUserNavigation::GROUP_SYSTEM`
4. Add missing `lang/vi/{auth,pagination,passwords,validation}.php` (or publish Laravel vi)
5. Close `lang/*.json` gaps for Site/SiteService/User actions (`Lưu`, `Huỷ`, …)
6. Reconcile `seo-content-ai-compat` `filament.php` EN-only (52) keys

---
_Raw finding count 1317. Scanner does not claim zero false positives on Blade;
nav/modelLabel Critical list was manually cross-checked against source._
