# Repository layout (canonical)

> Status: **Canonical at `D:\work\`**  
> Last updated: 2026-08-18 — client-core merged into client  
> Deploy: **NOT** done · ZIP: **NOT** done

## Target tree

```text
D:\work\
├── omnichannel-client/          # Laravel shell + App\Core runtime
│   ├── app/Core/                # platform/runtime (former client-core)
│   └── addons/                  # junction → omnichannel-addons
├── omnichannel-addons/          # peer-addon monorepo
├── ops-server/                  # standalone SaaS control plane (separate)
├── wp-seo-ai/                   # WordPress bridge (separate repo)
└── omnichannel-backend/         # legacy monolith reference (read-only)
```

**Retired:** `omnichannel-client-core` standalone package — merged into `omnichannel-client/app/Core`.

Open workspace: `D:\work\omnichannel.code-workspace`.

## Boot order

```text
Laravel shell (omnichannel-client)
  → ClientCoreServiceProvider (app/Core, registered in bootstrap/providers.php)
  → AddonDiscovery(discovery_roots)
  → peer addon providers (filesystem manifests)
```

No hard-coded business providers in `bootstrap/providers.php`.

## Composer path strategy

`omnichannel-client/composer.json`:

```json
"repositories": [
  { "type": "path", "url": "../omnichannel-addons", "options": { "symlink": true } }
],
"require": {
  "omnichannel/addons": "0.1.0"
}
```

`App\Core\*` autoloads via client `"App\\": "app/"`.

Filesystem discovery uses junction:

```text
omnichannel-client/addons  →  ../omnichannel-addons
```

Env override: `OMNICHANNEL_ADDONS_PATH`.

## Frontend / Vite

- Entry points reference `addons/<slug>/resources/...` (via junction).
- Alias `@client-core` → `resources/js/client-core`
- `resolve.preserveSymlinks` + explicit `react` / `react-dom` / `lucide-react` aliases.

## Migrations

- Core Laravel migrations: `omnichannel-client/database/migrations`
- Peer addon migrations: `omnichannel-addons/<slug>/database/migrations`
- `MigrationPathLocator` globs `addons/*/database/migrations`
- `php artisan refactor:migrate --verify --via-mysql` runs from **client**

## Compat shell

`omnichannel-addons/seo-content-ai-compat/`  
Slug remains `seo-content-ai` (legacy). Namespace `App\Addons\SeoContentAi\*` autoloaded from that folder. **No new business code.**

## First commands (from client)

```text
composer dump-autoload -o
php artisan optimize:clear
php artisan about
npm run build
node --test resources/js/client-core/__tests__/saveCoordinator.test.mjs
$PHP_BIN vendor/bin/phpunit --testsuite ClientCore
```
