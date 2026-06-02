# User FAQ

Common questions from RoomVox users.

## Booking Basics

### How do I book a room?

Add the room as an attendee to a calendar event in any calendar app — Nextcloud Calendar, Apple Calendar, Outlook, Thunderbird, or eM Client. The room responds Accepted, Tentative, or Declined like a human attendee. See [Booking Rooms](booking-rooms.md) for per-app instructions.

### How do I know whether a room is available?

In **Nextcloud Calendar with the calendar patch installed**, the room browser shows real-time availability per room with green/red/blue badges. Without the patch, the standard resource picker shows availability in search results. In other calendar apps, the room responds to your invitation with Accepted/Declined immediately.

### What's the difference between Accepted and Tentative?

- **Accepted** — the room is confirmed. No further action needed.
- **Tentative** — the room requires manager approval. You'll be notified by email when approved or declined.

### Can I book a room without a Nextcloud account?

No. RoomVox uses Nextcloud's authentication. You need a Nextcloud account with at least Booker role for the room. External attendees can be invited to your event, but only Nextcloud users can book rooms.

## Permissions

### Why can't I book a room I can see?

You have **Viewer** role but not **Booker**. Ask your administrator to add you (or your group) as a Booker. The **Responsible contact** field in **Personal Settings → My Rooms** tells you who to ask.

### Why can I see some rooms but not others?

Rooms are restricted by permissions. You only see rooms where you have at least Viewer role — either directly or via group membership. Nextcloud administrators see all rooms regardless.

### Can my colleagues see my bookings?

Bookings live in CalDAV calendars owned by the room. Managers of the room can see all bookings; other users typically see only that the room is busy at a given time (free/busy), not the event details. Your event in **your own** calendar follows your usual calendar privacy rules.

## Recurring Events

### Can I book a recurring event?

Yes. RoomVox supports recurring events, with caveats:

- **Availability rules** apply to **every** occurrence
- **Booking horizon** is checked against the **furthest** occurrence
- **Infinite recurring events** (no `UNTIL` / `COUNT`) are always declined when a booking horizon is set
- **Conflict checking** applies to every occurrence — booking the second instance of a weekly series is correctly flagged as a conflict (fixed in v1.1.0)

### Can I cancel just one occurrence of a recurring booking?

Yes (since v1.1.1). When you cancel a recurring booking through the admin UI, you choose between **Cancel this occurrence** and **Cancel entire series**. Cancelling one occurrence writes an `EXDATE` on the master event and marks the room as `DECLINED` for that one instance in your own calendar.

## Notifications

### Why don't I receive emails?

Common reasons:

- Your administrator has not enabled email notifications in RoomVox settings
- Your Nextcloud profile has no email address — set one in **Personal Settings → Email**
- Nextcloud's SMTP isn't configured — ask your administrator
- Emails are in your spam folder — check there

See [Notifications](notifications.md) for the full list of notification types.

### Why does the email come from `noreply@…`?

Rooms without a real email address use the Nextcloud system sender. Your administrator can set a real email per room (e.g., `boardroom@company.com`), which becomes the sender for that room's notifications.

## Personal Settings

### Why don't I have an "Approvals" or "Bookings" tab?

Both tabs are **Manager-only**:

- **Approvals** — appears if you manage at least one room. Shows pending bookings to approve/decline.
- **Bookings** — appears if you manage at least one room. Shows the full booking overview scoped to your rooms (since v1.1.0).

If you're a Booker or Viewer, you only see the **My Rooms** tab.

### Can I subscribe to a room's calendar in my external calendar app?

Not directly. The iCal feed at `/api/v1/rooms/{id}/calendar.ics` requires a Bearer token, which external calendar apps don't support. Your administrator can create a Public API token and provide you a tokenized URL, or you can rely on your own calendar showing the events you've added the room to.

## Client-Specific

### iOS sends the wrong attendee type — does RoomVox handle that?

Yes. iOS / macOS Calendar sends room attendees with `CUTYPE=INDIVIDUAL` instead of `CUTYPE=ROOM`. RoomVox **auto-detects and corrects** this transparently. No action needed.

### eM Client only sets LOCATION — does RoomVox handle that?

Yes. eM Client sometimes adds a room only via `LOCATION` without an `ATTENDEE`. RoomVox **detects the room by matching the LOCATION against known room names** and adds the proper CalDAV attendee transparently.

### Which calendar apps work best?

| Client | Notes |
|---|---|
| Nextcloud Calendar (with patch) | Best experience — visual room browser, real-time availability, multi-filter |
| Nextcloud Calendar (standard) | Works fully, more basic resource picker |
| Apple Calendar (macOS, iOS) | Full support |
| Outlook (CalDAV) | Full support |
| Thunderbird | Full support |
| eM Client | Full support |

See [Tips](tips.md) for client-specific tricks.

## See Also

- [Overview](overview.md)
- [Booking Rooms](booking-rooms.md)
- [Notifications](notifications.md)
- [Personal Settings](personal-settings.md)
- [Tips](tips.md)
- [Troubleshooting](troubleshooting.md)
