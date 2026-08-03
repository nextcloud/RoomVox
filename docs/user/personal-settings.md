# Personal Settings

Access your RoomVox-related information via **Settings → Personal → RoomVox**. The page has up to three tabs depending on your role.

## Accessing Personal Settings

1. Click your **profile picture** or **username** in the top right
2. Select **Personal Settings**
3. Scroll down the left sidebar and click **RoomVox**

## Tabs

### My Rooms

Shows all rooms you have at least Viewer permission for.

| Column | Description |
|---|---|
| Name | The room's display name |
| Type | Meeting room, studio, lecture hall, etc. |
| Capacity | Maximum number of people |
| Location | Building, address |
| Role | Your effective role (Viewer / Booker / Manager / Admin) |
| Responsible contact | Free-text contact info set by the administrator |

**Why this tab matters for Viewers**: even if you can't book a room, the Responsible contact tells you who to ask. Useful when you discover a meeting room exists but isn't available to you directly.

### Approvals (Managers only)

Shows pending bookings (status **Tentative**) for rooms you manage. From here you can:

- See the event title, room, organizer, and requested time
- Click **Approve** to confirm the booking — the organizer gets a confirmation email
- Click **Decline** to reject — the organizer gets a decline email

You also receive an email when a new pending booking arrives, so you don't need to keep this tab open.

### Bookings (Managers only, v1.1.0+)

The third tab gives Managers the same booking overview that admins see under **Settings → Administration → RoomVox → Bookings**, but **scoped to the rooms you manage**.

The component is shared with the admin view:

- **Stats cards** — total bookings, pending, accepted, declined
- **Filters** — by room, status, and date range
- **List / Calendar toggle** — switch between list view and FullCalendar
- **Drag-and-drop** in calendar view — move a booking between rooms (subject to conflict check)
- **Cancel-booking** — same flow as the admin Bookings tab

This tab only appears if you manage at least one room.

## What This Page Is Not For

- **Seeing your own bookings** — those live in your **Nextcloud Calendar**. Rooms are CalDAV resources, so your bookings appear in your calendar like any other event with the room as an attendee.
- **Subscribing to a room's calendar in an external app** — this is done with a per-room feed URL, not from this page. An administrator or room manager enables the **external calendar feed** in the room editor and shares the URL; you then add it as a read-only subscription in your calendar app. See [Can I subscribe to a room's calendar?](faq.md#can-i-subscribe-to-a-rooms-calendar-in-my-external-calendar-app) and the [Public API](../features/public-api.md) reference.

## See Also

- [Managing Bookings](managing-bookings.md) — how to approve, decline, reschedule
- [Booking Rooms](booking-rooms.md) — how to book from calendar apps
- [Overview](overview.md) — what RoomVox is
