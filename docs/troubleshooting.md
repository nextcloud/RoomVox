# Troubleshooting

Common issues and solutions for RoomVox.

## Rooms Not Appearing in Calendar Apps

### Room not visible in Nextcloud Calendar

**Cause:** The room may be inactive or the user doesn't have the required permissions.

**Solution:**
1. Check the room is **active** in the admin panel (Room Editor > Active toggle)
2. Check **permissions** — if group restrictions are configured, the user must be in one of the allowed groups
3. Refresh the calendar app (Ctrl+Shift+R or clear cache)
4. Check the debug endpoint: `GET /apps/roomvox/api/debug/rooms` (admin only)

### Room not visible in Apple Calendar / Outlook / Thunderbird

**Cause:** CalDAV account may not be syncing resources.

**Solution:**
1. Verify the CalDAV account is configured and syncing
2. Force a full resync of the CalDAV account
3. Check that the room backend is registered: visit the debug endpoint
4. Some clients require a restart to pick up new resources

## Booking Issues

### Booking is declined — "No permission"

**Cause:** The user doesn't have Booker or Manager role for the room.

**Solution:**
1. Check the room's permissions in the admin panel
2. Add the user or their group as a Booker
3. Check room group permissions if the room is in a group
4. Nextcloud admins always have full access

### Booking is declined — "Scheduling conflict"

**Cause:** Another event is already booked at the requested time.

**Solution:**
1. Check existing bookings for the room in the Bookings tab
2. Choose a different time slot
3. Cancelled and declined bookings don't count as conflicts

### Booking is declined — "Outside availability"

**Cause:** The requested time is outside the room's availability rules.

**Solution:**
1. Check the room's availability rules in the Room Editor
2. Book within the allowed days and time window
3. Disable availability rules if the restriction is no longer needed

### Booking is declined — "Beyond booking horizon"

**Cause:** The event is too far in the future.

**Solution:**
1. Check the room's maximum booking horizon in the Room Editor
2. Book within the allowed time frame (e.g., max 90 days ahead)
3. For recurring events, ensure the last occurrence is within the horizon
4. Infinite recurring events (no UNTIL or COUNT) are always declined when a horizon is set

### Booking stuck as "Tentative" / Pending

**Cause:** The room has auto-accept disabled and no manager has approved the booking yet.

**Solution:**
1. A room manager needs to approve the booking in the Bookings tab
2. Check that the room has at least one user with Manager role
3. Managers should receive email notifications for pending bookings

## Email Issues

### No emails being sent

**Cause:** Email notifications may be disabled or SMTP not configured.

**Checklist:**
1. Email notifications enabled: RoomVox Settings > "Enable email notifications"
2. Nextcloud SMTP configured: Settings > Administration > Basic settings > Email server
3. Test Nextcloud email: Use "Send email" button in Basic settings
4. Check `mail_smtpsecure` is set (common omission)

### Emails from wrong sender address

**Cause:** Room email or SMTP configuration mismatch.

**Solution:**
1. If room uses `@roomvox.local` email → notifications use NC system sender. Set a real email to change this.
2. If per-room SMTP is configured → the SMTP username is the envelope sender, room email is Reply-To
3. Check SMTP provider allows sending from the configured address

### "550 Sender address rejected"

**Cause:** The sender domain doesn't exist or isn't authorized.

**Solution:**
1. Check `mail_from_address` + `mail_domain` in Nextcloud config
2. Use a domain that exists in DNS
3. For per-room SMTP, verify the SMTP account is authorized to send from the configured address

### "Connection timed out"

**Cause:** Missing encryption configuration.

**Solution:**
1. Set `mail_smtpsecure` to `tls` for port 587 (STARTTLS)
2. Set `mail_smtpsecure` to `ssl` for port 465
3. Check firewall allows outbound connections on the SMTP port

### Test email works but booking notifications don't

**Cause:** Email notifications may be disabled in RoomVox settings.

**Solution:**
1. Check RoomVox Settings > "Enable email notifications" is ON
2. Check the organizer and managers have email addresses in their Nextcloud profiles
3. Check Nextcloud logs for RoomVox email errors

## Calendar Patch Issues

### Room browser not showing after patch deployment

**Cause:** Browser cache serving old JavaScript.

**Solution:**
1. Hard refresh: Ctrl+Shift+R (or Cmd+Shift+R on macOS)
2. Clear browser cache completely
3. Verify the patch was deployed: check file timestamps on the server
   ```bash
   ls -la /var/www/nextcloud/apps/calendar/js/
   ```

### Room browser shows after NC update but rooms are empty

**Cause:** Nextcloud or Calendar app update may have overwritten patched files.

**Solution:**
1. Redeploy the calendar patch: `./deploy-calendar.sh <target>`
2. If Calendar app was updated, review stock changes and update the patch

### "resource_booking_enabled" error (NC33)

**Cause:** NC33 hides the resource picker if no room backend is registered.

**Solution:**
1. Verify RoomVox is enabled: `occ app:list | grep roomvox`
2. RoomVox registers a room backend automatically — the flag should be true
3. If the issue persists, disable and re-enable RoomVox

## Permission Issues

### User can see room but can't book

**Cause:** User has Viewer role but not Booker role.

**Solution:**
1. Add the user or their group as a Booker in the room's permissions
2. Check room group permissions if applicable

### Manager can't see bookings tab

**Cause:** User may not have Manager role on any room.

**Solution:**
1. The booking overview shows bookings across rooms the user can manage
2. Verify the user has Manager role on at least one room
3. Nextcloud admins see all rooms and bookings

## General Issues

### Admin panel not loading

**Cause:** JavaScript build may be missing or corrupted.

**Solution:**
1. Rebuild the frontend:
   ```bash
   cd /var/www/nextcloud/apps/roomvox
   npm ci
   npm run build
   ```
2. Check browser console (F12) for JavaScript errors
3. Clear Nextcloud's JS/CSS cache:
   ```bash
   sudo -u www-data php occ maintenance:repair
   ```

### Room data seems corrupted

**Cause:** IAppConfig data may have been manually edited or corrupted.

**Solution:**
1. Use the debug endpoint to inspect room data: `GET /apps/roomvox/api/debug/rooms`
2. Check IAppConfig directly:
   ```bash
   sudo -u www-data php occ config:app:get roomvox rooms_index
   sudo -u www-data php occ config:app:get roomvox room/<roomId>
   ```
3. If a specific room is broken, delete and recreate it

## Log Locations

Check these logs for debugging:

```bash
# Nextcloud log (includes RoomVox errors)
tail -f /var/www/nextcloud/data/nextcloud.log

# Filter for RoomVox entries
tail -f /var/www/nextcloud/data/nextcloud.log | grep -i "roomvox"

# Filter for email/SMTP errors
tail -f /var/www/nextcloud/data/nextcloud.log | grep -i "RoomVox.*mail\|smtp"

# Filter for scheduling errors
tail -f /var/www/nextcloud/data/nextcloud.log | grep -i "RoomVox.*schedul"
```
