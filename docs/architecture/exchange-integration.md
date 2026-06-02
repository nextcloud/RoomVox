# Exchange Integration

RoomVox can synchronize bookings with Microsoft Exchange / MS365 room mailboxes via the **Microsoft Graph API**. This page describes the integration's architecture, sync modes, webhook flow, and operational characteristics.

For user-facing setup, see [Settings → Exchange Sync](../admin/settings.md#exchange-sync-optional).

## Overview

```
┌──────────────────────────────────────────────────────┐
│                Microsoft Graph API                    │
│  Calendar events · Webhooks · Delta queries           │
└────────────────────┬─────────────────────────────────┘
                     │ OAuth2 (client credentials)
                     │ HTTPS
                     ▼
┌──────────────────────────────────────────────────────┐
│              RoomVox Backend (PHP)                    │
│                                                       │
│  ┌────────────────┐    ┌─────────────────────┐      │
│  │ GraphApiClient │    │ ExchangeSyncService │      │
│  └────────────────┘    └──────────┬──────────┘      │
│                                   │                  │
│  ┌────────────────┐    ┌──────────▼──────────┐      │
│  │ WebhookService │    │     CalDAVService    │      │
│  └────────────────┘    └─────────────────────┘      │
│                                                       │
│  Background Jobs:                                     │
│  • ExchangeSyncJob (TimedJob, 15 min)                │
│  • InitialExchangeSyncJob (QueuedJob, one-shot)      │
│  • WebhookSyncJob (QueuedJob, per webhook)           │
│  • WebhookRenewalJob (TimedJob, 12 h)                │
└──────────────────────────────────────────────────────┘
                     │
                     ▼
┌──────────────────────────────────────────────────────┐
│        Nextcloud CalDAV (oc_calendarobjects)         │
└──────────────────────────────────────────────────────┘
```

## Authentication

RoomVox connects to Microsoft Graph via **OAuth2 client credentials** — an app registration with delegated calendar permissions:

| Setting | Source |
|---|---|
| `exchange_tenantId` | Azure AD tenant ID |
| `exchange_clientId` | App registration client ID |
| `exchange_clientSecret` | App registration secret (encrypted via `ICrypto` at rest) |

Token caching is handled by `GraphApiClient` — tokens are refreshed ~5 minutes before expiry.

## Per-Room Linking

The app-wide credentials authenticate RoomVox once. Each room is then linked individually:

1. Admin sets a room's email to its Exchange mailbox address
2. RoomVox detects the change in `RoomApiController::update()`
3. Sets `initialSyncStatus = 'pending'`
4. Queues an `InitialExchangeSyncJob`
5. Frontend polls the room data every 5 s for status updates

## Sync Modes

### Full Sync (`fullSync()`)

Fetches all events from **-30 days to +365 days**, compares with the local CalDAV calendar, and creates / updates / deletes to match Exchange. Used for:

- **Initial sync** when a room is first linked
- **Manual retry** via `POST /api/rooms/{id}/exchange/initial-sync` when initial sync failed
- **Manual debug** (admin-triggered)

### Delta Sync (`pullExchangeChanges()`)

Uses Microsoft Graph's **delta query** to fetch only changed events since the last sync. This is the primary mode for ongoing synchronization. Runs every 15 minutes via `ExchangeSyncJob` (TimedJob).

Both modes build a **sync index** — a mapping of Exchange event IDs to CalDAV URIs — to efficiently match events across systems.

### Conflict Detection

When syncing from Exchange, the service respects the `showAs` property. Only events marked `busy`, `tentative`, or `oof` (out of office) create bookings that block the room. Events marked `free` or `workingElsewhere` are synced but don't cause conflicts.

## Webhook Real-Time Updates

For near-instant sync, RoomVox creates a Microsoft Graph webhook subscription per Exchange-linked room. When an event changes in Exchange, Microsoft sends a notification to the RoomVox webhook endpoint.

### Webhook Flow

```
Microsoft Graph
    │
    │ POST /api/webhook/exchange
    │ (notification payload)
    ▼
WebhookController
    │
    ├─ Validates Microsoft-supplied validation token (on subscription creation)
    ├─ Validates request signature (subsequent notifications)
    ├─ Identifies room by subscription ID lookup
    │
    └─ Either:
       ├─ Queue WebhookSyncJob for the room (default)
       └─ Run inline sync in HTTP request (when exchangeWebhookInlineSync = true)
```

### Inline Sync with Throttling

For low-latency updates, the webhook controller can optionally run the sync inline (inside the HTTP request) instead of queuing a background job. Controlled by `exchangeWebhookInlineSync`. Inline sync includes:

- **Per-room throttle** — minimum interval between syncs for one room (default 30 s)
- **Global rate limit** — caps total inline syncs per minute across all rooms to prevent overload

If a webhook arrives during the throttle window, it's queued as a background job instead. This protects RoomVox from a runaway loop where rapid Exchange changes would otherwise saturate the request thread.

### Subscription Renewal

Microsoft Graph webhook subscriptions expire after 3 days. The `WebhookRenewalJob` (TimedJob, every 12 hours) renews all subscriptions with a safety margin of at least 36 hours before expiry. If renewal fails (e.g., the secret rotated), the subscription is recreated.

## Initial Sync Protection

While the initial sync is in progress (`pending` or `syncing`), the `SchedulingPlugin` **blocks new bookings** for that room with schedule status `5.3` (temporary failure). This prevents double-bookings from users booking a slot that already exists in Exchange but hasn't been synced yet.

The organizer receives a **Room Sync In Progress** email asking to retry shortly. Once the sync completes (`initialSyncStatus = 'completed'`), bookings are accepted normally.

## Frontend Progress Indicator

The `RoomEditor.vue` shows a live indicator during initial sync:

| Status | Display |
|---|---|
| `pending` | "Exchange sync queued…" + loading spinner |
| `syncing` | "Syncing Exchange calendar…" + loading spinner |
| `completed` | Success message |
| `failed` | Error message + Retry button |

The frontend polls every 5 s while the sync is active and stops automatically when it completes or fails.

## Performance: buildSyncIndex Batching

Earlier versions of `buildSyncIndex()` performed N+1 queries: one query to list all calendar objects (metadata only), then one `getCalendarObject()` call per event to read the actual iCal data. At 50 Exchange rooms with 500 bookings each, this produced ~25,000 queries per 15-minute sync cycle.

The current implementation uses Nextcloud's `getMultipleCalendarObjects()`, which fetches calendar data in batches of 100:

```
Before:  1 + N queries  (e.g., 501 for 500 bookings)
After:   1 + ⌈N/100⌉   (e.g., 6 for 500 bookings)
```

At 50 rooms × 500 bookings = ~25,000 → ~300 queries per sync cycle. **98% reduction.**

The CalDAV time-range index on `oc_calendarobjects` (`firstoccurence`, `lastoccurence` columns) keeps conflict detection O(log n) regardless of total booking count.

## API Endpoints

### New (post-Exchange integration)

| Method | URL | Description |
|---|---|---|
| `POST` | `/api/rooms/{id}/exchange/initial-sync` | Retry initial Exchange sync for a room |
| `POST` | `/api/webhook/exchange` | Microsoft Graph webhook receiver |

### Room Exchange Properties

The room's `exchangeConfig` object exposes:

| Property | Type | Description |
|---|---|---|
| `enabled` | bool | Whether Exchange sync is configured for this room |
| `resourceEmail` | string\|null | The Exchange room mailbox address |
| `initialSyncStatus` | string\|null | `pending`, `syncing`, `completed`, `failed` |
| `initialSyncError` | string\|null | Error message when `initialSyncStatus = 'failed'` |

## Removed: Manual Force Sync

The previous manual "Force Sync" button was removed. Initial synchronization is fully automatic — triggered by saving the Exchange configuration on a room. A retry option remains if the initial sync fails.

## Test Coverage

Exchange sync has its own unit test suite (`ExchangeSyncServiceTest`, 55 tests) covering:

- Full sync vs. delta sync paths
- Conflict detection with `showAs` filtering
- `WebhookServiceTest` — subscription create/renew/delete, notification URL validation
- `WebhookControllerTest` — validation token handling, notification processing, inline sync, throttling
- `PerformanceTest` — response-time benchmarks
- `LoadSimulationTest` — scale testing at 1500 rooms × 300 bookings/hour

All tests use PHPUnit with mocked Nextcloud interfaces — no running Nextcloud instance required.

## Security Notes

- **Client secret encrypted** via `ICrypto` at rest
- **Webhook signature validation** before processing notification payloads
- **HTTPS required** for webhook notification URL — Microsoft Graph rejects HTTP endpoints
- **Subscription IDs** are random UUIDs from Microsoft — predictable IDs would allow forged notifications
- **OAuth2 tokens** cached in memory only, never persisted

## See Also

- [Backend Architecture](backend-architecture.md) — service layer overview
- [CalDAV Scheduling](caldav-scheduling.md) — how Exchange-synced bookings interact with the SchedulingPlugin
- [Admin Settings → Exchange Sync](../admin/settings.md#exchange-sync-optional) — configuration UI
- [Admin Troubleshooting](../admin/troubleshooting.md) — sync issues
