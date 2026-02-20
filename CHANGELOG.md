# Changelog

All notable changes to RoomVox will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
