# omnichannel-client

Thin Laravel application shell with embedded `App\Core` platform runtime.

Requires path package:
- `../omnichannel-addons`

Local: `composer install` then junction/symlink `addons` -> `../omnichannel-addons` (created by stage script).

**Retired:** standalone `omnichannel-client-core` package — runtime lives in `app/Core/`.

## Developer docs

Canonical index: [`docs/README.md`](docs/README.md).

AI execution layer (stabilized): [`docs/architecture/AI_EXECUTION_ROUTING.md`](docs/architecture/AI_EXECUTION_ROUTING.md) · History [`docs/architecture/AI_HISTORY_PROMPT_VERSION.md`](docs/architecture/AI_HISTORY_PROMPT_VERSION.md) · Debug playbook [`docs/operations/AI_DEBUG_PLAYBOOK.md`](docs/operations/AI_DEBUG_PLAYBOOK.md).  
Agent discipline: `.cursor/rules/debug-fix-discipline.mdc` (DEBUG ≠ FIX).
## UI TESTING WORKFLOW

- Do not autonomously launch the Cursor integrated browser
  for routine UI testing.
- Do not start php artisan serve or create alternative servers.
- The canonical local application is http://seo-ops.test/.
- Frontend tasks must include the required build.
- Verify compilation, asset manifests, API contracts,
  and relevant automated tests.
- Do not claim browser verification from build results.
- Do not block task completion waiting for browser login.
- The user performs manual UI testing in their own browser.
- Browser E2E testing is allowed only when explicitly requested.
- Report build results and provide concise manual test steps.