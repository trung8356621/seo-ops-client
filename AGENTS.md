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
