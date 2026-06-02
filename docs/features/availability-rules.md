# Availability Rules

Each room can be restricted to **specific days and time windows** (e.g., weekdays 08:00–18:00) and to a **maximum booking horizon** (e.g., max 90 days ahead). Bookings outside these constraints are **automatically declined** with a clear email explanation.

## Availability Rules (Day / Time)

Configure in the room editor under **Restrict booking hours**:

1. Enable the toggle
2. Add one or more rules:
   - **Days** — pick which days of the week (Mon–Sun)
   - **From / To** — time window for those days
3. Use presets for common patterns

### Presets

| Preset | Days | Window |
|---|---|---|
| Weekdays 08–18 | Mon–Fri | 08:00–18:00 |
| Weekdays 09–17 | Mon–Fri | 09:00–17:00 |

You can also build custom rules — for example, separate rules for weekdays (08–18) and Saturday morning (09–13).

### Show / Hide Weekends

App-wide, you can toggle whether the booking calendar **displays** weekends — but this is a UI setting only. To **prevent** weekend bookings, use Availability Rules with weekday-only days. The two settings are independent.

## Booking Horizon

Limit how far in advance a room can be booked:

- **0** (default) — no limit
- **N** days — bookings beyond `today + N` are declined

Useful for high-demand rooms to prevent speculative reservations months in advance.

## How Bookings Are Evaluated

When a booking arrives, RoomVox checks (in order):

1. **Permission** — user has at least Booker role on the room
2. **Availability rules** — every occurrence falls inside the allowed days/times
3. **Booking horizon** — every occurrence is within `today + horizon` days
4. **Conflict detection** — no overlapping accepted/tentative bookings

A failure in any step results in a **DECLINED** response with an email naming the reason.

## Recurring Events

Both availability rules and the booking horizon apply to **every occurrence** of a recurring event:

- A weekly meeting fails if **any** week falls outside the availability rules
- A weekly meeting fails if **any** occurrence is beyond the booking horizon
- **Infinite recurring events** (no `UNTIL` / `COUNT`) are **always declined** when a booking horizon is set — there's no way to verify they all fit

The conflict-detection layer also expands recurrences correctly since v1.1.0 — booking the second instance of a weekly series now correctly triggers a conflict mail. Before v1.1.0, only the master event was checked, missing later-occurrence conflicts.

## Email Feedback to the Organizer

When a booking is auto-declined due to availability or horizon, the email tells the organizer **why** so they can reschedule without guessing:

### Outside Availability Hours

> The room is only available **Mon, Tue, Wed, Thu, Fri 09:00–17:00**.

### Booking Horizon Exceeded

> This room can only be booked up to **60 days** ahead.
> The earliest date that is no longer bookable: **2026-09-15**.

### Scheduling Conflict

> Another booking exists from **14:00 to 15:30** on **2026-06-10**.

See [Email Notifications](email-notifications.md) for the full list.

## Use Cases

### Office hours only

Restrict the boardroom to weekdays 09:00–17:00 to prevent after-hours bookings that would require key/lighting access.

### Two-shift building

Two separate rules — weekdays 08:00–17:00, Saturday 09:00–13:00 — to allow weekend morning bookings without opening up Sunday.

### Quarterly planning constraint

Set the booking horizon to 90 days on high-demand training rooms to prevent the calendar being booked solid for next year already.

### Infinite recurring fence

Combine a 365-day horizon with **no** infinite recurrences — users who try to book a weekly meeting "indefinitely" get a clear rejection and can re-book with a 1-year `UNTIL` clause.

## Architectural Notes

- Availability is published as a CalDAV `VAVAILABILITY` object on each room's calendar, so capable clients can show the available window in their UI directly
- Rules are stored per-room in IAppConfig (`room/{roomId}` → `availabilityRules`)
- The booking horizon is stored as `maxBookingHorizon` (integer days, 0 = no limit)
- Evaluation happens server-side in the [CalDAV Scheduling Plugin](../architecture/caldav-scheduling.md) — no client-side enforcement, no way to bypass

## See Also

- [Managing Rooms](../admin/room-management.md#booking-behavior) — where to configure rules
- [Email Notifications](email-notifications.md) — the decline emails
- [CalDAV Scheduling](../architecture/caldav-scheduling.md) — how the plugin evaluates bookings
- [Best Practices](../admin/best-practices.md#use-availability-rules-instead-of-manual-approval) — when to use rules vs. manager approval
