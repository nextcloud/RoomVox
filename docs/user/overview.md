# RoomVox Overview (User Guide)

Welcome to **RoomVox** — book meeting rooms directly from the calendar app you already use.

## What is RoomVox?

RoomVox makes meeting rooms available as **standard CalDAV resources** in any calendar app — Nextcloud Calendar, Apple Calendar, Outlook, Thunderbird, eM Client. There is no separate booking interface to learn: you book a room the same way you'd invite a colleague to a meeting.

When you add a room to a calendar event, RoomVox:

1. Checks whether you have **permission** to book the room
2. Verifies the room is **available** at the requested time
3. Applies the room's **availability rules** (allowed days/times)
4. Applies the **booking horizon** (max days ahead)
5. Either **auto-accepts** the booking or marks it as **Tentative** (pending manager approval)
6. Sends **email notifications** to you and to the room's managers

## How a Room Appears

A room behaves like a CalDAV calendar resource:

- It has a name, capacity, location, and facilities (projector, whiteboard, video conferencing, etc.)
- It has an email address — usually a real mailbox (`boardroom@company.com`) or an internal one
- It responds **Accepted**, **Tentative**, or **Declined** to your invitation, just like a human attendee

Your administrator creates and maintains the list of rooms. You see all rooms you have at least Viewer permission for.

## Booking Responses

| Status | Meaning |
|---|---|
| **Accepted** | The room is confirmed — no further action needed |
| **Tentative** | The room requires manager approval — you'll be notified when approved or declined |
| **Declined** | The booking was rejected — see the reason in the email |

### Why a Booking May Be Declined

- **Scheduling conflict** — another booking exists at that time
- **No permission** — you don't have Booker or Manager role for that room
- **Outside availability** — the requested time is outside the room's available hours
- **Beyond booking horizon** — the event is too far in the future
- **Room sync in progress** — Exchange-linked room is still syncing; retry shortly

You receive a clear email with the reason in each case.

## Permission Roles

| Role | Can view | Can book | Can manage |
|---|:-:|:-:|:-:|
| Viewer | ✓ | | |
| Booker | ✓ | ✓ | |
| Manager | ✓ | ✓ | ✓ |

Managers can approve/decline pending bookings, edit room settings, and cancel any booking. Viewers see the room and its **Responsible contact** in **Personal Settings → My Rooms** — useful for knowing who to ask if you can't book yourself.

## Calendar Clients Supported

| Client | Notes |
|---|---|
| Nextcloud Calendar | Full support. Optional [visual room browser](../features/calendar-patch.md) |
| Apple Calendar (macOS / iOS) | Full support. Auto-fix for `CUTYPE=INDIVIDUAL` |
| Microsoft Outlook | Full support via CalDAV account |
| Thunderbird | Full support via CalDAV account |
| eM Client | Full support. Auto-detection by LOCATION |

## See Also

- [Booking Rooms](booking-rooms.md) — how to book from each calendar app
- [Managing Bookings](managing-bookings.md) — approve, decline, reschedule, cancel
- [Notifications](notifications.md) — all email types
- [Personal Settings](personal-settings.md) — your rooms and bookings
- [Tips](tips.md) — client-specific tricks
- [Troubleshooting](troubleshooting.md) — when something doesn't work
- [FAQ](faq.md) — common questions
