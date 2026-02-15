# Email Configuratie — Vereisten & Architectuur

RoomVox verstuurt twee soorten emails. Beide hebben hun eigen configuratie-vereisten.

---

## Twee email-flows

### 1. RoomVox notificaties (MailService)

Booking bevestigingen, afwijzingen, conflicten, annuleringen en goedkeuringsverzoeken aan managers. Verzonden door `lib/Service/MailService.php`.

**Ontvangers:**
- Organizer (bevestiging, afwijzing, conflict, annulering)
- Room managers (goedkeuringsverzoek, annulering)

### 2. Nextcloud CalDAV uitnodigingen (iMIP)

Kalenderuitnodigingen (.ics) naar externe deelnemers van een event. Verzonden door Nextcloud's eigen DAV scheduling via de `IMipService`.

---

## Nextcloud SMTP configuratie (vereist)

Beide email-flows gebruiken de Nextcloud SMTP configuratie als basis. Deze moet correct ingesteld zijn.

### Vereiste `config.php` instellingen

```php
'mail_smtpmode'     => 'smtp',
'mail_smtphost'     => 'smtp.provider.nl',
'mail_smtpport'     => 587,
'mail_smtpsecure'   => 'tls',          // Verplicht voor port 587 (STARTTLS)
'mail_smtpauth'     => true,
'mail_smtpname'     => 'user@provider.nl',
'mail_smtppassword' => 'wachtwoord',
'mail_from_address' => 'noreply',       // Deel voor de @
'mail_domain'       => 'provider.nl',   // Domein
```

### Instellen via `occ`

```bash
sudo -u www-data php occ config:system:set mail_smtpmode     --value smtp
sudo -u www-data php occ config:system:set mail_smtphost     --value smtp.provider.nl
sudo -u www-data php occ config:system:set mail_smtpport     --value 587 --type integer
sudo -u www-data php occ config:system:set mail_smtpsecure   --value tls
sudo -u www-data php occ config:system:set mail_smtpauth     --value true --type boolean
sudo -u www-data php occ config:system:set mail_smtpname     --value user@provider.nl
sudo -u www-data php occ config:system:set mail_smtppassword --value wachtwoord
sudo -u www-data php occ config:system:set mail_from_address --value noreply
sudo -u www-data php occ config:system:set mail_domain       --value provider.nl
```

### Veelvoorkomende fouten

| Fout | Oorzaak | Oplossing |
|---|---|---|
| `550 5.1.8 Sender address rejected: Domain not found` | Afzenderdomein bestaat niet in DNS | Gebruik een geldig domein in `mail_from_address` + `mail_domain` |
| `Connection timed out` | `mail_smtpsecure` niet ingesteld | Zet `mail_smtpsecure` op `tls` voor port 587 |
| `Authentication failed` | Verkeerde credentials | Controleer `mail_smtpname` en `mail_smtppassword` |

---

## Room email-adressen

Elke ruimte heeft een emailadres dat voor twee doelen gebruikt wordt:

### CalDAV scheduling (intern)

Het room-emailadres is het CalDAV-adres waarmee de ruimte als attendee wordt toegevoegd aan events. Nextcloud gebruikt dit voor interne scheduling (iTIP berichten).

Als er geen custom emailadres is ingevuld, genereert RoomVox automatisch een intern adres: `<room-id>@roomvox.local`. Dit werkt prima voor CalDAV — het domein hoeft niet te bestaan.

### SMTP afzender (optioneel)

Als een ruimte een **echt extern emailadres** heeft (bv. `directiekamer@bedrijf.nl`), gebruikt RoomVox dit als From-adres voor notificatie-emails. Hierdoor komt de mail zichtbaar van de ruimte.

Als het room-emailadres op `@roomvox.local` eindigt, wordt het **niet** als afzender gebruikt. In dat geval gebruikt Nextcloud het systeem-afzenderadres (`mail_from_address@mail_domain`).

### Wanneer een echt emailadres invullen?

| Situatie | Aanbeveling |
|---|---|
| Ruimte heeft eigen mailbox (bv. `zaal1@bedrijf.nl`) | Vul in als room email |
| SMTP-provider staat meerdere afzenders toe | Vul in, mail komt van de ruimte |
| Alleen CalDAV scheduling nodig | Laat leeg, auto-generated `@roomvox.local` volstaat |
| SMTP-provider staat alleen 1 afzender toe | Laat leeg, NC systeem-afzender wordt gebruikt |

---

## Per-room SMTP (optioneel)

Elke ruimte kan een eigen SMTP-server geconfigureerd krijgen in de Room Editor (sectie "SMTP Configuration"). Als dit is ingevuld, gebruikt RoomVox deze server in plaats van de Nextcloud SMTP configuratie.

### Hoe het werkt

- De SMTP username wordt als envelope sender gebruikt (niet het room-emailadres)
- Als het room-emailadres verschilt van de SMTP username, wordt het room-emailadres als Reply-To gezet
- Het SMTP wachtwoord wordt versleuteld opgeslagen via `ICrypto`

### Wanneer per-room SMTP gebruiken?

- Elke ruimte heeft een eigen mailaccount
- De organisatie wil dat emails per ruimte van een ander adres komen
- De globale Nextcloud SMTP mag niet gebruikt worden voor room notificaties

---

## Checklist voor werkende email

1. **Nextcloud SMTP geconfigureerd** — inclusief `mail_smtpsecure`
2. **Email notificaties ingeschakeld** — RoomVox admin > Settings > "Enable email notifications"
3. **Managers hebben emailadres** — Nextcloud gebruikersinstellingen > Email
4. **Organisers hebben emailadres** — anders kan er geen bevestiging verstuurd worden
5. **Test via Room Editor** — "Send test email" knop in de SMTP sectie

### Testen

```bash
# Check Nextcloud mail configuratie
sudo -u www-data php occ config:system:get mail_smtphost
sudo -u www-data php occ config:system:get mail_smtpsecure

# Stuur test-email via occ
sudo -u www-data php occ notification:test-push admin

# Check logs voor email fouten
tail -f /var/www/nextcloud/data/nextcloud.log | grep -i "RoomVox.*mail\|RoomVox.*email\|smtp"
```

---

*Laatst bijgewerkt: 2026-02-13*
