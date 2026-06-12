# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

RoomVox is a CalDAV-native room booking app for Nextcloud (v32–34, PHP 8.2+). Rooms appear as bookable resources in any CalDAV calendar client (Nextcloud Calendar, Apple Calendar, Outlook, Thunderbird, eM Client) without a separate booking UI.

## Build Commands

```bash
# Frontend
npm ci                  # Install dependencies
npm run build           # Production build → js/roomvox-*.js
npm run dev             # Development build
npm run watch           # Dev build with file watching

# Backend (PHP, no build step)
composer install --no-dev   # Production dependencies only
composer install            # Include dev dependencies (phpunit)
```

### Tests

Standalone PHPUnit 10 suite — no running Nextcloud instance required. All Nextcloud interfaces are mocked; Sabre classes are stubbed via [tests/stubs/sabre.php](tests/stubs/sabre.php); `OCP\` / `NCU\` namespaces are autoloaded from the `nextcloud/ocp` dev dependency via [tests/bootstrap.php](tests/bootstrap.php).

```bash
vendor/bin/phpunit --testsuite unit                          # All unit tests
vendor/bin/phpunit tests/Unit/Service/RoomServiceTest.php    # Single file
vendor/bin/phpunit --filter testStripMailto                  # Single test
vendor/bin/phpunit --coverage-html build/report              # With coverage (needs Xdebug/PCOV)
```

Some tests exercise private methods via `ReflectionMethod` on purpose — core business logic (availability rules, iCal generation, CSV parsing) is validated directly, independent of the public API.

CI runs via `.gitea/workflows/tests.yml` (phpunit on PHP 8.2+8.3 matrix, plus `npm run build`).

## Architecture

### Data Storage: Zero Database

All configuration is stored as JSON values in Nextcloud's `IAppConfig` (key-value store). There are no database migrations or schema files. Key patterns:
- `room/{roomId}` — Room configuration JSON
- `permissions/{roomId}` — Permission rules JSON
- `rooms_index` — Array of all room IDs

Bookings are standard CalDAV events stored in Nextcloud's calendar backend.

### Virtual User Accounts

Each room gets a hidden Nextcloud user (`rb_*` prefix) via `RoomUserBackend`. These serve as CalDAV principals (calendar owners) but cannot log in or appear in user search.

### CalDAV Scheduling Plugin (Priority 99)

`lib/Dav/SchedulingPlugin.php` is the core of the booking system. It extends Sabre's `ServerPlugin` and runs at priority 99 (before Sabre's default handler at 100) to intercept iTIP scheduling messages for room principals. It:
- Checks permissions and conflicts
- Enforces availability rules and booking horizon
- Sets PARTSTAT (ACCEPTED/TENTATIVE/DECLINED)
- Delivers events directly to room calendars via CalDavBackend
- Returns `false` to prevent Sabre's default (failing) delivery

This plugin exists because Sabre/Nextcloud's scheduling cannot resolve virtual room principals via `getPrincipalByUri` without an active user session.

### Service Layer

Controllers depend on services (`lib/Service/`), services depend on each other. A circular dependency between `PermissionService` and `RoomService` is broken via late injection in `Application::boot()`.

Key services:
- **RoomService** — CRUD for rooms, manages virtual user accounts
- **CalDAVService** — Direct CalDavBackend access for booking operations
- **PermissionService** — Role-based access (Viewer/Booker/Manager) with group inheritance
- **MailService** — Per-room SMTP or Nextcloud IMailer fallback, passwords encrypted with ICrypto
- **ImportExportService** — CSV import/export (RoomVox + MS365/Exchange formats)
- **ApiTokenService** — Bearer token management for Public API v1
- **Exchange/** — Optional Microsoft Graph sync ([ExchangeSyncService](lib/Service/Exchange/), [WebhookService](lib/Service/Exchange/)). Pulls bookings from Exchange room mailboxes; webhook subscriptions must be renewed within a 36h safety margin and require HTTPS notification URLs (Graph rejects HTTP).

### Frontend

Vue 3 (Composition API) with `@nextcloud/vue` components and FullCalendar. Entry point is `src/main.js`, mounts to `#app-roomvox` in the admin settings page.

Views: RoomList, RoomEditor, RoomGroupEditor, BookingOverview, PermissionEditor.

### Bootstrap & Registration

`lib/AppInfo/Application.php` registers:
1. `RoomBackend` as CalDAV room backend (rooms appear as resources)
2. `SabrePluginListener` for the scheduling plugin
3. `ApiTokenMiddleware` for public API bearer token auth
4. `RoomUserBackend` for virtual room users

### API Structure

Two API layers defined in `appinfo/routes.php`:
- **Internal API** (`/api/rooms`, `/api/bookings`, etc.) — Session-authenticated, used by the admin frontend
- **Public API v1** (`/api/v1/rooms`, etc.) — Bearer token authenticated via `ApiTokenMiddleware`

### Permission Model

Permissions use three roles (Viewer, Booker, Manager) assigned to users or Nextcloud groups. Room groups allow shared permissions that are inherited by all member rooms. Effective permissions = room-level ∪ group-level.

## Namespace

PHP namespace: `OCA\RoomVox\` maps to `lib/` (PSR-4).
