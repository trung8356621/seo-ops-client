# Omnichannel Client — Agent instructions

## Repository map (authoritative after Task 11)

| Concern | Path |
|---------|------|
| Laravel shell | `D:\work\_split\omnichannel-client` |
| Platform / runtime | `D:\work\_split\omnichannel-client-core` |
| Peer business addons | `D:\work\_split\omnichannel-addons/<slug>` |
| Compat Filament shell | `D:\work\_split\omnichannel-addons\seo-content-ai-compat` |
| WordPress plugin | `D:\work\wp-seo-ai` |

**Do not** treat `omnichannel-backend` as runtime SoT after cutover. It remains a legacy working tree until migration complete.

## Hard rules

1. Shell never hard-codes business addon provider classes.
2. Client-core never imports business addon implementations.
3. Addon A never imports Addon B internals — capability / command / event / DTO only.
4. SeoContentAi compat = views/lang/panel bootstrap only — **no new business code**.
5. Protected DBs `omi_channel` / `omi_seo_ai`: only `refactor:migrate --verify --via-mysql`. Never fresh.

## Verify from client

```text
cd D:\work\_split\omnichannel-client
composer dump-autoload -o
php artisan optimize:clear
npm run build
node --test ../omnichannel-client-core/resources/js/__tests__/saveCoordinator.test.mjs
php artisan refactor:migrate --verify --via-mysql
```

Architecture: `docs/architecture/REPO_SPLIT.md`, `ADDON_ARCHITECTURE.md`, `NEW_AGENT_HANDOFF.md`.
