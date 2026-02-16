# Future Consideration: Personal Settings Page

**Status**: Removed in v0.3.0 (Feb 2026)
**Reason**: Insufficient added value over existing Nextcloud features

## What was built

A Personal Settings page (Settings > Personal > RoomVox) with:
- Tab navigation (Overview, My rooms, My bookings)
- Room list with role badges (admin/manager/booker/viewer)
- Bookings list with status badges (Confirmed/Pending/Declined)
- iCal feed URL per room (copy to clipboard)
- Quick stats (upcoming bookings count, rooms available, rooms managed)
- Next booking card

## Why it was removed

Most functionality duplicated what Nextcloud already provides:

| Feature | Already available in |
|---------|---------------------|
| Upcoming bookings | Nextcloud Calendar |
| Next booking | Nextcloud Calendar + Dashboard |
| All bookings list | Nextcloud Calendar (rooms are CalDAV resources) |
| iCal feed URL | Doesn't work in external calendar apps (requires session auth); Public API with Bearer token exists for programmatic use |

The **only unique features** were:
1. **Room access overview with roles** - Which rooms the user can access and with what role
2. **Pending booking status** - TENTATIVE bookings awaiting approval (not visible in Nextcloud Calendar)

## Known issues at time of removal

### My bookings organizer matching bug

The `myBookings()` endpoint filtered bookings where `extractOrganizerId(organizer) === userId`. The organizer field format varies:

- **Locally created bookings** (via BookingApiController): `ORGANIZER;CN=alice:mailto:alice@localhost` → extracts `alice` (works)
- **External iTIP bookings** (via CalDAV clients): `ORGANIZER;CN=John Smith:mailto:john.smith@company.com` → extracts `john` (WRONG, userId might be `jsmith`)

The `extractOrganizerId()` method takes everything before `@`, which only works for `@localhost` addresses.

**Fix approach**: Use Nextcloud's user lookup by email (`IUserManager::getByEmail()`) to resolve the organizer email to a userId, or compare against all known email addresses for the current user.

### iCal feed authentication

External calendar apps (macOS Calendar, Outlook, Google Calendar) cannot use the iCal feed because:
- Session auth requires browser cookies
- Bearer token auth is not supported by calendar apps
- The industry standard is secret-URL-based sharing (Outlook, Google, Skedda, Nextcloud Calendar all use this)

**If rebuilding**: Implement per-user secret tokens (32+ chars, cryptographically random) embedded in the URL. Provide token management (generate/revoke/regenerate) in the UI. Consider busy/free-only mode to minimize data exposure.

## Files that were removed

```
lib/Settings/PersonalSettings.php      - ISettings implementation
lib/Settings/PersonalSection.php       - IIconSection implementation
lib/Controller/PersonalApiController.php - API endpoints (myRooms, myBookings, calendarFeed)
src/views/PersonalSettings.vue         - Vue 3 component
src/personal.js                        - Webpack entry point
templates/personal.php                 - PHP template
js/roomvox-personal.js                 - Compiled output
js/roomvox-personal.js.LICENSE.txt     - License file
```

## Files that were modified

```
appinfo/info.xml       - Removed <personal> and <personal-section> from <settings>
appinfo/routes.php     - Removed personal_api routes
webpack.config.js      - Removed 'personal' entry point
src/services/api.js    - Removed getMyRooms() and getMyBookings()
```

## If rebuilding in the future

Consider a simplified single-page layout with:

1. **My rooms table** - Name, Type, Capacity, Location, Role badge. Sorted by role (admin > manager > booker > viewer). This is the only place users can see their room access.

2. **Pending bookings section** (only shown when TENTATIVE bookings exist) - Room, Title, Date & Time, Status badge. Only TENTATIVE bookings, since confirmed ones are already in Nextcloud Calendar.

3. **Optional: iCal subscription with secret tokens** - Per-user, per-room secret URL for external calendar apps. Requires token management UI and audit logging.

Key backend code to reuse:
- `PermissionService::getEffectiveRole()` for room-role resolution
- `CalDAVService::getBookings()` for fetching bookings from room calendars
- `RoomService::getAllRooms()` for room listing
