# Room Booking for Nextcloud

CalDAV-native room booking for Nextcloud. Rooms appear as bookable resources in any calendar app — no separate booking interface needed.

## Features

- **CalDAV Resources** — Rooms show up in NC Calendar, Apple Calendar, Outlook, eM Client, Thunderbird
- **Auto-accept / Approval workflow** — Per-room setting: instant confirmation or manager approval
- **Conflict detection** — Automatic overlap checking, conflicting bookings are declined
- **Permission system** — Viewer / Booker / Manager roles per room, user and group based
- **Email notifications** — Booking confirmations, decline notices, approval requests with iCalendar attachments
- **Per-room SMTP** — Each room can have its own SMTP config (passwords encrypted via ICrypto)
- **Client compatibility** — Fixes for iOS (CUTYPE=INDIVIDUAL) and eM Client (LOCATION-only booking)

## Requirements

- Nextcloud 32
- PHP 8.1+

## Installation

```bash
# Clone into Nextcloud apps directory
cd /var/www/nextcloud/apps/
git clone https://gitea.rikdekker.nl/sam/roomvox.git

# Install PHP dependencies
cd roomvox
composer install --no-dev

# Build frontend
npm ci
npm run build

# Enable the app
sudo -u www-data php /var/www/nextcloud/occ app:enable roomvox
```

## Development

```bash
npm run dev       # Development build
npm run watch     # Watch mode with auto-rebuild
npm run build     # Production build
```

## Architecture

### Backend (PHP)

```
lib/
├── AppInfo/Application.php          # Bootstrap: registers room backend, user backend, Sabre plugin
├── Connector/Room/
│   ├── RoomBackend.php              # IBackend — exposes rooms as CalDAV resources
│   └── Room.php                     # IRoom + IMetadataProvider — room properties & metadata
├── Dav/
│   └── SchedulingPlugin.php         # Sabre ServerPlugin (priority 99) — handles iTIP scheduling
├── Listener/
│   └── SabrePluginListener.php      # Registers SchedulingPlugin with Sabre DAV server
├── UserBackend/
│   └── RoomUserBackend.php          # Virtual user accounts for room principals (rb_*)
├── Service/
│   ├── RoomService.php              # Room CRUD — stores config in IAppConfig (no database)
│   ├── CalDAVService.php            # Calendar operations via CalDavBackend
│   ├── PermissionService.php        # Role-based access control (viewer/booker/manager)
│   └── MailService.php              # Email notifications with iCal REPLY/CANCEL attachments
├── Controller/
│   ├── PageController.php           # Admin panel page
│   ├── RoomApiController.php        # Room CRUD API
│   ├── BookingApiController.php     # Booking list & approve/decline API
│   └── SettingsController.php       # App settings API
└── Settings/
    ├── AdminSection.php             # Admin settings navigation
    └── AdminSettings.php            # Admin settings page
```

### Frontend (Vue 3)

```
src/
├── main.js                          # App entry point
├── App.vue                          # Main app with sidebar navigation
├── views/
│   ├── RoomList.vue                 # Room management table
│   ├── RoomEditor.vue               # Room create/edit form with SMTP config
│   ├── PermissionEditor.vue         # User/group permission management
│   └── BookingOverview.vue          # Booking list with approve/decline actions
└── services/
    └── api.js                       # API client wrapper
```

### Data Storage

No custom database tables. All data stored via `IAppConfig`:

| Key pattern | Content |
|---|---|
| `room/<roomId>` | Room config JSON (name, email, capacity, SMTP, etc.) |
| `rooms_index` | JSON array of all room IDs |
| `permissions/<roomId>` | Permission config JSON (viewers, bookers, managers) |

### How Scheduling Works

1. User adds a room resource to their calendar event
2. Nextcloud's Sabre DAV server fires a `schedule` event
3. Our `SchedulingPlugin` (priority 99, before Sabre's 100) intercepts it
4. Permission check → Conflict check → Set PARTSTAT → Deliver to room calendar → Send email
5. Returns `false` to stop Sabre from attempting delivery (which would fail)

This bypass is needed because Sabre's `getPrincipalByUri()` requires an active user session to resolve room principals, which isn't available during scheduling.

## API Routes

| Method | Route | Description |
|---|---|---|
| `GET` | `/api/rooms` | List all rooms |
| `POST` | `/api/rooms` | Create room |
| `GET` | `/api/rooms/{id}` | Get room |
| `PUT` | `/api/rooms/{id}` | Update room |
| `DELETE` | `/api/rooms/{id}` | Delete room |
| `GET` | `/api/rooms/{id}/permissions` | Get room permissions |
| `PUT` | `/api/rooms/{id}/permissions` | Set room permissions |
| `GET` | `/api/rooms/{id}/bookings` | List bookings |
| `POST` | `/api/rooms/{id}/bookings/{uid}/respond` | Approve/decline booking |
| `GET` | `/api/settings` | Get app settings |
| `PUT` | `/api/settings` | Update app settings |

## License

AGPL-3.0-or-later
