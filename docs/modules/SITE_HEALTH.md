# Global Site Health

> Owner: Site Sync addon (monitor/state), SEO addon (operational notifications + Operational Alert Hook promotion), Client Core (shared Filament Operational Alert Hook mount)

Site Health is a lightweight availability monitor independent from Site Sync. It checks active managed `wp-headless` sites every five minutes in this order: DNS, public HTTPS response, bridge heartbeat, then capability/auth response. It never runs a sync, crawls content, or stores successful-check history.

## State and incidents

`seo_site_health_states` stores one compact current row per site. The first failure is `suspected`; the second consecutive failure opens an incident in `seo_site_health_incidents`. Repeated failures update the active incident. Recovery resolves it, clears the failure counter, and creates one operational recovery notification. A later outage creates a new immutable incident ID.

Machine codes are `DNS_ERROR`, `CONNECTION_TIMEOUT`, `CONNECTION_ERROR`, `TLS_ERROR`, `HTTP_5XX`, `SITE_UNREACHABLE`, `WP_BRIDGE_UNREACHABLE`, `WP_BRIDGE_AUTH_ERROR`, and `HEARTBEAT_INVALID`.

## Runtime

The `seo:site-health:monitor` command is scheduled every five minutes with scheduler-level and per-site locks. Operators may target one site with `--site=<id>`. The host must run Laravel `schedule:run` every minute.

Active Down/Degraded incidents are published through `OperationalNotificationService` and explicitly promoted to the **Operational Alert Hook** (see [OPERATIONAL_ALERT_HOOK.md](../architecture/OPERATIONAL_ALERT_HOOK.md)). The shared Filament mount is registered once at `PanelsRenderHook::CONTENT_BEFORE` on `admin`, `seo`, `seo-main`, and `seeding`. Resolved/recovered incidents leave the hook automatically; recovery history remains in Notification Center.

`SiteHealthNotice` / `GlobalSiteHealthReadModel` remain available for Site Sync–owned detail/retry flows and unit tests; they are not the shared high-visibility surface.
