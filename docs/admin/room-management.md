# Room Management

This guide covers creating, configuring, and organizing rooms in RoomVox.

## Room Overview

The admin panel has five tabs: **Rooms**, **Bookings**, **Import / Export**, **Settings**, and **Statistics**. The Rooms tab shows all rooms organized by group, with columns for name, room number, type, address, capacity, auto-accept status, and active status.

![Room overview — all rooms organized by group](../screenshots/rooms-overview.png)

## Creating Rooms

1. Go to **Settings > Administration > RoomVox**
2. Click the **Rooms** tab
3. Click **+ New Room**
4. Fill in the room details and click **Create Room**

### Room Properties

| Field | Required | Description |
|-------|----------|-------------|
| Name | Yes | Display name shown in calendar apps (e.g., "Meeting Room 1") |
| Room Number | No | Building/floor identifier (e.g., "2.17" for floor 2, room 17) |
| Capacity | No | Maximum number of people |
| Room Type | No | Category from configured types (meeting room, studio, etc.) |
| Address | No | Building name, street, and city — displayed as location |
| Description | No | Additional information about the room |
| Facilities | No | Available equipment (projector, whiteboard, video conferencing, etc.) |
| Email | No | Custom email address for the room (see below) |
| Room Group | No | Assign room to a group for shared permissions |

![Room editor — general settings and location](../screenshots/rooms-edit.png)

### Room Email Address

Each room has an email address used for two purposes:

1. **CalDAV scheduling** — The address used when the room is added as an attendee. If no custom email is set, RoomVox generates an internal address (`<room-id>@roomvox.local`).

2. **Notification sender** — If the room has a real external email address (e.g., `room1@company.com`), notifications are sent from that address. Internal `@roomvox.local` addresses fall back to the Nextcloud system sender.

### Facilities

Available facility checkboxes:

- Projector
- Whiteboard
- Video conferencing
- Audio system
- Display/screen
- Wheelchair accessible

These are published as CalDAV room features and can be used for filtering in the calendar patch room browser.

![Room editor — facilities, auto-accept, availability, and booking horizon](../screenshots/rooms-settings.png)

## Booking Behavior

### Auto-Accept

When enabled, bookings are automatically confirmed if there are no conflicts. When disabled, bookings are set to tentative (pending) and require manager approval.

Configure per room, or set the default in Settings > Default auto-accept.

### Availability Rules

Restrict when rooms can be booked:

1. In the room editor, enable **Restrict booking hours**
2. Add one or more rules:
   - **Days** — select which days of the week (Monday through Sunday)
   - **From/To** — time window for each day
3. Use presets for common patterns:
   - **Weekdays 08–18** — Monday to Friday, 8:00 AM to 6:00 PM
   - **Weekdays 09–17** — Monday to Friday, 9:00 AM to 5:00 PM

Bookings outside these rules are automatically declined.

### Maximum Booking Horizon

Limit how far in advance rooms can be booked:

- Set the number of days (e.g., 90 = max 3 months ahead)
- Set to 0 for no limit
- Recurring events are checked against their last occurrence

## Room Status

### Active / Inactive

Rooms can be activated or deactivated:

- **Active** — room appears as a CalDAV resource and can be booked
- **Inactive** — room is hidden from calendar apps but configuration is preserved

Toggle the **Active** switch in the room editor.

### Deleting Rooms

To permanently delete a room:

1. Click the **Delete** button in the room editor
2. Confirm deletion

This removes:
- The room configuration
- The room's CalDAV calendar and all bookings
- The room service account
- Room permissions

## Room Types

Room types help categorize your rooms. They're shown in the room list and published as CalDAV metadata.

### Managing Types

1. Go to **Settings** tab in the admin panel
2. Find the **Room Types** section
3. Add, edit, or remove types
4. Drag to reorder

![Settings — room types configuration](../screenshots/settings.png)

### Default Types

- Meeting Room
- Rehearsal Room
- Studio
- Lecture Hall
- Telephone Booth
- Outdoor Area
- Other

## Room Groups

Room groups let you organize rooms and share permissions across multiple rooms.

### Creating a Group

1. In the room list, click **Manage groups**
2. Click **Add group**
3. Enter a name and optional description
4. Click **Save**

### Assigning Rooms to Groups

When editing a room, select a **Room Group** from the dropdown. The room inherits permissions from the group (merged with its own room-level permissions).

### Group Permissions

Set permissions at the group level to apply them to all rooms in the group:

1. Click the permissions icon on the group
2. Add viewers, bookers, and managers
3. These permissions are merged (union) with each room's individual permissions

### Deleting Groups

Groups can only be deleted when no rooms are assigned to them. Move or unassign all rooms before deleting.

## Per-Room SMTP

Each room can have its own SMTP server for sending notifications. See [Email Configuration](email-configuration.md) for details.

### Configuration

In the room editor, expand the **SMTP Configuration** section:

| Field | Description |
|-------|-------------|
| Host | SMTP server hostname (e.g., `smtp.company.com`) |
| Port | SMTP port (default: 587) |
| Username | SMTP authentication username |
| Password | SMTP authentication password (encrypted with ICrypto) |
| Encryption | TLS, SSL, or None |

### Testing

Click **Send test email** to verify the SMTP configuration. Enter a recipient email address and check if the test email arrives.
