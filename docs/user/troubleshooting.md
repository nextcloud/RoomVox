# User Troubleshooting

Common issues users experience with RoomVox and how to resolve them. For admin-side issues, see the [Admin Troubleshooting](../admin/troubleshooting.md).

## Rooms Not Appearing in Calendar Apps

### Room not visible in Nextcloud Calendar

**Likely cause:** The room is inactive, or you don't have the required permissions.

**Try:**

1. Ask your administrator to verify the room is **active** in the admin panel
2. Ask whether your account or group has been added with at least **Viewer** role
3. Hard-refresh the calendar: `Ctrl+Shift+R` / `Cmd+Shift+R`

### Room not visible in Apple Calendar / Outlook / Thunderbird

**Likely cause:** Your CalDAV account is not syncing resources, or the client hasn't picked up new resources yet.

**Try:**

1. Verify the CalDAV account is configured and syncing
2. Force a full resync of the CalDAV account
3. Some clients (especially Apple Calendar on iOS) require a restart to pick up new resources

## Booking Declined

### "No permission"

You don't have the Booker or Manager role for this room.

**Ask your administrator** to add you (or your group) as a Booker.

### "Scheduling conflict"

Another event is already booked at the requested time. Cancelled and declined bookings don't count as conflicts.

**Try:**

1. Pick a different time slot
2. For recurring events, check whether a single occurrence conflicts (the booking is declined when **any** occurrence overlaps)

### "Outside availability"

The requested time is outside the room's availability rules (e.g. weekdays 09:00–17:00).

**Try:**

1. Book within the allowed days and time window
2. Ask your administrator if the restriction is still needed

### "Beyond booking horizon"

The event is too far in the future.

**Try:**

1. Book within the room's maximum horizon (e.g., max 90 days ahead)
2. For recurring events, ensure the last occurrence is within the horizon
3. Infinite recurring events (no `UNTIL` or `COUNT`) are always declined when a horizon is set

### Booking stuck as "Tentative" / Pending

The room has auto-accept disabled and no manager has approved the booking yet.

**A room manager** needs to approve the booking. Managers receive email notifications about pending bookings.

## Calendar Client Issues

### Apple Calendar (iOS) sends the wrong attendee type

iOS sends room attendees with `CUTYPE=INDIVIDUAL` instead of `CUTYPE=ROOM`. **RoomVox automatically detects and fixes this** — no action needed.

### eM Client doesn't add the room as an attendee

eM Client sometimes only sets the `LOCATION` field without adding the room as an attendee. **RoomVox automatically detects this by matching the location against known room names** and adds the proper CalDAV attendee — no action needed.

## Language Issues

### Tour or notifications appear in the wrong language

RoomVox is available in English, Dutch, German, and French. The language is determined by your Nextcloud language setting.

**Try:**

1. Check your Nextcloud language in **Personal Settings → Language**
2. Hard-refresh: `Ctrl+Shift+R` / `Cmd+Shift+R`

If you'd like RoomVox in another language, contact your administrator — translations can be contributed via Transifex.

## See Also

- [FAQ](faq.md) — Common user questions
- [Booking Rooms](booking-rooms.md) — How to book from different calendar apps
- [Personal Settings](personal-settings.md) — My Rooms, Approvals, Bookings tabs
- [Admin Troubleshooting](../admin/troubleshooting.md) — If you're an administrator
