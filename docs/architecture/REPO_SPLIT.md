# Repository Split (canonical)

> Status: **Canonical at `D:\work\`** (Task 12 cutover)  
> Staging under `D:\work\_split` is obsolete — do not use as SoT.  
> Deploy: **NOT** done · ZIP: **NOT** done · Manual UI: **NOT** yet

## Target tree

```text
D:\work\
├── omnichannel-client/          # thin Laravel shell
├── omnichannel-client-core/     # platform/runtime package
├── omnichannel-addons/          # peer-addon monorepo
├── wp-seo-ai/                   # WordPress bridge (separate repo)
└── omnichannel-backend.__pre_split_backup/   # rollback only (after rename)
```

Open workspace: `D:\work\omnichannel.code-workspace` (four folders only).

## Boot order

```text
Laravel shell (omnichannel-client)
  → ClientCoreServiceProvider (omnichannel/client-core)
  → AddonDiscovery(discovery_roots)
  → peer addon providers (filesystem manifests)
```

No hard-coded business providers in `bootstrap/providers.php`.

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

Local edits visible via Composer path junctions under `vendor/omnichannel/*`.

Filesystem discovery uses junction:

```text
omnichannel-client/addons  →  ../omnichannel-addons
```

Env override: `OMNICHANNEL_ADDONS_PATH`.

## Frontend / Vite

- Entry points reference `addons/<slug>/resources/...` (via junction).
- Alias `@client-core` → `../omnichannel-client-core/resources/js`
- `resolve.preserveSymlinks` + explicit `react` / `react-dom` / `lucide-react` aliases.

## Migrations

- Core Laravel migrations: `omnichannel-client/database/migrations`
- Peer addon migrations: `omnichannel-addons/<slug>/database/migrations`
- `MigrationPathLocator` globs `addons/*/database/migrations`
- `php artisan refactor:migrate --verify --via-mysql` runs from **client**

## Compat shell

`omnichannel-addons/seo-content-ai-compat/`  
Slug remains `seo-content-ai` (legacy). Namespace `App\Addons\SeoContentAi\*` autoloaded from that folder. **No new business code.**

## Independence

Runtime classes resolve under `D:\work\omnichannel-client\vendor\omnichannel\{client-core,addons}\...` (junctions to sibling repos).  
Full rename of `omnichannel-backend` → `omnichannel-backend.__pre_split_backup` may stay blocked while Cursor holds the old folder open — run `D:\work\RENAME_PRE_SPLIT_BACKUP.ps1` after closing that workspace.

## First commands (from client)

```text
cd D:\work\omnichannel-client
composer dump-autoload -o
php artisan optimize:clear
php artisan route:list
npm run build
node --test ../omnichannel-client-core/resources/js/__tests__/saveCoordinator.test.mjs
php artisan refactor:migrate --verify --via-mysql
php artisan refactor:migrate --verify --via-mysql
php vendor/bin/phpunit --filter=ArticleExtensionOwnershipContractTest
```
