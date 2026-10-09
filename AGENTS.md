# omnichannel-client

Thin Laravel application shell with embedded platform runtime (`app/Core`).

## Mandatory coding skill

For all coding, editing, debugging, implementation, and testing tasks, always apply `.agents/skills/compact-coding/SKILL.md`.

## Owns
- bootstrap / env / public entry
- root config composition
- storage / app lifecycle
- `App\Core\*` platform/runtime (addon discovery, registries, migration guards, SaveCoordinator JS)
- discovering peer addons via junction

## Does NOT own
Business SEO, Content, Media, WordPress, Publishing, Site Sync, Prompts, Search Intelligence, Agent product logic.

## Path packages
- `../omnichannel-addons` → `omnichannel/addons`
- Junction `addons/` → `../omnichannel-addons` (gitignored)

## Retired
- `omnichannel-client-core` standalone package — merged into `app/Core` (2026-08-18)

## Feature routing
See workspace root docs / sibling `omnichannel-addons/AGENTS.md`.

Editor widget locks: all registered Editor widgets locked except `seo` (intentionally unlocked for active development). See `addons/content/editor-widget-locks.json`, `npm run check:editor-widget-locks`, `npm run widget-lock -- status`.

## Docs maintenance
Docs updates are **manual / bulk on request** only — no per-repo auto-scan rule. Optional skill: `$docs-bulk-update` (`.agents/skills/docs-update-on-xong/SKILL.md`). Canonical docs: `docs/modules|contracts|architecture|operations` — not `docs/archive/*`. Human-facing help: `resources/help-seed/` + `docs/modules/CONTEXTUAL_HELP.md` (and `seo-ops-help` for public Help).

AI execution SoT (stabilized): `docs/architecture/AI_EXECUTION_ROUTING.md`. Debug discipline: shared `seo-ops-workspace/.cursor/rules/debug-fix-discipline.mdc` (description-triggered).

## Test / Tool / Debug Artifact Placement
- Keep repository root for project entry/config files only; never place temporary tests, probes, debug/audit/repair scripts, maintenance tools, fixtures, generated reports, logs, dumps, or one-off utilities there.
- Application tests belong in `tests/Unit`, `tests/Feature`, or another existing canonical test directory. Addon/module tests belong in that module's `tests/` tree. Reusable fixtures belong in `tests/Fixtures` or the owning module's fixture directory.
- Reusable diagnostics and developer tools belong in the existing scripts/tools area, using `scripts/dev/` when no better owner exists. Repeatable repair or maintenance tools belong in `scripts/maintenance/`.
- Generated diagnostic output belongs in an ignored temp/report directory and must not be committed unless deliberately curated as a minimal, deterministic fixture.
- Delete one-off probes after use. If temporarily needed, place them under `scripts/dev/` or an ignored temp directory, never as root `_probe.php`, `_debug.php`, `_audit.php`, `_try.php`, report JSON, log dump, or Python probe files.
- Before finishing work that creates a test/tool/probe, place it in its canonical owner directory, delete disposable artifacts, and verify the root remains clean. Work is incomplete until misplaced artifacts are corrected and temporary outputs are removed.
## LOCAL DEVELOPMENT ENVIRONMENT — IMPORTANT

The SEO-OPS application already has a configured local development domain.

### Canonical Development URL

- Application: `http://seo-ops.test/`
- Article Editor: `http://seo-ops.test/seo/articles/{article_id}/edit?site_id={site_id}`
- Example: `http://seo-ops.test/seo/articles/12169/edit?site_id=4`

### Mandatory Rules

1. ALWAYS use `http://seo-ops.test/` for local browser testing and UI verification.
2. DO NOT automatically execute `php artisan serve`.
3. DO NOT create another Laravel development server on `127.0.0.1:8000` or another port.
4. DO NOT modify `APP_URL`, web server configuration, or virtual host settings merely to make browser testing work.
5. The existing local development environment is managed outside the Cursor agent.
6. Before browser testing, attempt to access the canonical domain.
7. If the domain is inaccessible from the agent's browser environment, report the access issue instead of launching a replacement server.
8. Do not restart or terminate existing web server processes without explicit user approval.

### Frontend Development

- Inspect the actual frontend build configuration before running build commands.
- Use the existing project build scripts.
- Building frontend assets is allowed when required.
- Do not automatically launch an additional Vite development server.
- Verify that the application loads the newly built assets.

### Browser Testing

Use the existing authenticated application session when available.

If authentication is required and no session is available, report the limitation. Do not change authentication configuration or create test accounts without authorization.

### Principle

**The Cursor agent works against the existing local development environment. It must not create a parallel application server.**