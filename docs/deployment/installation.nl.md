# Installatie

Deze gids beschrijft de vereisten en het installatieproces voor RoomVox.

## Systeemvereisten

| Component | Vereiste |
|-----------|-------------|
| Nextcloud | 32 tot 35 |
| PHP | 8.2 of hoger |
| SMTP | Geconfigureerd in Nextcloud (voor e-mail-notificaties) |

## Ondersteunde talen

RoomVox is beschikbaar in het Engels (en), Nederlands (nl), Duits (de) en Frans (fr). De taal wordt automatisch bepaald door de taalinstelling van de gebruiker in Nextcloud — er is geen extra configuratie nodig.

## Installatie

### Vanuit de Nextcloud App Store

1. Ga naar **Apps** in je Nextcloud-instantie
2. Zoek naar **RoomVox**
3. Klik op **Installeren**

### Vanuit source

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

### Installatie verifiëren

Controleer na het inschakelen van de app of hij werkt:

1. Ga naar **Instellingen > Beheer** in Nextcloud
2. Zoek **RoomVox** in de linker zijbalk
3. Klik erop om het beheerpaneel te openen

## Vereiste configuratie

### Nextcloud SMTP

RoomVox gebruikt de SMTP-configuratie van Nextcloud voor het versturen van e-mail-notificaties. Dit moet geconfigureerd zijn voordat e-mail-notificaties werken.

**Via de Nextcloud-beheer-UI:**

Instellingen > Beheer > Basisinstellingen > E-mailserver

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

**Via `occ`-commando's:**

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

### CalDAV-uitnodigingen inschakelen

Voor iMIP-agenda-uitnodigingen naar externe e-mailadressen:

```bash
sudo -u www-data php occ config:app:set dav sendInvitations --value yes
```

Zonder dit mislukken agenda-uitnodigingen aan externe deelnemers met "Failed to deliver invitation".

## Eerste inrichting

Na de installatie:

1. **Instellingen configureren** — Ga naar het RoomVox-beheerpaneel > tab Instellingen
   - Schakel e-mail-notificaties in
   - Stel het standaard auto-accepteer-gedrag in
   - Configureer ruimte-types
2. **Ruimtes aanmaken** — Zie [Ruimtebeheer](../admin/room-management.md)
3. **Permissies instellen** — Zie [Permissies](../admin/permissions.md)
4. **E-mail testen** — Maak een testruimte aan en gebruik de knop "Testmail versturen"

## Upgraden

Zo upgrade je RoomVox naar een nieuwere versie:

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

Er zijn geen database-migraties nodig — RoomVox slaat alle data op via de IAppConfig van Nextcloud.

## Deïnstalleren

```bash
# Disable the app
sudo -u www-data php /var/www/nextcloud/occ app:disable roomvox

# Remove the app directory
rm -rf /var/www/nextcloud/apps/roomvox
```

> **Let op:** het uitschakelen van de app verwijdert de service-accounts van ruimtes en de CalDAV-resources. De ruimte-configuratiedata die in IAppConfig is opgeslagen, wordt niet automatisch opgeruimd.
