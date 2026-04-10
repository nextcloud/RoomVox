# Changelog

All notable changes to RoomVox will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
