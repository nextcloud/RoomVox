# Backend Architecture

This document covers RoomVox's PHP backend in detail — storage model, virtual user accounts, service layer, and bootstrap. For the system overview see [Architecture Overview](overview.md). For the scheduling plugin specifically see [CalDAV Scheduling](caldav-scheduling.md).

## Directory Layout

```
lib/
├── AppInfo/
│   └── Application.php             ← Bootstrap, DI registration, event listener wiring
├── Controller/
│   ├── ApiTokenController.php      ← API token CRUD (admin)
│   ├── BookingApiController.php    ← Internal API: bookings
│   ├── ExchangeApiController.php   ← Internal API: Exchange config + sync ops
│   ├── LicenseController.php       ← Subscription validation
│   ├── PageController.php          ← Admin page template
│   ├── PersonalApiController.php   ← Personal Settings tabs (My Rooms / Approvals / Bookings)
│   ├── PublicApiController.php     ← Public API v1 (Bearer-token authenticated)
│   ├── RoomApiController.php       ← Internal API: rooms CRUD
│   ├── RoomGroupApiController.php  ← Internal API: room groups
│   ├── SettingsController.php      ← App-wide settings (defaults, room types, telemetry)
│   └── WebhookController.php       ← Microsoft Graph webhook receiver
├── Service/
│   ├── ApiTokenService.php         ← Bearer token management
│   ├── CalDAVService.php           ← Direct CalDavBackend access
│   ├── ImportExportService.php     ← CSV import/export (RoomVox + MS365 formats)
│   ├── LicenseService.php          ← Subscription validation
│   ├── MailService.php             ← Per-room SMTP or IMailer fallback
│   ├── PermissionService.php       ← Role-based access (Viewer/Booker/Manager)
│   ├── RoomGroupService.php        ← Room group CRUD
│   ├── RoomService.php             ← Room CRUD, virtual user lifecycle
│   ├── TelemetryService.php        ← Anonymous usage stats
│   └── Exchange/                   ← Microsoft Graph integration (see exchange-integration.md)
│       ├── ExchangeSyncService.php
│       ├── GraphApiClient.php
│       └── WebhookService.php
├── BackgroundJob/
│   ├── ExchangeSyncJob.php         ← TimedJob (15 min) — delta sync per Exchange-linked room
│   ├── InitialExchangeSyncJob.php  ← QueuedJob — one-shot full sync on first link
│   ├── TelemetryJob.php            ← TimedJob (24 h) — anonymous usage report
│   ├── WebhookRenewalJob.php       ← TimedJob (12 h) — renew Graph subscriptions
│   └── WebhookSyncJob.php          ← QueuedJob — sync triggered by webhook
├── Connector/Room/
│   ├── Room.php                    ← Implements OCP\Calendar\Room\IRoom
│   └── RoomBackend.php             ← Implements OCP\Calendar\Room\IBackend
├── Dav/
│   ├── SabrePluginListener.php     ← Injects SchedulingPlugin into Sabre server
│   └── SchedulingPlugin.php        ← Priority-99 iTIP handler (see caldav-scheduling.md)
├── Middleware/
│   └── ApiTokenMiddleware.php      ← Bearer auth for /api/v1/*
├── Settings/
│   ├── AdminSection.php            ← Settings → Administration entry
│   ├── AdminSettings.php           ← Admin settings page integration
│   ├── PersonalSection.php         ← Settings → Personal entry
│   └── PersonalSettings.php        ← Personal settings page integration
└── UserBackend/
    └── RoomUserBackend.php         ← Virtual rb_* users for CalDAV principals
```

## Storage Model: IAppConfig Only

RoomVox uses **no custom database tables**. All data lives in Nextcloud's `oc_appconfig` table under the `roomvox` app:

| Key Pattern | Content |
|---|---|
| `rooms_index` | JSON array of all room IDs |
| `room/{roomId}` | Room configuration JSON |
| `permissions/{roomId}` | Room permission JSON |
| `room_groups_index` | JSON array of all room group IDs |
| `group/{groupId}` | Room group configuration JSON |
| `group_permissions/{groupId}` | Group permission JSON |
| `roomTypes` | JSON array of room type objects |
| `defaultAutoAccept` | `'true'` / `'false'` |
| `emailEnabled` | `'true'` / `'false'` |
| `showWeekendsInCalendar` | `'true'` / `'false'` |
| `telemetry_enabled` | `'true'` / `'false'` |
| `api_tokens` | JSON array of `{prefix, hashedToken, name, scope, roomIds, expiresAt}` |
| Exchange settings | `exchange_tenantId`, `exchange_clientId`, `exchange_clientSecret`, `exchangeGloballyEnabled`, `exchangeWebhookInlineSync`, etc. |

Bookings themselves are **standard CalDAV events** in Nextcloud's calendar backend (`oc_calendarobjects`) — RoomVox doesn't shadow them.

### Why No Database

- Zero migration overhead — no schema changes between releases
- Simple deployment — no DB setup, no migration runner
- Room counts are typically tens to hundreds — key-value is efficient at this scale
- Permissions and settings are naturally document-shaped (JSON)
- Booking storage lives in the existing CalDAV layer

### Room Data Shape

```json
{
  "id": "boardroom",
  "userId": "rb_boardroom",
  "name": "Boardroom",
  "email": "boardroom@company.com",
  "roomNumber": "1.01",
  "address": "Main Building, Kerkstraat 10, , Amsterdam",
  "roomType": "meeting-room",
  "capacity": 12,
  "description": "Top-floor boardroom with full AV",
  "responsibleContact": "Anne Janssen (anne@voxcloud.nl)",
  "facilities": ["projector", "whiteboard", "videoconf"],
  "autoAccept": false,
  "active": true,
  "groupId": "building-a",
  "availabilityRules": {
    "enabled": true,
    "rules": [{ "days": [1,2,3,4,5], "startTime": "09:00", "endTime": "17:00" }]
  },
  "maxBookingHorizon": 90,
  "calendarUri": "room-rb_boardroom",
  "smtpConfig": {
    "host": "smtp.company.com",
    "port": 587,
    "username": "boardroom@company.com",
    "password": "<encrypted via ICrypto>",
    "encryption": "tls"
  },
  "exchangeConfig": {
    "enabled": false,
    "resourceEmail": null,
    "initialSyncStatus": null,
    "initialSyncError": null
  },
  "createdAt": "2026-01-15T10:30:00+00:00"
}
```

## Virtual User Accounts

Each room gets a hidden Nextcloud user with the `rb_` prefix (e.g., `rb_boardroom`). Implemented via `RoomUserBackend`:

- Registered as a Nextcloud user backend
- Cannot be used to log in (`checkPassword()` always returns false)
- Hidden from user search and listings
- Provides CalDAV principal resolution (`principals/users/rb_*`)
- Display name matches the room name

This pattern gives rooms CalDAV principals without creating real user accounts that count against licenses or appear in user pickers.

### Why Virtual Users?

Sabre/Nextcloud's CalDAV scheduling requires a `principals/users/<uid>` URI to resolve attendees. Without a corresponding Nextcloud user, `getPrincipalByUri()` fails. Virtual users provide the principal URI without the side effects of a real account.

## Service Layer

Controllers depend on services. Services depend on each other. Key services:

| Service | Responsibility |
|---|---|
| `RoomService` | Room CRUD, manages virtual user accounts, room indexing |
| `RoomGroupService` | Room group CRUD |
| `PermissionService` | Role resolution (Viewer/Booker/Manager) with group inheritance |
| `CalDAVService` | Direct `CalDavBackend` access for booking CRUD, conflict detection |
| `MailService` | Per-room SMTP or IMailer fallback, ICrypto-encrypted passwords |
| `ImportExportService` | CSV parsing (RoomVox + MS365 formats), duplicate detection |
| `ApiTokenService` | Bearer token CRUD, hashing, scope checks |
| `TelemetryService` | Aggregates and ships anonymous usage data |
| `LicenseService` | Validates VoxCloud subscription keys |
| `Exchange/ExchangeSyncService` | Microsoft Graph sync (see [Exchange Integration](exchange-integration.md)) |
| `Exchange/GraphApiClient` | OAuth2 + Graph HTTP client |
| `Exchange/WebhookService` | Graph webhook subscription management |

### Circular Dependency: PermissionService ↔ RoomService

`PermissionService` needs `RoomService` to read room data (for group inheritance). `RoomService` needs `PermissionService` for permission-aware queries. Constructor DI would create a circular dependency.

The cycle is broken via **late injection in `Application::boot()`**:

```php
public function boot(IBootContext $context): void {
    $permissionService = $container->get(PermissionService::class);
    $roomService = $container->get(RoomService::class);
    $permissionService->setRoomService($roomService);
}
```

This pattern is documented in `Application.php` and unit tests verify the wiring.

## Bootstrap

`lib/AppInfo/Application.php` runs in two phases:

### Registration Phase (`register()`)

1. Register `RoomBackend` as a CalDAV room backend (rooms appear as resources in calendar apps)
2. Register `SabrePluginListener` — Sabre's plugin-injection event listener
3. Register `ApiTokenMiddleware` for `/api/v1/*` endpoints
4. Configure DI bindings, event listeners

### Boot Phase (`boot()`)

1. Register `RoomUserBackend` with Nextcloud's user manager
2. Wire late injection between `PermissionService` and `RoomService` (cycle break)
3. Validate signed Exchange credentials are decryptable (if Exchange enabled)

## API Layers

Two API layers defined in `appinfo/routes.php`:

### Internal API (`/api/*`)

- Session-authenticated (Nextcloud login required)
- Used by the admin Vue frontend
- Examples: `/api/rooms`, `/api/bookings`, `/api/all-bookings`, `/api/personal/*`

### Public API v1 (`/api/v1/*`)

- Bearer-token authenticated via `ApiTokenMiddleware`
- For external integrations (displays, kiosks, third-party systems)
- Examples: `/api/v1/rooms`, `/api/v1/rooms/{id}/bookings`, `/api/v1/rooms/{id}/calendar.ics`

See [API Reference](api-reference.md) and [Public API](../features/public-api.md).

## Permission Model

Three roles with hierarchical capabilities:

```
Manager > Booker > Viewer
```

Permissions are stored at two levels (`permissions/{roomId}` and `group_permissions/{groupId}`):

```json
{
  "viewers":  [{ "type": "user",  "id": "alice" },
               { "type": "group", "id": "all-staff" }],
  "bookers":  [{ "type": "user",  "id": "bob" }],
  "managers": [{ "type": "group", "id": "facilities" }]
}
```

Effective permissions are the **union** of room-level + group-level entries. Nextcloud administrators always have full access (admin bypass).

### CalDAV Visibility

**Group** entries are also published as `group_restrictions` in the CalDAV room metadata — Nextcloud Calendar only shows rooms to users in at least one of those groups. **User** entries are enforced at booking time by the scheduling plugin but don't gate CalDAV visibility — users see all rooms whose groups they're in, even if they're not individually listed as a Booker.

## Telemetry

The `TelemetryService` collects anonymous usage data and the `TelemetryJob` (TimedJob, 24-hour interval with stable per-instance jitter up to 2 hours) ships it. See [Telemetry](../admin/telemetry.md) for the complete data inventory.

Data is sent to the VoxCloud telemetry server; 15-second timeout per request; failed reports silently retry on the next interval.

## Security Notes

- **CSRF protection** on all state-changing internal API endpoints
- **Bearer token authentication** on Public API v1 with strict scope enforcement
- **HTML sanitization** on user-supplied fields (room descriptions, etc.)
- **ICrypto-encrypted** SMTP passwords and Exchange client secrets at rest
- **Server-side group filtering** on bookings — users only see bookings for rooms they have access to
- **PartialMatch warning logs** (v1.1.1+) when iTIP sender resolves to zero or multiple users — surfaces LDAP/AD duplicates immediately

## See Also

- [Architecture Overview](overview.md) — high-level diagram and component map
- [CalDAV Scheduling](caldav-scheduling.md) — SchedulingPlugin deep dive
- [Exchange Integration](exchange-integration.md) — Microsoft Graph sync
- [API Reference](api-reference.md) — endpoint catalogue
- [NC 34 Compatibility](nc34-compatibility.md) — version audit
