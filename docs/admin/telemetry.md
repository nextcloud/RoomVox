# Usage statistics

With an administrator's permission, RoomVox sends usage statistics about the installation to `licenses.voxcloud.nl`, run by VoxCloud, once a day. **Nothing is sent until an administrator switches this on.** No personal data, content or names are sent.

The rules RoomVox follows are the VoxCloud telemetry rules (design `TELEMETRY.md` 1.1.0).

## How you are asked

After RoomVox is installed or upgraded, every Nextcloud administrator gets a notification in the bell with three answers:

| Answer | Effect |
|---|---|
| **Share usage statistics** | Usage statistics are switched on |
| **Not now** | They stay off; you are asked again with the next RoomVox version |
| **Never ask again** | They stay off; you are not asked again |

Regular users are never asked. Installations upgraded from a version before 1.6.0 that never made a choice are switched off by the upgrade; an explicit "on" is kept.

## What is sent

The Support tab of the RoomVox admin settings lists every field with what it is used for. That list is generated from the same definition the report is built from, so it is always exactly what is sent:

| Field | Used for |
|---|---|
| Installation identifier | A SHA-256 hash of the server's address, to tell installations apart and join the reports of the VoxCloud apps on one server with its licence records. The address itself is not sent |
| Field-list version | Which version of this list the report follows; fields added later are only sent after you agree to them |
| RoomVox, Nextcloud and PHP version | Which versions are still in use and must be supported. PHP as `major.minor` only |
| Number of user accounts, users active in the last 30 days, disabled accounts | To size a licence and to find installations that may need one |
| Nextcloud subscription (yes or no) | Servers with a Nextcloud Enterprise subscription are listed as Enterprise customers and not approached about a licence |
| Country | A world map of installations. From `default_phone_region`, or worked out on the server from `default_timezone`; the time zone itself is not sent |
| Rooms, room groups, rooms that accept bookings automatically, rooms with their own mail server, rooms synced with Microsoft Exchange, Exchange sync on (yes or no) | How RoomVox features are used. Exchange tenant ID, client ID and secret are never sent |

Nothing about individual users, rooms or bookings is sent: no names, email addresses, room names, booking content or credentials.

## The licence-usage report is separate

While a subscription key is entered, RoomVox also reports the key, the installation identifier and the number of rooms, room groups, user accounts and disabled accounts to `licenses.voxcloud.nl`, so the subscription can be checked and seats counted. That report is part of the subscription, not of this choice, and stops when the key is removed.

## Changing your mind

### Via the admin panel

1. Go to **Settings > Administration > RoomVox**
2. Click the **Support** tab
3. Switch **Share usage statistics** on or off

### Via the command line

```bash
sudo -u www-data php occ config:app:set roomvox telemetry_enabled --value false
```

## Manual report

While usage statistics are on, **Send report now** on the Support tab sends a report immediately. It refuses while they are off, and shows "Already sent recently" when a report went out within the last hour.

## Technical details

- Reports are sent by a Nextcloud background job (`TelemetryJob`) every 24 hours, with a jitter of up to 2 hours that is stable per installation
- Failed reports are retried on the next interval; timeout 15 seconds
- The choice is stored in the app config: `telemetry_enabled`, `telemetry_consent_schema`, `telemetry_asked_version` and `telemetry_never_ask`
