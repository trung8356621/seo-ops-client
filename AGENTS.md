# omnichannel-client

Thin Laravel application shell with embedded platform runtime (`app/Core`).

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
