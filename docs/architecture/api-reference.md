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
    "location": "Building A, Heidelberglaan 8, 3584 CS Utrecht",
    "autoAccept": true,
    "active": true
  }
]
```

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

**Query parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `from` | string | 7 days ago | Start of date range (ISO 8601) |
| `to` | string | 30 days ahead | End of date range (ISO 8601) |

**Response:**
```
Content-Type: text/calendar; charset=utf-8

BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//RoomVox//Nextcloud//EN
X-WR-CALNAME:Meeting Room 1
BEGIN:VEVENT
UID:abc-123-def
DTSTART:20260215T130000Z
DTEND:20260215T140000Z
SUMMARY:Team standup
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

**Required:** Admin

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

## Rooms

### List All Rooms

```
GET /api/rooms
```

Returns all rooms visible to the current user, with permission flags.

**Response:**
```json
{
  "rooms": [
    {
      "id": "meeting-room-1",
      "name": "Meeting Room 1",
      "roomNumber": "2.17",
      "address": "Main Building, Kerkstraat 10, Amsterdam",
      "roomType": "meeting-room",
      "capacity": 10,
      "email": "meeting-room-1@roomvox.local",
      "description": "Corner room with projector",
      "facilities": ["projector", "whiteboard"],
      "autoAccept": true,
      "active": true,
      "groupId": "building-a",
      "canBook": true,
      "canManage": false
    }
  ]
}
```

> **Note:** SMTP passwords are always masked as `"***"` in responses.

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
  "facilities": ["projector", "whiteboard", "videoconf"],
  "autoAccept": true,
  "groupId": "building-a",
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

Only `name` is required. All other fields are optional.

**Response:** The created room object.

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
    "total": 42,
    "accepted": 35,
    "pending": 5,
    "declined": 2
  }
}
```

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
      "status": "CONFIRMED"
    }
  ]
}
```

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

**Response:**
```json
{
  "status": "ok",
  "uid": "abc123-def456"
}
```

**Error (409 Conflict):**
```json
{
  "status": "error",
  "message": "Time conflict with existing booking"
}
```

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
  "movedUid": "new-uid-789"
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

**Response:**
```json
{
  "groups": [
    {
      "id": "building-a",
      "name": "Building A",
      "description": "Main office building",
      "createdAt": "2026-01-15T10:00:00Z"
    }
  ]
}
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

**Response:** Created group object.

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

## Settings

### Get Settings

```
GET /api/settings
```

**Required:** Admin

**Response:**
```json
{
  "defaultAutoAccept": true,
  "emailEnabled": true,
  "roomTypes": [
    { "id": "meeting-room", "label": "Meeting Room" },
    { "id": "studio", "label": "Studio" },
    { "id": "lecture-hall", "label": "Lecture Hall" }
  ]
}
```

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

### Search Users and Groups

```
GET /api/sharees?search={query}
```

Search for Nextcloud users and groups to add in the permission editor.

**Query parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `search` | string | Search query (name or group name) |

**Response:**
```json
{
  "users": [
    { "id": "alice", "displayName": "Alice Smith" }
  ],
  "groups": [
    { "id": "developers", "displayName": "Developers" }
  ]
}
```

## Debug

### Debug Room Registration

```
GET /api/debug/rooms
```

**Required:** Admin

Returns internal details about room backend registration, room principals, and CalDAV calendars. Useful for troubleshooting.

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
  "status": "error",
  "message": "Description of the error"
}
```

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
