# Repository Split (Task 11)

> Status: Staging under `D:\work\_split`  
> Verified: 2026-08-10  
> Deploy: **NOT** done · ZIP: **NOT** done · Manual UI: **NOT** yet

## Target tree

```text
D:\work\_split\
├── omnichannel-client/          # thin Laravel shell
├── omnichannel-client-core/     # platform/runtime package
└── omnichannel-addons/          # peer-addon monorepo
```

`wp-seo-ai` remains sibling at `D:\work\wp-seo-ai` (untouched).

Old `omnichannel-backend` kept intact until cutover; staging does **not** delete it.

## Boot order

```text
Laravel shell (omnichannel-client)
  → ClientCoreServiceProvider (omnichannel/client-core)
  → AddonDiscovery(discovery_roots)
  → peer addon providers (filesystem manifests)
```

No hard-coded `SeoServiceProvider::class` / business providers in `bootstrap/providers.php`.

## Composer path strategy

`omnichannel-client/composer.json`:

```json
"repositories": [
  { "type": "path", "url": "../omnichannel-client-core", "options": { "symlink": true } },
  { "type": "path", "url": "../omnichannel-addons", "options": { "symlink": true } }
],
"require": {
  "omnichannel/client-core": "0.1.0",
  "omnichannel/addons": "0.1.0"
}
```

Local edits to core/addons are immediately visible via Composer path junctions under `vendor/omnichannel/*`.

Filesystem discovery uses junction:

```text
omnichannel-client/addons  →  ../omnichannel-addons
```

Env override: `OMNICHANNEL_ADDONS_PATH`.

## Frontend / Vite

- Entry points still reference `addons/<slug>/resources/...` (via junction).
- Alias `@client-core` → `../omnichannel-client-core/resources/js`
- `resolve.preserveSymlinks` + explicit `react` / `react-dom` / `lucide-react` aliases so Rollup resolves deps from client `node_modules` while compiling external addon sources.

## Migrations

- Core Laravel migrations: `omnichannel-client/database/migrations`
- Peer addon migrations: `omnichannel-addons/<slug>/database/migrations`
- `MigrationPathLocator` globs `addons/*/database/migrations` (no hard-coded business class list)
- `php artisan refactor:migrate --verify --via-mysql` runs from **client**

## Compat shell

`omnichannel-addons/seo-content-ai-compat/`  
Slug remains `seo-content-ai` (legacy). Namespace `App\Addons\SeoContentAi\*` autoloaded from that folder. **No new business code.**

## Independence

Runtime classes resolve under `_split/` only (reflection proof PASS).  
Full directory rename of `omnichannel-backend` may be blocked while IDE locks the folder; path proof is authoritative.

## First commands (from client)

```text
cd D:\work\_split\omnichannel-client
composer dump-autoload -o
php artisan optimize:clear
php artisan route:list
npm run build
node --test ../omnichannel-client-core/resources/js/__tests__/saveCoordinator.test.mjs
php artisan refactor:migrate --verify --via-mysql
php artisan refactor:migrate --verify --via-mysql
$PHP_BIN vendor/bin/phpunit --filter=ArticleExtensionOwnershipContractTest
```
