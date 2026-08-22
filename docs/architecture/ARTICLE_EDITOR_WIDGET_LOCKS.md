# Article Editor Widget Locks

> Status: Canonical  
> Manifest: `omnichannel-addons/content/editor-widget-locks.json`  
> Agent rule: `omnichannel-client/.cursor/rules/editor-widget-locks.mdc`

## Purpose

Freeze stable Article Editor sidebar widgets so later Editor work cannot silently regress them.

**Manifest = policy. Guard = generic enforcement.** The guard never hard-codes widget IDs; it reads the manifest dynamically.

## Current policy

**SEO remains unlocked for active development.**

All other registered Article Editor widgets are locked.

| Technical ID | Display label | Runtime panelId | locked |
|--------------|---------------|-----------------|--------|
| `seo` | SEO | `seo` | **false** (intentional) |
| `featured` | Featured | `featured` | true |
| `images` | Images | `images` | true |
| `gallery` | Gallery | `product-album` | true |
| `reviews` | Reviews | `reviews` | true |
| `links` | Links | `links` | true |
| `cta` | CTA | `cta` (aliases links) | true |
| `vocabulary` | Vocabulary | `vocabulary` | true |
| `faq` | FAQ | `faq` | true |
| `ai-chat` | AI | `ai-chat` | true |
| `publishing` | Publishing | `publishing` | true |
| `status` | Trạng thái | `article` | true |

Notes:

- Lock ID `status` ≠ runtime `panelId` (`article`). Do not rename either while locked.
- `Trạng thái` is Vietnamese-only (known locale gap). **Do not localize while locked.**
- Gallery UI mounts via Featured chip (`navChip: false`); lock id remains `gallery`.
- FAQ / AI Chat are Editor widgets even when not dock chips (`navChip: false`).
- Known issue (SEO domain, still unlocked): Featured Snippet table generation still requires separate investigation/test. Lock policy does **not** resolve that bug.

## Commands (from `omnichannel-client`)

```bash
npm run widget-lock -- status
npm run widget-lock -- unlock <id>
npm run widget-lock -- lock <id>
npm run widget-lock -- seal [id]
npm run check:editor-widget-locks
```

There is **no** unlock-all. Each widget lock is independent.

Workflow for intentional edits to a locked widget:

1. `npm run widget-lock -- unlock reviews` (example)
2. Make the minimum required change
3. `npm run widget-lock -- lock reviews` (re-seals fingerprints)
4. `npm run check:editor-widget-locks` must PASS

Later SEO can be frozen with `npm run widget-lock -- lock seo` without changing the guard implementation.

## Protection model

Each widget lists `paths[]`:

- whole file, or
- region via `start` / `end` markers inside a shared file

Fingerprints are SHA-256 of protected content (newlines normalized). Changing unlocked Editor code outside those regions remains allowed. SEO paths are registered now so a future `lock seo` seals the same regions.

## Related architecture

- Shell dock / panels: [`ARTICLE_EDITOR_SHELL_BOUNDARY.md`](ARTICLE_EDITOR_SHELL_BOUNDARY.md)
- Runtime modules: [`ARTICLE_EDITOR_RUNTIME.md`](ARTICLE_EDITOR_RUNTIME.md)
- FAQ / SEO semantics: [`ARTICLE_EDITOR_WIDGETS_OWNERSHIP.md`](ARTICLE_EDITOR_WIDGETS_OWNERSHIP.md)
