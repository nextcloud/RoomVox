# RoomVox Documentation

Welcome to the RoomVox documentation. RoomVox is a CalDAV-native room booking app for Nextcloud that lets users book meeting rooms directly from any calendar app — Nextcloud Calendar, Apple Calendar, Outlook, Thunderbird, eM Client — without a separate booking UI.

![Room overview](../screenshots/rooms-overview.png)

*Rooms appear as bookable CalDAV resources. Bookings happen in the calendar app users already use.*

## Quick Navigation

### For Users

Learn how to book rooms, manage bookings, and understand notifications.

- [Overview](user/overview.md) — What RoomVox is and how rooms work as calendar resources
- [Booking Rooms](user/booking-rooms.md) — How to book rooms from Nextcloud Calendar, Apple Calendar, Outlook, Thunderbird, and eM Client
- [Managing Bookings](user/managing-bookings.md) — Approve, decline, reschedule, and cancel bookings
- [Notifications](user/notifications.md) — Email notifications explained
- [Personal Settings](user/personal-settings.md) — My Rooms, Approvals, and Bookings tabs
- [Tips](user/tips.md) — Client-specific tricks and time-savers
- [Troubleshooting](user/troubleshooting.md) — Booking declined, room not visible, language issues
- [FAQ](user/faq.md) — Common user questions

### For Administrators

Installation, configuration, room management, and operations.

- [Admin Guide](admin/guide.md) — Day-to-day administration
- [Settings](admin/settings.md) — Admin panel reference
- [Managing Rooms](admin/room-management.md) — Creating rooms, types, groups, facilities, availability rules
- [Permissions](admin/permissions.md) — Viewer / Booker / Manager roles, room group inheritance
- [Email Configuration](admin/email-configuration.md) — Nextcloud SMTP, per-room SMTP, encryption
- [Import / Export](admin/import-export.md) — CSV import/export, MS365/Exchange migration
- [Telemetry](admin/telemetry.md) — Anonymous usage data (opt-out)
- [Best Practices](admin/best-practices.md) — Permission strategy, group hierarchy, maintenance
- [Troubleshooting](admin/troubleshooting.md) — Email problems, calendar patch issues, debug endpoints
- [FAQ](admin/faq.md) — Common admin questions

### Features

Per-feature documentation for capabilities.

- [Approval Workflow](features/approval-workflow.md) — Tentative bookings, manager approval, decline reasons
- [Availability Rules](features/availability-rules.md) — Day/time restrictions and booking horizon
- [Email Notifications](features/email-notifications.md) — All 9 notification types
- [Calendar Patch](features/calendar-patch.md) — Visual room browser for Nextcloud Calendar
- [Public API](features/public-api.md) — Bearer-token REST API for displays, kiosks, integrations
- [Comparison vs. Calendar Resource Management](features/comparison.md) — Feature comparison with Nextcloud's built-in resource app

### For Architects & Developers

Technical documentation for integration, evaluation, and contribution.

- [Architecture Overview](architecture/overview.md) — System design and components
- [API Reference](architecture/api-reference.md) — Internal + Public v1 REST API endpoints
- [Backend Architecture](architecture/backend-architecture.md) — IAppConfig storage, virtual users, service layer
- [CalDAV Scheduling](architecture/caldav-scheduling.md) — Sabre plugin, iTIP flow, PARTSTAT handling
- [Exchange Integration](architecture/exchange-integration.md) — Microsoft Graph sync, webhooks, conflict detection
- [Nextcloud 34 Compatibility](architecture/nc34-compatibility.md) — NC34 audit, 1.2.0 release plan

### Deployment

Installation, App Store submission, and release process.

- [Installation](deployment/installation.md) — Requirements and installation guide
- [Release Process](deployment/release-process.md) — Version sync, build, GitHub releases
- [App Store Submission](deployment/app-store-submission.md) — Certificate, packaging, signing, uploading

## Getting Started

New to RoomVox? Start with the [Getting Started Guide](getting-started.md) to create your first room in five minutes.

## Support

- **Issues & Feature Requests:** [GitHub Issues](https://github.com/nextcloud/RoomVox/issues)
- **Source Code:** [GitHub Repository](https://github.com/nextcloud/RoomVox)

## License

RoomVox is licensed under the [AGPL-3.0 License](https://www.gnu.org/licenses/agpl-3.0.html).
