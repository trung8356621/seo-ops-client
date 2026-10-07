# Operational Alert Hook

## Purpose

High-visibility UI surface for **active** important operational incidents.

Canonical project term (stable for agents and product prompts):

**Operational Alert Hook**

Stable internal identifiers:

- hook id: `operational-alert`
- display surface enum value: `operational_alert`
- Livewire component: `operational-alert-hook`

Do **not** invent synonyms (`critical banner`, `system warning bar`, `service error banner`, `health banner`).

## Canonical flow

```
Producer / Monitor
      ↓
OperationalNotificationService
      ↓
database notification / active incident
      ├─ Notification Center   (always)
      └─ Operational Alert Hook (only when explicitly promoted)
```

Notification Center remains the canonical notification/incident storage + history.

Operational Alert Hook is an **additional** opt-in UI surface. It does **not** create a second incident table or history system.

## When to use

Promote an incident to Operational Alert Hook when it materially blocks or degrades user operation and requires immediate attention.

Examples:

- website / domain down
- website degraded
- Semantic API (seo-ops-semantic) unavailable / not ready
- blocking worker / service failure
- critical integration unavailable
- important data stale/blocking

## When NOT to use

- normal success / recovery-only noise in the hook
- ordinary info chatter
- transient one-off toast
- validation / form errors
- regular business notifications that belong only in Notification Center

Info severity is normally **not** promoted unless a publisher explicitly opts into this surface.

## Developer contract

Publishers opt in through the typed display-surface API — never via ad-hoc context flags such as `context['show_banner']`.

```php
use Omnichannel\Addons\Seo\Enums\NotificationDisplaySurface;

$notifications->notify(
    eventCode: $event,
    severity: $severity,
    recipients: $recipients,
    title: $title,
    message: $message,
    // ...
    dedupKey: $dedupKey,
    displaySurfaces: NotificationDisplaySurface::withOperationalAlertHook(),
);
```

Equivalent surfaces list:

```php
displaySurfaces: [
    NotificationDisplaySurface::NotificationCenter,
    NotificationDisplaySurface::OperationalAlert,
]
```

Defaults (omit `displaySurfaces` or pass `[]`):

- Notification Center only
- **not** shown in Operational Alert Hook

Payload persistence (inside notification `data.operational`):

```json
{
  "display_surfaces": ["notification_center", "operational_alert"]
}
```

Read side for the UI:

- `OperationalAlertHookService::forUser(User $user)`
- returns active + unresolved + promoted alerts only
- ordered by severity (critical → danger → warning → info), then most recent

Shell mount (once):

- Client Core registers `PanelsRenderHook::CONTENT_BEFORE`
- thin Blade: `resources/views/filament/hooks/operational-alert-hook.blade.php`
- Livewire: `operational-alert-hook` (SEO addon)

Do **not** paste the alert component into individual pages.

## Incident lifecycle

| State | Hook behavior |
|---|---|
| active + promoted | displayed |
| dedup update / occurrence_count++ | same hook item updates |
| resolved via `OperationalNotificationService::resolve()` | removed on next render |
| recovery notification | Notification Center only (not promoted by default) |

Local UI dismissal must never resolve the operational incident. Prefer keeping the alert visible until the producer/monitor resolves it.

## Site Health — first consumer

`SiteHealthNotificationPublisher`:

- `SiteHealthDown` → Operational Alert Hook
- `SiteHealthDegraded` → Operational Alert Hook
- `SiteHealthRecovered` / resolve → removed from active hook

Site Health state machine, two-failure incident behavior, DNS/TLS/WP diagnosis, and recovery logic remain unchanged. The publisher only exposes existing operational notifications onto this shared surface.

## Semantic Service — official consumer

When `SEMANTIC_ENABLED=true`, seo-ops-semantic is a **required** runtime dependency for semantic SEO features.

```
SemanticServiceHealthMonitor / SemanticAnalyticsClient transport
      ↓
SemanticServiceHealthReporter
      ↓
SemanticServiceNotificationCapability
      ↓
SemanticServiceNotificationPublisher (SEO)
      ↓
OperationalNotificationService
      ├─ Notification Center
      └─ Operational Alert Hook
```

| Event | Code | Severity | Hook |
|---|---|---|---|
| Unavailable (connection / timeout / 502–504) | `semantic.service_unavailable` | Critical | yes |
| Degraded (`/health/ready` ready≠true) | `semantic.service_degraded` | Danger | yes |
| Recovered | `semantic.service_recovered` | Info (recovery) | resolved off hook |

Stable dedup key: `semantic-service:availability` (one active incident for the outage).

Rules:

- When semantic integration is **enabled**, failure **MUST** be surfaced — consumers must not silently invent Laravel fuzzy/heuristic fallbacks.
- When semantic is **explicitly disabled**, the monitor must not emit a false “service down” incident.
- Application 4xx (e.g. 422 validation) proves the service answered and is **not** classified as down.
- Schedule: `php artisan semantic:monitor` every five minutes (`seo-content-ai:semantic-service-monitor`).
- Manual diagnose remains `php artisan semantic:doctor` (inspect only).

Publisher: `SemanticServiceNotificationPublisher` uses `NotificationDisplaySurface::withOperationalAlertHook()`.

No second alert database. No page-specific banner.

## Ownership

| Concern | Owner |
|---|---|
| Write path / dedup / resolve | SEO `OperationalNotificationService` |
| Display surface enum | SEO `NotificationDisplaySurface` |
| Hook read model | SEO `OperationalAlertHookService` |
| Livewire + Blade alert UI | SEO addon |
| Shared Filament mount point | Client Core (`ClientCoreServiceProvider`) |
| Notification Center | Filament database notifications (unchanged) |
