# CalDAV Scheduling

The `SchedulingPlugin` is the heart of RoomVox's booking system. It is a Sabre DAV `ServerPlugin` registered at **priority 99** that intercepts iTIP scheduling messages for room principals, evaluates the request, delivers the event to the room calendar, and notifies the relevant parties.

This document explains why the plugin exists, the iTIP REQUEST and CANCEL flows, the post-write hook for client compatibility, and why the priority matters.

## Why a Custom Plugin?

Sabre's default scheduling handler runs at priority 100. To deliver an iTIP message to an attendee's calendar, it calls `getPrincipalByUri()` to resolve the principal. Resolution requires an **active user session** because Nextcloud's user backend reads the session.

Room principals (`principals/users/rb_*`) are **virtual** — they're hidden Nextcloud users that can't log in. There's no session to fall back on, so Sabre's default handler fails: it can't find the principal, it can't deliver the message, and the booking silently doesn't happen.

RoomVox's `SchedulingPlugin` intercepts the message **before** Sabre's handler at priority 99, performs delivery itself, and returns `false` to stop Sabre from attempting its own delivery.

## REQUEST Flow (New / Updated Booking)

When a calendar client adds a room to an event, the iCal data containing an `ATTENDEE` with the room's principal is uploaded to the organizer's calendar. Sabre's CalDAV server fires a `schedule-deliver` event. RoomVox's plugin runs first.

```
iTIP REQUEST arrives
    │
    ├─ 1. Resolve sender → Nextcloud user ID
    │
    ├─ 2. Permission check
    │     PermissionService::canBook(user, room)?
    │     If no permission → DECLINE (schedule status 3.7)
    │                        sendPermissionDenied() + clear room from organizer event
    │
    ├─ 3. Availability check
    │     Event time within room's availability rules?
    │     If outside → DECLINE (3.7) + sendOutsideAvailability()
    │
    ├─ 4. Booking horizon check
    │     Every occurrence (incl. recurrences) within max days?
    │     If beyond → DECLINE (3.7) + sendHorizonExceeded()
    │
    ├─ 5. Conflict detection
    │     CalDAVService::hasConflict() — expands RRULE, checks every occurrence
    │     If conflict → DECLINE (3.0) + sendConflict()
    │
    ├─ 6. PARTSTAT determination
    │     room.autoAccept ? ACCEPTED : TENTATIVE
    │
    ├─ 7. Attendee enrichment
    │     Fix CUTYPE=ROOM (iOS sends INDIVIDUAL), set LOCATION, set CN
    │
    ├─ 8. Deliver to room calendar
    │     CalDAVService::createBooking()
    │
    ├─ 9. Set schedule status → 1.2 (delivered)
    │
    └─ 10. Notifications
          ACCEPTED → MailService::sendAccepted(organizer)
          TENTATIVE → MailService::notifyManagers(approval request)
```

### Permission Denied Side Effect

When a booking is declined for permission reasons, RoomVox does more than just respond DECLINE:

1. **Removes the room attendee** from the organizer's own event
2. **Clears the `LOCATION` field**
3. **Sends a Permission Denied email** explaining the rejection

This prevents the organizer's calendar from showing a room they can't book — the event is corrected back to a no-room state. The same pattern is used for manager-cancellation in v1.1.0+.

### Recurring Event Conflict Checking

Since v1.1.0, conflict detection expands RRULEs via Sabre's `EventIterator` and checks **every occurrence** in the query window. Earlier versions only compared the master event's DTSTART/DTEND, which missed conflicts on later occurrences of weekly series ([#8](https://github.com/nextcloud/RoomVox/issues/8)).

`EXDATE` and `RECURRENCE-ID` overrides are handled natively, so excluding a single occurrence works correctly.

## CANCEL Flow

```
iTIP CANCEL arrives
    │
    ├─ 1. Find booking in room calendar by UID (+ optional recurrenceId)
    ├─ 2. Delete from room calendar (or write EXDATE for single occurrence, v1.1.1+)
    ├─ 3. Set schedule status → 1.2
    └─ 4. MailService::sendCancelled(organizer + managers)
```

### Single-Occurrence Cancellation (v1.1.1+)

A new optional `?recurrenceId=` query parameter on `DELETE /api/rooms/{id}/bookings/{uid}` and `DELETE /api/v1/rooms/{id}/bookings/{uid}` cancels one instance of a recurring booking:

- Writes `EXDATE` on the master `VEVENT`
- Removes any matching `RECURRENCE-ID` override
- The booker's own event gets a `RECURRENCE-ID` override `VEVENT` with the room attendee marked `DECLINED` and `LOCATION` cleared for that one instance

This matches the UX expectations of "Cancel this occurrence" while preserving the rest of the series.

## Post-Write Hook

After any `.ics` file write (organizer's calendar), the plugin fixes the organizer's copy:

- Sets `CUTYPE=ROOM` on room attendees (fixes iOS sending `CUTYPE=INDIVIDUAL`)
- Writes back the correct `PARTSTAT` on the room attendee (since RoomVox handled delivery, Sabre never wrote it)
- Detects eM Client bookings: scans the `LOCATION` field for a known room name, adds the room as an `ATTENDEE` when found

This post-write hook is what makes the iOS and eM Client client-compatibility fixes transparent — users never see or do anything special. The fixes happen during scheduling.

### iOS / macOS Fix

iOS sends rooms with `CUTYPE=INDIVIDUAL` instead of `CUTYPE=ROOM`. Without the fix, the organizer's calendar would show the room as a regular person attendee. The post-write hook detects rooms (by checking the principal URI against the `rb_*` pattern) and rewrites `CUTYPE`.

### eM Client Fix

eM Client sometimes sets only the `LOCATION` field of an event without adding the room as an `ATTENDEE`. The post-write hook reads `LOCATION` and, if it matches a known room name, adds the room as an attendee — going through the normal scheduling flow on the next save.

## ORGANIZER Field Construction (v1.1.1+)

Pre-1.1.1, `CalDAVService::createBooking()` unconditionally appended `@localhost` to the organizer when building the `ORGANIZER` property. External-email bookings became `mailto:user@company.com@localhost` (undeliverable) and `CN` was the raw email instead of a display name.

The property is now built via a shared resolver:

- External addresses → emitted as-is, enriched with `CN` only when matching exactly one Nextcloud user
- Nextcloud user IDs → canonical email + display name (same logic that fixed [#5](https://github.com/nextcloud/RoomVox/issues/5))
- Unresolvable organizers → property omitted rather than fabricated

## Manager-Approval Hook (v1.1.1+)

`POST /api/v1/rooms/{id}/bookings` (Public API) and `POST /api/rooms/{id}/bookings` (Internal API) on a room with `autoAccept=false` previously wrote directly to the room calendar via `CalDavBackend` and **skipped** the SchedulingPlugin entirely. This skipped the manager-notification hook, so approval emails weren't sent for API-created bookings.

Both endpoints now invoke the same notification path the iTIP flow uses — managers see API-created bookings in their approval queue exactly as they do bookings from Nextcloud Calendar.

## Why Priority 99?

Sabre's default scheduling handler runs at priority 100. Plugins fire in **descending** priority order. By registering at 99:

- RoomVox runs **first**
- RoomVox decides whether the message is for a room principal
- If yes, RoomVox handles it and returns `false` to stop Sabre
- If no (the message targets a real user), RoomVox returns `true` and Sabre's default handler processes normally

This selective interception keeps RoomVox's scope minimal — it only intercepts when needed.

## Schedule Status Codes

The plugin sets schedule status codes that calendar clients interpret:

| Code | Meaning | RoomVox use |
|---|---|---|
| 1.2 | Delivered, no further action | Successful booking, cancellation |
| 3.0 | Conflict detected | Time overlap with existing booking |
| 3.7 | Conditional decline | Permission, availability, or horizon failure |
| 5.3 | Temporary failure | Room sync in progress (Exchange initial sync) |

Codes 3.7 are deliberately "soft" — the organizer can retry with different parameters and may succeed. Code 5.3 is temporary — the same event retried later may succeed without changes.

## Performance Characteristics

- Permission check: O(1) for room-level entries, O(g) for group inheritance (g = number of room groups the user is a member of)
- Conflict detection: O(log n) via CalDAV time-range indexes on `firstoccurence`/`lastoccurence`
- Recurring event expansion: O(k) where k = occurrences within the query window
- Availability rule check: O(r) where r = number of rules (typically 1–2)

A "busy" room with many bookings actually resolves **faster** than an empty one — the time-range query finds a conflict sooner and returns DECLINED immediately without scanning further.

## See Also

- [Architecture Overview](overview.md) — system context
- [Backend Architecture](backend-architecture.md) — service layer and storage
- [Email Notifications](../features/email-notifications.md) — emails the plugin triggers
- [Availability Rules](../features/availability-rules.md) — rule semantics
- [Approval Workflow](../features/approval-workflow.md) — TENTATIVE / Manager flow
