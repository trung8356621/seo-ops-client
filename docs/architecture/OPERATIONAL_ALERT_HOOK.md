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
- required microservice unavailable (future)
- blocking worker / service failure (future)
- critical integration unavailable (future)
- important data stale/blocking (future)

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

## Future publishers

Example: Semantic Service Down

1. Detect failure in the owning monitor
2. Call `OperationalNotificationService::notify(...)` with `displaySurfaces: NotificationDisplaySurface::withOperationalAlertHook()`
3. Do **not** add another Blade banner

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
