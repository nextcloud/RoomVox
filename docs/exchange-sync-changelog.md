# Exchange Sync — Changes & Optimizations

Overview of all changes, optimizations, and new features in the Exchange sync integration (`exchange-sync-unit-tests` branch).

## Exchange Sync Integration

### Microsoft Graph API Client

**File:** `lib/Service/Exchange/GraphApiClient.php`

RoomVox connects to Microsoft Exchange via the Microsoft Graph API using OAuth2 client credentials. The client handles authentication, token caching, and all Graph API communication.

Configuration is stored in Nextcloud's app settings:
- Tenant ID, Client ID, Client Secret
- Global enable/disable toggle

The client supports:
- Calendar event delta queries (incremental sync)
- Calendar view queries (time-range based)
- Webhook subscription management
- Connection testing and resource validation

### Two-Way Sync Service

**File:** `lib/Service/Exchange/ExchangeSyncService.php`

The sync service reconciles events between Exchange and the RoomVox CalDAV calendar. It supports two sync modes:

1. **Full sync** (`fullSync()`) — Fetches all events from -30 days to +365 days, compares with the local CalDAV calendar, and creates/updates/deletes to match Exchange.

2. **Delta sync** (`pullExchangeChanges()`) — Uses Microsoft Graph's delta query to fetch only changed events since the last sync. This is the primary mode for ongoing synchronization.

Both modes build a **sync index** — a mapping of Exchange event IDs to CalDAV URIs — to efficiently match events across systems.

#### Conflict Detection

When syncing from Exchange, the service respects the `showAs` property. Only events marked as `busy`, `tentative`, or `oof` (out of office) create bookings that block the room. Events marked `free` or `workingElsewhere` are synced but don't cause conflicts.

### Background Jobs

Three background jobs handle Exchange synchronization:

| Job | Type | Purpose |
|-----|------|---------|
| `ExchangeSyncJob` | TimedJob (15 min) | Recurring delta sync for all Exchange-enabled rooms |
| `InitialExchangeSyncJob` | QueuedJob | One-shot full sync when a room is first linked to Exchange |
| `WebhookSyncJob` | QueuedJob | Processes a webhook notification for a single room |

### Webhook Real-Time Updates

**Files:** `lib/Service/Exchange/WebhookService.php`, `lib/Controller/WebhookController.php`

For near-instant sync, RoomVox creates Microsoft Graph webhook subscriptions for each Exchange-enabled room. When an event changes in Exchange, Microsoft sends a notification to the RoomVox webhook endpoint.

The webhook flow:
1. Microsoft Graph sends POST to `/api/webhook/exchange`
2. `WebhookController` validates the request and identifies the room by subscription ID
3. A `WebhookSyncJob` is queued for the affected room
4. The background job runs `pullExchangeChanges()` for that room

Webhook subscriptions expire after 3 days. The `WebhookRenewalJob` (TimedJob, every 12 hours) renews all subscriptions before expiry.

**Inline sync with throttling:** For low-latency updates, the webhook controller can optionally run the sync inline (in the HTTP request) instead of queuing a background job. This is controlled by the `exchangeWebhookInlineSync` setting and includes both per-room throttling (configurable interval) and a global rate limit to prevent overload.

## Automatic Initial Sync

When a room is linked to Exchange for the first time (or the resource email changes), RoomVox automatically imports all existing Exchange events.

### Flow

```
Admin saves room with Exchange config
    │
    ├─ RoomApiController detects Exchange was just enabled
    │  (or resource email changed)
    │
    ├─ Sets initialSyncStatus = 'pending'
    │
    ├─ Queues InitialExchangeSyncJob
    │
    │   Background job runs:
    │   ├─ Sets status = 'syncing'
    │   ├─ Runs fullSync() (all events -30d to +365d)
    │   ├─ Sets status = 'completed'
    │   └─ Creates webhook subscription
    │
    └─ Frontend polls every 5s for status updates
```

### Booking Protection During Sync

While the initial sync is in progress (`pending` or `syncing`), the SchedulingPlugin blocks new bookings for that room with schedule status `5.3` (temporary failure). This prevents double-bookings from users booking a slot that already exists in Exchange but hasn't been synced yet.

Once the sync completes, bookings are accepted normally.

### Frontend Progress Indicator

The RoomEditor shows a live progress indicator during initial sync:
- **Pending** — "Exchange sync queued..." with loading spinner
- **Syncing** — "Syncing Exchange calendar..." with loading spinner
- **Completed** — Success message
- **Failed** — Error message with retry button

The frontend polls the room data every 5 seconds while the sync is active and stops automatically when it completes or fails.

### Removed: Manual Force Sync

The previous manual "Force Sync" button has been removed. Initial synchronization is now fully automatic — triggered by saving the Exchange configuration. A retry option is available if the initial sync fails.

## Performance Optimizations

### buildSyncIndex() — Batch Fetch

**Problem:** The `buildSyncIndex()` method performed N+1 database queries: one query to list all calendar objects (metadata only), then one `getCalendarObject()` call per event to read the actual iCal data. At 50 Exchange rooms with 500 bookings each, this produced 25,000+ queries per 15-minute sync cycle.

**Solution:** Replaced individual `getCalendarObject()` calls with Nextcloud's `getMultipleCalendarObjects()`, which fetches calendar data in batches of 100.

```
Before:  1 + N queries  (e.g., 501 queries for 500 bookings)
After:   1 + ⌈N/100⌉   (e.g., 6 queries for 500 bookings)
```

At scale (50 rooms × 500 bookings), this reduces total queries from ~25,000 to ~300 per sync cycle — a **98% reduction**.

### CalDAV Time-Range Index

Conflict detection during booking uses Nextcloud's CalDAV time-range query on `oc_calendarobjects`. This table has database indexes on `firstoccurence` and `lastoccurence` (managed by Nextcloud's own migrations), making the lookup O(log n) regardless of how many bookings exist.

A busy room with many bookings actually resolves faster — the query finds a conflicting event sooner and returns `DECLINED` immediately without scanning further.

## Test Suite

### Unit Tests (176 tests)

Comprehensive PHPUnit test suite covering all Exchange sync functionality:

| Test Class | Tests | Coverage |
|------------|-------|----------|
| `ExchangeSyncServiceTest` | 55 | Full sync, delta sync, conflict detection, showAs filtering |
| `GraphApiClientTest` | 20 | Authentication, token caching, API calls, error handling |
| `WebhookServiceTest` | 15 | Subscription create/renew/delete, notification URL |
| `WebhookControllerTest` | 20 | Validation token, notification processing, inline sync, throttling |
| `CalDAVServiceTest` | 30 | Calendar provisioning, booking CRUD, conflict detection |
| `SettingsControllerTest` | 10 | Settings read/write, Exchange settings |
| `PerformanceTest` | 15 | Response time benchmarks for all critical paths |
| `LoadSimulationTest` | 11 | Scale testing: 1500 rooms × 300 bookings/hour |

### Integration Tests (50 tests)

End-to-end tests running against the live Nextcloud instance:
- Full booking lifecycle (create → accept → cancel)
- Exchange sync roundtrip
- Permission enforcement
- CalDAV delivery verification

## API Changes

### New Endpoint

| Method | URL | Description |
|--------|-----|-------------|
| POST | `/api/rooms/{id}/exchange/initial-sync` | Retry initial Exchange sync for a room |

### Removed Endpoint

| Method | URL | Description |
|--------|-----|-------------|
| POST | `/api/rooms/{id}/exchange/sync` | Manual force sync (replaced by automatic initial sync) |

### New Room Properties

The room's `exchangeConfig` object now includes:

| Property | Type | Description |
|----------|------|-------------|
| `initialSyncStatus` | string\|null | `pending`, `syncing`, `completed`, or `failed` |
| `initialSyncError` | string\|null | Error message if initial sync failed |

## File Changes Summary

| File | Change |
|------|--------|
| `lib/BackgroundJob/InitialExchangeSyncJob.php` | **New** — One-shot job for initial Exchange sync |
| `lib/BackgroundJob/ExchangeSyncJob.php` | **New** — Recurring 15-min delta sync |
| `lib/BackgroundJob/WebhookSyncJob.php` | **New** — Webhook-triggered sync |
| `lib/BackgroundJob/WebhookRenewalJob.php` | **New** — Webhook subscription renewal |
| `lib/Service/Exchange/ExchangeSyncService.php` | **New** — Core sync logic + buildSyncIndex optimization |
| `lib/Service/Exchange/GraphApiClient.php` | **New** — Microsoft Graph API client |
| `lib/Service/Exchange/WebhookService.php` | **New** — Webhook subscription management |
| `lib/Controller/ExchangeApiController.php` | **New** — Exchange admin API endpoints |
| `lib/Controller/WebhookController.php` | **New** — Webhook receiver endpoint |
| `lib/Controller/RoomApiController.php` | **Modified** — Triggers initial sync on Exchange link |
| `lib/Service/RoomService.php` | **Modified** — `updateExchangeInitialSyncStatus()`, preserves sync fields |
| `lib/Dav/SchedulingPlugin.php` | **Modified** — Blocks bookings during initial sync |
| `src/views/RoomEditor.vue` | **Modified** — Progress indicator, removed Force Sync |
| `src/services/api.js` | **Modified** — New API calls for Exchange sync |
| `appinfo/routes.php` | **Modified** — Exchange API routes |
| `appinfo/info.xml` | **Modified** — Background job registration |
