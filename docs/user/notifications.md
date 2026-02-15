# Email Notifications

RoomVox sends email notifications to keep organizers and managers informed about booking status changes.

## Prerequisites

- **Email notifications enabled** in RoomVox settings (Settings > Administration > RoomVox > Settings)
- **Nextcloud SMTP configured** (Settings > Administration > Basic settings > Email server)
- **Users have email addresses** set in their Nextcloud profile

## Notification Types

### Booking Confirmed

**Sent to:** Organizer

Triggered when a booking is accepted (either auto-accepted or approved by a manager).

![Booking confirmation email with event details and location](../screenshots/confirmation-email.png)

**Contains:**
- Room name
- Event summary
- Event date and time
- Organizer name and email

### Booking Declined

**Sent to:** Organizer

Triggered when a booking is declined by a manager.

**Contains:**
- Room name
- Event summary
- Event date and time
- Reason for decline (if provided)

### Scheduling Conflict

**Sent to:** Organizer

Triggered when a booking is automatically declined due to a time conflict with an existing booking.

**Contains:**
- Room name
- Event summary
- Requested date and time
- Conflict information

### Approval Request

**Sent to:** All room managers

Triggered when a new booking arrives for a room with auto-accept disabled. The booking is set to tentative (pending) status.

**Contains:**
- Room name
- Event summary
- Event date and time
- Organizer name and email

### Booking Cancelled

**Sent to:** Organizer and all room managers

Triggered when a booking is cancelled (either by the organizer or via the admin panel).

**Contains:**
- Room name
- Event summary
- Event date and time
- Cancellation information

## iCalendar Attachments

Notification emails include iCalendar (`.ics`) attachments where applicable:

- **REPLY** attachments for accepted/declined responses
- **CANCEL** attachments for cancellation notices

These attachments allow calendar apps to automatically update the event status.

## Email Sender Address

The "From" address on notification emails depends on the room's email configuration:

| Configuration | From Address |
|---------------|-------------|
| Room has custom email (e.g., `room1@company.com`) | Room email address |
| Room has per-room SMTP configured | SMTP username as envelope sender, room email as Reply-To |
| Room uses auto-generated email (`@roomvox.local`) | Nextcloud system sender address |
| No room email configured | Nextcloud system sender address |

## When Notifications Are Sent

| Event | Organizer Gets | Managers Get |
|-------|---------------|-------------|
| Booking auto-accepted | Confirmation email | — |
| Booking pending approval | — | Approval request |
| Manager approves booking | Confirmation email | — |
| Manager declines booking | Decline email | — |
| Scheduling conflict (auto-decline) | Conflict email | — |
| Booking cancelled | Cancellation email | Cancellation email |

## Troubleshooting Notifications

If emails are not being sent:

1. **Check email is enabled** — RoomVox settings > "Enable email notifications"
2. **Check Nextcloud SMTP** — Settings > Administration > Basic settings > Email server > Send test email
3. **Check user email addresses** — Users need an email address set in their Nextcloud profile
4. **Check logs** — Look for email errors in the Nextcloud log:
   ```bash
   tail -f /var/www/nextcloud/data/nextcloud.log | grep -i "RoomVox.*mail\|smtp"
   ```
5. **Test per-room SMTP** — Use the "Send test email" button in the room editor's SMTP section

See [Email Configuration](../admin/email-configuration.md) for detailed SMTP setup instructions.
