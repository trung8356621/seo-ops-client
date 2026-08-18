# Client Core purity (Task 13)

> Status: Audit after client-core merge into client  
> Location: `omnichannel-client/app/Core` (`App\Core\*`)

## Verdict

**No known business bleed requiring moves.** Core stays protocol/runtime only.

## Responsibilities (KEEP)

| Area | Path | Role |
|------|------|------|
| Addon discovery | `app/Core/Addon/` | Discover/register peer addons; no hard-coded business list |
| Capability / command / event | `app/Core/Capability`, `app/Core/Command`, `app/Core/Event` | Buses + contracts |
| API / automation registries | `app/Core/Api`, `app/Core/Automation` | Protocol registries |
| Queue / operations / sites | `app/Core/Queue`, `app/Core/Operations`, `app/Core/Sites` | Runtime identity + ops logging |
| Dual-DB migrate | `app/Core/Database/*`, `app/Core/Console/Commands/Refactor*` | `refactor:migrate`, guards, path locators |
| Frontend runtime | `resources/js/client-core/saveCoordinator.js` (+ related) | Cross-owner save coordination |

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
