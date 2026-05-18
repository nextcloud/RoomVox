# Telemetry

RoomVox collects anonymous usage data to help improve the app. This is an **opt-out** feature — it is enabled by default and can be disabled at any time.

## What Data Is Collected

RoomVox sends the following anonymous data once every 24 hours:

| Data | Description |
|------|-------------|
| Instance hash | SHA-256 hash of your Nextcloud URL (not the URL itself) |
| App version | Installed RoomVox version |
| Total rooms | Number of rooms configured |
| Total room groups | Number of room groups |
| Room type counts | How many rooms per type (e.g., 3 meeting rooms, 2 studios) |
| Average capacity | Average room capacity |
| Facilities counts | How many rooms have each facility (projector, whiteboard, etc.) |
| Auto-accept count | How many rooms use auto-accept |
| Rooms with SMTP | How many rooms have per-room SMTP configured |
| Availability rules | How many rooms have availability rules enabled |
| Total users | Total Nextcloud user count |
| Active users (30d) | Users active in the last 30 days |
| Nextcloud version | Installed Nextcloud version |
| PHP version | Server PHP version |
| Country code | From Nextcloud's `default_phone_region` setting |
| Database type | MySQL, PostgreSQL, or SQLite |
| Default language | Nextcloud default language |
| Default timezone | Server timezone |
| OS family | Linux, Windows, or macOS |
| Web server | Apache or nginx |
| Docker | Whether the server runs in a Docker container |
| Extended Support / Enterprise | Boolean indicating whether the host Nextcloud has an Extended Support / Enterprise subscription. Sourced from Nextcloud's public API (`OCP\Util::hasExtendedSupport`). Falls back to `false` if the host is Community |
| Subscription key | Your RoomVox subscription key (when one is configured). Sent so the license server can authenticate the Enterprise claim above — the boolean alone could be spoofed by anyone posting to the telemetry endpoint. Empty string for community instances |

## What Is NOT Collected

- No usernames, email addresses, or personal data
- No booking content, event titles, or descriptions
- No IP addresses or hostnames
- No room names or addresses
- No passwords or API tokens

## Where Data Is Sent

Telemetry data is sent to the RoomVox telemetry server.

## How to Disable Telemetry

### Via Admin Panel

1. Go to **Settings > Administration > RoomVox**
2. Click the **Support** tab
3. Disable the **Send anonymous usage statistics** toggle

### Via Command Line

```bash
sudo -u www-data php occ config:app:set roomvox telemetry_enabled --value false
```

## Manual Report

You can send a telemetry report immediately from the Support tab:

1. Go to **Settings > Administration > RoomVox**
2. Click the **Support** tab
3. Click **Send report now**

The button shows clear feedback:
- **Success**: confirms the report was sent and updates the timestamp
- **Error**: shows the specific server error message (e.g., rate limit, connectivity issue)

## Technical Details

- Telemetry runs as a Nextcloud background job (`TelemetryJob`)
- Reports are sent every 24 hours with a random jitter of up to 2 hours to spread load
- The jitter is stable per installation (based on instance ID hash)
- Failed reports are silently retried on the next interval
- Timeout: 15 seconds per request
