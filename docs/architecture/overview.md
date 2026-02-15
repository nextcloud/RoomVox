# Architecture Overview

RoomVox is a CalDAV-native room booking system for Nextcloud. This document describes the system architecture, data flow, and key design decisions.

## High-Level Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    Calendar Clients                          │
│  NC Calendar  ·  Apple Calendar  ·  Outlook  ·  Thunderbird │
└──────────────────────┬──────────────────────────────────────┘
                       │ CalDAV / iTIP
                       ▼
┌─────────────────────────────────────────────────────────────┐
│                  Nextcloud + Sabre DAV                        │
│                                                              │
│  ┌──────────────┐    ┌───────────────────┐                  │
│  │ RoomBackend  │    │ SchedulingPlugin  │                  │
│  │  (IBackend)  │    │  (priority 99)    │                  │
│  └──────┬───────┘    └────────┬──────────┘                  │
│         │                     │                              │
│  ┌──────▼───────┐    ┌────────▼──────────┐                  │
│  │    Room      │    │  CalDAVService    │                  │
│  │   (IRoom)    │    │                   │                  │
│  └──────────────┘    └───────────────────┘                  │
│                                                              │
│  ┌──────────────┐    ┌───────────────────┐                  │
│  │ RoomService  │    │ PermissionService │                  │
│  └──────────────┘    └───────────────────┘                  │
│                                                              │
│  ┌──────────────┐    ┌───────────────────┐                  │
│  │ MailService  │    │ RoomUserBackend   │                  │
│  └──────────────┘    └───────────────────┘                  │
│                                                              │
│  ┌──────────────────────────────────────────────────────┐   │
│  │                    IAppConfig                         │   │
│  │  rooms_index · room/{id} · permissions/{id} · ...    │   │
│  └──────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
```

## Core Components

### CalDAV Room Backend

**Files:** `lib/Connector/Room/RoomBackend.php`, `lib/Connector/Room/Room.php`

RoomVox implements Nextcloud's `IBackend` interface to expose rooms as CalDAV resources. When calendar apps query for available resources, the RoomBackend returns Room objects with metadata (capacity, type, address, facilities).

The Room class implements `IRoom` and publishes CalDAV properties:

| DAV Property | Source |
|-------------|--------|
| `{urn:ietf:params:xml:ns:caldav}calendar-description` | Formatted room description |
| `{http://nextcloud.com/ns}room-type` | Room type ID |
| `{http://nextcloud.com/ns}room-seating-capacity` | Capacity number |
| `{http://nextcloud.com/ns}room-building-address` | Room address |
| `{http://nextcloud.com/ns}room-building-room-number` | Room number |
| `{http://nextcloud.com/ns}room-features` | Comma-separated facility IDs |

Room visibility in calendar apps is controlled via `group_restrictions` derived from group entries in the permission system.

### Scheduling Plugin

**File:** `lib/Dav/SchedulingPlugin.php`

The heart of RoomVox. A Sabre DAV `ServerPlugin` registered at **priority 99** (before Sabre's default handler at priority 100) that intercepts iTIP scheduling messages.

#### REQUEST Flow (New/Updated Booking)

```
iTIP REQUEST arrives
    │
    ├─ 1. Permission Check
    │     Resolve sender → NC user → canBook()?
    │     If no permission → DECLINE (3.7)
    │
    ├─ 2. Availability Check
    │     Event time within room's availability rules?
    │     If outside hours → DECLINE (3.7)
    │
    ├─ 3. Booking Horizon Check
    │     Event (incl. recurrences) within max days?
    │     If too far ahead → DECLINE (3.7)
    │
    ├─ 4. Conflict Detection
    │     Overlapping accepted/tentative bookings?
    │     If conflict → DECLINE (3.0) + sendConflict()
    │
    ├─ 5. PARTSTAT Determination
    │     autoAccept? → ACCEPTED : TENTATIVE
    │
    ├─ 6. Attendee Enrichment
    │     Fix CUTYPE (iOS), add LOCATION
    │
    ├─ 7. Deliver to Room Calendar
    │     Store in room's CalDAV calendar
    │
    ├─ 8. Set Schedule Status → 1.2 (delivered)
    │
    └─ 9. Notifications
          ACCEPTED → sendAccepted() to organizer
          TENTATIVE → notifyManagers()
```

#### CANCEL Flow

```
iTIP CANCEL arrives
    │
    ├─ 1. Delete from room calendar
    ├─ 2. Set schedule status → 1.2
    └─ 3. sendCancelled() to organizer + managers
```

#### Post-Write Hook

After any `.ics` file write, the plugin fixes the organizer's copy:
- Sets `CUTYPE=ROOM` on room attendees (fixes iOS)
- Writes back the correct `PARTSTAT` (since we handle delivery, not Sabre)
- Handles eM Client: detects rooms by LOCATION match and adds as ATTENDEE

#### Why Priority 99?

Sabre's default scheduling handler runs at priority 100 and requires `getPrincipalByUri()` to resolve room principals, which needs an active user session. Since room principals use virtual service accounts (`rb_*`), Sabre's handler would fail. RoomVox intercepts first, handles delivery, and returns `false` to stop Sabre from attempting its own delivery.

### Virtual User Accounts

**File:** `lib/UserBackend/RoomUserBackend.php`

Each room has a virtual user account with the `rb_` prefix (e.g., `rb_meeting-room-1`). These accounts:

- Are registered with Nextcloud for CalDAV principal resolution
- Have display names matching the room name
- Are **hidden** from user search and listings
- Cannot be used to log in (password check always fails)
- Are not counted as real users

This design lets rooms have CalDAV principals (`principals/users/rb_*`) without creating real user accounts.

### Data Storage

**File:** `lib/Service/RoomService.php`

RoomVox uses **no custom database tables**. All data is stored via Nextcloud's `IAppConfig`:

| Key Pattern | Content |
|------------|---------|
| `rooms_index` | JSON array of all room IDs |
| `room/{roomId}` | Room configuration JSON |
| `permissions/{roomId}` | Room permission JSON |
| `room_groups_index` | JSON array of all group IDs |
| `group/{groupId}` | Room group configuration JSON |
| `group_permissions/{groupId}` | Group permission JSON |
| `defaultAutoAccept` | Boolean string ('true'/'false') |
| `emailEnabled` | Boolean string |
| `roomTypes` | JSON array of type objects |

#### Room Data Structure

```json
{
  "id": "meeting-room-1",
  "userId": "rb_meeting-room-1",
  "name": "Meeting Room 1",
  "email": "meeting-room-1@roomvox.local",
  "roomNumber": "2.17",
  "address": "Main Building, Kerkstraat 10, Amsterdam",
  "roomType": "meeting-room",
  "capacity": 10,
  "description": "Corner room with projector",
  "facilities": ["projector", "whiteboard", "videoconf"],
  "autoAccept": true,
  "active": true,
  "groupId": "building-a",
  "availabilityRules": {
    "enabled": true,
    "rules": [
      { "days": [1,2,3,4,5], "startTime": "08:00", "endTime": "18:00" }
    ]
  },
  "maxBookingHorizon": 90,
  "calendarUri": "room-rb_meeting-room-1",
  "smtpConfig": {
    "host": "smtp.company.com",
    "port": 587,
    "username": "room1@company.com",
    "password": "encrypted...",
    "encryption": "tls"
  },
  "createdAt": "2026-01-15T10:30:00+00:00"
}
```

#### Why No Database?

- Zero migration overhead — no schema changes needed
- Simple deployment — no database setup required
- Room count is typically small (tens to hundreds), making key-value storage efficient
- Permissions and settings are naturally document-shaped (JSON)
- Booking data is stored in CalDAV calendars, not in RoomVox storage

### Permission System

**File:** `lib/Service/PermissionService.php`

Three-role hierarchy with user and group entries:

```
Manager > Booker > Viewer
```

Permissions are stored at two levels:
1. **Room-level** — specific to a single room
2. **Group-level** — inherited by all rooms in the group

Effective permissions are the union of both levels. Nextcloud administrators always have full access.

### Email Service

**File:** `lib/Service/MailService.php`

Sends transactional emails for booking events:

- **Per-room SMTP**: Uses Symfony Mailer directly with the room's SMTP config
- **Global fallback**: Uses Nextcloud's IMailer
- SMTP passwords are encrypted with `ICrypto` before storage
- Internal `@roomvox.local` emails are not used as sender addresses

### CalDAV Service

**File:** `lib/Service/CalDAVService.php`

Interface to Nextcloud's CalDAV backend for:

- **Calendar provisioning** — creating/deleting room calendars
- **Booking CRUD** — creating, reading, updating, deleting events
- **Conflict detection** — checking for time overlaps
- **Availability publishing** — VAVAILABILITY objects for room availability rules

## Application Bootstrap

**File:** `lib/AppInfo/Application.php`

Registration phase:
1. Register `RoomBackend` as CalDAV room backend
2. Register `SabrePluginListener` for Sabre plugin injection

Boot phase:
1. Register `RoomUserBackend` with user manager
2. Wire late injection to break circular dependency between PermissionService and RoomService

## Frontend

### Technology Stack

- Vue 3 with Composition API
- Nextcloud Vue component library (`@nextcloud/vue`)
- Webpack build via `@nextcloud/webpack-vue-config`

### Structure

```
src/
├── main.js                    # Entry point, mounts to #app-roomvox
├── App.vue                    # Tab navigation (Rooms, Bookings, Settings)
├── views/
│   ├── RoomList.vue           # Room table with search and filters
│   ├── RoomEditor.vue         # Room create/edit form
│   ├── PermissionEditor.vue   # User/group permission assignment
│   └── BookingOverview.vue    # Booking list with approve/decline
└── services/
    └── api.js                 # Axios-based API client
```

The admin panel is rendered inside Nextcloud's settings framework (`/settings/admin/roomvox`), mounted to a plain `<div id="app-roomvox">` without NcContent/NcAppContent wrappers.
