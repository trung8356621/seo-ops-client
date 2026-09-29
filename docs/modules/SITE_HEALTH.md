# Global Site Health

> Owner: Site Sync addon (monitor/state/UI read model), SEO addon (operational notifications), Client Core (global Filament hook)

Site Health is a lightweight availability monitor independent from Site Sync. It checks active managed `wp-headless` sites every five minutes in this order: DNS, public HTTPS response, bridge heartbeat, then capability/auth response. It never runs a sync, crawls content, or stores successful-check history.

## State and incidents

`seo_site_health_states` stores one compact current row per site. The first failure is `suspected`; the second consecutive failure opens an incident in `seo_site_health_incidents`. Repeated failures update the active incident. Recovery resolves it, clears the failure counter, and creates one operational recovery notification. A later outage creates a new immutable incident ID.

Machine codes are `DNS_ERROR`, `CONNECTION_TIMEOUT`, `CONNECTION_ERROR`, `TLS_ERROR`, `HTTP_5XX`, `SITE_UNREACHABLE`, `WP_BRIDGE_UNREACHABLE`, `WP_BRIDGE_AUTH_ERROR`, and `HEARTBEAT_INVALID`.

## Runtime

The `seo:site-health:monitor` command is scheduled every five minutes with scheduler-level and per-site locks. Operators may target one site with `--site=<id>`. The host must run Laravel `schedule:run` every minute.

The global Filament notice is registered once at `PanelsRenderHook::CONTENT_BEFORE` and is available on `admin`, `seo`, `seo-main`, and `seeding`. It aggregates active incidents and uses `SiteAccess` for tenant scoping. Dismissal is stored in the browser against the user plus the active incident/severity signature; dismissal never changes backend health.

Manual “Check again now” invokes the same canonical check and transition services. Diagnostic payloads are sanitized and never contain bridge tokens or credentials.
