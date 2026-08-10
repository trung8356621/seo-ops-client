# Client Core purity (Task 13)

> Status: Audit after physical split  
> Package: `omnichannel/client-core` → `D:\work\omnichannel-client-core`

## Verdict

**No known business bleed requiring moves.** Core stays protocol/runtime only.

## Responsibilities (KEEP)

| Area | Path | Role |
|------|------|------|
| Addon discovery | `src/Addon/` | Discover/register peer addons; no hard-coded business list |
| Capability / command / event | `src/Capability`, `src/Command`, `src/Event` | Buses + contracts |
| API / automation registries | `src/Api`, `src/Automation` | Protocol registries |
| Queue / operations / sites | `src/Queue`, `src/Operations`, `src/Sites` | Runtime identity + ops logging |
| Dual-DB migrate | `src/Database/*`, `src/Console/Commands/Refactor*` | `refactor:migrate`, guards, path locators |
| Frontend runtime | `resources/js/saveCoordinator.js` (+ related) | Cross-owner save coordination |

## Keyword hits reviewed (not business ownership)

- `SiteIdentity` — site id protocol, not WP sync implementation
- `PublisherCapability` — capability contract surface
- `ApiContext` — request/context DTO for API layer

## Forbidden (unchanged)

Import addon implementations; SEO/Article/WP/Publishing/Media/Site Sync/Prompt/Agent product code.

## Refactor artisan commands

| Command | Decision |
|---------|----------|
| `refactor:migrate` | **KEEP** — permanent dual-DB operational tool |
| `refactor:migrate-fresh` | **KEEP** — destructive, guard-protected test DBs only |
| `refactor:reconcile-migrations` | **KEEP** — history reconcile helper |
| `refactor:data-counts` | **KEEP** — verify counts around migrate |

Do not weaken destructive DB guard.
