# RoomVox

CalDAV-native room booking for Nextcloud. Rooms appear as bookable resources in any calendar app — no separate booking interface needed.

![Room overview](screenshots/rooms-overview.png)

## Features

- **CalDAV Resources** — Rooms show up in Nextcloud Calendar, Apple Calendar, Outlook, eM Client, Thunderbird
- **Auto-accept / Approval workflow** — Per-room setting: instant confirmation or manager approval
- **Conflict detection** — Automatic overlap checking, conflicting bookings are declined
- **Permission system** — Viewer / Booker / Manager roles per room, user and group based
- **Room groups** — Organize rooms into groups with shared permissions
- **Availability rules** — Restrict booking hours per room (e.g., weekdays 08:00–18:00)
- **Booking horizon** — Limit how far in advance rooms can be booked
- **Email notifications** — Booking confirmations, decline notices, approval requests with iCalendar attachments
- **Per-room SMTP** — Each room can have its own SMTP config (passwords encrypted via ICrypto)
- **Custom room types** — Define and manage room types (meeting room, studio, lecture hall, etc.)
- **Public REST API** — Bearer token API for external integrations (displays, kiosks, digital signage)
- **CSV Import/Export** — Bulk room management, with MS365/Exchange format support
- **Client compatibility** — Fixes for iOS (CUTYPE=INDIVIDUAL) and eM Client (LOCATION-only booking)
- **No database** — All configuration stored via Nextcloud's IAppConfig

## Screenshots

### Room Management
![Room overview with groups, search, and status columns](screenshots/rooms-overview.png)

### Booking Overview
![Booking management with stats, filters, and status](screenshots/bookings-overview-list.png)

### Room Browser (Calendar Integration)
![Visual room browser with building and facility filters](screenshots/bookroom-filter.png)

### Room Editor
![Room editor with capacity, type, location, and facilities](screenshots/rooms-edit.png)

### CSV Import
![CSV import preview with format detection and validation](screenshots/import-rooms.png)

### Email Notifications
![Booking confirmation email with Accept/Decline buttons](screenshots/confirmation-email.png)

## Requirements

- Nextcloud 32–33
- PHP 8.2+

## Installation

### From Nextcloud App Store

1. Go to **Apps** in your Nextcloud instance
2. Search for **RoomVox**
3. Click **Install**

### From Source

```bash
# Clone into Nextcloud apps directory
cd /var/www/nextcloud/apps/
git clone https://gitea.rikdekker.nl/sam/RoomVox.git roomvox

# Install PHP dependencies
cd roomvox
composer install --no-dev

# Build frontend
npm ci
npm run build

# Enable the app
sudo -u www-data php /var/www/nextcloud/occ app:enable roomvox
```

### Prerequisites

- **SMTP must be configured** in Nextcloud (Settings > Administration > Basic settings > Email server)
- **`sendInvitations`** must be enabled for iMIP calendar invitations:

```bash
sudo -u www-data php /var/www/nextcloud/occ config:app:set dav sendInvitations --value yes
```

## How It Works

1. **Admin creates rooms** via the admin panel (Settings > Administration > RoomVox)
2. **Rooms appear as bookable resources** in any CalDAV-compatible calendar app
3. **Users book rooms** by adding them to calendar events
4. **RoomVox handles everything** — scheduling, conflict detection, permissions, and notifications

## Technical Highlights

| Feature | Description |
|---------|-------------|
| CalDAV native | Rooms are standard CalDAV resources, compatible with any calendar app |
| Zero database | All data stored in Nextcloud's IAppConfig — no migrations needed |
| Smart scheduling | Priority 99 Sabre plugin handles iTIP before Nextcloud's default handler |
| Permission inheritance | Room groups share permissions with their rooms |
| Client fixes | Automatic workarounds for iOS and eM Client quirks |

## CalDAV Client Compatibility

| Client | Status | Notes |
|--------|--------|-------|
| Nextcloud Calendar | Full support | Optional visual room browser via calendar patch |
| Apple Calendar (macOS/iOS) | Full support | Auto-fix for CUTYPE=INDIVIDUAL |
| Microsoft Outlook | Full support | Via CalDAV account |
| Thunderbird | Full support | Via CalDAV account |
| eM Client | Full support | Auto-fix for LOCATION-only bookings |

## Development

```bash
npm run dev       # Development build
npm run watch     # Watch mode with auto-rebuild
npm run build     # Production build
```

## Documentation

Full documentation is available in the [docs/](docs/index.md) directory:

- [Getting Started](docs/getting-started.md) — Create your first room in 5 minutes
- [User Guide](docs/user/booking-rooms.md) — How to book rooms
- [Admin Guide](docs/admin/installation.md) — Installation and configuration
- [Architecture](docs/architecture/overview.md) — Technical overview
- [API Reference](docs/architecture/api-reference.md) — REST API endpoints

## License

AGPL-3.0-or-later

## Authors

Sam Ditmeijer & Rik Dekker
