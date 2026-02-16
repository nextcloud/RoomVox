# Getting Started with RoomVox

This guide will help you set up RoomVox and create your first bookable room in just a few minutes.

## Prerequisites

- Nextcloud 32 or 33
- PHP 8.2+
- SMTP configured in Nextcloud (for email notifications)
- RoomVox app installed (see [Installation Guide](admin/installation.md))

## Step 1: Open the Admin Panel

Navigate to **Settings > Administration > RoomVox** in your Nextcloud instance.

You'll see the RoomVox admin panel with tabs for Rooms, Bookings, Import / Export, Settings, and Statistics.

## Step 2: Configure Settings

Before creating rooms, review the app settings:

1. Click the **Settings** tab
2. Set **Default auto-accept** — toggle whether new rooms auto-accept bookings by default
3. Enable **Email notifications** — turn on booking confirmation and approval emails
4. Configure **Room types** — add or modify room types (meeting room, studio, lecture hall, etc.)
5. Review **Telemetry** — anonymous usage data is enabled by default ([details](admin/telemetry.md))

![Settings — general options and room types](../screenshots/settings.png)

## Step 3: Create Your First Room

1. Click the **Rooms** tab
2. Click **+ New Room**
3. Fill in the room details:
   - **Name** — e.g., "Meeting Room 1" (required)
   - **Room number** — e.g., "2.17" for floor 2, room 17
   - **Capacity** — maximum number of people
   - **Room type** — select from your configured types
   - **Address** — building, street, and city
   - **Facilities** — check applicable options (projector, whiteboard, video conferencing, etc.)
4. Configure booking behavior:
   - **Auto-accept** — automatically confirm bookings, or require manager approval
   - **Availability rules** — optionally restrict booking to specific days and times
   - **Booking horizon** — optionally limit how far in advance bookings can be made
5. Click **Create Room**

![Room editor — fill in details for your new room](../screenshots/rooms-edit.png)

The room is now available as a CalDAV resource in calendar apps.

## Step 4: Set Permissions

1. In the room list, click the **permissions icon** for your room
2. Add users or groups with the appropriate role:
   - **Viewer** — can see the room in calendar apps
   - **Booker** — can book the room
   - **Manager** — can approve/decline bookings and manage the room
3. Click **Save**

If no permissions are configured, the room is visible and bookable by everyone.

## Step 5: Book a Room

### From Nextcloud Calendar

1. Create a new calendar event
2. In the event editor, look for the **Resources** section
3. Search for your room by name
4. Add the room to the event
5. Save the event

The room will respond with:
- **Accepted** — if auto-accept is enabled and no conflicts
- **Tentative** — if manager approval is required
- **Declined** — if there's a scheduling conflict

![Room browser — browse and add rooms from the calendar](../screenshots/bookroom-start.png)

### From Other Calendar Apps

Rooms appear as CalDAV resources in any compatible calendar app:

- **Apple Calendar** — rooms show in the resource picker when creating events
- **Microsoft Outlook** — add via CalDAV account, rooms appear as resources
- **Thunderbird** — rooms available through CalDAV resource support
- **eM Client** — rooms detected automatically via LOCATION field

## Step 6: Manage Bookings

1. Go to the **Bookings** tab in the admin panel
2. Filter by room, status, or date range
3. For pending bookings (tentative), click **Approve** or **Decline**
4. Approved bookings send confirmation emails to the organizer

![Bookings overview — manage all bookings](../screenshots/bookings-overview-list.png)

## Step 7: Set Up API Tokens (Optional)

If you want to integrate with external systems (room displays, kiosks, digital signage), create an API token:

1. Go to the **Settings** tab
2. Scroll down to **API Tokens**
3. Enter a name (e.g., "Lobby Display") and select a scope (`read`, `book`, or `admin`)
4. Click **Create token**
5. Copy the generated token immediately — it won't be shown again

![API Tokens — manage tokens for external integrations](../screenshots/api-tokens.png)

See the [API Reference](architecture/api-reference.md) for endpoint documentation and usage examples.

## Next Steps

- [Room Management](admin/room-management.md) — Advanced room configuration
- [Import / Export](admin/import-export.md) — Bulk room management via CSV
- [Permissions](admin/permissions.md) — Detailed permission setup
- [Email Configuration](admin/email-configuration.md) — Configure per-room SMTP
- [API Reference](architecture/api-reference.md) — Public API for external integrations
- [Calendar Patch](admin/calendar-patch.md) — Install the visual room browser for Nextcloud Calendar
