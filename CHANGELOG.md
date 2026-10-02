# Changelog

All notable changes to RoomVox will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.6.0] - 2026-10-02 - Opt-in usage statistics, Public API time fixes & one calendar per room

> **Upgrading.** Usage statistics are now off until an administrator agrees. The upgrade switches them off on every installation that did not make a recorded choice and asks each administrator once, through the Nextcloud notification bell (see *Changed*). The upgrade also moves bookings out of the calendar RoomVox used to create for each room into the calendar Nextcloud keeps for it; nothing needs to be done by hand.

> **Translations.** RoomVox is still waiting for its place on the Nextcloud community translation platform ([nextcloud/docker-ci#986](https://github.com/nextcloud/docker-ci/issues/986)), so strings added or changed since August are not translated yet: 86 of 508 in Dutch and 182 in German and French fall back to English. Parts of the admin pages, the usage-statistics question and some notification emails therefore appear in English. Translations arrive in later releases without any configuration change.

### Added

- **The Public API can be called from web pages on other origins** ([#33](https://github.com/nextcloud/RoomVox/pull/33), contributed by JPromi): browser-based room displays and dashboards were blocked by CORS, because the browser's preflight request carried no token and responses had no `Access-Control-Allow-Origin` header. `OPTIONS /api/v1/...` now answers without a token, and every v1 response, including a rejected token, allows any origin. That is safe because the API authenticates with Bearer tokens only, never with cookies; the internal API stays same-origin.

- **The user and administrator guides are available in Dutch**: the 16 pages that were still stubs pointing to the English text (FAQ, troubleshooting, email configuration, permissions, room management, import and export, settings and others) are now translated. The API reference documents all 57 routes with the response shapes the code actually returns, and the documented Nextcloud compatibility reads 32 to 35, as `info.xml` has declared since 1.5.0.

### Fixed

- **The room export answered a non-administrator with an empty file** ([#42](https://github.com/nextcloud/RoomVox/issues/42)): `GET /api/rooms/export` returned HTTP 200 and an empty `error.csv`, so a missing permission looked like an instance without rooms. It now refuses with 403 and the same error body as the import endpoints.

- **Room names with special characters were shown escaped**: an action menu read "Actions for room Meeting &amp;amp; Training" to a screen reader, and a name containing something tag-like was rewritten, also in the conflict message of the room-address check and in the CSV import preview. Room names are now inserted as plain text; nothing in the app renders translations as HTML.

- **The room list's column headings ran into each other** in longer languages ("Auto-accepteren" over "Status" in Dutch), and the actions column was narrower than its menu button, so an ellipsis showed beside it. Headings now wrap inside their column and the actions column fits its button. The tab rows keep every label inside its tab in any language and wrap onto a second row when they no longer fit.

- **The Public API rejected every booking and reported every room as unavailable when booking hours were restricted** ([#32](https://github.com/nextcloud/RoomVox/issues/32)): availability rules store weekdays as integers `0`-`6` with `0` = Sunday — the format the admin interface writes and the one the CalDAV scheduling path reads. The Public API derived the weekday as a lowercase abbreviation (`"mon"`) instead and compared that against those integers, which cannot match on PHP 8. Any room with "Restrict booking hours" enabled was therefore treated as outside its allowed hours at every moment: `GET /api/v1/rooms/{id}/status` answered `unavailable` around the clock, the availability endpoint reported no open window, and `POST /api/v1/rooms/{id}/bookings` refused bookings that were well inside the configured hours. Bookings made through a normal calendar client were never affected, which is why this went unnoticed: that path does the comparison correctly. All three comparison sites now share a single matcher that reads the weekday as an integer. Values that a REST client stored as strings (`["1","2"]`) are accepted as the same weekdays, while non-numeric values no longer coerce to `0` and silently mean Sunday.

- **Bookings made through the Public API with a UTC offset were stored at the wrong time** ([#45](https://github.com/nextcloud/RoomVox/issues/45)): a booking requested as `09:00+02:00` was written to the calendar as `09:00Z`, so Nextcloud Calendar, the booking overview and all feeds showed it two hours late. The API response and the manager's approval mail both repeated the requested time, so a manager approved 09:00 while the room was blocked at 11:00, and the conflict check ran against a different slot than the one that was stored. Times are now converted to UTC before they are written. Booking hours are also checked in the instance timezone (`default_timezone`) instead of in whatever offset the client sent, so `07:00Z` and `09:00+02:00` are treated the same; previously no input was handled correctly by both the storage and the hours check. `GET /api/v1/rooms/{id}/status` reads "now" and "today" in that timezone as well, where it used to evaluate booking hours against UTC. `GET /api/v1/rooms/{id}/availability` does the same: its slots are now local times, so a slot it reports as free is one `POST /bookings` accepts. A booking lying entirely outside the day's booking hours no longer produces an inverted slot that pushed the free window back, floating bookings (no timezone) keep their clock time, and booking lists are sorted by actual start time rather than by the text of the timestamp, which put a `07:00+00:00` booking before an earlier `08:30+02:00` one.

- **Notification emails showed times in the zone the event was stored in** ([#49](https://github.com/nextcloud/RoomVox/issues/49)): an event stored in UTC was confirmed as 11:30–14:30 while Nextcloud Calendar showed the booked 13:30–16:30 in Vienna. After the fix for #45 every Public API booking is stored in UTC, so the two had to ship together. Mails now show times in the instance timezone and name it, e.g. `13:30 – 16:30 (Europe/Vienna)`. Floating times are printed as stored. All-day events are no longer converted either, since that would move them onto the previous day west of UTC; they are now shown as dates (`Monday, October 5, 2026`, or `Monday, October 5, 2026 – Wednesday, October 7, 2026` for several days) instead of the meaningless `00:00 – 00:00` they had before.

- **Permissions of deleted accounts and groups are now removed**: permissions store bare user and group ids, and nothing cleared them when the account or group was deleted. A deleted account stayed listed in the permission editor, indistinguishable from a live one. A deleted group was worse: the room kept listing it as manager while it resolved to nobody, so approval requests quietly reached no one. RoomVox now listens for account and group deletion and removes the entries from room and room-group permissions. A group that merely fails to resolve, such as an unreachable LDAP backend, is left alone. When a deletion leaves a room with no manager at all, counting its room group's managers as well, the log names that room.

- **Two rooms can no longer share an email address**: a room's address is its scheduling identity, which iMIP invitations, replies and the Exchange link are keyed on, and nothing stopped two rooms from holding the same one. Creating a room on a taken address, or changing a room to one, is now refused. The editor says which room holds it and keeps the form open. A CSV with two rows on one address flags the second row. Rooms that already shared an address stay editable as long as the address is left unchanged. A room created without an address gets `{id}@roomvox.local`, and that address is now kept unique as well.

- **A room without a calendar was reported as free** ([#44](https://github.com/nextcloud/RoomVox/issues/44)): deleting a room only soft-deleted its calendar, which kept the calendar's slot in the database, so recreating a room with the same id failed with a 500 and left the room without a calendar. `GET /api/v1/rooms/{id}/status` then answered `free` whatever was booked, and the conflict check let every booking through. Room calendars are now deleted permanently, and a room whose calendar cannot be created is removed again instead of being left behind. A room that still has no calendar is reported as `unavailable` with the new field `reason: "no_calendar"`, offers no free slots in `/availability`, refuses API bookings with a 422 that names the cause, and fails the conflict check closed. `reason` is also `outside_booking_hours` when a room is unavailable because of its booking hours, and `null` otherwise.

- **`POST /api/rooms` ignored `groupId`** ([#41](https://github.com/nextcloud/RoomVox/issues/41)): the create endpoint copied an explicit list of request fields into the new room, and `groupId` was not on it, so every room was created without a room group whatever the request said. No error was returned. This also affected the admin interface: a room group picked in the editor while creating a room was dropped, and the room showed up ungrouped. `groupId` is now passed through on create, as it already was on update.

- **Recurring bookings were only checked on their first date** ([#46](https://github.com/nextcloud/RoomVox/issues/46)): a series booked from a calendar app was checked against existing bookings and the booking hours with its first occurrence only, so a later date could double-book the room. With approval enabled it was worse: the manager approved the whole series without any sign that some dates collided. Every occurrence from now on is now checked, including exceptions moved to another time and dates removed with EXDATE; a series with an end is checked to its end, one without up to the booking horizon or a year ahead. Exchange-linked rooms are checked with one query for the whole series instead of one per date, and that query now sends its time span in UTC. If any date conflicts or falls outside the booking hours, the whole series is declined and the email lists those dates, so the organizer can exclude them and book again.

- **Deactivating a room deleted all of its bookings**: RoomVox stopped announcing an inactive room to Nextcloud, which treats a room that disappears as deleted and permanently deletes its calendar, so every booking in it was gone the moment the room was saved as inactive, and the room came back empty when reactivated. Inactive rooms now stay known to Nextcloud and keep their calendar and bookings. They are hidden from the room picker in calendar apps and refuse new bookings everywhere: calendar invitations are declined, and the Public API and the RoomVox interface answer 422. `GET /api/v1/rooms/{id}/status` reports them as `unavailable` with `reason: "inactive"`.

- **Bookings could end up in a calendar RoomVox no longer read**: besides the calendar Nextcloud keeps for every room resource, RoomVox provisioned a calendar of its own and wrote bookings there until Nextcloud's appeared, typically within the hour. From then on RoomVox read only Nextcloud's, so earlier bookings disappeared from status, availability and the conflict check, and their slots could be booked again. A room now has one calendar, the one Nextcloud creates for it, which RoomVox asks Nextcloud to create as soon as the room is. On upgrade, a repair step moves any bookings left in the old calendars into the room calendars and removes the old calendars.

### Changed

- **English text follows American spelling**: "canceled", "license" and "organization" replace "cancelled", "licence" and "organisation" in 14 strings, mostly the cancellation mails, so the Support pane no longer shows both spellings. Like every string added since August, they are untranslated in this release (see *Translations* above). Nothing else changes; iCalendar's `CANCELLED` status is untouched.

- **Usage statistics are off until an administrator agrees**: RoomVox used to send a daily usage report to `licenses.voxcloud.nl` by default. It now sends nothing until an administrator switches it on. The upgrade switches every installation off whose "on" was not a recorded choice: up to 1.5.0 the admin pane saved the usage-statistics setting along with every change to the general settings, so an "on" from those versions cannot be told apart from a deliberate one. Those administrators are asked again; an "on" given through the new question or switch is kept. After an install or upgrade every administrator is asked once through the Nextcloud notification bell, with three equal answers: *Share usage statistics*, *Not now* (asked again with the next version) and *Never ask again*. The question goes to administrators only.

- **The usage report is smaller, and the admin pane shows exactly what it contains**: the report now carries only the fields allowed by the VoxCloud telemetry rules (design `TELEMETRY.md` 1.1.0): an installation hash, the field-list version, the RoomVox, Nextcloud and PHP (`major.minor`) versions, user counts, whether the server has a Nextcloud subscription, the country, and counts of rooms, room groups and room features. The subscription key, the Extended Support flag, database, operating system, web server, Docker, language and time zone, room types, facilities, capacity, availability rules and the always-zero booking count are no longer sent. `version` is now `appVersion`, a failed user count is sent as `null` instead of `1`, and the country is worked out on the server from the default phone region or the default time zone, of which only the two-letter code is sent. The Support pane lists every field with what it is used for, from the same definition the report is built from, names where the data goes, and mentions the separate licence-usage report that runs while a subscription key is entered. "Send report now" says "Already sent recently" instead of showing an error when a report went out within the hour. There is now one switch, on the Support tab; the second copy on the Statistics tab is gone, and the general settings endpoint no longer reads or writes it.

- **Where the usage statistics stand**: checked on a test server against `TELEMETRY.md` 1.1.0. With statistics off, nothing is sent: the daily job skips and *Send report now* refuses. With them on, one report goes out carrying exactly the 16 listed fields and no other headers than `User-Agent` and `Content-Type`; a second report within the hour is held back. The receiving side was updated on 2026-10-02 to match: it reads `appVersion`, verifies a Nextcloud subscription by the installation hash instead of the subscription key, no longer guesses the country from the sender's IP address or time zone, and no longer keeps the sender's IP address in its access logs for these reports. Logs from before that date still hold addresses and expire after four weeks. Figures that earlier RoomVox versions sent, such as the server details and the room facility names, stay stored at `licenses.voxcloud.nl` for now; this update stops sending them but does not delete them.

- **The Statistics and Support tabs follow the VoxCloud layout**: *Statistics* shows only figures counted on this server (total rooms, active rooms, room groups) as tiles with theme icons instead of emoji, and no longer carries an *About* block. *Support* is now four sections in a fixed order: *About RoomVox*; *Subscription*, with what a subscription includes, how to get one through Nextcloud, the number of named users, the subscription notice and the key field; *Usage statistics*; and *Help*, with links to the documentation, the issue tracker and the other Vox apps. The list of what the usage statistics contain is folded away behind a line that names the number of fields, and the consent notification now links straight to it, opened. The list's labels sit above their purpose instead of running into it, and the subscription notice is no longer shown twice on the Support tab.

- **The tabs on the admin and personal pages are one switcher with an icon on every tab**: both rows were hand-built buttons. They are now a named radio group, so a screen reader announces the row and the active tab, and each tab carries an icon for what is behind it. On a narrow screen the tabs wrap instead of pushing the page sideways. The room count left the tab (the room list counts per group); waiting approvals stay visible in their tab label.

- **The Import / export and Settings tabs use Nextcloud's own form controls**: their text fields, password field and selects were hand-built and are now Nextcloud's components, each with a visible label; behaviour and the moment of saving are unchanged. Notices are Nextcloud note cards, the import result tiles use the theme's success, warning and error colours, an error message no longer used a background colour as its text colour, and "General", "Room types" and "Facilities" are translatable.

- **The admin interface follows the Nextcloud theme**: status badges, licence messages and calendar events used fixed colours that stayed light in dark mode. They now use Nextcloud's theme colours, which keep their contrast in both themes. The room table keeps a readable width on narrow screens and scrolls inside its own card instead of squeezing the room name into a few dozen pixels. Every row's action menu now has an accessible name with the room or group name, and the facilities switches are grouped so screen readers announce the group.

### Security

- **No known vulnerabilities in the JavaScript dependencies**: the five dependency updates proposed for the GitHub repository ([#36](https://github.com/nextcloud/RoomVox/pull/36)-[#40](https://github.com/nextcloud/RoomVox/pull/40)) are applied, plus `npm audit fix` within the declared version ranges. `npm audit` goes from 21 vulnerabilities (13 high) to 0; `composer audit` reports nothing. The flagged packages are build tools; none of them ships in the app's JavaScript.

## [1.5.0] - 2026-09-14 - Nextcloud 35 support, LDAP group search & buildable from a clean clone

### Added

- **Nextcloud 35 support** — `info.xml` now declares `<nextcloud min-version="32" max-version="35"/>`. Verified against a running Nextcloud 35.0.0 RC3 on PHP 8.5.9 rather than inferred from release notes: all 50 `OCP\` symbols the app imports resolve, all 44 classes load without a fatal and none turned abstract, the app installs and enables, `/status.php` and `/login` both stay 200, the log stays free of RoomVox entries, and the CalDAV room backend registers and publishes room metadata as before. No code changes were required — the declared ceiling was the only thing preventing installation. Nextcloud 35 retypes `IBootContext::getServerContainer()` to `Psr\Container\ContainerInterface`, which RoomVox is unaffected by because it only ever calls `->get()` on it. Four APIs the app uses became or remain deprecated without being removed (`ISecureRandom::generate`, `ICountUsersBackend`, `IConfig::getAppValue` and friends, `Calendar\Room\IManager::getBackends`); replacing them is cleanup for a later release, and two of the replacements do not exist on Nextcloud 32, which is still supported. The audit is written up in [NC 35 Compatibility](docs/architecture/nc35-compatibility.md).

### Changed

- **The Support tab no longer links to the VoxCloud website for pricing.** Subscriptions are sold exclusively through Nextcloud, so the "Pricing details" button — which pointed at `voxcloud.nl/pricing` — has been removed rather than repointed. In its place the tab states plainly that subscriptions run through Nextcloud and names the route: your Nextcloud account manager, or `sales@nextcloud.com`. The VoxCloud address stays on the tab for questions about the app itself, which is support rather than sales. The "Learn more about RoomVox" link to the VoxCloud homepage is gone for the same reason; it had no counterpart in the other VoxCloud apps. Nothing about what the app does changes: every feature keeps working without a subscription, for every account, exactly as before.

### Fixed

- **Building from a fresh clone failed: neither lock file was committed** ([#35](https://github.com/nextcloud/RoomVox/issues/35)): `.gitignore` excluded both `package-lock.json` and `composer.lock`, so a clone contained neither. `npm ci` — which the README tells you to run — refuses to work without a lock file and aborted with `EUSAGE`, and every `npm install` instead resolved dependencies afresh, which is how an upstream `@nextcloud/vue` 9.11.0 → 9.12.0 release could break an install process that had been working. Both lock files are now tracked, so a clone builds from the same dependency tree the app was tested against.
- **`composer install` could not resolve on the supported PHP baseline** ([#35](https://github.com/nextcloud/RoomVox/issues/35)): the `nextcloud/ocp` development dependency was set to `dev-master`, which follows Nextcloud's main branch. That branch now requires PHP 8.3 or newer, so it conflicted with the PHP 8.2 baseline RoomVox still supports and no installable set of packages existed. Since that dependency only supplies the `OCP\` API stubs used for static analysis and tests, it is now pinned to `dev-stable32`, matching the app's own minimum Nextcloud version and the other VoxCloud apps. This is a build-time dependency only; nothing about which Nextcloud or PHP versions RoomVox runs on has changed.
- **LDAP groups could not be found in the permission editor** ([#31](https://github.com/nextcloud/RoomVox/issues/31)): The group search asked Nextcloud for matching groups, which does reach every group backend including LDAP. What it missed is that a backend decides for itself what the typed text is matched against: `user_ldap` matches the LDAP group's display name attribute, not the Nextcloud group ID. A group whose ID differs from that attribute — an LDAP group identified by its distinguished name, for instance — therefore never turned up, even when the exact ID was typed in full. Nextcloud's own share dialog compensates for this with an extra exact-ID lookup; RoomVox now does the same, so those groups are reachable. This also explains a difference that remains by design: local groups match on any part of the name, while LDAP matches from the start of the name only (and, when share-dialog enumeration is switched off, only exactly) — that is Nextcloud's LDAP behaviour, identical in the share dialog.
- **A group search that found nothing left no trace in the log.** An LDAP group backend that is registered but not fully configured — `user_ldap` activates its group backend only when both the group filter and the group-member association attribute are set — returns an empty list silently, which looks exactly like "this app does not support LDAP". Empty searches are now logged at debug level, naming what to check.
- **Permission groups that no backend can resolve are now logged.** When a group in a room's permissions cannot be resolved — an LDAP backend that is unreachable or no longer active — its members were skipped without a word, so managers in such a group silently stopped receiving approval notifications while their access itself kept working. This is now a warning in the log; the behaviour is otherwise unchanged.
- **Stale translations for removed interface text.** The Dutch bundle still carried a string offering enterprise pricing at a VoxCloud address, left over from before subscriptions moved to Nextcloud. It was no longer reachable from any source file, but it shipped in every release. That string and four other orphans were removed.

## [1.4.0] - 2026-08-30 - Subscription notice, Exchange usage reporting & Enterprise detection

Installations above 100 users now see a note about a subscription in the settings screen. Nothing is gated by it: RoomVox keeps working in full, for every account, with or without a subscription.

> **Translations.** The strings added in this release are not translated yet — RoomVox is still waiting for its resource on the Nextcloud community translation platform ([nextcloud/docker-ci#986](https://github.com/nextcloud/docker-ci/issues/986)). Until it opens, new text falls back to English; the interface stays translated where it already was.

### Added

- **A subscription note on installations above 100 users.** Shown on every tab of the settings screen, not only under Support. Instances with a Nextcloud Enterprise subscription see a note pointing at their Nextcloud account manager instead. Nothing is limited or enforced either way.
- **Telemetry reports whether the Microsoft Exchange sync is used** — whether it is switched on, and how many rooms are linked to a resource mailbox. The Azure tenant ID, client ID and client secret are never read and never sent.
- **Telemetry reports disabled accounts** separately from the total, so the difference is visible when usage is compared against a contract.

### Changed

- **The pricing button is labelled "Pricing details"** and no longer reads as a purchase button. Subscriptions are sold through Nextcloud.

### Fixed

- **Nextcloud Enterprise was detected with the wrong check,** so instances with a plain Enterprise subscription were treated as Community — both in the settings screen and in the usage figures reported back to VoxCloud. Nothing in the app behaved differently.
- **The reported user count left out LDAP and SSO accounts,** because it counted database rows rather than asking Nextcloud. Only affects reporting, not any limit in the app.
- **The instance identifier did not line up with the other VoxCloud apps,** so one server could appear as a different instance per app. Affected instances migrate themselves at the next report; nothing needs reconfiguring.
- **The tab bar pushed the page sideways on phones.** The tabs now wrap to a second row instead of overflowing.

## [1.3.0] - 2026-08-13 - Room metadata, multi-room bookings & translatable emails

Room metadata that RoomVox already held — floor, address, building — never fully arrived at the calendar clients that show it. This release fixes the publishing side, which matters now that Nextcloud Calendar is gaining a room browser that groups rooms per building and filters on floor and capacity ([nextcloud/calendar#8264](https://github.com/nextcloud/calendar/pull/8264)). It also fixes booking one event into two rooms at once, and makes notification emails translatable. No stored data changes; the room editor keeps its fields exactly as they are.

A minor rather than a patch release: notification emails become translatable, bookings gain an `allDay` field in the API, and RoomVox moves onto the Nextcloud community translation workflow — which changes how the app is built and which files ship.

> **Translations.** The strings added in this release are not translated yet: RoomVox is being onboarded onto the Nextcloud community translation platform ([nextcloud/docker-ci#986](https://github.com/nextcloud/docker-ci/issues/986)). Until translators pick them up, untranslated text falls back to English — the interface stays translated where it already was. New translations arrive in later releases without any configuration change.

### Added
- **Notification emails are translatable and sent in the recipient's language** ([#24](https://github.com/nextcloud/RoomVox/issues/24)): every subject and body in `MailService` was a hardcoded English string — the app had no `IL10N` at all — so a French user with a French interface still received English mail. All 14 notification types now resolve their text through Nextcloud's translation system, and the language is chosen **per recipient** rather than from the server default: the organizer, each room manager, and the admin can each receive the same booking in their own language, resolved from their Nextcloud account (external addresses, which have no account to read a preference from, fall back to the instance default; an address matching more than one account is treated as unknown rather than guessed). Values are never translated — room names, event titles and dates are substituted into placeholders, so translators get whole sentences instead of fragments. Alongside this, RoomVox moved to the Nextcloud community translation workflow: all 526 frontend call sites were converted to the `t('roomvox', …)` form the Nextcloud translation bot extracts (it reads only that literal form, so with the previous `$t(…)` wrapper it would have found **none** of them), a POT template is generated from both the Vue and PHP sources, and the existing German, French and Dutch translations were carried over so no language restarts from zero. A prebuild guard fails the build when a new source string has not been pushed for translation yet.
- **Rooms publish their building name**: RoomVox holds the building as its own field, but CalDAV had nowhere to put it, so clients that group rooms per building had to infer one from the address — usually by taking everything before the first comma, which turns a room at "1098 XG, Amsterdam" into a building called "1098 XG". Rooms now publish `{http://nextcloud.com/ns}room-building-name`, taken from the building field itself rather than guessed. An empty building field publishes nothing rather than letting the street stand in for it. Nextcloud has no `IRoomMetadata::BUILDING_NAME` constant for this yet — it sits alongside the existing `BUILDING_ADDRESS`, `BUILDING_STORY` and `BUILDING_ROOM_NUMBER` — so the key is published as a plain string; clients that do not know it simply ignore it.

### Changed
- **Interface labels follow the Nextcloud writing guidelines**: 44 labels used Title Case where Nextcloud uses sentence case ("Save Changes" → "Save changes", "My Rooms" → "My rooms"), and twelve progress labels ended in three full stops rather than a real ellipsis ("Saving..." → "Saving …"). Acronyms, product names and Microsoft's own field labels keep their capitals, so "API tokens", "SMTP Host" and "Client Secret" still read the way an administrator sees them in Entra. Existing German, French and Dutch translations were carried over to the renamed labels, so nothing that was translated became untranslated. Cosmetic only — no behaviour changes.
- **Translations no longer carry strings the app removed**: each language bundle held 79 entries for labels deleted in earlier releases. They were never shown (Nextcloud simply never looked them up) but shipped in every release and would have been imported into the translation platform as translations without a source string. Each language now holds exactly the 359 strings the app actually uses.

### Fixed
- **Some CSV columns were silently dropped on import** ([#29](https://github.com/nextcloud/RoomVox/issues/29)): importing a room with every column filled in lost `roomNumber`, `roomType`, `postalCode` and `autoAccept` — which also meant RoomVox's own CSV export could not be imported back, the very file the export offers as a template. CSV headers are matched case-insensitively, but the parser compared the lowercased header (`roomnumber`) against field names that are camelCase (`roomNumber`); the four columns whose names contain a capital matched nothing and were skipped without warning, while the ten all-lowercase ones came through fine. Headers are now resolved to their canonical field name through a single map derived from the export column list, so the export/import round-trip cannot drift apart again. Case-insensitive matching and the MS365/Exchange column mapping are unchanged.
- **Month view showed the wrong month in the header** ([#26](https://github.com/nextcloud/RoomVox/issues/26)): the booking calendar's month view labelled August 2026 as "July 2026". The heading was taken from the first cell in the grid, and a month view pads its first row with the tail of the previous month (August 2026 starts on a Saturday, so the grid opens on 27 July). Months could therefore appear twice or not at all while paging through the calendar. The heading now comes from the period the view actually represents.
- **Approval requests stayed in the queue after the meeting had passed** ([#28](https://github.com/nextcloud/RoomVox/issues/28)): a manager's approval list showed every pending request ever made, including meetings that were weeks in the past, because the bookings were fetched with no date range at all. Requests whose meeting has already ended are now filtered out at the source. A meeting that has started but not yet finished is still listed — it can still meaningfully be approved. Nothing is deleted: an expired request only disappears from the queue, so the booker's own calendar entry and history stay intact.
- **All-day bookings were shifted by the timezone** ([#27](https://github.com/nextcloud/RoomVox/issues/27)): an all-day event showed as running 02:00–02:00 across two days instead of covering one whole day. An all-day `VEVENT` stores a plain calendar date (`DTSTART;VALUE=DATE:20260813`) with no time and no timezone, but RoomVox serialised every booking as a precise timestamp — which turns midnight into an instant that renders as 02:00 in UTC+2 and spills into the next day. All-day bookings are now detected and passed to the interface as a bare date with an explicit all-day flag, so the calendar and the booking list show them as a full day with no time attached, labelled "All day" rather than a clock time. The resource timeline needed the same care: it only renders 08:00–19:00, so a booking running midnight to midnight was drawn straight through into the next day's slots — all-day bookings are now bounded to the hours the timeline shows, while a multi-day booking keeps its real length. Timed bookings are unaffected. API consumers see this too: booking objects gain an `allDay` boolean, and for an all-day booking `dtstart`/`dtend` are a plain `YYYY-MM-DD` date instead of a timestamp — clients that assume a timestamp should check the flag.
- **Booking two rooms on one event without auto-accept only requested approval for the first** ([#22](https://github.com/nextcloud/RoomVox/issues/22)): the second room stayed on "Needs action", never appeared in the approval queue, and a manager could not change it. An event booked into two rooms carries *both* room `ATTENDEE` lines, and each room calendar holds its own copy of that same event — but every PARTSTAT lookup scanned for the *first* `CUTYPE=ROOM` attendee rather than the attendee belonging to the room whose calendar was being read. So room B reported room A's status (`ACCEPTED` once A was approved, hiding B from the queue, which filters strictly on `TENTATIVE`), and approving room B wrote `ACCEPTED` onto room A's line, leaving B stuck forever. The three affected lookups in `CalDAVService` (`getBookings()`, `getRawCalendarObjects()`, `updateBookingPartstat()`) now match the owning room's own email first, via a shared `extractRoomPartstat()` helper; the previous `CUTYPE=ROOM`/non-organizer heuristic remains as the fallback for single-room events and for clients like iOS that send `CUTYPE=INDIVIDUAL`. Two related multi-room defects are fixed alongside it: declining one room removed its attendee mid-iteration, which invalidated the loop and silently skipped the PARTSTAT write-back for every later room on the event (removals are now deferred until after the loop), and that same decline cleared the event `LOCATION` even when another room was still attached (`LOCATION` is now only cleared when no room attendee survives, otherwise it is rebuilt from the remaining room). Earlier analysis had suspected Sabre/Nextcloud's iTIP dispatch; the scheduling path is in fact correct and fully per-room — the defect was one layer down, in reading the result back.
- **Room floor was invisible to every CalDAV client**: the room backend published the floor under `{http://nextcloud.com/ns}room-building-floor`, which is not a key any client asks for. Nextcloud defines the property as `IRoomMetadata::BUILDING_STORY` (`room-building-story`), and cdav-library requests only that one in its PROPFIND, so the value never reached Nextcloud Calendar, Outlook, Apple Calendar or Thunderbird. The backend now publishes `room-building-story`. Nothing consumed the old key, so this only adds data where there was none — on our test instance 97 of 113 rooms had a floor set that no client could see. Floors appear after the hourly `UpdateCalendarResourcesRoomsBackgroundJob` has run, or immediately after `occ background-job:execute`.
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
