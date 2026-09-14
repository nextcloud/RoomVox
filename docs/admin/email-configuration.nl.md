# E-mail-configuratie

RoomVox verstuurt twee soorten e-mails, elk met eigen configuratie-eisen.

## Twee e-mail-flows

### 1. RoomVox-notificaties (MailService)

Boekings-bevestigingen, afwijzingen, conflicten, annuleringen en manager-goedkeurings-verzoeken. Verstuurd door `lib/Service/MailService.php`.

**Ontvangers:**
- Organisator (bevestiging, afwijzing, conflict, annulering)
- Ruimte-managers (goedkeurings-verzoek, annulering)

### 2. Nextcloud CalDAV-uitnodigingen (iMIP)

Agenda-uitnodigingen (`.ics`) naar externe deelnemers van een event. Verstuurd door Nextclouds eigen DAV-scheduling via de IMipService.

## Nextcloud-SMTP-configuratie (verplicht)

Beide e-mail-flows gebruiken de Nextcloud-SMTP-configuratie als basis. Deze moet correct geconfigureerd zijn.

### Via de admin-UI

Instellingen > Beheer > Basisinstellingen > E-mailserver

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

### Via `occ`-commando's

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

### Veelvoorkomende fouten

| Fout | Oorzaak | Oplossing |
|-------|-------|----------|
| `550 5.1.8 Sender address rejected: Domain not found` | Afzender-domein bestaat niet in DNS | Gebruik een geldig domein in `mail_from_address` + `mail_domain` |
| `Connection timed out` | `mail_smtpsecure` niet ingesteld | Zet `mail_smtpsecure` op `tls` voor poort 587 |
| `Authentication failed` | Verkeerde inloggegevens | Check `mail_smtpname` en `mail_smtppassword` |

## E-mailadressen van ruimtes

Elke ruimte heeft een e-mailadres dat voor twee doelen gebruikt wordt:

### CalDAV-scheduling (intern)

Het e-mailadres van de ruimte is het CalDAV-adres dat gebruikt wordt wanneer de ruimte als deelnemer aan events wordt toegevoegd. Nextcloud gebruikt dit voor interne scheduling (iTIP-berichten).

Als er geen eigen e-mailadres is ingesteld, genereert RoomVox automatisch een intern adres: `<room-id>@roomvox.local`. Dit werkt prima voor CalDAV — het domein hoeft niet te bestaan.

### SMTP-afzender (optioneel)

Als een ruimte een **echt extern e-mailadres** heeft (bijvoorbeeld `boardroom@company.com`), gebruikt RoomVox dat als From-adres voor notificatie-e-mails. Daardoor lijken de e-mails van de ruimte zelf te komen.

Als het ruimte-e-mailadres eindigt op `@roomvox.local`, wordt het **niet** als afzender gebruikt. In dat geval wordt het systeem-afzenderadres van Nextcloud (`mail_from_address@mail_domain`) gebruikt.

### Wanneer stel je een eigen e-mailadres in

| Situatie | Aanbeveling |
|-----------|---------------|
| Ruimte heeft een eigen mailbox (bijvoorbeeld `room1@company.com`) | Instellen als ruimte-e-mailadres |
| SMTP-provider staat meerdere afzenders toe | E-mailadres instellen — notificaties komen van de ruimte |
| Alleen CalDAV-scheduling nodig | Leeg laten — automatisch gegenereerde `@roomvox.local` volstaat |
| SMTP-provider staat maar één afzender toe | Leeg laten — NC-systeem-afzender wordt gebruikt |

## SMTP per ruimte (optioneel)

Elke ruimte kan zijn eigen SMTP-server geconfigureerd krijgen in de ruimte-editor (sectie SMTP-configuratie). Als die is ingesteld, gebruikt RoomVox deze server in plaats van de Nextcloud-SMTP-configuratie.

### Hoe het werkt

- De SMTP-gebruikersnaam wordt gebruikt als envelope-sender (niet het ruimte-e-mailadres)
- Als het ruimte-e-mailadres afwijkt van de SMTP-gebruikersnaam, wordt het ruimte-e-mailadres als Reply-To gezet
- Het SMTP-wachtwoord wordt versleuteld opgeslagen via `ICrypto`

### Configuratie-velden

| Veld | Beschrijving |
|-------|-------------|
| Host | Hostname van de SMTP-server (bijvoorbeeld `smtp.company.com`) |
| Port | SMTP-poort (standaard: 587, bereik: 1–65535) |
| Username | Gebruikersnaam voor SMTP-authenticatie |
| Password | Wachtwoord voor SMTP-authenticatie |
| Encryption | TLS (STARTTLS), SSL of geen |

### Wanneer gebruik je SMTP per ruimte

- Elke ruimte heeft een eigen e-mailaccount
- Je organisatie wil dat e-mails per ruimte van een ander adres komen
- De globale Nextcloud-SMTP mag niet gebruikt worden voor ruimte-notificaties

### Testen

Gebruik de knop **Testmail versturen** in de SMTP-sectie van de ruimte-editor om de configuratie te verifiëren.

## E-mail-checklist

1. **Nextcloud-SMTP geconfigureerd** — inclusief `mail_smtpsecure`
2. **E-mail-notificaties ingeschakeld** — RoomVox-beheer > Instellingen > "E-mail-notificaties inschakelen"
3. **Managers hebben e-mailadressen** — Nextcloud-gebruikersinstellingen > E-mail
4. **Organisatoren hebben e-mailadressen** — anders kunnen bevestigingen niet verstuurd worden
5. **iMIP-uitnodigingen ingeschakeld** — `occ config:app:set dav sendInvitations --value yes`
6. **Testen via de ruimte-editor** — knop "Testmail versturen" in de SMTP-sectie

### Verificatie-commando's

```bash
# Check Nextcloud mail configuration
sudo -u www-data php occ config:system:get mail_smtphost
sudo -u www-data php occ config:system:get mail_smtpsecure

# Check logs for email errors
tail -f /var/www/nextcloud/data/nextcloud.log | grep -i "RoomVox.*mail\|RoomVox.*email\|smtp"
```
