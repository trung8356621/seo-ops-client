# Service Widget — Admin Header Integration

Embeds the OPS-SERVER React Service Widget into Filament Admin / SEO / Seeding headers.

Canonical OPS-SERVER contracts: sibling repo `ops-server` → `docs/modules/SERVICE_WIDGET_RENEWAL.md`.

## Flow

1. Header shows **Dịch vụ** (lazy; no OPS call on page load).
2. Click → `POST /api/service-widget/session` (Laravel auth).
3. Laravel uses enrolled H1 machine credential → OPS `POST /api/v1/installations/{id}/widget/token`.
4. Browser receives short-lived widget bearer only (never H1 secret).
5. Lazy-load `/vendor/ops-service-widget/ops-service-widget.js`, mount Real Mode (`useMock: false`).

## Bundle

Copied from OPS-SERVER build:

```text
public/vendor/ops-service-widget/ops-service-widget.js
```

Refresh after OPS `pnpm build:widget`:

```bash
copy D:\work\ops-server\public\assets\ops-service-widget.js public\vendor\ops-service-widget\
```

Override URL: `OPS_SERVICE_WIDGET_BUNDLE_URL`.

## Env

| Variable | Role |
|---|---|
| `OPS_SERVER_BASE_URL` | Control plane (also used by H1) |
| `OPS_SERVICE_WIDGET_BUNDLE_URL` | Optional absolute/relative bundle URL |
| `OPS_SERVICE_WIDGET_TIMEOUT` | S2S mint timeout (seconds, default 5) |
| `OPS_SERVICE_WIDGET_API_TIMEOUT_MS` | Browser widget API timeout |

OPS-SERVER must set `OPS_SERVICE_WIDGET_TOKEN_SECRET` and allow CORS origin for this host.

## Live check (`seo-ops-import-test.test`)

1. Enrolled Installation with commercial services on OPS.
2. Open Admin Header → **Dịch vụ**.
3. Confirm services + renew creates Pending Order (Admin Mark as Paid on OPS).
4. Disable OPS temporarily → click shows offline + Retry; page load stays fast.
