# Frontend build domains

Production assets are built locally and deployed with the application source. `npm run build` creates every required manifest; the production host does not need Node.js.

| Changed source | Build required | Output |
| --- | --- | --- |
| Core/Admin only | `npm run build:core` | `public/build` |
| Article Editor content/runtime | `npm run build:editor` | `public/build-editor` |
| Standalone Media UI | `npm run build:media` | `public/build-media` |
| Standalone SEO UI | `npm run build:seo` | `public/build-seo` |
| GSC/Search Intelligence UI | `npm run build:search` | `public/build-search` |
| Content Projects/workflow UI | `npm run build:projects` | `public/build-projects` |
| Support/Chat | `npm run build:support` | `public/build-support` |
| Agent Runtime | `npm run build:agent` | `public/build-agent` |

Build ownership follows entry points and their imports, not addon directory names. Each build uses the shared root `node_modules` and bundles its own transitive dependencies and vendor chunks.

## Cross-build source dependencies

- Changes under `media/editor/**` or other Media source imported by Article Editor require `build:editor`. Also run `build:media` only when a standalone Media entry imports the changed source.
- Changes under `seo/editor/**` or other SEO source imported by Article Editor require `build:editor`. Also run `build:seo` only when a standalone SEO entry imports the changed source.
- The Article Editor composition root also imports WordPress, Publishing, AI Prompt, Content, and Media source. Rebuild `build:editor` when any imported source changes.
- `article-media-picker-cache-bootstrap.js` is a Media-owned entry. Article Edit loads it from `build-media`; it is not duplicated as an Editor entry.
- `article-execution-history.jsx` is owned by the Content Projects build because its current consumer is the project/execution-history view.

Run `npm run build` before packaging a complete production ZIP. A partial build empties only its own output directory and does not refresh other manifests.
