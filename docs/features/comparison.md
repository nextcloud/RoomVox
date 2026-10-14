# RoomVox vs Nextcloud Calendar Resource Management

This page compares RoomVox with Nextcloud's own [Calendar Resource Management](https://github.com/nextcloud/calendar_resource_management) app (CRM). CRM recently gained a web interface, so we often get asked: *what does RoomVox add?*

Checked in **October 2026** against RoomVox 1.6.1 and against the CRM code and issue tracker: stable release 0.12.2, release candidate 1.0.0-rc.2 (the first release with the web UI), and `main`. CRM 1.0.0 itself was not out yet at the time of writing. Calendar behaviour refers to Nextcloud Calendar 6.6 on Nextcloud 35.

## In short

The two apps solve different problems.

- **Calendar Resource Management is a room register.** You enter buildings, floors, rooms and resources. Nextcloud then shows those rooms in the Calendar app, so users can invite them to an event. CRM contains no booking logic itself. Whatever happens after the invitation is done by Nextcloud server: a free/busy check that accepts or declines the room.
- **RoomVox is a booking system.** Besides publishing rooms to the calendar, it decides *who* may book *which* room, *when* and *how far ahead*. It can route bookings through a manager for approval, emails everyone involved, and connects rooms to Microsoft 365/Exchange, displays and other systems.

If you only need rooms to show up in the calendar and the free/busy answer is good enough, CRM is a sound and free choice. Once you need rules, approval, notifications or access control per room, you need RoomVox.

## At a glance

| | **RoomVox** | **Calendar Resource Management** |
|---|---|---|
| Version | 1.6.1 | 0.12.2 stable · 1.0.0-rc.2 with web UI |
| Maintainer | VoxCloud | Nextcloud GmbH |
| License | AGPL-3.0 | AGPL-3.0 |
| Nextcloud | 32–35 | 31–35 (0.12) · 34–35 (1.0) |
| PHP | 8.2+ | 8.1–8.5 (0.12) · 8.3–8.5 (1.0) |
| Admin interface | Web UI (admin) + personal page for managers | Web UI in admin settings (1.0, list/create/delete) + `occ` |
| Interface languages | English, Dutch, German, French | English only |
| Who decides on a booking | RoomVox's own scheduling rules | Nextcloud server's free/busy check |

## Feature comparison

### Booking rules

| | **RoomVox** | **CRM** |
|---|:---:|:---:|
| Rooms appear in the room finder of Nextcloud Calendar 6.6+ | Yes | Yes |
| Double-booking check for single events | Yes | Yes (by Nextcloud server) |
| Double-booking check for recurring events | Yes, every occurrence | No — server does not answer recurring invitations |
| Auto-accept | Per room | Always, if free |
| Manager approval | Per room | No ([#198](https://github.com/nextcloud/calendar_resource_management/issues/198), open) |
| Opening hours per room (days and times) | Yes, multiple rules | No |
| Booking horizon (max days ahead) | Yes, per room | No |
| Deactivate a room temporarily | Yes, existing bookings are kept | No |

### Notifications

| | **RoomVox** | **CRM** |
|---|:---:|:---:|
| Email to the organizer | Accepted, declined (with the reason), cancelled | No |
| Email to room managers | Approval requests, cancellations | No |
| Emails in the recipient's language | Yes | — |
| Send from the room's own mail account (per-room SMTP) | Yes, password encrypted | No |

### Access control

| | **RoomVox** | **CRM** |
|---|:---:|:---:|
| Restrict who can book a room | Yes, per user or group (incl. LDAP) | Per group, `occ` only |
| Roles | Viewer, Booker, Manager | — |
| Permissions on a group of rooms, inherited by its rooms | Yes | No |
| Hide rooms from users who may not see them | Yes | Yes (restricted rooms) |
| Managers handle bookings for their own rooms | Yes, without admin rights | No |
| Delegate room setup to a non-admin | No, admin only | Yes, via Nextcloud's *Administration privileges* |

### Administration

| | **RoomVox** | **CRM** |
|---|:---:|:---:|
| Create and delete rooms in a web UI | Yes | Yes (1.0) |
| Edit an existing room | Yes | Not yet — no edit in the UI or `occ` ([PR #342](https://github.com/nextcloud/calendar_resource_management/pull/342) pending) |
| Overview of all bookings (calendar and list) | Yes | No |
| Usage statistics | Yes | No |
| Bulk import/export (CSV) | Yes, including Microsoft 365 `Get-Place` export | No |
| Room types | Configurable list | Free text per room |
| Facilities | Configurable list | Fixed set of six (phone, video, TV, projector, whiteboard, wheelchair access) |

### Integrations

| | **RoomVox** | **CRM** |
|---|:---:|:---:|
| Microsoft 365 / Exchange room mailboxes, two-way sync | Yes | No |
| Public REST API (displays, kiosks, signage) | Yes, scoped tokens | No |
| Subscribable iCal feed per room | Yes | No |
| Fixes for Apple Calendar (iOS/macOS) and eM Client | Yes | No |

### Resource model

| | **RoomVox** | **CRM** |
|---|:---:|:---:|
| Rooms | Yes | Yes |
| Buildings and floors | As room fields (building, floor, address) | Separate entities |
| Equipment and other resources | No | Yes |
| Vehicles | No | Yes (`occ` only) |
| Database tables | None — config in app settings, bookings in CalDAV | Own tables |

## What RoomVox adds

### Recurring meetings are checked too

Nextcloud server answers a room invitation by checking the room's free/busy time. It does this **only for single events**: invitations with a repeat rule get no answer at all, so a weekly meeting can land on an occupied room without anyone noticing. There are open reports of double bookings made from other calendar apps as well ([#199](https://github.com/nextcloud/calendar_resource_management/issues/199), [#192](https://github.com/nextcloud/calendar_resource_management/issues/192)).

RoomVox checks **every occurrence** of a recurring series against existing bookings, opening hours and the booking horizon. If one date conflicts, the series is declined and the email lists the dates that are the problem, so the organizer can fix exactly those.

### Rules per room

Each room can have:

- **Opening hours** — for example weekdays 08:00–18:00, with several rules per room.
- **A booking horizon** — for example at most 90 days ahead. A series that repeats forever is refused for a room with a horizon.
- **Auto-accept or approval** — some rooms are free to book, others go via a manager.

CRM has none of these. Nextcloud server can honour opening hours if they are set on the room's calendar, but CRM offers no way to set them.

### Approval by a room manager

For rooms that need approval, the booking is marked *tentative* and the room's managers get an email. They approve or decline from their personal RoomVox page in Nextcloud, and the organizer is informed by email. Managers need no admin rights. A manager booking their own room is accepted directly.

### Everyone hears what happened

With CRM, the only feedback is the room's status in the organizer's calendar app. Nextcloud does not email rooms or resources, and no one is told when something goes wrong.

RoomVox emails the organizer when a booking is accepted, declined or cancelled, and always says *why* a booking was declined: no permission, outside opening hours, beyond the horizon, or a conflict. Managers get approval requests and cancellations. Emails are sent in each recipient's own language, and a room can send from its own mail account.

### Access control that fits an organization

RoomVox has three roles — **Viewer**, **Booker** and **Manager** — that you assign to users or groups, including LDAP groups. Give a whole group of rooms its permissions once, and every room in it inherits them. Rooms a user may not see are hidden from the room picker.

CRM can restrict a room to certain groups, but only on the command line, and without a distinction between viewing, booking and managing.

### Microsoft 365 and Exchange

RoomVox can link a room to an Exchange room mailbox and keep both sides in sync:

- Bookings made in Nextcloud are written to Exchange.
- Bookings made in Outlook/Exchange are pulled into Nextcloud, through webhooks and a sync every 15 minutes.
- Exchange bookings count in the double-booking check.

This lets an organization move to Nextcloud step by step, or run both side by side. Rooms can also be imported straight from a Microsoft 365 `Get-Place` export.

### Connect displays and other systems

The [Public API](public-api.md) lets room displays, kiosks, digital signage and other applications read room status and availability, and create or cancel bookings. Each token has a scope (read, book or admin), can be limited to specific rooms and can expire. Every room can also publish a subscribable iCal feed.

### Works with the calendar apps people already use

Booking happens in the user's own calendar app. RoomVox corrects known quirks so bookings also work from:

- **Apple Calendar (iOS/macOS)**, which invites rooms as a person instead of a room.
- **eM Client**, which only fills in the location field.

Like CRM, RoomVox publishes each room's building, floor, capacity and facilities. The room finder in Nextcloud Calendar 6.6 and later uses these to filter rooms and group them by building. Rooms are only grouped by building when the room's building field is filled in; rooms without one are listed under *Other rooms*.

### Day-to-day management

- A **booking overview** in calendar and list view, for admins and for managers of their own rooms.
- **Statistics** on room usage.
- **CSV import and export** with a preview before anything changes.
- **Editing** any room setting at any time. In CRM 1.0 a room can only be created or deleted; editing is still in review.

## Where Calendar Resource Management is the better fit

- **Equipment, vehicles and other resources.** RoomVox only handles rooms.
- **Buildings and floors as separate entities.** RoomVox stores building and floor as fields of a room, which is enough for filtering and grouping but not a full location hierarchy.
- **Delegating room setup.** CRM's settings can be assigned to a non-admin through Nextcloud's *Administration privileges*. In RoomVox, managers handle bookings but creating rooms and setting permissions stays with the admin.
- **A minimal setup.** If rooms only need to be visible and free/busy is enough, CRM does the job with less to configure.

## Which one to choose

| You need… | Choose |
|---|---|
| Rooms visible in the calendar, simple free/busy | Calendar Resource Management |
| Equipment or vehicle booking | Calendar Resource Management |
| Reliable checks on recurring meetings | **RoomVox** |
| Opening hours or a booking horizon per room | **RoomVox** |
| Approval by a room manager | **RoomVox** |
| Email confirmations and reasons for a decline | **RoomVox** |
| Viewer/Booker/Manager roles per room or group of rooms | **RoomVox** |
| Microsoft 365 / Exchange sync, or migration from it | **RoomVox** |
| Room displays, kiosks or other integrations via an API | **RoomVox** |
