---
name: docs-bulk-update
description: "Bulk-update canonical backend docs after multi-day or multi-module code changes, or when the user explicitly asks to sync/summarize docs. Replaces the retired XONG! per-session workflow."
---

# Purpose

Keep canonical docs aligned via **one consolidated pass** — not per-agent micro-updates.

# Trigger conditions

- User asks to update, sync, or summarize docs (e.g. "cập nhật docs", "tổng kết docs").
- User retires `XONG!` workflow and wants bulk catch-up.
- Large code landings span client + addons and canonical docs are stale.

# Required context

- `git log` / `git diff` on `omnichannel-client`, `omnichannel-addons`, `wp-seo-ai` if relevant.
- `docs/README.md` module index.
- `docs/QUICK_DOC_SUMMARY.md` for digest section.

# Workflow

1. Identify all changed symbols, routes, services, jobs, migrations, contracts since last doc pass.
2. Select every affected canonical doc from mapping in `.cursor/rules/auto-update-docs.mdc`.
3. Patch all files in one pass; bump `Last verified` dates.
4. Update `docs/QUICK_DOC_SUMMARY.md` digest (not SoT).
5. Update `docs/README.md` if new module doc added.
6. Route feature ownership: content/media/seo/wordpress/publishing/content-projects/ai-prompt/search-intelligence/site-sync/agent — not SeoContentAi compat shell.

# Verification

- Grep changed symbols appear in updated docs.
- No application source changed unless already part of the active task.

# Safety

- Do not rewrite full large docs when focused patches work.
- Do not copy archive into canonical without code verification.
- Do not use retired `XONG!` trigger.

# Expected final report

Concise Vietnamese summary listing all files updated and date range covered.
