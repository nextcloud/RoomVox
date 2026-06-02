# Admin FAQ

Common questions from RoomVox administrators. For user-facing questions, see the [User FAQ](../user/faq.md).

## Configuration

### Do I need a database for RoomVox?

No custom tables. RoomVox stores **all** configuration in Nextcloud's `IAppConfig` (key-value store). Bookings live in standard CalDAV calendars. No migrations on install or upgrade. Works with any DB backend Nextcloud supports (PostgreSQL, MySQL, SQLite).

### Where are the rooms in the database?

In `oc_appconfig` under the `roomvox` app. Inspect with:

```bash
sudo -u www-data php occ config:app:get roomvox rooms_index       # array of room IDs
sudo -u www-data php occ config:app:get roomvox room/<roomId>     # one room
sudo -u www-data php occ config:app:get roomvox permissions/<roomId>  # permissions
```

See [Backend Architecture](../architecture/backend-architecture.md) for the full key/value model.

### Can I have different settings per room?

Yes. The app-wide **Settings** tab is for defaults (default auto-accept, room types, email enable, telemetry). Per-room settings (auto-accept, availability rules, booking horizon, email address, per-room SMTP, Exchange link) live in the room editor.

### Why doesn't Nextcloud's calendar sharing affect RoomVox?

RoomVox has its **own** permission system. Sharing a calendar in Nextcloud has no effect on room access. This is intentional — room booking semantics (3 roles, group inheritance, CalDAV resource visibility) differ from calendar sharing. See [Permissions](permissions.md).

### Can users see rooms they can't book?

Yes. **Viewer** role gives visibility without booking ability. Useful for users who occasionally need to know room availability or ask someone else to book on their behalf. See the **Responsible contact** field in [Managing Rooms](room-management.md).

## Booking Behavior

### Why was my user's booking declined?

Common reasons:

| Reason | What to check |
|---|---|
| **No permission** | Add user/group as Booker on the room (or its room group) |
| **Scheduling conflict** | Another booking exists at that time — check Bookings tab |
| **Outside availability** | Booking is outside the room's availability rules |
| **Beyond booking horizon** | Booking is too far in the future |
| **Sender resolves to 0 or multiple users** | LDAP/AD with duplicate emails — check warning log (v1.1.1+) |

The organizer receives a clear email explaining the rejection.

### How do conflicts work for recurring events?

Since v1.1.0, conflicts are checked at **every occurrence** of a recurring event — not just the master. Earlier versions only checked the master, missing conflicts on weekly meeting series. See [CHANGELOG #8](https://github.com/nextcloud/RoomVox/blob/main/CHANGELOG.md).

### Can a user cancel just one occurrence of a recurring series?

Since v1.1.1, yes. The admin UI offers an explicit choice between **Cancel this occurrence** and **Cancel entire series**. Single-occurrence cancel writes an `EXDATE` on the master and removes any matching `RECURRENCE-ID` override. The booker's own calendar gets a `RECURRENCE-ID` override marking the room as `DECLINED` for that one instance. See [CHANGELOG #13](https://github.com/nextcloud/RoomVox/blob/main/CHANGELOG.md).

### What happens when a manager cancels an accepted booking?

Since v1.1.0, the cancel flow mirrors iTIP CANCEL:

1. The booking is removed from the room calendar
2. The room attendee is removed from the booker's own event
3. `LOCATION` is cleared
4. A "Booking cancelled by manager" email is sent to the booker

The slot frees up in the Room Finder immediately. See [CHANGELOG #10](https://github.com/nextcloud/RoomVox/blob/main/CHANGELOG.md).

## Email

### Why isn't email working?

Check in order:

1. **Nextcloud SMTP configured** — Settings → Administration → Basic settings → Email server. Click "Send email" to test.
2. **`mail_smtpsecure` set** — common omission. `tls` for port 587, `ssl` for port 465.
3. **RoomVox email enabled** — Settings tab → "Enable email notifications".
4. **Users have email addresses** — required for both organizer and managers.
5. **`sendInvitations` enabled** — `occ config:app:set dav sendInvitations --value yes`.

See [Email Configuration](email-configuration.md) and [Troubleshooting](troubleshooting.md).

### Can each room have its own SMTP?

Yes. Per-room SMTP in the room editor:

- Host, port, username, password (encrypted with `ICrypto`), encryption (TLS/SSL/none)
- The SMTP **username** is the envelope sender
- The **room email** is set as Reply-To if it differs from the username

Rooms without per-room SMTP fall back to Nextcloud's global SMTP. See [Email Configuration → Per-Room SMTP](email-configuration.md#per-room-smtp-optional).

### Why do emails come from `noreply@roomvox.local`?

Rooms with auto-generated `@roomvox.local` emails are **not** used as sender addresses. The Nextcloud system sender (`mail_from_address@mail_domain`) is used instead.

Set a real email on the room (e.g., `boardroom@company.com`) to change this.

## Versioning & Compatibility

### What Nextcloud versions are supported?

Currently NC 32–33 (per `appinfo/info.xml`). NC 34 is fully tested and compatible — the `max-version` bump to 34 is planned for v1.2.0. See [NC 34 Compatibility](../architecture/nc34-compatibility.md).

### Are there breaking changes I should know about?

| Version | Change | Impact |
|---|---|---|
| v1.0.0 → v1.1.0 | Cancel-by-manager mirrors iTIP CANCEL | Bookers now get a cancellation email and their event no longer shows the room |
| v1.0.x → v1.1.0 | Conflict checking now expands recurring events | Bookings that previously sneaked through on later occurrences now correctly decline |
| v1.1.0 → v1.1.1 | `responsibleContact` field now in API whitelist | Fixed: edits no longer silently dropped on create/update |
| v1.1.0 → v1.1.1 | API booking-create routes manager-approval emails | Bookings created via REST API now trigger approval emails for non-auto-accept rooms |
| v1.1.0 → v1.1.1 | `ORGANIZER` field no longer fabricates `@localhost` | External-email bookings now have correct CalDAV `ORGANIZER` |

Full history in [CHANGELOG.md](https://github.com/nextcloud/RoomVox/blob/main/CHANGELOG.md).

## Public API

### How do I create an API token?

1. **Settings → Administration → RoomVox → Settings tab**
2. Scroll to **API Tokens** → enter a name and scope
3. Click **Create token** — copy immediately, it's shown only once

See [Public API](../features/public-api.md) for usage examples.

### What scopes are available?

| Scope | Allows |
|---|---|
| `read` | List rooms, read bookings, read availability |
| `book` | Above + create bookings |
| `admin` | Above + statistics + admin operations |

Tokens can be optionally restricted to specific rooms.

### Where can I see API token usage?

The internal **Statistics** tab shows aggregate booking counts but does **not** currently break down by token. Use external monitoring or check `nextcloud.log` for `RoomVox.*api/v1` entries.

## Exchange / MS365

### How do I link a room to Exchange?

1. Configure Exchange credentials in Settings → Exchange (tenant ID, client ID, client secret)
2. Enable Exchange sync globally
3. Per room: set the room's email to its Exchange mailbox address — RoomVox automatically queues an initial full sync (`-30d` to `+365d`)
4. Webhook subscriptions are created automatically for near-real-time updates

See [Exchange Integration](../architecture/exchange-integration.md).

### Will bookings during initial sync work?

No — they're temporarily declined with schedule status `5.3` (temporary failure) and the organizer receives a "Room sync in progress" email asking to retry. This prevents double-bookings while RoomVox hasn't yet seen all Exchange events. Bookings resume normally once the sync completes.

## Best Practices

### How many rooms can RoomVox handle?

Tested at **1500 rooms × 300 bookings/hour** (load simulation tests). The bottleneck is typically Nextcloud's CalDAV layer, not RoomVox itself. The `buildSyncIndex()` optimization (v1.1.x) reduces Exchange sync queries by 98% — 25,000 queries → 300 queries per 15-min cycle at 50 rooms × 500 bookings.

### Should I use per-room SMTP for all rooms?

Usually not. Nextcloud's global SMTP handles all rooms in one config. Reserve per-room SMTP for rooms with their own mailboxes where senders must visibly come from that mailbox.

### Should I always require manager approval?

No. Use availability rules and booking horizon to auto-decline obviously-wrong bookings. Reserve manager approval for rooms where human judgment is needed (boardroom, lecture hall, high-demand spaces).

## See Also

- [Admin Guide](guide.md)
- [Best Practices](best-practices.md)
- [Troubleshooting](troubleshooting.md)
- [User FAQ](../user/faq.md)
