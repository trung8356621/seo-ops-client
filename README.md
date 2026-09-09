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
