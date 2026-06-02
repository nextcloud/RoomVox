# Admin Troubleshooting

Common issues and resolutions for RoomVox administrators. For user-facing issues, see the [User Troubleshooting](../user/troubleshooting.md).

## Rooms Not Appearing in Calendar Apps

### Room not visible to users

| Possible cause | Solution |
|---|---|
| Room is inactive | Toggle **Active** in the room editor |
| Permissions exclude the user/group | Check the room's permissions; remember effective permissions are the union of room + room group |
| Browser cache | Ask user to hard-refresh (`Ctrl+Shift+R` / `Cmd+Shift+R`) |
| Debug | `GET /apps/roomvox/api/debug/rooms` (admin only) lists registered rooms |

### Room not visible in Apple Calendar / Outlook / Thunderbird

CalDAV clients cache resource lists. Force a full resync, or restart the client.

## Email Issues

### No emails being sent

**Checklist:**

1. **Email notifications enabled** — RoomVox Settings → "Enable email notifications"
2. **Nextcloud SMTP configured** — Settings → Administration → Basic settings → Email server
3. **Test Nextcloud email** — use the "Send email" button in Basic settings
4. **`mail_smtpsecure` is set** — a common omission. Set `tls` for port 587 or `ssl` for port 465

### Emails from wrong sender address

| Configuration | Sender |
|---|---|
| Room has `@roomvox.local` email | Nextcloud system sender |
| Room has real external email | Room email address |
| Per-room SMTP configured | SMTP username (envelope sender), room email is Reply-To |

Verify the SMTP provider allows sending from the configured address.

### "550 Sender address rejected"

The sender domain doesn't exist in DNS or isn't authorized. Check `mail_from_address` + `mail_domain` in `config.php`.

### "Connection timed out"

`mail_smtpsecure` is missing. Set to `tls` for port 587 (STARTTLS) or `ssl` for port 465.

### Test email works but booking notifications don't

- Verify **RoomVox Settings → "Enable email notifications"** is ON
- Verify organizer and managers have email addresses in their Nextcloud profiles
- Check `nextcloud.log` for RoomVox email errors

See [Email Configuration](email-configuration.md) for complete SMTP setup.

## Calendar Patch Issues

### Room browser not showing after patch deployment

**Likely cause:** Browser cache serving old JavaScript.

**Try:**

1. Hard-refresh: `Ctrl+Shift+R` / `Cmd+Shift+R`
2. Clear browser cache completely
3. Verify the patch was deployed:
   ```bash
   ls -la /var/www/nextcloud/apps/calendar/js/
   ```

### Room browser shows after NC update but rooms are empty

A Nextcloud or Calendar-app update may have overwritten the patched files.

**Try:**

1. Redeploy the calendar patch: `./deploy-calendar.sh <target>`
2. If the Calendar app version changed, review stock changes and update the patch — see [Calendar Patch](../features/calendar-patch.md#updating-after-nc-upgrades)

### "resource_booking_enabled" error (NC33)

NC33 hides the resource picker if no room backend is registered.

**Try:**

1. Verify RoomVox is enabled: `occ app:list | grep roomvox`
2. RoomVox registers a room backend automatically — the flag should be true
3. If the issue persists, disable and re-enable RoomVox

## Permission Issues

### User can see room but can't book

The user has **Viewer** role but not **Booker**. Add them or their group as a Booker.

### Manager can't see bookings tab

The user may not have **Manager** role on any room.

- The booking overview shows bookings across rooms the user can manage
- Verify the user has Manager role on at least one room
- Nextcloud admins always see all rooms and bookings

### Booking declined as "Booking not permitted" for a user that should have access

The iTIP sender may resolve to zero or multiple Nextcloud users (typical for LDAP/AD setups where the same email exists on multiple accounts).

Since v1.1.1, the log is **warning** level and names the sender email, match count, and resolved UIDs, so duplicate-account configurations are immediately visible. Check `nextcloud.log` for the warning.

## General Issues

### Admin panel not loading

| Cause | Fix |
|---|---|
| Missing or corrupted JS build | `cd /var/www/nextcloud/apps/roomvox && npm ci && npm run build` |
| Nextcloud JS/CSS cache | `sudo -u www-data php occ maintenance:repair` |
| JavaScript errors | Check the browser console (F12) |

### Room data seems corrupted

RoomVox stores everything in IAppConfig. Inspect with:

```bash
sudo -u www-data php occ config:app:get roomvox rooms_index
sudo -u www-data php occ config:app:get roomvox room/<roomId>
```

The debug endpoint also exposes the room snapshot: `GET /apps/roomvox/api/debug/rooms`.

If a specific room is broken, delete and recreate it. Since v1.1.0, no per-room data is silently dropped — `responsibleContact` is now in the whitelist on both create and update endpoints.

## Public API Issues

### 401 — "Missing or invalid Authorization header"

The Bearer token is missing or malformed.

- Include the header: `Authorization: Bearer rvx_your_token_here`
- The header is case-insensitive (`bearer` works too)
- Tokens start with `rvx_`

### 401 — "Invalid or expired API token"

- Check the token exists in **Settings → API Tokens**
- Check the token's expiration date
- Create a new token if needed

### 403 — "Insufficient permissions"

The token's scope is too low for the requested action.

| Scope | Allows |
|---|---|
| `read` | Listing rooms, reading bookings |
| `book` | Above + creating bookings |
| `admin` | Above + statistics + admin operations |

If the token is restricted to specific rooms, it only accesses those rooms.

### 400 — "Date range must not exceed 365 days"

Reduce the date range to at most 365 days. For statistics, use the default range (last 30 days) or specify a shorter period.

### CSV Import — "File too large (max 5MB)"

Split the file into smaller parts, or remove unnecessary columns or rows. Export from the source system with fewer fields.

## Log Locations

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

## See Also

- [User Troubleshooting](../user/troubleshooting.md) — user-facing issues
- [FAQ](faq.md) — common admin questions
- [Email Configuration](email-configuration.md) — SMTP setup
- [Calendar Patch](../features/calendar-patch.md) — patch installation and updates
