# Article Editor fixes (2026-08)

> Status: Changelog / handoff  
> Owner: content + media + ai-prompt addons  
> Scope: Edit Article React runtime  
> Related: [ARTICLE_EDITOR.md](../modules/ARTICLE_EDITOR.md)

## 1. Outline heading rename — local-first

**Problem:** Đổi tên heading trong tab Outline bị chặn toast kiểu “Heading chưa lưu xong trên server…”, trong khi sửa title ở Section vẫn được.

**Cause:** UI gate theo `isPersistedOutlineHeadingId` (chỉ nhận numeric DB id). Outline client dùng id `client:{blockId}` / `pending-{blockId}` trước khi persist.

**Fix:**
- Shared `updateOutlineHeadingTitle()` trong `useArticleEditorOutline.js`.
- Outline + Section cùng path local-first; không chờ server id.
- Helpers: `isPersistedOutlineHeadingId`, `resolveBlockIdFromOutlineHeadingId` (`contentDocumentHelpers.js`).
- Canonical local id vẫn là `client:{blockId}` (không invent ID system mới).

**Tests:** `ArticleEditorPhase4ClientUtilitiesTest` (và contract outline liên quan).

## 2. AI Media — placeholder / hang / double image

### 2.1 Placeholder bị coi là lỗi ảnh

- `AI_PLACEHOLDER_LOADING_URL` dùng **inline data URI** (`seoMediaApi.js`).
- `brokenImageGuard` + `assistantWidgetHealth` bỏ qua AI loading placeholder.

### 2.2 Class not found làm sập Livewire JSON

- `TypographyValidationLevel.php` thiếu `use Omnichannel\Addons\Seo\Support\RenderingPreference;` → response Livewire hỏng → UI hang.

### 2.3 UI AI Media

- Bỏ nút **Preview prompt** khỏi `ArticleAiChatPanel.jsx` (giữ Copy + Generate with API).
- Contract: `ArticleEditorSyncWpVisibilityTest`.

### 2.4 Hang / poll `ai-jobs` + heartbeat

**Nguyên nhân vận hành:**
- `php artisan serve` single-threaded.
- Job `completed` nhưng URL vẫn placeholder / meta `failed` → client poll vô hạn.
- Overlapping fetch `ai-jobs`.

**Fix:**
- Coi `completed` + placeholder URL là failed; dừng poll.
- Không overlap poll `ai-jobs`.
- Dispatch `GenerateMediaJob` ngay (không chỉ `afterResponse`).
- Timeout Livewire ~90s; reconcile stale jobs.
- Normalize failed trong `formatAiMediaPayload` + clear generating UI.

### 2.5 Double image

- Client spinner = data URI; Livewire event dùng `/assets/images/placeholder-loading.svg` → insert lần 2.
- Match/reuse processing block trong `placeProcessingImagePlaceholder` + event bridge; dedupe spinner còn sót khi apply.

**Key files:**  
`seoMediaApi.js`, `brokenImageGuard.js`, `assistantWidgetHealth.js`, `TypographyValidationLevel.php`, `ArticleAiChatPanel.jsx`, `GenerateMediaJob.php`, `ArticleEditorMediaAiService.php`, `SeoMediaController.php`, `useArticleEditorImageGeneration.js`, `useArticleEditorExternalEventsBridge.js`, `ArticleImagesTab.jsx`

**Ops note:** Sau hang, restart `artisan serve`. Queue backlog (vd. `seo-audit`) cần worker thật — serve không drain queue.

## 3. Locale pass (Edit Article surfaces)

Canonical dictionary: `omnichannel-addons/content/resources/js/utils/i18n.js` (`en` + `vi`, helper `t()`).

Đã đưa chuỗi UI cứng (VN/EN lẫn) vào `t()` cho:

| Surface | Files |
|---------|--------|
| Featured sidebar | `media/.../FeaturedSidebarPanel.jsx` |
| Product gallery sidebar | `media/.../GallerySidebarPanel.jsx` |
| AI Media session / gen errors | `ArticleAiChatPanel.jsx`, `useArticleEditorImageGeneration.js` |
| Outline toolbar, actions, toasts, duplicate compare | `ArticleOutlineTab.jsx` |

Keys mới (prefix): `editor_featured_*`, `editor_product_album_*`, `editor_gallery_*`, `editor_session_read_only_generate`, `editor_generate_image_*`, `outline_*`.

Còn sót có thể có ở panel/module khác ngoài scope lần này — ưu tiên surface user nhìn thấy trên Edit Article.

## 4. Verify

```text
# remote / host
$PHP_BIN vendor/bin/phpunit --filter=ArticleEditorPhase4ClientUtilitiesTest
$PHP_BIN vendor/bin/phpunit --filter=ArticleEditorSyncWpVisibilityTest

# local frontend (omnichannel-client)
npm run build
```

Smoke: mở Edit Article — đổi locale `vi`/`en` — kiểm tra Featured / Gallery / Outline / AI Media labels + toast.
