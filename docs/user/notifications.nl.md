# E-mail-notificaties

RoomVox verstuurt e-mail-notificaties om organisatoren en managers op de hoogte te houden van wijzigingen in de boekings-status.

## Vereisten

- **E-mail-notificaties ingeschakeld** in de RoomVox-instellingen (Instellingen > Beheer > RoomVox > Instellingen)
- **Nextcloud-SMTP geconfigureerd** (Instellingen > Beheer > Basisinstellingen > E-mailserver)
- **Gebruikers hebben een e-mailadres** ingesteld in hun Nextcloud-profiel

## Notificatie-types

### Boeking bevestigd

**Verstuurd naar:** organisator

Getriggerd wanneer een boeking geaccepteerd wordt (automatisch geaccepteerd of goedgekeurd door een manager).

![Boekings-bevestigings-e-mail met event-details en locatie](../../screenshots/confirmation-email.png)

**Bevat:**
- Ruimte-naam
- Event-samenvatting
- Event-datum en -tijd
- Naam en e-mailadres van de organisator

### Boeking afgewezen

**Verstuurd naar:** organisator

Getriggerd wanneer een boeking door een manager wordt afgewezen.

**Bevat:**
- Ruimte-naam
- Event-samenvatting
- Event-datum en -tijd
- Reden van afwijzing (indien opgegeven)

### Permissie geweigerd

**Verstuurd naar:** organisator

Getriggerd wanneer een boeking automatisch wordt afgewezen omdat de gebruiker geen permissie heeft om de ruimte te boeken.

**Bevat:**
- Ruimte-naam
- Event-samenvatting
- Event-datum en -tijd

Wanneer een boeking om permissie-redenen geweigerd wordt, wordt de ruimte-deelnemer ook uit het event van de organisator verwijderd en het LOCATION-veld gewist, zodat de agenda de ruimte niet langer als onderdeel van het event toont.

### Planning-conflict

**Verstuurd naar:** organisator

Getriggerd wanneer een boeking automatisch wordt afgewezen vanwege een tijd-conflict met een bestaande boeking. Terugkerende events worden op het niveau van de afzonderlijke voorkomende keren gecheckt — de tweede (of latere) instantie van een wekelijkse serie boeken triggert nu correct een conflict-mail, niet alleen de eerste instantie.

**Bevat:**
- Ruimte-naam
- Event-samenvatting
- Gevraagde datum en tijd
- Conflict-informatie

### Boekings-horizon overschreden

**Verstuurd naar:** organisator

Getriggerd wanneer een boeking automatisch wordt afgewezen omdat hij voorbij de ingestelde `Maximum booking horizon` van de ruimte valt. Terugkerende events met een laatste voorkomende keer ver in de toekomst (of zonder `UNTIL`/`COUNT`) vallen ook onder deze check.

**Bevat:**
- Ruimte-naam, event-samenvatting, gevraagde datum en tijd
- De exacte horizon in dagen (bijvoorbeeld `60 days`)
- De vroegste datum die niet meer boekbaar is (`vandaag + N dagen`), zodat de organisator kan verplaatsen zonder te gokken

### Buiten beschikbaarheids-uren

**Verstuurd naar:** organisator

Getriggerd wanneer een boeking buiten de ingestelde beschikbaarheids-regels van de ruimte valt (bijvoorbeeld alleen doordeweeks 09:00–17:00).

**Bevat:**
- Ruimte-naam, event-samenvatting, gevraagde datum en tijd
- Een samenvatting van de beschikbaarheids-regels van de ruimte (`Mon, Tue, Wed, Thu, Fri 09:00–17:00`)

### Ruimte-sync bezig

**Verstuurd naar:** organisator

Tijdelijke fout: getriggerd wanneer een boeking binnenkomt terwijl de initiële Exchange-sync van een ruimte nog draait. De organisator wordt gevraagd het over een paar minuten opnieuw te proberen.

### Goedkeurings-verzoek

**Verstuurd naar:** alle ruimte-managers

Getriggerd wanneer een nieuwe boeking binnenkomt voor een ruimte met auto-accept uitgeschakeld. De boeking krijgt de status voorlopig (in afwachting).

**Bevat:**
- Ruimte-naam
- Event-samenvatting
- Event-datum en -tijd
- Naam en e-mailadres van de organisator

### Boeking geannuleerd

**Verstuurd naar:** organisator en alle ruimte-managers

Getriggerd wanneer een boeking door de organisator geannuleerd wordt (doordat zijn agenda-app een iTIP CANCEL verstuurt).

**Bevat:**
- Ruimte-naam
- Event-samenvatting
- Event-datum en -tijd
- Annulerings-informatie

### Boeking geannuleerd door manager

**Verstuurd naar:** organisator (booker)

Getriggerd wanneer een beheerder of manager een al geaccepteerde boeking annuleert via de actie **Boeking annuleren** in RoomVox (admin-tabblad Boekingen of de modal per boeking). Anders dan "Boeking geannuleerd" hierboven: de booker heeft niets in gang gezet — een ruimte-manager heeft de ruimte weggehaald. De ruimte wordt ook verwijderd uit het eigen agenda-event van de booker (LOCATION gewist, ROOM-deelnemer verwijderd), zodat het slot vrijkomt in de Room Finder.

**Bevat:**
- Ruimte-naam, event-samenvatting, datum en tijd
- Uitleg dat de boeking door een ruimte-manager is geannuleerd en dat de ruimte is vrijgegeven

## iCalendar-bijlagen

Notificatie-e-mails bevatten waar van toepassing iCalendar-bijlagen (`.ics`):

- **REPLY**-bijlagen voor geaccepteerde/afgewezen responses
- **CANCEL**-bijlagen voor annulerings-berichten

Met deze bijlagen kunnen agenda-apps de event-status automatisch bijwerken.

## Afzender-adres van e-mails

Het "Van"-adres op notificatie-e-mails hangt af van de e-mail-configuratie van de ruimte:

| Configuratie | Van-adres |
|---------------|-------------|
| Ruimte heeft een eigen e-mailadres (bijvoorbeeld `room1@company.com`) | E-mailadres van de ruimte |
| Ruimte heeft eigen SMTP geconfigureerd | SMTP-gebruikersnaam als envelope-afzender, ruimte-e-mail als Reply-To |
| Ruimte gebruikt automatisch gegenereerd e-mailadres (`@roomvox.local`) | Systeem-afzender-adres van Nextcloud |
| Geen ruimte-e-mail geconfigureerd | Systeem-afzender-adres van Nextcloud |

## Wanneer notificaties verstuurd worden

| Event | Organisator krijgt | Managers krijgen |
|-------|---------------|-------------|
| Boeking automatisch geaccepteerd | Bevestigings-e-mail | — |
| Boeking wacht op goedkeuring | — | Goedkeurings-verzoek |
| Manager keurt boeking goed | Bevestigings-e-mail | — |
| Manager wijst boeking af | Afwijzings-e-mail | — |
| Permissie geweigerd (auto-afwijzing) | Permissie-geweigerd-e-mail | — |
| Planning-conflict (auto-afwijzing) | Conflict-e-mail | — |
| Boekings-horizon overschreden (auto-afwijzing) | Horizon-overschreden-e-mail (met N dagen + afkap-datum) | — |
| Buiten beschikbaarheids-uren (auto-afwijzing) | Beschikbaarheids-e-mail (met samenvatting van de regels) | — |
| Ruimte-sync bezig (tijdelijke fout) | Sync-bezig-e-mail | — |
| Organisator annuleert zijn eigen boeking | Annulerings-e-mail | Annulerings-e-mail |
| Manager annuleert een geaccepteerde boeking | Annulering-door-manager-e-mail | — |

## Troubleshooting notificaties

Als er geen e-mails verstuurd worden:

1. **Check of e-mail ingeschakeld is** — RoomVox-instellingen > "Enable email notifications"
2. **Check de Nextcloud-SMTP** — Instellingen > Beheer > Basisinstellingen > E-mailserver > Test-e-mail versturen
3. **Check de e-mailadressen van gebruikers** — gebruikers moeten een e-mailadres ingesteld hebben in hun Nextcloud-profiel
4. **Check de logs** — zoek naar e-mail-fouten in de Nextcloud-log:
   ```bash
   tail -f /var/www/nextcloud/data/nextcloud.log | grep -i "RoomVox.*mail\|smtp"
   ```
5. **Test SMTP per ruimte** — gebruik de knop "Send test email" in de SMTP-sectie van de ruimte-editor

Zie [E-mail-configuratie](../admin/email-configuration.md) voor gedetailleerde SMTP-setup-instructies.
