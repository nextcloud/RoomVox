# RoomVox vs Nextcloud Calendar Resource Management

This document compares RoomVox with Nextcloud's built-in [Calendar Resource Management](https://github.com/nextcloud/calendar_resource_management) app (v0.12.0-dev.1, February 2026).

## Overview

| | **RoomVox** | **Calendar Resource Management** |
|---|---|---|
| Version | 0.3.0 | 0.12.0-dev.1 |
| License | AGPL-3.0 | AGPL-3.0 |
| Nextcloud | 32–33 | 31–34 |
| PHP | 8.2+ | 8.1–8.5 |
| Data storage | IAppConfig (no database) | Database (6+ tables) |
| Admin interface | Full web UI | CLI only (`occ` commands) |

## Feature Comparison

| Feature | **RoomVox** | **Calendar Resource Management** |
|---|:---:|:---:|
| Rooms as CalDAV resources | Yes | Yes |
| Web-based admin panel | Yes | No |
| Conflict detection | Yes | No (broken — [#199](https://github.com/nextcloud/calendar_resource_management/issues/199)) |
| Auto-accept bookings | Yes (per room) | Partial (unreliable — [#192](https://github.com/nextcloud/calendar_resource_management/issues/192)) |
| Approval workflow | Yes | No ([#198](https://github.com/nextcloud/calendar_resource_management/issues/198)) |
| Availability rules | Yes (day/time) | No |
| Booking horizon | Yes (max days ahead) | No |
| Email notifications | Yes (5 types) | No ([#196](https://github.com/nextcloud/calendar_resource_management/issues/196)) |
| Per-room SMTP | Yes (encrypted) | No |
| Permission system | Yes (3 roles) | Group restrictions only |
| Room groups | Yes (with inherited permissions) | No |
| Custom room types | Yes | No |
| Room facilities | Yes (customizable) | Yes (fixed set) |
| Building/floor hierarchy | No | Yes |
| Vehicles & general resources | No | Yes |
| iOS compatibility fix | Yes (CUTYPE) | No |
| eM Client compatibility fix | Yes (LOCATION) | No |
| Recurring event validation | Yes (horizon + availability) | No conflict checking |
| Public REST API | Yes (Bearer token auth) | No |
| CSV import/export | Yes (RoomVox + MS365) | No |
| Bulk room management | Yes (CSV import) | CLI only |
| Zero database migrations | Yes | No |

## Why RoomVox

### Reliable Booking Management

RoomVox provides **working conflict detection** that automatically prevents double bookings. The Calendar Resource Management app has a known, unresolved issue where overlapping bookings are not detected ([#199](https://github.com/nextcloud/calendar_resource_management/issues/199)), making it unreliable for real-world room booking scenarios.

### Approval Workflows

RoomVox supports both **auto-accept** and **manager approval** modes per room. When a room requires approval, the booking is set to tentative and all designated managers receive an email notification. They can then approve or decline the booking from the admin panel. Calendar Resource Management has no approval workflow — bookings are accepted immediately without oversight.

### Full Admin Interface

RoomVox includes a **complete web-based admin panel** for creating and managing rooms, configuring permissions, reviewing bookings, and adjusting settings. Calendar Resource Management requires administrators to use CLI commands (`occ`) for all management tasks, which is impractical for non-technical administrators.

### Email Notifications

RoomVox sends **five types of email notifications**:

| Notification | When |
|---|---|
| Booking confirmed | After auto-accepting a booking |
| Booking declined | Permission, availability, or conflict rejection |
| Booking conflict | When a time overlap is detected |
| Approval request | When manual approval is required (sent to managers) |
| Booking cancelled | When the organizer cancels (sent to organizer + managers) |

Calendar Resource Management does not send any email notifications.

### Per-Room SMTP

Each room can have its **own SMTP configuration**, allowing booking confirmations to be sent from the room's own email address. SMTP passwords are encrypted using Nextcloud's ICrypto. Rooms without custom SMTP fall back to Nextcloud's global mail configuration.

### Granular Permissions

RoomVox implements a **three-role permission system**:

| Role | Can view | Can book | Can manage |
|---|:---:|:---:|:---:|
| Viewer | Yes | No | No |
| Booker | Yes | Yes | No |
| Manager | Yes | Yes | Yes |

Permissions can be assigned to **individual users** or **Nextcloud groups**, at both the room level and the room group level. Effective permissions are the union of both. Calendar Resource Management only supports group-based restrictions without role differentiation.

### Availability Rules & Booking Horizon

RoomVox lets administrators define **when rooms can be booked** (e.g., weekdays 08:00–18:00) and **how far in advance** (e.g., maximum 90 days). These rules are enforced for both single and recurring events. Calendar Resource Management has no availability or horizon features.

### Calendar Client Compatibility

RoomVox includes **automatic compatibility fixes** for common CalDAV client issues:

- **iOS/macOS Calendar**: Sends `CUTYPE=INDIVIDUAL` instead of `CUTYPE=ROOM` — RoomVox auto-corrects this and adds the LOCATION field
- **eM Client**: Sends bookings with only a LOCATION field (no ATTENDEE) — RoomVox detects the room by location match and adds the proper CalDAV attendee

These fixes happen transparently during scheduling without any user intervention.

### Zero Database Overhead

RoomVox stores all configuration in Nextcloud's **IAppConfig** key-value store. This means:

- No database migrations during install or upgrade
- No schema conflicts with other apps
- Booking data lives in standard CalDAV calendars
- Works with any database backend (PostgreSQL, MySQL, SQLite)

## Where Calendar Resource Management Differs

Calendar Resource Management supports **resource types beyond rooms**:

- **Buildings** with addresses and accessibility flags
- **Stories** (floors) within buildings
- **Vehicles** with make, model, range, and electric status
- **General resources** for equipment and assets

It also provides a **building-floor-room hierarchy** for organizations with multiple locations. RoomVox currently focuses exclusively on rooms, organized in flat groups.

## Roadmap

RoomVox is designed with an extensible architecture. Additional resource types (vehicles, equipment, shared spaces) may be added in future versions, expanding the scope beyond room booking while maintaining the same level of scheduling reliability, permissions, and notifications.

## Summary

RoomVox is purpose-built for **reliable room booking** with the features organizations need: conflict prevention, approval workflows, email notifications, availability rules, and an intuitive admin interface. Calendar Resource Management offers broader resource type support but lacks the core booking functionality required for dependable day-to-day room management.

| Need | Recommendation |
|---|---|
| Reliable room booking with conflict detection | **RoomVox** |
| Approval workflows for room requests | **RoomVox** |
| Email notifications for bookings | **RoomVox** |
| Web-based administration | **RoomVox** |
| Vehicle or equipment management | Calendar Resource Management |
| Multi-building hierarchy | Calendar Resource Management |
