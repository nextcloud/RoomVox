# Email Configuration

RoomVox sends two types of emails, each with their own configuration requirements.

## Two Email Flows

### 1. RoomVox Notifications (MailService)

Booking confirmations, declines, conflicts, cancellations, and manager approval requests. Sent by `lib/Service/MailService.php`.

**Recipients:**
- Organizer (confirmation, decline, conflict, cancellation)
- Room managers (approval request, cancellation)

### 2. Nextcloud CalDAV Invitations (iMIP)

Calendar invitations (`.ics`) to external attendees of an event. Sent by Nextcloud's own DAV scheduling via the IMipService.

## Nextcloud SMTP Configuration (Required)

Both email flows use the Nextcloud SMTP configuration as their foundation. This must be properly configured.

### Via Admin UI

Settings > Administration > Basic settings > Email server

### Via `config.php`

```php
'mail_smtpmode'     => 'smtp',
'mail_smtphost'     => 'smtp.provider.com',
'mail_smtpport'     => 587,
'mail_smtpsecure'   => 'tls',          // Required for port 587 (STARTTLS)
'mail_smtpauth'     => true,
'mail_smtpname'     => 'user@provider.com',
'mail_smtppassword' => 'password',
'mail_from_address' => 'noreply',       // Part before the @
'mail_domain'       => 'provider.com',  // Domain
```

### Via `occ` Commands

```bash
sudo -u www-data php occ config:system:set mail_smtpmode     --value smtp
sudo -u www-data php occ config:system:set mail_smtphost     --value smtp.provider.com
sudo -u www-data php occ config:system:set mail_smtpport     --value 587 --type integer
sudo -u www-data php occ config:system:set mail_smtpsecure   --value tls
sudo -u www-data php occ config:system:set mail_smtpauth     --value true --type boolean
sudo -u www-data php occ config:system:set mail_smtpname     --value user@provider.com
sudo -u www-data php occ config:system:set mail_smtppassword --value password
sudo -u www-data php occ config:system:set mail_from_address --value noreply
sudo -u www-data php occ config:system:set mail_domain       --value provider.com
```

### Common Errors

| Error | Cause | Solution |
|-------|-------|----------|
| `550 5.1.8 Sender address rejected: Domain not found` | Sender domain doesn't exist in DNS | Use a valid domain in `mail_from_address` + `mail_domain` |
| `Connection timed out` | `mail_smtpsecure` not set | Set `mail_smtpsecure` to `tls` for port 587 |
| `Authentication failed` | Wrong credentials | Check `mail_smtpname` and `mail_smtppassword` |

## Room Email Addresses

Each room has an email address used for two purposes:

### CalDAV Scheduling (Internal)

The room email address is the CalDAV address used when the room is added as an attendee to events. Nextcloud uses this for internal scheduling (iTIP messages).

If no custom email is set, RoomVox auto-generates an internal address: `<room-id>@roomvox.local`. This works fine for CalDAV — the domain doesn't need to exist.

### SMTP Sender (Optional)

If a room has a **real external email address** (e.g., `boardroom@company.com`), RoomVox uses it as the From address for notification emails. This makes emails appear to come from the room.

If the room email ends with `@roomvox.local`, it is **not** used as sender. In that case, Nextcloud's system sender address (`mail_from_address@mail_domain`) is used instead.

### When to Set a Custom Email

| Situation | Recommendation |
|-----------|---------------|
| Room has its own mailbox (e.g., `room1@company.com`) | Set as room email |
| SMTP provider allows multiple senders | Set email — notifications come from the room |
| Only CalDAV scheduling needed | Leave empty — auto-generated `@roomvox.local` suffices |
| SMTP provider allows only one sender | Leave empty — NC system sender is used |

## Per-Room SMTP (Optional)

Each room can have its own SMTP server configured in the Room Editor (SMTP Configuration section). When set, RoomVox uses this server instead of the Nextcloud SMTP configuration.

### How It Works

- The SMTP username is used as envelope sender (not the room email address)
- If the room email differs from the SMTP username, the room email is set as Reply-To
- The SMTP password is stored encrypted via `ICrypto`

### Configuration Fields

| Field | Description |
|-------|-------------|
| Host | SMTP server hostname (e.g., `smtp.company.com`) |
| Port | SMTP port (default: 587, range: 1–65535) |
| Username | SMTP authentication username |
| Password | SMTP authentication password |
| Encryption | TLS (STARTTLS), SSL, or None |

### When to Use Per-Room SMTP

- Each room has its own email account
- Your organization wants emails to come from different addresses per room
- The global Nextcloud SMTP should not be used for room notifications

### Testing

Use the **Send test email** button in the room editor's SMTP section to verify the configuration.

## Email Checklist

1. **Nextcloud SMTP configured** — including `mail_smtpsecure`
2. **Email notifications enabled** — RoomVox admin > Settings > "Enable email notifications"
3. **Managers have email addresses** — Nextcloud user settings > Email
4. **Organizers have email addresses** — otherwise confirmations can't be sent
5. **iMIP invitations enabled** — `occ config:app:set dav sendInvitations --value yes`
6. **Test via Room Editor** — "Send test email" button in the SMTP section

### Verification Commands

```bash
# Check Nextcloud mail configuration
sudo -u www-data php occ config:system:get mail_smtphost
sudo -u www-data php occ config:system:get mail_smtpsecure

# Check logs for email errors
tail -f /var/www/nextcloud/data/nextcloud.log | grep -i "RoomVox.*mail\|RoomVox.*email\|smtp"
```
