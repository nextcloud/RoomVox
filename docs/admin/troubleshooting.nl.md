# Troubleshooting voor beheerders

Veelvoorkomende problemen en oplossingen voor RoomVox-beheerders. Voor problemen aan gebruikerskant, zie [Troubleshooting voor gebruikers](../user/troubleshooting.md).

## Ruimtes verschijnen niet in agenda-apps

### Ruimte niet zichtbaar voor gebruikers

| Mogelijke oorzaak | Oplossing |
|---|---|
| Ruimte staat op inactief | Zet **Actief** aan in de ruimte-editor |
| Permissies sluiten de gebruiker/groep uit | Check de permissies van de ruimte; onthoud dat effectieve permissies de vereniging zijn van ruimte + ruimte-groep |
| Browser-cache | Vraag de gebruiker om een harde refresh (`Ctrl+Shift+R` / `Cmd+Shift+R`) |
| Debug | `GET /apps/roomvox/api/debug/rooms` (alleen beheerders) toont de geregistreerde ruimtes |

### Ruimte niet zichtbaar in Apple Calendar / Outlook / Thunderbird

CalDAV-clients cachen resource-lijsten. Forceer een volledige resync, of herstart de client.

## E-mail-problemen

### Er worden geen e-mails verstuurd

**Checklist:**

1. **E-mail-notificaties ingeschakeld** — RoomVox-instellingen → "E-mail-notificaties inschakelen"
2. **Nextcloud-SMTP geconfigureerd** — Instellingen → Beheer → Basisinstellingen → E-mailserver
3. **Test de Nextcloud-e-mail** — gebruik de knop "E-mail versturen" in de basisinstellingen
4. **`mail_smtpsecure` is ingesteld** — een veelvoorkomende omissie. Zet `tls` voor poort 587 of `ssl` voor poort 465

### E-mails komen van het verkeerde afzenderadres

| Configuratie | Afzender |
|---|---|
| Ruimte heeft een `@roomvox.local`-e-mailadres | Nextcloud-systeem-afzender |
| Ruimte heeft een echt extern e-mailadres | E-mailadres van de ruimte |
| SMTP per ruimte geconfigureerd | SMTP-gebruikersnaam (envelope-sender), ruimte-e-mailadres is Reply-To |

Controleer of de SMTP-provider versturen vanaf het geconfigureerde adres toestaat.

### "550 Sender address rejected"

Het afzender-domein bestaat niet in DNS of is niet geautoriseerd. Check `mail_from_address` + `mail_domain` in `config.php`.

### "Connection timed out"

`mail_smtpsecure` ontbreekt. Zet op `tls` voor poort 587 (STARTTLS) of `ssl` voor poort 465.

### Testmail werkt wel, maar boekings-notificaties niet

- Verifieer dat **RoomVox-instellingen → "E-mail-notificaties inschakelen"** AAN staat
- Verifieer dat organisator en managers een e-mailadres in hun Nextcloud-profiel hebben
- Check `nextcloud.log` op RoomVox-e-mail-fouten

Zie [E-mail-configuratie](email-configuration.md) voor de volledige SMTP-setup.

## Problemen met de calendar-patch

### Ruimte-browser verschijnt niet na uitrol van de patch

**Waarschijnlijke oorzaak:** browser-cache serveert oude JavaScript.

**Probeer:**

1. Harde refresh: `Ctrl+Shift+R` / `Cmd+Shift+R`
2. Browser-cache volledig legen
3. Verifieer dat de patch is uitgerold:
   ```bash
   ls -la /var/www/nextcloud/apps/calendar/js/
   ```

### Ruimte-browser verschijnt na een NC-update, maar de ruimtes zijn leeg

Een update van Nextcloud of de Calendar-app kan de gepatchte bestanden overschreven hebben.

**Probeer:**

1. Rol de calendar-patch opnieuw uit: `./deploy-calendar.sh <target>`
2. Als de versie van de Calendar-app veranderd is, bekijk de wijzigingen in de stock-bestanden en werk de patch bij — zie [Calendar-patch](../features/calendar-patch.md#bijwerken-na-nc-upgrades)

### Fout "resource_booking_enabled" (NC33)

NC33 verbergt de resource-picker als er geen ruimte-backend geregistreerd is.

**Probeer:**

1. Verifieer dat RoomVox ingeschakeld is: `occ app:list | grep roomvox`
2. RoomVox registreert automatisch een ruimte-backend — de flag hoort true te zijn
3. Als het probleem blijft, schakel RoomVox uit en weer in

## Permissie-problemen

### Gebruiker ziet de ruimte wel, maar kan niet boeken

De gebruiker heeft de rol **Viewer** maar niet **Booker**. Voeg de gebruiker of zijn groep toe als Booker.

### Manager ziet het boekingen-tabblad niet

De gebruiker heeft mogelijk op geen enkele ruimte de rol **Manager**.

- Het boekings-overzicht toont boekingen van de ruimtes die de gebruiker kan beheren
- Verifieer dat de gebruiker op ten minste één ruimte de rol Manager heeft
- Nextcloud-beheerders zien altijd alle ruimtes en boekingen

### Boeking afgewezen met "Boeking niet toegestaan" voor een gebruiker die wel toegang zou moeten hebben

De iTIP-afzender kan naar nul of meerdere Nextcloud-gebruikers oplossen (typisch voor LDAP/AD-setups waar hetzelfde e-mailadres op meerdere accounts staat).

Sinds v1.1.1 staat de log op niveau **warning** en noemt hij het afzender-e-mailadres, het aantal matches en de gevonden UID's, zodat configuraties met dubbele accounts direct zichtbaar zijn. Check `nextcloud.log` op de warning.

## Algemene problemen

### Het beheerpaneel laadt niet

| Oorzaak | Oplossing |
|---|---|
| Ontbrekende of corrupte JS-build | `cd /var/www/nextcloud/apps/roomvox && npm ci && npm run build` |
| Nextcloud-JS/CSS-cache | `sudo -u www-data php occ maintenance:repair` |
| JavaScript-fouten | Check de browser-console (F12) |

### Ruimte-data lijkt corrupt

RoomVox slaat alles op in IAppConfig. Inspecteer met:

```bash
sudo -u www-data php occ config:app:get roomvox rooms_index
sudo -u www-data php occ config:app:get roomvox room/<roomId>
```

Het debug-endpoint toont de ruimte-snapshot ook: `GET /apps/roomvox/api/debug/rooms`.

Als een specifieke ruimte kapot is, verwijder hem en maak hem opnieuw aan. Sinds v1.1.0 wordt er geen ruimte-data meer stil weggegooid — `responsibleContact` staat nu op de whitelist van zowel het create- als het update-endpoint.

## Problemen met de publieke API

### 401 — "Missing or invalid Authorization header"

Het Bearer-token ontbreekt of is misvormd.

- Stuur de header mee: `Authorization: Bearer rvx_your_token_here`
- De header is hoofdletter-ongevoelig (`bearer` werkt ook)
- Tokens beginnen met `rvx_`

### 401 — "Invalid or expired API token"

- Check of het token bestaat onder **Instellingen → API-tokens**
- Check de vervaldatum van het token
- Maak zo nodig een nieuw token aan

### 403 — "Insufficient permissions"

De scope van het token is te laag voor de gevraagde actie.

| Scope | Staat toe |
|---|---|
| `read` | Ruimtes opvragen, boekingen lezen |
| `book` | Bovenstaande + boekingen aanmaken |
| `admin` | Bovenstaande + statistieken + beheer-operaties |

Als het token beperkt is tot specifieke ruimtes, heeft het alleen toegang tot die ruimtes.

### 400 — "Date range must not exceed 365 days"

Verklein het datumbereik tot maximaal 365 dagen. Gebruik voor statistieken het standaardbereik (laatste 30 dagen) of geef een kortere periode op.

### CSV-import — "File too large (max 5MB)"

Splits het bestand in kleinere delen, of verwijder overbodige kolommen of rijen. Exporteer uit het bronsysteem met minder velden.

## Waar de logs staan

```bash
# Nextcloud log (includes RoomVox errors)
tail -f /var/www/nextcloud/data/nextcloud.log

# Filter for RoomVox entries
tail -f /var/www/nextcloud/data/nextcloud.log | grep -i "roomvox"

# Filter for email/SMTP errors
tail -f /var/www/nextcloud/data/nextcloud.log | grep -i "RoomVox.*mail\|smtp"

# Filter for scheduling errors
tail -f /var/www/nextcloud/data/nextcloud.log | grep -i "RoomVox.*schedul"
```

## Zie ook

- [Troubleshooting voor gebruikers](../user/troubleshooting.md) — problemen aan gebruikerskant
- [FAQ](faq.md) — veelgestelde beheerdersvragen
- [E-mail-configuratie](email-configuration.md) — SMTP-setup
- [Calendar-patch](../features/calendar-patch.md) — installatie en updates van de patch
