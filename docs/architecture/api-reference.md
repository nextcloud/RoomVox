# API Reference

All RoomVox API endpoints are available under `/apps/roomvox/api/`. Authentication is required for all endpoints.

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

## Error Responses

All endpoints return consistent error responses:

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
