# API Reference

RoomVox has two types of API:

1. **Internal API** (`/api/...`) — Used by the admin interface. Requires a Nextcloud session (cookie-based authentication).
2. **Public API v1** (`/api/v1/...`) — For external integrations. Requires a Bearer token (see [API Tokens](#api-tokens)).

All endpoints are relative to `/apps/roomvox/` (or `/index.php/apps/roomvox/` for POST/PUT/DELETE requests).

---

## Public API v1

The public API is designed for external systems: room displays, kiosks, digital signage, Power Automate, Zapier, and custom applications. All endpoints require a Bearer token.

### Authentication

All v1 endpoints require an API token sent as a Bearer token:

```
Authorization: Bearer rvx_abc123def456...
```

Tokens are managed in the RoomVox admin panel under **Settings > API Tokens**.

### Scopes

| Scope | Level | Access |
|-------|-------|--------|
| `read` | 1 | View rooms, availability, bookings, iCal feed |
| `book` | 2 | Everything in read + create and cancel bookings |
| `admin` | 3 | Everything in book + statistics |

Scopes are hierarchical: a `book` token can do everything a `read` token can.

Tokens can optionally be restricted to specific rooms. If no room restriction is set, the token has access to all rooms.

### Room Status

#### Get Current Room Status

```
GET /api/v1/rooms/{id}/status
```

**Scope:** `read`

Returns the real-time status of a room: free, busy, or unavailable (outside availability rules).

**Response:**
```json
{
  "room": {
    "id": "meeting-room-1",
    "name": "Meeting Room 1",
    "email": "meeting-room-1@roomvox.local",
    "capacity": 12,
    "roomNumber": "2.17",
    "roomType": "meeting-room",
    "facilities": ["projector", "whiteboard"],
    "location": "Building A, Heidelberglaan 8, 3584 CS Utrecht",
    "autoAccept": true,
    "active": true
  },
  "status": "busy",
  "currentBooking": {
    "title": "Team standup",
    "organizer": "Jan de Vries",
    "start": "2026-02-15T09:00:00+01:00",
    "end": "2026-02-15T09:30:00+01:00",
    "minutesRemaining": 12
  },
  "nextBooking": {
    "title": "Sprint planning",
    "organizer": "Maria Schmidt",
    "start": "2026-02-15T10:00:00+01:00",
    "end": "2026-02-15T11:00:00+01:00"
  },
  "freeUntil": null,
  "todayBookings": [
    {
      "title": "Team standup",
      "start": "2026-02-15T09:00:00+01:00",
      "end": "2026-02-15T09:30:00+01:00",
      "status": "accepted"
    }
  ]
}
```

**Status values:**

| Status | Meaning |
|--------|---------|
| `free` | Room is available now |
| `busy` | Room is currently occupied |
| `unavailable` | Outside configured availability hours |

When `status` is `free` and there is a next booking, `freeUntil` contains the start time of the next booking.

#### Get Room Availability

```
GET /api/v1/rooms/{id}/availability
```

**Scope:** `read`

Returns time slots for a given date showing which periods are free or busy.

**Query parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `date` | string | today | Date in `YYYY-MM-DD` format |
| `from` | string | — | Custom range start (ISO 8601), overrides `date` |
| `to` | string | — | Custom range end (ISO 8601), overrides `date` |

> **Note:** When using `from`/`to`, the date range must not exceed 365 days. Invalid dates return a 400 error.

**Response:**
```json
{
  "room": { "id": "meeting-room-1", "name": "Meeting Room 1" },
  "date": "2026-02-15",
  "availabilityRules": {
    "start": "08:00",
    "end": "18:00",
    "days": ["mon", "tue", "wed", "thu", "fri"]
  },
  "slots": [
    { "start": "08:00", "end": "09:00", "status": "free" },
    { "start": "09:00", "end": "09:30", "status": "busy", "title": "Team standup" },
    { "start": "09:30", "end": "10:00", "status": "free" },
    { "start": "10:00", "end": "11:00", "status": "busy", "title": "Sprint planning" },
    { "start": "11:00", "end": "18:00", "status": "free" }
  ]
}
```

### Rooms

#### List Rooms

```
GET /api/v1/rooms
```

**Scope:** `read`

Returns all rooms accessible to the token. If the token has room restrictions, only those rooms are returned.

**Query parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `active` | string | Filter by active status (`true` or `false`) |
| `type` | string | Filter by room type (e.g., `meeting-room`) |
| `capacity_min` | int | Minimum capacity |

**Response:**
```json
[
  {
    "id": "meeting-room-1",
    "name": "Meeting Room 1",
    "email": "meeting-room-1@roomvox.local",
    "capacity": 12,
    "roomNumber": "2.17",
    "roomType": "meeting-room",
    "facilities": ["projector", "whiteboard"],
    "description": "Large meeting room on 2nd floor",
    "responsibleContact": "Anne Janssen (anne@voxcloud.nl)",
    "location": "Building A, Heidelberglaan 8, 3584 CS Utrecht",
    "autoAccept": true,
    "active": true
  }
]
```

The `responsibleContact` field (since 1.1.0) is a free-text string admins use to tell viewers who to approach when they can't book the room themselves. Empty string when not configured.

#### Get Room Details

```
GET /api/v1/rooms/{id}
```

**Scope:** `read`

**Response:** Single room object (same structure as list items).

### Bookings

#### List Bookings for a Room

```
GET /api/v1/rooms/{id}/bookings
```

**Scope:** `read`

**Query parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `from` | string | Start date (ISO 8601) |
| `to` | string | End date (ISO 8601) |
| `status` | string | Filter: `accepted`, `pending`, or `declined` |

> **Note:** The date range must not exceed 365 days. Invalid dates return a 400 error.

**Response:**
```json
[
  {
    "uid": "abc123-def456",
    "title": "Team Meeting",
    "start": "2026-03-01T10:00:00+01:00",
    "end": "2026-03-01T11:00:00+01:00",
    "organizer": "Alice",
    "status": "accepted",
    "room": { "id": "meeting-room-1", "name": "Meeting Room 1" }
  }
]
```

#### Create Booking

```
POST /api/v1/rooms/{id}/bookings
```

**Scope:** `book`

**Request body:**
```json
{
  "title": "Team meeting",
  "start": "2026-02-15T14:00:00+01:00",
  "end": "2026-02-15T15:00:00+01:00",
  "organizer": "j.devries@company.com",
  "description": "Weekly team sync"
}
```

`title`, `start`, and `end` are required. `organizer` and `description` are optional.

**Response (201):**
```json
{
  "uid": "abc-123-def",
  "title": "Team meeting",
  "start": "2026-02-15T14:00:00+01:00",
  "end": "2026-02-15T15:00:00+01:00",
  "status": "accepted",
  "room": { "id": "meeting-room-1", "name": "Meeting Room 1" }
}
```

The `status` depends on the room's auto-accept setting: `accepted` if auto-accept is on, `pending` if manual approval is required.

**Error responses:**

| Status | Body | Meaning |
|--------|------|---------|
| 400 | `{"error": "title, start, and end are required"}` | Missing fields |
| 400 | `{"error": "Invalid date format for start or end"}` | Unparseable dates |
| 400 | `{"error": "End time must be after start time"}` | End before or equal to start |
| 409 | `{"error": "Room is already booked during this time"}` | Scheduling conflict |
| 422 | `{"error": "Booking is outside available hours"}` | Outside availability rules |
| 422 | `{"error": "Booking exceeds maximum booking horizon"}` | Too far in advance |

#### Cancel Booking

```
DELETE /api/v1/rooms/{id}/bookings/{uid}
```

**Scope:** `book`

**Response:**
```json
{ "status": "ok" }
```

### iCalendar Feed

#### Get Room Calendar Feed

```
GET /api/v1/rooms/{id}/calendar.ics
```

**Scope:** `read`

Returns an iCalendar (.ics) feed with all accepted bookings for the room. Compatible with any calendar application, room display, or digital signage system.

**Recurring events:** master VEVENTs are passed through with `RRULE`, `EXDATE`, and any `RECURRENCE-ID` overrides intact. Clients expand the recurrences themselves — RoomVox does not pre-expand the series, which would produce duplicate UIDs and cause RFC 5545–compliant clients to deduplicate down to a single event.

**Query parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `from` | string | unbounded | Optional start of date range (ISO 8601). Filters non-recurring events only — events with an RRULE always pass through |
| `to` | string | unbounded | Optional end of date range (ISO 8601). Filters non-recurring events only |

**Response:**
```
Content-Type: text/calendar; charset=utf-8

BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//RoomVox//Nextcloud//EN
X-WR-CALNAME:Meeting Room 1
BEGIN:VEVENT
UID:abc-123-def
DTSTART;TZID=Europe/Amsterdam:20260215T130000
DTEND;TZID=Europe/Amsterdam:20260215T140000
SUMMARY:Team standup
RRULE:FREQ=WEEKLY;BYDAY=MO
ORGANIZER;CN=Jan de Vries:mailto:j.devries@company.com
LOCATION:Building A, Heidelberglaan 8, 3584 CS Utrecht
STATUS:CONFIRMED
END:VEVENT
END:VCALENDAR
```

**Use cases:**
- Room displays (SyncSign, Joan, Crestron) can subscribe to this feed
- Calendar apps (Google Calendar, Apple Calendar, Thunderbird) can add as a read-only subscription
- Digital signage can poll this URL periodically

> **Note:** external calendar apps cannot send an `Authorization` header when subscribing to a URL. For those, use the secret-authenticated public feed below instead of this Bearer route.

#### Get Room Calendar Feed (public, secret-authenticated)

```
GET /api/v1/rooms/{id}/feed/{secret}/calendar.ics
```

**Auth:** per-room feed secret in the URL path — **no Bearer token**. This is the URL external calendar clients and signage displays subscribe to.

Returns the same iCalendar output as the Bearer feed above (identical byte-for-byte). The feed is opt-in per room: an admin or room manager enables it and obtains the URL from the room editor (or via `POST /api/rooms/{id}/feed`). The secret is read-only and scoped to a single room.

**Security:**
- The secret grants **read-only** access to one room's bookings and nothing else — it cannot book or administer.
- If a URL leaks, regenerate it (rotating the secret immediately invalidates the old URL); other rooms and API tokens are unaffected.
- The endpoint is brute-force protected against secret enumeration and compares secrets in constant time.
- The feed exposes booking titles and organizers — share the URL only with trusted parties.

**Responses:**
- `200` with `Content-Type: text/calendar` and a `VCALENDAR` body on a valid secret.
- On an unknown, disabled, or mismatched secret: an empty `.ics` (no booking data, existence of the room is not revealed) and the request is throttled.

### Statistics

#### Get Usage Statistics

```
GET /api/v1/statistics
```

**Scope:** `admin`

Returns room and booking statistics with per-room utilization data.

**Query parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `from` | string | 30 days ago | Start date (`YYYY-MM-DD`) |
| `to` | string | today | End date (`YYYY-MM-DD`) |
| `room` | string | — | Filter by room ID |

> **Note:** The date range must not exceed 365 days. If the token is restricted to specific rooms, only those rooms are included in the statistics.

**Response:**
```json
{
  "period": { "from": "2026-02-01", "to": "2026-02-15" },
  "rooms": {
    "total": 15,
    "active": 12,
    "byType": { "meeting-room": 8, "studio": 2, "lecture-hall": 2 }
  },
  "bookings": {
    "total": 342,
    "accepted": 298,
    "declined": 22,
    "pending": 12,
    "cancelled": 10
  },
  "utilization": [
    {
      "roomId": "meeting-room-1",
      "roomName": "Meeting Room 1",
      "totalHoursBooked": 86.5,
      "totalHoursAvailable": 160,
      "utilizationPercent": 54.1,
      "bookingCount": 48
    }
  ]
}
```

### Public API Error Responses

| Status | Meaning |
|--------|---------|
| 400 | Invalid input (bad dates, missing fields, end before start, date range > 365 days) |
| 401 | Missing or invalid Bearer token |
| 403 | Token scope insufficient, or token has no access to this room |
| 404 | Room or booking not found |
| 409 | Scheduling conflict |
| 422 | Booking validation failed (outside hours, beyond horizon) |
| 500 | Server error |

**Error format:**
```json
{
  "error": "Description of the error"
}
```

### Quick Start Example

```bash
# Create a token in the admin UI, then:

# List all rooms
curl -H "Authorization: Bearer rvx_your_token_here" \
  https://cloud.example.com/apps/roomvox/api/v1/rooms

# Check if a room is free
curl -H "Authorization: Bearer rvx_your_token_here" \
  https://cloud.example.com/apps/roomvox/api/v1/rooms/meeting-room-1/status

# Check availability for tomorrow
curl -H "Authorization: Bearer rvx_your_token_here" \
  "https://cloud.example.com/apps/roomvox/api/v1/rooms/meeting-room-1/availability?date=2026-02-16"

# Create a booking (note: use /index.php/ prefix for POST)
curl -X POST \
  -H "Authorization: Bearer rvx_your_token_here" \
  -H "Content-Type: application/json" \
  -d '{"title":"Team sync","start":"2026-02-16T10:00:00+01:00","end":"2026-02-16T11:00:00+01:00"}' \
  https://cloud.example.com/index.php/apps/roomvox/api/v1/rooms/meeting-room-1/bookings

# Subscribe to iCal feed
curl -H "Authorization: Bearer rvx_your_token_here" \
  https://cloud.example.com/apps/roomvox/api/v1/rooms/meeting-room-1/calendar.ics
```

---

## API Tokens

Token management endpoints for administrators. These use Nextcloud session authentication (not Bearer tokens).

### List Tokens

```
GET /api/tokens
```

**Required:** Admin

**Response:**
```json
[
  {
    "id": "tok_abc123",
    "name": "Lobby Display",
    "scope": "read",
    "roomIds": [],
    "createdAt": "2026-02-15T10:00:00+01:00",
    "lastUsedAt": "2026-02-15T14:30:00+01:00",
    "expiresAt": null
  }
]
```

### Create Token

```
POST /api/tokens
```

**Required:** Admin

**Request body:**
```json
{
  "name": "Lobby Display",
  "scope": "read",
  "roomIds": ["meeting-room-1", "meeting-room-2"],
  "expiresAt": "2026-12-31T23:59:59+01:00"
}
```

`name` is required. `scope` defaults to `read`. `roomIds` and `expiresAt` are optional.

**Response (201):**
```json
{
  "id": "tok_abc123",
  "name": "Lobby Display",
  "token": "rvx_aBcDeFgHiJkLmNoPqRsTuVwXyZ0123456789ab",
  "scope": "read",
  "roomIds": ["meeting-room-1", "meeting-room-2"],
  "createdAt": "2026-02-15T10:00:00+01:00",
  "lastUsedAt": null,
  "expiresAt": "2026-12-31T23:59:59+01:00"
}
```

> The `token` field is only returned on creation. Store it immediately — it cannot be retrieved later.

### Delete Token

```
DELETE /api/tokens/{id}
```

**Required:** Admin

**Response:**
```json
{ "status": "ok" }
```

---

## Import/Export

Bulk room management via CSV files. All endpoints require admin access.

### Export Rooms

```
GET /api/rooms/export
```

**Required:** Admin

A non-admin caller does not get a `403` here: the response is an empty CSV named `error.csv` with HTTP 200. Check the filename, not the status code.

Downloads all rooms as a CSV file with the following columns:

| Column | Description |
|--------|-------------|
| `name` | Room name |
| `email` | Room email address |
| `capacity` | Number of seats |
| `roomNumber` | Room/floor number |
| `roomType` | Room type ID |
| `building` | Building name |
| `street` | Street address |
| `postalCode` | Postal/ZIP code |
| `city` | City |
| `facilities` | Comma-separated facility list |
| `description` | Room description |
| `autoAccept` | `true` or `false` |
| `active` | `true` or `false` |

### Download Sample CSV

```
GET /api/rooms/sample-csv
```

**Required:** Admin session (framework-level; the method itself performs no check)

Returns a static template with the expected headers and one example row. It contains no instance data.

Downloads a sample CSV file with headers and one example row. Useful as a template for creating import files.

### Import Preview

```
POST /api/rooms/import/preview
```

**Required:** Admin

Upload a CSV file to preview what will happen before importing. Supports both RoomVox and MS365/Exchange formats (auto-detected). Maximum file size: **5 MB**.

**Request:** `multipart/form-data` with a `file` field.

**Response:**
```json
{
  "columns": ["name", "email", "capacity", "roomNumber", "..."],
  "rows": [
    {
      "line": 2,
      "data": {
        "name": "Meeting Room 1",
        "email": "room1@company.com",
        "capacity": "12",
        "roomType": "meeting-room"
      },
      "action": "create",
      "matchedId": null,
      "matchedName": null,
      "errors": []
    },
    {
      "line": 3,
      "data": { "name": "Existing Room", "..." : "..." },
      "action": "update",
      "matchedId": "existing-room-id",
      "matchedName": "Existing Room",
      "errors": []
    }
  ],
  "detected_format": "roomvox"
}
```

**Actions:** `create` (new room) or `update` (matches existing room by email or name).

### Import Rooms

```
POST /api/rooms/import
```

**Required:** Admin

**Request:** `multipart/form-data` with `file` and `mode` fields.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `file` | file | — | CSV file (required, max 5 MB) |
| `mode` | string | `create` | `create` (skip existing) or `update` (create + update existing) |
| `enableExchangeSync` | bool | `false` | Turn on Exchange sync for imported rooms that carry a resource mailbox |

**Response:**
```json
{
  "created": 5,
  "updated": 2,
  "skipped": 1,
  "errors": [
    {
      "line": 4,
      "name": "Bad Room",
      "errors": ["Room name is required"]
    }
  ]
}
```

### Supported CSV Formats

**RoomVox format** — Standard CSV with the column names listed above.

**MS365/Exchange format** — Exported via `Get-EXOMailbox | Get-Place | Export-Csv`. Column mapping:

| MS365 Column | RoomVox Field |
|--------------|---------------|
| `DisplayName` | name |
| `PrimarySmtpAddress` / `EmailAddress` | email |
| `Capacity` / `ResourceCapacity` | capacity |
| `Floor` / `FloorLabel` | roomNumber |
| `Building` | building |
| `City` | city |
| `Tags` | facilities |
| `IsWheelchairAccessible` | wheelchair facility |

The format is automatically detected based on column names.

---

## Internal API

The internal API is used by the RoomVox admin interface. It requires Nextcloud session authentication (cookies + CSRF token).

### Conventions

Three things are easy to get wrong here, so they are worth stating once.

**Collections are returned as bare JSON arrays, not wrapped in an object.** `GET /api/rooms` returns `[{...}, {...}]`, not `{"rooms": [...]}`. The same holds for room bookings, room groups, sharees, the personal endpoints and the token list. The only endpoint that wraps a collection is `GET /api/all-bookings`, which has to, because it also carries a `stats` object.

**Most endpoints require an administrator.** Nextcloud's controller layer requires an admin session unless a method is marked `#[NoAdminRequired]`. In the internal API only `GET /api/rooms`, `GET /api/all-bookings` and the three `/api/personal/*` endpoints are reachable by non-admins; those filter on the caller's own permissions. Everything else is admin-only, and most of them check again inside the method. Per-endpoint requirements below say "Admin" when both apply.

**Errors are not uniform across the API.** There are three shapes:

| Shape | Used by |
|---|---|
| `{"error": "Description"}` | Everything except the two below |
| `{"success": false, "message": "Description"}` | `/api/license/*` and `/api/settings/license` |
| Empty body, status only | `POST /api/webhook/exchange` |

Some endpoints also report failure with **HTTP 200** and a flag in the body rather than a 4xx status — see [Soft failures](#soft-failures).

## Rooms

### List All Rooms

```
GET /api/rooms
```

Returns every room the caller may at least view, each with permission flags for that caller. Admins see all rooms.

**Required:** any logged-in user (`401` without a session)

**Response:** a bare JSON array — there is no `rooms` wrapper.

```json
[
  {
    "id": "meeting-room-1",
    "name": "Meeting Room 1",
    "roomNumber": "2.17",
    "floor": "2",
    "address": "Main Building, Kerkstraat 10, 1017 AA, Amsterdam",
    "roomType": "meeting-room",
    "capacity": 10,
    "email": "meeting-room-1@roomvox.local",
    "description": "Corner room with projector",
    "responsibleContact": "facilities@company.com",
    "facilities": ["projector", "whiteboard"],
    "autoAccept": true,
    "active": true,
    "groupId": "building-a",
    "availabilityRules": {},
    "maxBookingHorizon": null,
    "calendarUri": "room-meeting-room-1",
    "smtpConfig": {},
    "exchangeConfig": {},
    "feedEnabled": false,
    "createdAt": "2026-02-20T09:14:00+01:00",
    "canView": true,
    "canBook": true,
    "canManage": false
  }
]
```

Two fields are deliberately not what the stored room holds:

- `smtpConfig.password` is always `"***"` when a password is set.
- `feedSecret` is stripped entirely and replaced by the boolean `feedEnabled`. The subscribe URL is only handed out by `GET /api/rooms/{id}` and `POST /api/rooms/{id}/feed`, because this list is reachable by viewers.

### Create Room

```
POST /api/rooms
```

**Required:** Admin

**Request body:**
```json
{
  "name": "Meeting Room 1",
  "roomNumber": "2.17",
  "capacity": 10,
  "roomType": "meeting-room",
  "address": "Main Building, Kerkstraat 10, Amsterdam",
  "description": "Corner room with projector",
  "responsibleContact": "Anne Janssen (anne@voxcloud.nl)",
  "facilities": ["projector", "whiteboard", "videoconf"],
  "autoAccept": true,
  "floor": "2",
  "email": "room1@company.com",
  "availabilityRules": {
    "enabled": true,
    "rules": [
      { "days": [1,2,3,4,5], "startTime": "08:00", "endTime": "18:00" }
    ]
  },
  "maxBookingHorizon": 90,
  "smtpConfig": {
    "host": "smtp.company.com",
    "port": 587,
    "username": "room1@company.com",
    "password": "secret",
    "encryption": "tls"
  }
}
```

Only `name` is required. All other fields are optional. The `address` field uses the 4-part comma-separated format (`Building, Street, Postal code, City`) — empty parts are preserved so partial addresses round-trip correctly. The `responsibleContact` field (since 1.1.0, max 255 chars) is visible to every user with view-permission in Personal Settings → My Rooms.

**Response:** `201 Created` with the created room object.

> **`groupId` is ignored on create.** The controller does not read it, so a new room is always created without a room group regardless of what is sent. Assign the group with `PUT /api/rooms/{id}` right after creating, which does honour it.

### Get Room

```
GET /api/rooms/{id}
```

**Required:** Manager or Admin

**Response:** Single room object.

### Update Room

```
PUT /api/rooms/{id}
```

**Required:** Manager or Admin

**Request body:** Same fields as create (all optional, only provided fields are updated).

**Response:** Updated room object.

### Delete Room

```
DELETE /api/rooms/{id}
```

**Required:** Admin

Deletes the room, its calendar, service account, and permissions.

**Response:**
```json
{ "status": "ok" }
```

## Bookings

### List All Bookings

```
GET /api/all-bookings
```

Returns bookings across all rooms visible to the current user.

**Query parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `room` | string | Filter by room ID |
| `status` | string | `all`, `pending`, `accepted`, `declined` |
| `from` | string | Start date (ISO 8601) |
| `to` | string | End date (ISO 8601) |
| `scope` | string | `view` (default) or `manage`. With `scope=manage` only rooms the user can manage are included — used by the Manager Bookings tab in Personal Settings (since 1.1.0). Admins always see every room regardless of scope |

**Response:**
```json
{
  "bookings": [
    {
      "uid": "abc123-def456",
      "summary": "Team Meeting",
      "dtstart": "2026-03-01T10:00:00Z",
      "dtend": "2026-03-01T11:00:00Z",
      "organizer": "alice@company.com",
      "organizerName": "Alice",
      "partstat": "ACCEPTED",
      "roomId": "meeting-room-1",
      "roomName": "Meeting Room 1"
    }
  ],
  "stats": {
    "today": 3,
    "pending": 5,
    "thisWeek": 12
  }
}
```

`stats` counts bookings in the returned set: `today` and `thisWeek` by start date, `pending` by `partstat = TENTATIVE`. There is no total, accepted or declined count.

### List Room Bookings

```
GET /api/rooms/{id}/bookings
```

**Required:** Manager or Admin

**Query parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `from` | string | Start date (ISO 8601) |
| `to` | string | End date (ISO 8601) |

**Response:** a bare JSON array — there is no `bookings` wrapper.

```json
[
  {
    "uid": "abc123-def456",
    "uri": "abc123-def456.ics",
    "summary": "Team Meeting",
    "description": "Sprint review",
    "location": "Meeting Room 1",
    "dtstart": "2026-03-01T10:00:00Z",
    "dtend": "2026-03-01T11:00:00Z",
    "allDay": false,
    "organizer": "alice@company.com",
    "organizerName": "Alice",
    "partstat": "ACCEPTED",
    "status": "CONFIRMED",
    "recurrenceId": null
  }
]
```

**Errors:** `401` no session · `404` room not found

### Create Booking

```
POST /api/rooms/{id}/bookings
```

**Required:** Booker, Manager, or Admin

**Request body:**
```json
{
  "summary": "Team Meeting",
  "start": "2026-03-01T10:00:00Z",
  "end": "2026-03-01T11:00:00Z",
  "description": "Weekly sync"
}
```

`summary`, `start`, and `end` are required.

**Response:** `201 Created`

```json
{
  "status": "ok",
  "uid": "abc123-def456"
}
```

**Errors:**

| Status | Body |
|---|---|
| `400` | `{"error": "Summary, start, and end are required"}` |
| `404` | `{"error": "Room not found"}` |
| `409` | `{"error": "Time slot conflicts with existing booking"}` |

### Update Booking

```
PUT /api/rooms/{id}/bookings/{uid}
```

**Required:** Organizer, Manager, or Admin

**Request body:**
```json
{
  "start": "2026-03-01T14:00:00Z",
  "end": "2026-03-01T15:00:00Z",
  "roomId": "meeting-room-2"
}
```

If `roomId` is provided and differs from the current room, the booking is moved (deleted from old room, created in new room with a new UID).

**Response:**
```json
{
  "status": "ok",
  "uid": "new-uid-789",
  "moved": true
}
```

### Respond to Booking

```
POST /api/rooms/{id}/bookings/{uid}/respond
```

**Required:** Manager or Admin

**Request body:**
```json
{
  "action": "accept"
}
```

`action` must be `accept` or `decline`.

**Response:**
```json
{
  "status": "ok",
  "action": "accept"
}
```

### Delete Booking

```
DELETE /api/rooms/{id}/bookings/{uid}
```

**Required:** Organizer, Manager, or Admin

**Query parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `recurrenceId` | string | Cancel a single occurrence of a recurring booking instead of the whole series. Omit it to cancel every occurrence. |

**Side effects (since 1.1.0):**

A delete is a full cancellation, not just a removal from the room calendar:

1. The booking is deleted from the room calendar (and Exchange, if configured)
2. The room attendee is removed from the booker's own calendar event and `LOCATION` is cleared, so the slot frees up in the Room Finder
3. A `Booking cancelled` email is sent to the booker explaining the booking was cancelled by a room manager

Steps 2 and 3 are non-blocking — if the organizer's calendar cleanup or the mail fails, the API still returns `200 OK` and the failure is logged. They run whenever the booking has both an organizer address and a room address, regardless of who deletes it or whether the booking had been accepted.

**Response:**
```json
{ "status": "ok" }
```

## Permissions

### Get Room Permissions

```
GET /api/rooms/{id}/permissions
```

**Required:** Manager or Admin

**Response:**
```json
{
  "viewers": [
    { "type": "group", "id": "staff" }
  ],
  "bookers": [
    { "type": "user", "id": "alice" },
    { "type": "group", "id": "developers" }
  ],
  "managers": [
    { "type": "user", "id": "bob" }
  ]
}
```

### Set Room Permissions

```
PUT /api/rooms/{id}/permissions
```

**Required:** Manager or Admin

**Request body:** Same structure as the GET response.

**Response:**
```json
{ "status": "ok" }
```

## Room Groups

### List Room Groups

```
GET /api/room-groups
```

**Required:** Admin

**Response:** a bare JSON array — there is no `groups` wrapper.

```json
[
  {
    "id": "building-a",
    "name": "Building A",
    "description": "Main office building",
    "createdAt": "2026-01-15T10:00:00Z"
  }
]
```

### Create Room Group

```
POST /api/room-groups
```

**Required:** Admin

**Request body:**
```json
{
  "name": "Building A",
  "description": "Main office building"
}
```

`name` is required.

**Response:** `201 Created` with the created group object.

### Get Room Group

```
GET /api/room-groups/{id}
```

**Required:** Admin

**Response:** Single group object.

### Update Room Group

```
PUT /api/room-groups/{id}
```

**Required:** Admin

**Request body:** `name` and/or `description`.

**Response:** Updated group object.

### Delete Room Group

```
DELETE /api/room-groups/{id}
```

**Required:** Admin

Fails with **409 Conflict** if any rooms are still assigned to the group.

**Response:**
```json
{ "status": "ok" }
```

### Get Group Permissions

```
GET /api/room-groups/{id}/permissions
```

**Required:** Admin

**Response:** Same structure as room permissions.

### Set Group Permissions

```
PUT /api/room-groups/{id}/permissions
```

**Required:** Admin

**Request body:** Same structure as room permissions.

**Response:**
```json
{ "status": "ok" }
```

## Personal

Endpoints behind the user's own Personal settings page. These are the only internal endpoints besides `GET /api/rooms` and `GET /api/all-bookings` that a non-admin can call; each one filters on the caller's own permissions.

### List My Rooms

```
GET /api/personal/rooms
```

**Required:** any logged-in user

Rooms the caller has any role on, sorted by role (admin, then manager, booker, viewer). Administrators get every room with `role: "admin"`.

**Response:** bare array.

```json
[
  {
    "id": "meeting-room-1",
    "name": "Meeting Room 1",
    "roomType": "meeting-room",
    "capacity": 10,
    "address": "Main Building, Kerkstraat 10, 1017 AA, Amsterdam",
    "responsibleContact": "facilities@company.com",
    "role": "manager"
  }
]
```

### List Viewable Rooms

```
GET /api/personal/rooms/viewable
```

**Required:** any logged-in user

The id and email of every room the caller may view — enough to filter a calendar view without pulling full room objects.

**Response:** bare array of `{"id": "...", "email": "..."}`.

### List Pending Approvals

```
GET /api/personal/approvals
```

**Required:** any logged-in user

Bookings awaiting a decision (`partstat = TENTATIVE`) in rooms the caller manages, from now onwards, sorted by start time. Answer them with `POST /api/rooms/{id}/bookings/{uid}/respond`.

**Response:** bare array.

```json
[
  {
    "uid": "abc123-def456",
    "roomId": "meeting-room-1",
    "roomName": "Meeting Room 1",
    "summary": "Team Meeting",
    "dtstart": "2026-03-01T10:00:00+01:00",
    "dtend": "2026-03-01T11:00:00+01:00",
    "organizerName": "Alice",
    "organizer": "alice@company.com",
    "partstat": "TENTATIVE"
  }
]
```

All-day bookings carry a bare `YYYY-MM-DD` in `dtstart`/`dtend` rather than a timestamp.

---

## Exchange

Configuration and status for the Microsoft Exchange integration. All of these require an administrator. See [Exchange Integration](exchange-integration.md) for how the sync itself works.

### Test Connection

```
POST /api/exchange/test
```

Checks the configured tenant credentials against Microsoft Graph. Returns `{"success": true, "tenantName": "..."}`, or `{"success": false, "error": "..."}` — **both with HTTP 200**, see [Soft failures](#soft-failures). Returns `400` when the Exchange integration is switched off.

### Validate Resource Mailbox

```
POST /api/exchange/validate-resource
```

**Body:** `{ "email": "boardroom@company.com" }`

Checks that the address exists in Exchange before it is linked to a room. Returns `{"valid": true, "displayName": "..."}` or `{"valid": false, "error": "..."}`, again both with HTTP 200. `400` when `email` is missing.

### Retry Initial Sync

```
POST /api/rooms/{id}/exchange/initial-sync
```

Queues a fresh initial sync for one room after a failed attempt, and returns `{"status": "queued"}` immediately — the work happens in a background job. Poll `GET /api/exchange/status`, or read `exchangeConfig.initialSyncStatus` on the room. Returns `404` if the room does not exist, `400` if it has no Exchange configuration.

### Sync Status

```
GET /api/exchange/status
```

```json
{
  "globalEnabled": true,
  "configured": true,
  "rooms": [
    {
      "roomId": "meeting-room-1",
      "roomName": "Meeting Room 1",
      "resourceEmail": "boardroom@company.com",
      "syncEnabled": true,
      "lastSyncAt": "2026-03-01T09:00:00+01:00",
      "lastError": null
    }
  ]
}
```

Rooms without a resource mailbox are left out.

### Webhook Status

```
GET /api/exchange/webhooks
```

```json
{
  "notificationUrl": "https://cloud.example.com/apps/roomvox/api/webhook/exchange",
  "httpsAvailable": true,
  "rooms": [
    {
      "roomId": "meeting-room-1",
      "roomName": "Meeting Room 1",
      "subscriptionId": "a1b2c3",
      "expiresAt": "2026-03-04T09:00:00+01:00",
      "hasWebhook": true
    }
  ]
}
```

`notificationUrl` is `null` when the instance has no HTTPS URL — Microsoft Graph refuses to deliver to plain HTTP, so no subscription can be created. Only rooms with sync enabled are listed.

### Create Webhooks

```
POST /api/exchange/webhooks
```

Creates or renews a Graph subscription for every Exchange-enabled room. Returns `{"results": [{"roomId": "...", "roomName": "...", "success": true}]}` — a per-room result, so a partial failure still returns HTTP 200.

### Webhook Receiver

```
POST /api/webhook/exchange
```

**Public endpoint — no authentication.** This is where Microsoft Graph delivers change notifications, so it cannot require a session. It is protected instead by the `subscriptionId` lookup plus a constant-time comparison of the `clientState` secret that RoomVox generated when creating the subscription; notifications that fail either check are logged and ignored.

It is the one endpoint that does not speak JSON:

| Situation | Response |
|---|---|
| Graph handshake (`?validationToken=...`) | `200` with the token echoed back as `text/plain` |
| Change notification accepted | `202` with an empty body |
| Body is not a Graph notification | `400` with an empty body |

A `202` means the notification was taken, not that a sync has finished — small batches are synced inline, larger ones are queued.

---

## Settings

### Get Settings

```
GET /api/settings
```

**Required:** Admin

**Response:**
```json
{
  "defaultAutoAccept": false,
  "emailEnabled": true,
  "telemetryEnabled": true,
  "showWeekends": true,
  "roomTypes": [
    { "id": "meeting-room", "label": "Meeting Room" },
    { "id": "studio", "label": "Studio" },
    { "id": "lecture-hall", "label": "Lecture Hall" }
  ],
  "facilities": [
    { "id": "projector", "label": "Projector" },
    { "id": "whiteboard", "label": "Whiteboard" }
  ],
  "exchangeEnabled": false,
  "exchangeTenantId": "",
  "exchangeClientId": "",
  "exchangeClientSecret": "",
  "exchangeWebhookMaxInlineSync": 1,
  "exchangeWebhookRateLimit": 5
}
```

`exchangeClientSecret` is never returned: it reads `"***"` when a secret is stored and `""` when it is not. Sending `"***"` back on save is ignored, so a round-trip through this endpoint cannot wipe the stored secret.

### Save Settings

```
PUT /api/settings
```

**Required:** Admin

**Request body:** Same structure as GET response (all fields optional).

**Response:**
```json
{ "status": "ok" }
```

## Sharees

### Search Groups

```
GET /api/sharees?search={query}
```

Search Nextcloud groups to add in the permission editor.

**Groups only — individual users are not searchable here.** That is deliberate: Nextcloud Calendar filters room visibility through `group_restrictions`, which is group-based, so a per-user permission would not be reflected in the calendar picker.

**Required:** Admin

**Query parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `search` | string | Matched against the group display name. An empty query returns the first groups the backend offers. |

At most 25 groups are returned. If the query matches a group ID exactly but that group did not surface in the search — which happens with LDAP backends, where the search matches the display name attribute rather than the ID — the exact match is added as well.

**Response:** a bare JSON array. `type` is always `"group"`.

```json
[
  { "type": "group", "id": "developers", "label": "Developers" }
]
```

The `label` is for display; permission entries are stored by `type` and `id`.

## License & Telemetry

### Get License Stats

```
GET /api/license/stats
```

**Required:** Admin

Returns license status, usage statistics, and telemetry state.

### Save License Key

```
POST /api/settings/license
```

**Required:** Admin

**Body:**
```json
{ "licenseKey": "RVOX-XXXX-XXXX-XXXX-XXXX" }
```

### Validate License

```
POST /api/license/validate
```

**Required:** Admin

Validates the stored license key with the license server.

### Update Usage

```
POST /api/license/update-usage
```

**Required:** Admin

Reports the current room and user counts to the license server, so the subscription tier can be checked against actual usage. Returns `{"success": true, "result": {...}}` where `result` is the server's answer — or a `{"success": false, "reason": "..."}` object when there is no license key or the server is unreachable. See [Soft failures](#soft-failures).

### Send Telemetry Report

```
POST /api/license/telemetry
```

**Required:** Admin

Immediately sends anonymous usage statistics to the telemetry server.

**Response:**
```json
{ "success": true, "lastReport": 1712678400 }
```

On failure, includes the specific error:
```json
{ "success": false, "reason": "error", "message": "Rate limit exceeded" }
```

## Debug

### Debug Room Registration

```
GET /api/debug/rooms
```

**Required:** Admin

Returns internal details about room backend registration, room principals, and CalDAV calendars. Useful for troubleshooting.

```json
{
  "backends": ["OCA\\RoomVox\\Connector\\Room\\RoomBackend"],
  "rooms": [
    {
      "id": "meeting-room-1",
      "displayName": "Meeting Room 1",
      "email": "meeting-room-1@roomvox.local",
      "backend": "OCA\\RoomVox\\Connector\\Room\\RoomBackend",
      "groupRestrictions": []
    }
  ],
  "raw_rooms": [
    { "id": "meeting-room-1", "name": "Meeting Room 1", "email": "meeting-room-1@roomvox.local", "active": true }
  ]
}
```

`rooms` is what Nextcloud's room manager reports, `raw_rooms` is what RoomVox has stored. A room present in `raw_rooms` but missing from `rooms` means the backend did not publish it — the usual reason a room does not show up in the calendar picker.

---

## Error Responses

### Internal API

| Status | Meaning |
|--------|---------|
| 401 | Not authenticated |
| 403 | Insufficient permissions |
| 404 | Room or booking not found |
| 409 | Conflict (scheduling conflict or group has rooms) |
| 500 | Server error |

**Error format:**
```json
{
  "error": "Description of the error"
}
```

Two groups of endpoints deviate:

- `/api/license/*` and `/api/settings/license` answer with `{"success": false, "message": "..."}`.
- `POST /api/webhook/exchange` returns an empty body and communicates only through the status code.

### Soft failures

Some endpoints report a failure with **HTTP 200** and a flag in the body. Checking the status code alone is not enough there:

| Endpoint | Failure looks like |
|---|---|
| `POST /api/exchange/test` | `{"success": false, "error": "..."}` |
| `POST /api/exchange/validate-resource` | `{"valid": false, "error": "..."}` |
| `POST /api/license/validate` | `{"success": true, "validation": {"valid": false, ...}}` |
| `POST /api/license/update-usage` | `{"success": true, "result": {"success": false, ...}}` |
| `POST /api/license/telemetry` | `{"success": false, "reason": "..."}` |

The two license endpoints nest the real outcome: the outer `success` says the call was handled, the inner object says whether it worked.

### Public API v1

| Status | Meaning |
|--------|---------|
| 401 | Missing or invalid Bearer token |
| 403 | Insufficient scope or no room access |
| 404 | Room or booking not found |
| 409 | Scheduling conflict |
| 422 | Validation error (outside hours, beyond horizon) |
| 500 | Server error |

**Error format:**
```json
{
  "error": "Description of the error"
}
```
