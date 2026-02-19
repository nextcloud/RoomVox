# Installation

This guide covers the requirements and installation process for RoomVox.

## System Requirements

| Component | Requirement |
|-----------|-------------|
| Nextcloud | 32 or 33 |
| PHP | 8.2 or higher |
| SMTP | Configured in Nextcloud (for email notifications) |

## Supported Languages

RoomVox is available in English (en), Dutch (nl), German (de), and French (fr). The language is determined automatically by the user's Nextcloud language setting — no additional configuration is needed.

## Installation

### From Nextcloud App Store

1. Go to **Apps** in your Nextcloud instance
2. Search for **RoomVox**
3. Click **Install**

### From Source

```bash
# Clone into Nextcloud apps directory
cd /var/www/nextcloud/apps/
git clone https://github.com/nextcloud/RoomVox.git roomvox

# Install PHP dependencies
cd roomvox
composer install --no-dev

# Build frontend
npm ci
npm run build

# Enable the app
sudo -u www-data php /var/www/nextcloud/occ app:enable roomvox
```

### Verify Installation

After enabling the app, verify it's working:

1. Go to **Settings > Administration** in Nextcloud
2. Look for **RoomVox** in the left sidebar
3. Click it to open the admin panel

## Required Configuration

### Nextcloud SMTP

RoomVox uses Nextcloud's SMTP configuration for sending email notifications. This must be configured before email notifications will work.

**Via Nextcloud Admin UI:**

Settings > Administration > Basic settings > Email server

**Via `config.php`:**

```php
'mail_smtpmode'     => 'smtp',
'mail_smtphost'     => 'smtp.provider.com',
'mail_smtpport'     => 587,
'mail_smtpsecure'   => 'tls',
'mail_smtpauth'     => true,
'mail_smtpname'     => 'user@provider.com',
'mail_smtppassword' => 'password',
'mail_from_address' => 'noreply',
'mail_domain'       => 'provider.com',
```

**Via `occ` commands:**

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

### Enable CalDAV Invitations

For iMIP calendar invitations to external email addresses:

```bash
sudo -u www-data php occ config:app:set dav sendInvitations --value yes
```

Without this, calendar invitations to external attendees will fail with "Failed to deliver invitation".

## Initial Setup

After installation:

1. **Configure settings** — Go to RoomVox admin panel > Settings tab
   - Enable email notifications
   - Set default auto-accept behavior
   - Configure room types
2. **Create rooms** — See [Room Management](room-management.md)
3. **Set permissions** — See [Permissions](permissions.md)
4. **Test email** — Create a test room and use the "Send test email" button

## Upgrading

To upgrade RoomVox to a newer version:

```bash
cd /var/www/nextcloud/apps/roomvox

# Pull latest changes
git pull

# Update PHP dependencies
composer install --no-dev

# Rebuild frontend
npm ci
npm run build
```

No database migrations are needed — RoomVox stores all data via Nextcloud's IAppConfig.

## Uninstalling

```bash
# Disable the app
sudo -u www-data php /var/www/nextcloud/occ app:disable roomvox

# Remove the app directory
rm -rf /var/www/nextcloud/apps/roomvox
```

> **Note:** Disabling the app removes room service accounts and CalDAV resources. Room configuration data stored in IAppConfig is not automatically cleaned up.
