# Service Widget — Admin Header Integration

Embeds the OPS-SERVER React Service Widget **body** into Filament Admin / SEO / Seeding headers.

Canonical OPS-SERVER contracts: sibling repo `ops-server` → `docs/modules/SERVICE_STATUS_WIDGET.md`, `SERVICE_WIDGET_RENEWAL.md`, and `SERVICE_WIDGET_CHECKOUT_SECURITY.md` (Production: widget must never collect/confirm payment; checkout is OPS-hosted).

## Ownership

| Owner | Owns |
|---|---|
| **Laravel** | Header **Dịch vụ** button, dropdown size/position, host container, lazy script load, session mint |
| **OPS-SERVER** | React body (100% width/height), widget APIs, static JS bundle |

No second React trigger, no synthetic clicks, no CSS-hidden popover chrome.

## Flow

1. Header shows **Dịch vụ** (lazy; no OPS call on page load).
2. Click → open Laravel dropdown → `POST /api/service-widget/session` (Laravel auth).
3. Laravel uses enrolled H1 machine credential → OPS `POST /api/v1/installations/{id}/widget/token`.
4. Browser receives short-lived widget bearer only (never H1 secret). Installation ID comes from enrolled H1 state only.
5. Lazy-load `/vendor/ops-service-widget/ops-service-widget.js`, then:

```js
OpsServiceWidget.setAuthContext({
  mode: 'bearer',
  accessToken,
  installationId, // from session — not browser input
});
OpsServiceWidget.mount({
  target: host,
  useMock: false,
  installationId,
  apiBaseUrl,
  apiTimeoutMs,
  locale: 'vi',
});
```

6. React body calls OPS `GET .../services` and `POST .../renewals` (Pending Orders only).

## Lifecycle

| Action | Behavior |
|---|---|
| Close dropdown | Hide shell only — keep React instance |
| Reopen (token valid) | Show host; no remount |
| Token near expiry | Re-mint session → `setAuthContext` + `mount` (no `unmount` on API errors) |
| OPS offline / session error | Host error + **Thử lại**; do not unmount prior body |
| Filament `livewire:navigated` | Re-bind once; remount only if Shadow DOM was wiped |

## Bundle

Copied from OPS-SERVER build (`pnpm build:widget`):

```text
public/vendor/ops-service-widget/ops-service-widget.js
```

Refresh after OPS rebuild:

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

OPS-SERVER must set `OPS_SERVICE_WIDGET_TOKEN_SECRET` and allow CORS origin (`installation_url` and/or `OPS_SERVICE_WIDGET_CORS_ORIGINS`).

## Network checklist (browser)

1. `POST /api/service-widget/session` — Laravel (S2S mint behind it)
2. `GET /api/v1/widget/installations/{id}/services` — OPS (React)
3. `POST /api/v1/widget/installations/{id}/renewals` — OPS (React; Pending Order)

Renewal success creates a **Pending** Order only. No Mark as Paid / entitlement grant from the Client.

DEV may show Pending Order details in-widget for manual OPS Mark as Paid. Production must open OPS-SERVER-hosted Checkout (not embedded payment collection) — see OPS `SERVICE_WIDGET_CHECKOUT_SECURITY.md`.

## Live check (`seo-ops.test`)

1. Enrolled Installation with commercial services on OPS.
2. Open Admin Header → **Dịch vụ**.
3. Confirm services + renew creates Pending Order (Admin Mark as Paid on OPS).
4. Disable OPS temporarily → click shows offline + Retry; page load stays fast.
