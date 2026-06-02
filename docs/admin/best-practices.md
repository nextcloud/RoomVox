# Best Practices

Recommendations for designing, maintaining, and operating RoomVox.

## Permission Strategy

### Use Groups Over Individual Users

Wherever possible, assign permissions to Nextcloud groups rather than individual users.

- **Easier to maintain** — adding a new user to the right group grants access automatically
- **Survives user lifecycle** — leaving employees lose access when removed from groups, no per-room cleanup needed
- **Better CalDAV visibility** — group entries are published as CalDAV `group_restrictions`, so the room appears automatically in the resource list. User entries are enforced only at booking time and may require manual search

### Use Room Groups for Shared Permission Patterns

If a building, department, or location has multiple rooms with the same access pattern, create a **room group**:

- Set permissions once on the group
- All member rooms inherit those permissions
- Add room-specific exceptions on individual rooms (effective permissions = union of both)

Example:

```
Room Group "Building A":
  bookers: [group: staff]
  managers: [group: facilities]

Room "Boardroom" (in Building A):
  bookers: [group: leadership]

Effective permissions for "Boardroom":
  bookers: [group: staff, group: leadership]
  managers: [group: facilities]
```

### Assign At Least One Manager Per Room

Without a Manager, pending bookings can't be approved. If you use auto-accept everywhere, you can skip this — but for any room with `autoAccept=false`, designate at least one Manager who:

- Receives approval-request emails
- Can approve/decline from the Bookings tab
- Can edit room settings and permissions

### Keep Viewer Permissions Broad

Viewer role lets users **see** a room in calendar apps and in **Personal Settings → My Rooms**, where they also see the **Responsible contact** field. This is useful even for users who can't book — they know who to ask if they need the room.

### Review Permissions Periodically

Quarterly:

- Remove departed users from explicit permissions
- Verify group memberships still reflect current reality
- Check rooms with `autoAccept=false` still have an active Manager

## Room Configuration

### Set a Responsible Contact

Use the **Responsible contact** field (max 255 chars) to tell viewers who to approach when they cannot book a room themselves. Free-text — name + email, or just "Ask the building manager", or a phone number. Visible in Personal Settings → My Rooms.

### Use Availability Rules Instead of Manual Approval

If you only want rooms bookable during business hours:

- ✅ Configure [Availability Rules](../features/availability-rules.md) (weekday/time window)
- ❌ Don't disable auto-accept just to manually approve out-of-hours requests

Availability rules **auto-decline** out-of-hours bookings with a clear email that names the rules. Manual review for the same thing is wasted effort.

### Set a Booking Horizon for High-Demand Rooms

Limit how far ahead high-demand rooms can be booked (e.g., max 60 days). This:

- Prevents speculative reservations months out
- Forces more current scheduling
- Catches infinite recurring events (always declined when a horizon is set)

### Pick the Right Default for Auto-Accept

| Default | When to use |
|---|---|
| **On** (instant confirm) | High-trust environments, capacity not contested, fast iteration matters |
| **Off** (manager approval) | High-demand rooms, formal venues (boardroom, lecture hall), need to vet bookings |

Set the app-wide default in **Settings**, override per room as needed.

## Email Strategy

### Configure Nextcloud SMTP Once

Most installations should use **Nextcloud's global SMTP** for all rooms. Reserve per-room SMTP for cases where rooms must visibly send from their own mailbox (e.g., `boardroom@company.com`).

### Set Real Email Addresses for Visible Rooms

If a room has a real mailbox, set it as the room's email address:

- Notifications appear to come from the room (`From: boardroom@company.com`)
- iMIP invitations to external attendees show the room as a real entity
- Replies route to the right inbox

If a room has no real mailbox, leave the email empty — RoomVox auto-generates `<room-id>@roomvox.local` and falls back to the Nextcloud system sender for outgoing mail.

### Verify `mail_smtpsecure` Is Set

A common omission: `mail_smtpmode`, `mail_smtphost`, `mail_smtpport` are set but `mail_smtpsecure` is missing. SMTP works in some clients, times out in others. Always set:

- `tls` for port 587 (STARTTLS)
- `ssl` for port 465

### Enable `sendInvitations` for iMIP

```bash
sudo -u www-data php occ config:app:set dav sendInvitations --value yes
```

Without this, calendar invitations to external attendees fail with "Failed to deliver invitation".

## CSV Import (Migration from MS365)

### Use the Full Export Script

For MS365 migrations, use the full PowerShell script in [Import / Export](import-export.md) that joins `Get-EXOMailbox` and `Get-Place` — the simple `Get-Place` pipeline does **not** preserve email addresses, breaking duplicate detection.

### Import First, Permissions Second

Workflow:

1. Export from MS365 (full script with emails)
2. Import in **"Only create new rooms"** mode first to verify
3. Re-run in **"Create + update"** mode to update existing
4. Add permissions per room or per room group **after** rooms exist

### Export Before Bulk Changes

Always export a backup CSV before:

- Bulk import that updates existing rooms
- Manual edits to many rooms
- Deleting a room group

The export is a complete snapshot — round-trip safe.

## Maintenance

### Watch for "Booking Not Permitted" Warnings

Since v1.1.1, RoomVox logs **warning**-level entries when the iTIP sender resolves to zero or multiple Nextcloud users. This typically indicates LDAP/AD configurations where the same email exists on multiple accounts. Check `nextcloud.log`:

```bash
tail -f /var/www/nextcloud/data/nextcloud.log | grep -i "RoomVox.*permission\|RoomVox.*decline"
```

### Test the Calendar Patch After NC Updates

If you deploy the [Calendar Patch](../features/calendar-patch.md), every Nextcloud or Calendar-app update may overwrite the patched JS files. Re-deploy with `./deploy-calendar.sh <target>` after each update — and check whether the upstream Calendar version is still v6.2.0 (the patch is pinned to it).

### Plan for NC34

NC34 reached GA in June 2026. RoomVox is NC34-ready — see [Nextcloud 34 Compatibility](../architecture/nc34-compatibility.md). Bump `info.xml`'s `max-version` to `34` when upgrading.

## See Also

- [Permissions](permissions.md) — three-role system + inheritance
- [Email Configuration](email-configuration.md) — Nextcloud and per-room SMTP
- [Import / Export](import-export.md) — CSV workflows
- [Troubleshooting](troubleshooting.md) — common issues
