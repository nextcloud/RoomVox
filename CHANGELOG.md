# Changelog

All notable changes to RoomVox will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Known issues
- **Booking two rooms on one event without auto-accept only requests approval for the first** ([#22](https://github.com/nextcloud/RoomVox/issues/22)): investigated but not yet fixed. RoomVox's per-recipient handling (permission check, PARTSTAT, delivery to each room's calendar, manager notification) is correct and independent per room; the remaining suspect is Sabre/Nextcloud's iTIP dispatch (whether two room attendees produce one `schedule` event or two), which can't be confirmed by static analysis. Fix deferred pending a live trace.

## [1.2.2] - 2026-08-12 - Room metadata reaches CalDAV clients

### Added
- **Rooms publish their building name**: RoomVox holds the building as its own field, but CalDAV had nowhere to put it, so clients that group rooms per building had to infer one from the address — usually by taking everything before the first comma, which turns a room at "1098 XG, Amsterdam" into a building called "1098 XG". Rooms now publish `{http://nextcloud.com/ns}room-building-name`, taken from the building field itself rather than guessed. Proposed upstream as `IRoomMetadata::BUILDING_NAME`; clients that do not know the key yet simply ignore it.

### Fixed
- **Room floor was invisible to every CalDAV client**: the room backend published the floor under `{http://nextcloud.com/ns}room-building-floor`, which is not a key any client asks for. Nextcloud defines the property as `IRoomMetadata::BUILDING_STORY` (`room-building-story`), and cdav-library requests only that one in its PROPFIND, so the value never reached Nextcloud Calendar, Outlook, Apple Calendar or Thunderbird. The backend now publishes `room-building-story`. Nothing consumed the old key, so this only adds data where there was none.
- **Imported rooms published a malformed address**: addresses are stored in a fixed four part format ("Building, Street, PostalCode, City") so the room editor can split them back apart, but that is an internal detail. A room imported without a building or street was published to CalDAV clients as `, , 1098 XG, Amsterdam`, which clients show verbatim and use to derive a building name. Empty positions are now dropped when publishing, and in the room description, without touching how rooms are stored — the editor keeps its four fields. This matters more now that Nextcloud Calendar groups rooms by building; see [nextcloud/calendar#8264](https://github.com/nextcloud/calendar/pull/8264).

## [1.2.1] - 2026-08-03 - Room group in booking filter

### Changed
- **Booking administration room filter now shows the room group** ([#19](https://github.com/nextcloud/RoomVox/issues/19)): in installations with multiple room groups, room names are often reused (e.g. "Church" and "Office" in both "Parish A" and "Parish B"), and the filter listed them indistinguishably. Each option is now labelled "Room (Group)" — e.g. "Church (Parish A)" — so rooms can be told apart. Rooms without a group keep their plain name. Frontend-only; no API or data changes.

## [1.2.0] - 2026-08-03 - Subscribe-able iCal feeds, manager auto-accept & i18n fixes

### Security
- **"Show all rooms" dialog ignored room visibility permissions** ([#20](https://github.com/nextcloud/RoomVox/issues/20)): In the Nextcloud Calendar room picker, the type-ahead field correctly hid rooms a user may not view, but the "Show all rooms" dialog listed every room — exposing room names, locations, and organisational structure to unauthorised users. The dialog now intersects the calendar's room list against a server-authoritative allow-list (`GET /api/personal/rooms/viewable`, filtered by `PermissionService::canView`), so it shows exactly the rooms the type-ahead does. The allow-list endpoint returns only room id + email (no other detail), and the dialog fails closed — if the allow-list can't be loaded it shows no rooms rather than risk leaking. Booking was already enforced server-side by the scheduling plugin; this closes the information-disclosure gap in the browse UI.

### Added
- **Public, subscribe-able iCal feed per room** ([#16](https://github.com/nextcloud/RoomVox/issues/16)): The iCal feed at `/api/v1/rooms/{id}/calendar.ics` already existed, but required a `Bearer` token — which external calendar apps (Nextcloud Calendar, Outlook, Apple Calendar, Thunderbird) and signage displays cannot send, so nobody could actually subscribe to it. Rooms can now expose an opt-in feed at `/api/v1/rooms/{id}/feed/{secret}/calendar.ics`, authenticated by a per-room secret in the URL path instead of a header. Admins/room-managers enable, copy, and regenerate the feed URL from the room editor. The secret is read-only and room-scoped by construction: a leaked URL only exposes that one room's bookings and can be rotated per room without affecting API tokens or other rooms. Endpoint is brute-force protected against secret enumeration, comparison is timing-safe (`hash_equals`), the raw secret is never logged nor returned in room-list responses, and feeds are off by default (explicit opt-in per room). The existing Bearer-authenticated `calendar.ics` route is unchanged; both share one iCalendar builder so their output is byte-identical.

### Changed
- **A manager booking their own room no longer needs a second manager to approve it** ([#23](https://github.com/nextcloud/RoomVox/issues/23)): On a room with manual approval (`autoAccept=false`), a booking whose organizer is a manager of that room is now accepted directly instead of being parked as `TENTATIVE` in the approval queue. Requiring a manager to approve their own booking was a pointless extra step. Non-managers are unaffected — their bookings still go through the normal approval flow — and rooms without any configured managers are unchanged. The decision lives at the single PARTSTAT branch in `SchedulingPlugin::handleRequest()`.

### Fixed
- **German umlauts stripped instead of transliterated in generated identifiers** ([#18](https://github.com/nextcloud/RoomVox/issues/18)): Room/room-group slugs derived from names removed `ä/ö/ü/ß` outright, so "Küche" became `kche` and "Außenbereich" became `auenbereich`. They are now transliterated the standard German way (`ä→ae`, `ö→oe`, `ü→ue`, `ß→ss`), yielding `kueche` / `aussenbereich`. The slug logic — previously duplicated in `RoomService`, `RoomGroupService`, and `ImportExportService` — was consolidated into a single `RoomService::generateSlug()` so CSV import-matching stays byte-identical to room creation.
- **Unnecessary comma between postal code and city in event locations** ([#17](https://github.com/nextcloud/RoomVox/issues/17)): A room address of "…, 01324, Dresden" produced the event `LOCATION` "…, 01324, Dresden" instead of "…, 01324 Dresden". The calendar patch now formats the room address (postal code and city space-joined, building/room number folded into a trailing detail) instead of pasting the raw comma-separated address string, and the 3-part postal-code detection in `buildRoomLocation()` now also recognises 5-digit German postal codes (previously only the Dutch "1234 AB" form).
- **User's locale ignored in dates and times** ([#21](https://github.com/nextcloud/RoomVox/issues/21)): Dialogs and list views formatted dates/times with the browser default locale (often US `MM/DD`), confusingly disagreeing with the user's Nextcloud language. All `toLocale*String()` calls now pass the Nextcloud user locale (`getLanguage()`), matching the calendar view which already did so.

## [1.1.3] - 2026-06-22

### Fixed
- **Nextcloud Enterprise instances were never recognised in telemetry.** The telemetry instance hash was computed differently from the license `instance_url_hash` (different fallback source and/or no URL normalisation), so the license server's enterprise-claim validation could never match the two. `TelemetryService::getInstanceHash()` now delegates to `LicenseService::getInstanceUrlHash()`, guaranteeing both are byte-for-byte identical. Existing instances will report under a new (correct) instance hash on their next telemetry run.

## [1.1.2] - 2026-06-12 - Nextcloud 34 compatibility + documentation restructure

### Changed
- **Nextcloud 34 support** — Bumped `max-version` in `appinfo/info.xml` from 33 to 34. No code changes required: `Application.php` is Bootstrap-based (`IBootstrap`), all `\OC::$server->get*()` service-locator calls were eliminated in prior releases (notably `MailService::notifyManagers` / `sendCancelled` in v1.1.1), logging is PSR-3 throughout (`LoggerInterface`), `getAppValue()` calls all have defaults, and DAV registration uses `registerCalendarRoomBackend()` (the NC 30+ API). Sabre plugin registration via `SabrePluginAuthInitEvent` continues to work on NC 34. Verified by smoke-test on a Nextcloud 34.0.0 development instance.
- **Documentation restructured to match IntroVox/IntraVox/MetaVox layout** — Replaced the previous flat `docs/` tree (with one `troubleshooting.md`, `comparison.md`, and `future-*.md` at root) with a nested structure: `docs/index.md` hub, `docs/getting-started.md`, plus `admin/`, `user/`, `features/`, `architecture/`, and `deployment/` subdirectories. Added 14 new docs covering admin guide / settings / best-practices / FAQ, user overview / personal-settings / FAQ / tips / troubleshooting (split from the combined troubleshooting file), `features/{approval-workflow, availability-rules, email-notifications, public-api}`, and `architecture/{backend-architecture, caldav-scheduling, exchange-integration}`. Removed three internal-only docs (`future-ideas.md`, `future-personal-settings.md`, `exchange-sync-changelog.md`) from the public tree. README and `appinfo/info.xml` `<documentation>` block updated to point at the new hub pages.

## [1.1.1] - 2026-05-26 - Bug fixes — recurring cancel, public API gaps & form save

### Fixed
- **`responsibleContact` silently dropped on room create/update & opaque permission denies** ([#15](https://github.com/nextcloud/RoomVox/issues/15)): Two unrelated defects rolled into one user report. (1) The "Responsible contact" field (introduced in [#11](https://github.com/nextcloud/RoomVox/issues/11)) reached the frontend form and `RoomService`, but `RoomApiController::create()` and `update()` whitelisted the request payload field by field and `responsibleContact` was missing from both lists — so the value was filtered out before reaching the service layer and any edit appeared to "not save". The field is now in both whitelists; a regression test exercises the round-trip. (2) Permission denies caused by the iTIP sender resolving to zero or multiple Nextcloud users (typical for LDAP/AD setups where the same email address exists on more than one account) were logged at `debug` level only, so admins saw an "automatically declined — you do not have permission" mail without any actionable trace in the server log. The log is now `warning` level and names the sender email, the match count, and a sample of the resolved UIDs, so duplicate-account configurations are immediately visible. No behaviour change to the deny itself — the underlying group-permission resolution was correct
- **Approval mail never sent for non-auto-accept bookings via REST API & malformed `ORGANIZER`** ([#14](https://github.com/nextcloud/RoomVox/issues/14)): Two related defects on the API booking-create path. (1) `POST /api/v1/rooms/{id}/bookings` and `POST /api/rooms/{id}/bookings` on a room with `autoAccept=false` produced a `TENTATIVE` booking but skipped the manager-approval mail, because both controllers wrote directly to the room calendar via `CalDavBackend` and never traversed the Sabre `SchedulingPlugin` (where the manager-notification hook lives). Both endpoints now invoke the same notification path the iTIP flow uses, so managers see API-created bookings in their approval queue exactly as they do bookings made from Nextcloud Calendar — including the room-move case in the internal API. (2) `CalDAVService::createBooking()` unconditionally appended `@localhost` to the organizer when building the `ORGANIZER` property, so external emails became `mailto:user@company.com@localhost` (undeliverable) and `CN` was set to the raw email instead of a display name. The property is now built via a shared resolver: external addresses are emitted as-is (enriched with a `CN` only when they match exactly one Nextcloud user), Nextcloud user IDs resolve to canonical email + display name (the same logic that fixed [#5](https://github.com/nextcloud/RoomVox/issues/5) for the LOCATION-fallback), and unresolvable organizers cause the property to be omitted rather than fabricated. Internal cleanup: `MailService` migrated off the `\OC::$server->get()` service-locator anti-pattern in `notifyManagers` and `sendCancelled`, in favour of proper constructor injection of `IUserManager`
- **Cancelling one occurrence of a recurring booking removed the whole series** ([#13](https://github.com/nextcloud/RoomVox/issues/13)): The confirmation dialog mentioned only the clicked event, but on confirm the entire iCal object was deleted, taking every occurrence with it. The admin UI now offers an explicit choice between "Cancel this occurrence" and "Cancel entire series" whenever the booking is part of a recurring series; single-occurrence cancellation writes an `EXDATE` on the master `VEVENT` and removes any matching `RECURRENCE-ID` override instead of deleting the calendar object. The booker's own calendar gets a `RECURRENCE-ID` override `VEVENT` with the room attendee marked `DECLINED` and `LOCATION` cleared for that one instance, and the cancellation mail names the specific occurrence so it cannot be mistaken for a series-wide cancel. Exchange-synced rooms cancel the matching instance via the Graph `events/{master}/instances` endpoint. The internal API (`DELETE /api/rooms/{id}/bookings/{uid}`) and Public API v1 (`DELETE /api/v1/rooms/{id}/bookings/{uid}`) both accept a new optional `?recurrenceId=` query parameter; the existing series-delete behaviour is unchanged when it is omitted

## [1.1.0] - 2026-05-18

### Added
- **Manager Bookings overview** ([#12](https://github.com/nextcloud/RoomVox/issues/12)): Managers now get a third "Bookings" tab in Settings → Personal → RoomVox (next to "My Rooms" and "Approvals"), showing the same overview admins already had under Settings → Administration. It is scoped to rooms the user can manage via a new `?scope=manage` query param on the `/api/all-bookings` endpoint, and inherits everything from the existing `BookingOverview` component: stats cards, room and status filters, list/calendar toggle, and drag-and-drop move-between-rooms in the calendar view. The tab only appears for users with at least one managed (or admin) room
- **Responsible contact field for rooms** ([#11](https://github.com/nextcloud/RoomVox/issues/11)): Admins and managers can now set a free-text "Responsible contact" on each room (e.g. `Anne Janssen (anne@voxcloud.nl)` or `Ask building manager`). The value is visible to every user with view-permission in Personal Settings → My Rooms, so viewers know who to approach when they cannot book a room themselves. Stored alongside the existing room JSON (no migration needed), clamped to 255 characters. Also exposed via the Public API: `GET /api/v1/rooms` now includes a `responsibleContact` field in each room object

### Fixed
- **Admin booking-deletion not communicated to the booker** ([#10](https://github.com/nextcloud/RoomVox/issues/10)): When an admin or manager removed an already-accepted booking via the UI, the booker was not notified and the room kept showing as reserved in the booker's own calendar event. The cancel flow now mirrors the iTIP-CANCEL path: the room attendee is removed from the booker's event (and `LOCATION` cleared) and a `sendRespondCancelled` mail goes out explaining the booking was cancelled by a room manager. The action is renamed "Cancel booking" in the UI (with a "Keep booking" dismiss action) so it no longer looks like a destructive admin-only delete

### Added
- **Translations for the Calendar patch UI** ([#9](https://github.com/nextcloud/RoomVox/issues/9)): RoomVox-specific labels in the patched Nextcloud Calendar editor (In-person, Online (Talk), Suggested conference rooms, room types, facility names, room status badges and more) now resolve via the `roomvox` translation bundle instead of asking Calendar's own bundle for strings it never had. Adds 34 source strings in `l10n/en.{json,js}` with translations for German, Dutch and French. Hardcoded English labels in `resourceProps.js` and the "Room " number prefix in `principal.js` / `ResourceList.vue` are now wrapped in `t()` calls so they pick up locale too

### Fixed
- **Conflicts not detected on later occurrences of a recurring booking** ([#8](https://github.com/nextcloud/RoomVox/issues/8)): `hasConflict()` compared the requested time only against the master event's DTSTART/DTEND, so booking the second (or any later) occurrence of a weekly meeting was wrongly seen as a free slot — even though auto-accept would happily add the room a second time. The check now expands recurrences via Sabre's `EventIterator` and walks each occurrence inside the query window, with native EXDATE / RECURRENCE-ID handling. Same pattern as the iCal-feed fix from #4
- **Resource booking silently ignored when it exceeds the booking horizon** ([#7](https://github.com/nextcloud/RoomVox/issues/7)): Bookings that exceeded the room's `maxBookingHorizon` were declined without any notification to the organizer — the calendar event was simply created without the room attached. The scheduling plugin now sends a decline mail naming the configured horizon (in days) and the earliest date that is no longer bookable, so the organizer can reschedule without guessing. The same fix is applied to two other previously-silent reject paths: bookings outside the room's availability hours, and bookings made while a room's initial Exchange sync is still running
- **Location fields shift between Building/Street/Postal code when some are left empty** ([#6](https://github.com/nextcloud/RoomVox/issues/6)): The Room editor composed the stored `address` by joining the four parts (Building, Street, Postal code, City) and silently dropping empty ones. Reloading the room split that shorter string positionally, so e.g. Postal code would migrate into Street. The composer now always emits all four positions (empty parts kept), matching the convention already used by the CSV import path. Existing rooms whose address was saved via the buggy UI may need to be re-edited once; rooms imported via CSV are unaffected
- **ORGANIZER malformed when booking a room without explicit organizer** ([#5](https://github.com/nextcloud/RoomVox/issues/5)): Clients like eM Client omit the ORGANIZER property on single-organizer events. RoomVox's LOCATION-fallback path filled it in with `mailto:<userId>` (a Nextcloud username, no `@domain`, no CN). It now resolves the calendar owner's real email and display name via `IUserManager`, producing `ORGANIZER;CN=<name>:mailto:<email>`. If the user has no email configured the property is left unset, since the LOCATION-fallback writes the booking directly into the room calendar and does not need iTIP REPLY mails
- **Recurring bookings only show first occurrence** ([#4](https://github.com/nextcloud/RoomVox/issues/4)): A weekly (or other RRULE) booking appeared only once in both the iCal feed and the Booking Overview. Two underlying causes:
  - The iCal feed (`/api/v1/rooms/{id}/calendar.ics`) expanded RRULE server-side and emitted N VEVENTs sharing one UID with no RECURRENCE-ID, which clients deduplicate per RFC 5545 §3.8.4.7. The feed now passes through master VEVENTs with RRULE/EXDATE/RECURRENCE-ID intact so clients expand recurrences themselves. The hard-coded ±30-day window is also gone — open-ended series are no longer truncated
  - `CalDAVService::getBookings()` relied on `VCalendar::expand()`, which silently returns only the master event when the VTIMEZONE contains DAYLIGHT/STANDARD components with 1970 DTSTARTs (the standard Nextcloud Calendar output). Replaced with `EventIterator`, which expands the series reliably regardless of timezone definitions

## [1.0.6] - 2026-04-17

### Fixed
- **Rooms visible to users without permission**: new Sabre `RoomVisibilityPlugin` filters room principals out of PROPFIND responses for users who lack view access
- **Calendar patch toggles unresponsive on NC 6.3**: migrated `NcCheckboxRadioSwitch` bindings from Vue 2 to Vue 3 / `@nextcloud/vue` v9 syntax

## [1.0.5] - 2026-04-15

### Fixed
- **Room visibility ignores group permissions**: Rooms in a group with configured permissions were still visible to all users in "Suggested conference rooms". The `group_restrictions` in Nextcloud's room cache remained empty because the `PermissionService` did not always have access to the `RoomService` during background sync (DI timing issue). The `RoomBackend` now resolves group permissions directly when the normal merge path fails

## [1.0.4] - 2026-04-14

### Fixed
- **Cannot remove room from group**: Moving a room to "No group" had no effect because the controller filtered out `null` values, so the `groupId` was never cleared. Moving to a different group worked fine since that sent a non-null value

## [1.0.3] - 2026-04-14

### Fixed
- **Room visibility not updating after permission changes**: Changing permissions on a room or room group did not trigger a sync of Nextcloud's room cache, so rooms remained visible (or hidden) in the Room Finder until a different room update triggered the sync
- **No email notification on permission-denied bookings**: When a user without permission tried to book a room, the booking was silently declined with no feedback other than a small warning icon in the calendar. Now a "Booking not permitted" email is sent to the organizer explaining they lack permission
- **Declined booking not cleaned up in organizer's calendar**: When a booking was automatically declined (e.g. due to permissions), the room attendee and LOCATION remained in the organizer's event. Now the room attendee is removed and LOCATION is cleared for all automatic declines, matching the existing behavior for manager declines

### Improved
- **Permission Editor shows inherited group permissions**: When editing permissions for a room in a group, the editor now displays inherited group permissions as read-only entries with an "inherited" badge alongside the editable room-specific permissions

## [1.0.2] - 2026-04-10

### Fixed
- **Manager role cannot accept/decline bookings**: Non-admin users with the Manager role received "Failed to process response" because the booking API endpoints were missing the `#[NoAdminRequired]` attribute, causing Nextcloud's security middleware to block the request before the internal permission check could run
- **Group-level permissions not enforced at booking time**: The scheduling plugin only checked room-level permissions, ignoring inherited group permissions. Rooms with group-only permission rules were bookable by anyone
- **Room creation loses fields**: Creating a new room discarded Room number, Floor, Room type, and Address because the controller did not extract these fields from the request. Editing the room afterwards worked because the update endpoint did handle them (except Floor, which was also missing there)
- **Declined booking still shows "Reserved" in Room Finder**: The previous fix (v1.0.0) propagated the decline to the organizer's calendar but kept the room as an attendee with PARTSTAT=DECLINED. The Room Finder only checked attendee presence, not status. Now the room attendee is removed entirely and LOCATION is cleared on decline. The frontend also treats DECLINED attendees as not added
- **Permission Editor UI inconsistency for grouped rooms**: The group permission editor stated that individual rooms can have additional permissions, but the room editor was read-only for rooms in a group. The backend already supported merging room + group permissions; the UI now allows setting room-specific permissions

## [1.0.1] - 2026-04-09

### Added
- **Telemetry send button**: Admins can now manually send a usage report from the Support tab, with clear feedback on success or failure
- **Telemetry toggle**: Enable/disable anonymous usage statistics directly from the Support tab

### Changed
- **App Store description**: Removed evaluation disclaimer, cleaned up formatting, added VoxCloud as author
- **App Store metadata**: Added `office` category and GitHub Discussions link

### Fixed
- **Telemetry error feedback**: The "Send report now" button now shows the actual server error message instead of a generic failure notice

## [1.0.0] - 2026-04-09

### Added
- **Improved MS365 import**: Extended column mapping for Street, PostalCode, device names (AudioDeviceName, VideoDeviceName, DisplayDeviceName → facilities), Nickname (→ description), and BookingType (Standard → auto-accept)
- **Exchange sync on import**: New checkbox in MS365 import preview to automatically link imported rooms to their MS365 mailbox for bidirectional calendar sync
- **Show weekends toggle**: New setting in Settings > General to show or hide weekends in the booking calendar (default: visible). Closes [#3](https://github.com/nextcloud/RoomVox/issues/3)

### Changed
- **MS365 export documentation**: Replaced broken one-liner (`Get-EXOMailbox | Get-Place | Export-Csv`) with two options — a simple `Get-Place` export and a recommended full script that preserves email addresses by joining `Get-EXOMailbox` with `Get-Place` data
- **Permissions documentation**: Added prominent clarification that RoomVox uses its own permission system, separate from Nextcloud Calendar's sharing permissions. Getting-started guide now emphasizes that permissions must be configured to restrict room access

### Fixed
- **MS365 import missing email**: The previously documented PowerShell command lost the email address because `Get-Place` returns a different object type than `Get-EXOMailbox`. Documentation now explains this and provides a correct export script
- **Declined bookings not updating organizer calendar**: When a manager declined a booking via the RoomVox admin UI, the organizer's calendar still showed the room as "Reserved" (TENTATIVE). The respond flow now propagates the PARTSTAT change directly to the organizer's calendar event
- **No notification on booking accept/decline**: Managers accepting or declining bookings via the admin UI did not send any email to the organizer. The respond flow now sends confirmation or decline emails using the existing mail infrastructure
- **Recurring events showing only first occurrence**: The booking overview and personal approvals now expand recurring events (RRULE) into individual occurrences within the selected date range. Closes [#2](https://github.com/nextcloud/RoomVox/issues/2)

## [0.4.0] - 2026-02-20

### Added
- **Configurable Facilities**: Admins can now add, edit, remove, and reorder facility options (projector, whiteboard, etc.) in the Settings tab — same UI pattern as room types
- **Personal Settings page**: All users now see a "RoomVox" section under Settings > Personal with two tabs:
  - **My Rooms** — overview of rooms the user has access to, with role badges (Admin/Manager/Booker/Viewer)
  - **Approvals** — pending booking requests for rooms where the user is a manager, with accept/decline buttons
- Slug-based duplicate detection during CSV import: rooms are matched by generated ID in addition to email and name

### Changed
- Updated App Store description with evaluation disclaimer and improved formatting
- Added compatible calendar clients list to description
- Approval notification emails now include a direct link to Personal Settings instead of referencing "admin panel"
- CSV import now matches `@roomvox.local` emails for duplicate detection (previously excluded)

### Fixed
- Fixed facility ID mismatch between frontend and ImportExportService (`videoconf` vs `video-conference`, `audio` vs `audio-system`, etc.)
- Fixed CSV import creating duplicate rooms when re-importing exported data with `@roomvox.local` emails

## [0.3.0] - 2026-02-15

### Added
- **Public REST API (v1)**: Full API for external integrations (displays, kiosks, digital signage, Power Automate, custom apps)
  - `GET /api/v1/rooms` — List rooms with filters (active, type, capacity)
  - `GET /api/v1/rooms/{id}` — Room details
  - `GET /api/v1/rooms/{id}/status` — Real-time room status (free/busy/unavailable)
  - `GET /api/v1/rooms/{id}/availability` — Time slot availability for a given date
  - `GET /api/v1/rooms/{id}/bookings` — List bookings with date/status filters
  - `POST /api/v1/rooms/{id}/bookings` — Create bookings via API
  - `DELETE /api/v1/rooms/{id}/bookings/{uid}` — Cancel bookings via API
  - `GET /api/v1/rooms/{id}/calendar.ics` — iCalendar feed per room
  - `GET /api/v1/statistics` — Usage statistics and utilization data
- **API Token Authentication**: Bearer token system for external API access
  - Token management UI in admin Settings tab
  - Three scopes: `read`, `book`, `admin` (hierarchical)
  - Optional room restrictions per token
  - Optional token expiry dates
  - SHA-256 hashed token storage
  - Automatic last-used tracking
- **CSV Import/Export**: Bulk room management via CSV files
  - Export all rooms as CSV (13 columns)
  - Import from RoomVox CSV format
  - Import from MS365/Exchange format (auto-detected)
  - Preview before import with validation
  - Two import modes: create-only or create + update existing
  - Download sample CSV file
- **Internationalization**: Added German (de) and French (fr) translations

## [0.2.0] - 2026-02-13 - Initial Release

### Added
- **CalDAV Room Backend**: Rooms exposed as standard CalDAV resources via `IBackend`/`IRoom`
  - Compatible with Nextcloud Calendar, Apple Calendar, Outlook, Thunderbird, eM Client
  - Room metadata (capacity, type, address, facilities) published via DAV properties
  - Group-based visibility restrictions for NC Calendar
- **Room Management**: Full CRUD for rooms via admin panel
  - Room properties: name, number, type, address, capacity, description, facilities
  - Custom room types with drag-to-reorder
  - Room groups for organizing rooms with shared permissions
  - Activate/deactivate rooms without deletion
- **Scheduling Engine**: Sabre DAV plugin (priority 99) for iTIP handling
  - Auto-accept or manual approval workflow per room
  - Automatic conflict detection with existing bookings
  - Availability rules (restrict booking to specific days/times)
  - Maximum booking horizon (limit advance booking)
  - Recurring event support with RRULE analysis
- **Permission System**: Role-based access control
  - Three roles: Viewer, Booker, Manager
  - Per-user and per-group permission assignment
  - Room group permission inheritance
  - Nextcloud admin bypass
- **Email Notifications**: Transactional emails via MailService
  - Booking confirmed, declined, conflict, cancelled notifications
  - Manager approval requests for tentative bookings
  - iCalendar REPLY/CANCEL attachments
  - Per-room SMTP configuration (passwords encrypted via ICrypto)
- **Booking Management**: Admin overview and actions
  - View all bookings across rooms with date/status filters
  - Approve/decline pending bookings
  - Create, reschedule, and cancel bookings
  - Move bookings between rooms
- **Virtual User Accounts**: Room service accounts (`rb_*` prefix)
  - Registered with Nextcloud for CalDAV principal resolution
  - Hidden from user search and login
- **Client Compatibility Fixes**
  - iOS: Auto-fix CUTYPE from INDIVIDUAL to ROOM
  - eM Client: Detect rooms by LOCATION match and add as ATTENDEE
  - LOCATION field auto-population from room address
- **Admin Interface**: Vue 3 admin panel in Nextcloud settings
  - Room list with search and filtering
  - Room editor with SMTP configuration
  - Permission editor with user/group search
  - Booking overview with approve/decline actions
  - App settings (default auto-accept, email toggle, room types)
- **No Database**: All data stored via Nextcloud's IAppConfig
  - Room config, permissions, and settings as JSON
  - No database migrations required
- **Internationalization**: Full i18n support
  - English (en) and Dutch (nl) translations
  - All UI strings translatable

### Technical
- PHP 8.2+ required
- Nextcloud 32–33 compatible
- Vue 3 frontend with Nextcloud Vue components
- Sabre DAV scheduling plugin with priority 99
- CalDAV service for calendar provisioning and booking CRUD
- SMTP password encryption via Nextcloud ICrypto
<!-- compliance smoke trigger -->
<!-- runner online retry -->
<!-- e2e webhook smoke -->
