# omnichannel-client

Thin Laravel application shell only.

## Owns
- bootstrap / env / public entry
- root config composition
- storage / app lifecycle
- wiring Client Core + discovering addons

## Does NOT own
Business SEO, Content, Media, WordPress, Publishing, Site Sync, Prompts, Search Intelligence, Agent product logic.

## Path packages
- `../omnichannel-client-core` → `omnichannel/client-core`
- `../omnichannel-addons` → `omnichannel/addons`
- Junction `addons/` → `../omnichannel-addons` (gitignored)

## Feature routing
See workspace root docs / sibling `omnichannel-addons/AGENTS.md`.
