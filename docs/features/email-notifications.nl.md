# E-mail-notificaties

RoomVox verstuurt negen soorten e-mail-notificaties die de volledige levensloop van een boeking bestrijken. Deze pagina beschrijft elk type, wie het ontvangt, wanneer het afgaat en wat de e-mail bevat.

Voor de SMTP-opzet en de afzender-configuratie per ruimte, zie [E-mail-configuratie](../admin/email-configuration.md).

## Vereisten

- **E-mail-notificaties ingeschakeld** — Instellingen → Beheer → RoomVox → Instellingen → "E-mail-notificaties inschakelen"
- **Nextcloud-SMTP geconfigureerd** — Instellingen → Beheer → Basisinstellingen → E-mailserver
- **Gebruikers hebben e-mailadressen** — vereist voor zowel de organisator als de managers
- **`occ config:app:set dav sendInvitations --value yes`** — voor iMIP-uitnodigingen aan externe deelnemers

## De negen notificatie-types

| # | Notificatie | Verstuurd naar | Trigger |
|---|---|---|---|
| 1 | Boeking bevestigd | Organisator | Auto-accept, of manager-goedkeuring |
| 2 | Goedkeurings-verzoek | Alle ruimte-managers | Nieuwe boeking op een ruimte zonder auto-accept |
| 3 | Boeking afgewezen | Organisator | Manager heeft een openstaande boeking afgewezen |
| 4 | Permissie geweigerd | Organisator | Gebruiker mist de Booker-rol voor de ruimte |
| 5 | Planning-conflict | Organisator | Tijd overlapt met een bestaande boeking |
| 6 | Boekings-horizon overschreden | Organisator | Event voorbij de maximale horizon van de ruimte |
| 7 | Buiten beschikbaarheids-uren | Organisator | Event buiten de beschikbare dagen/tijden van de ruimte |
| 8 | Ruimte-sync bezig | Organisator | Boeking kwam binnen tijdens de initiële Exchange-sync |
| 9 | Boeking geannuleerd | Organisator + managers | Organisator annuleert (iTIP CANCEL) |
| 10 | Boeking geannuleerd door manager | Organisator | Manager heeft een al geaccepteerde boeking ingetrokken |

(Ja, het zijn er technisch gezien tien — "geannuleerd door manager" is in v1.1.0 afgesplitst van "geannuleerd" om duidelijk te maken wie de actie in gang zette.)

## 1. Boeking bevestigd

**Verstuurd naar:** organisator

Wordt getriggerd wanneer een boeking wordt geaccepteerd — ofwel automatisch door de ruimte, ofwel handmatig goedgekeurd door een manager.

![Boekings-bevestigings-e-mail](../../screenshots/confirmation-email.png)

**Bevat:**
- Ruimte-naam
- Event-samenvatting
- Datum en tijd van het event
- Naam en e-mailadres van de organisator
- iCalendar-`REPLY`-bijlage zodat de agenda-app de event-status automatisch kan bijwerken

## 2. Goedkeurings-verzoek

**Verstuurd naar:** alle ruimte-managers

Wordt getriggerd wanneer er een nieuwe boeking binnenkomt op een ruimte met `autoAccept=false`. De boeking krijgt de status **Voorlopig**.

![Goedkeurings-verzoek-e-mail met event-details en Goedkeuren/Afwijzen-links](../../screenshots/approval-mail.png)

**Bevat:**
- Ruimte-naam
- Event-samenvatting
- Datum en tijd van het event
- Naam en e-mailadres van de organisator
- Link naar het Boekingen-tabblad in het beheerpaneel (of naar Persoonlijke instellingen → Goedkeuringen voor managers die geen admin zijn)

## 3. Boeking afgewezen

**Verstuurd naar:** organisator

Wordt getriggerd wanneer een manager een openstaande boeking afwijst.

**Bevat:**
- Ruimte-naam, event-samenvatting, datum en tijd
- Reden voor de afwijzing (als de manager er een heeft opgegeven)
- iCalendar-`REPLY`-bijlage

## 4. Permissie geweigerd

**Verstuurd naar:** organisator

Wordt getriggerd wanneer een boeking **automatisch** wordt afgewezen omdat de gebruiker geen permissie heeft om de ruimte te boeken.

Behalve het versturen van de e-mail doet RoomVox ook:
- Verwijdert de ruimte uit het event van de organisator
- Wist het veld `LOCATION`

Zo toont het agenda-event de ruimte niet langer als onderdeel van de vergadering.

**Bevat:**
- Ruimte-naam, event-samenvatting, datum en tijd
- Uitleg dat de gebruiker geen permissie heeft

## 5. Planning-conflict

**Verstuurd naar:** organisator

Wordt getriggerd wanneer een boeking automatisch wordt afgewezen vanwege een tijd-overlap met een bestaande boeking. Conflict-checking expandeert sinds v1.1.0 terugkerende events — het boeken van de tweede instantie van een wekelijkse serie triggert nu terecht een conflict-e-mail.

**Bevat:**
- Ruimte-naam, event-samenvatting, gevraagde datum en tijd
- Conflict-informatie

## 6. Boekings-horizon overschreden

**Verstuurd naar:** organisator

Wordt getriggerd wanneer een boeking voorbij de `Maximale boekings-horizon` van de ruimte valt. Terugkerende events met een laatste voorkomende keer ver in de toekomst (of zonder `UNTIL` / `COUNT`) vallen hier ook onder.

**Bevat:**
- Ruimte-naam, event-samenvatting, gevraagde datum en tijd
- De exacte horizon (bijvoorbeeld `60 dagen`)
- De vroegste datum die niet langer boekbaar is (`vandaag + N dagen`), zodat de organisator kan verplaatsen zonder te gokken

## 7. Buiten beschikbaarheids-uren

**Verstuurd naar:** organisator

Wordt getriggerd wanneer een boeking buiten de beschikbaarheids-regels van de ruimte valt (bijvoorbeeld weekdagen 09:00–17:00).

**Bevat:**
- Ruimte-naam, event-samenvatting, gevraagde datum en tijd
- Een samenvatting van de beschikbaarheids-regels van de ruimte (`ma, di, wo, do, vr 09:00–17:00`)

## 8. Ruimte-sync bezig

**Verstuurd naar:** organisator

Een **tijdelijke fout**: wordt getriggerd wanneer een boeking binnenkomt terwijl de initiële Exchange-sync van een ruimte nog loopt.

**Bevat:**
- Uitleg dat de ruimte tijdelijk niet beschikbaar is tijdens het synchroniseren
- Suggestie om het over een paar minuten opnieuw te proberen

Dit voorkomt dubbele boekingen tijdens de initiële Exchange-import. Zie [Exchange-integratie](../architecture/exchange-integration.md).

## 9. Boeking geannuleerd

**Verstuurd naar:** organisator en alle ruimte-managers

Wordt getriggerd wanneer de organisator zijn eigen boeking annuleert door een iTIP-`CANCEL` te versturen vanuit zijn agenda-app.

**Bevat:**
- Ruimte-naam, event-samenvatting, datum en tijd
- Annulerings-informatie
- iCalendar-`CANCEL`-bijlage

## 10. Boeking geannuleerd door manager (v1.1.0+)

**Verstuurd naar:** organisator (booker)

Wordt getriggerd wanneer een admin of manager een **al geaccepteerde** boeking annuleert via de actie **Boeking annuleren** in het Boekingen-tabblad van het beheerpaneel of in de modal per boeking.

Dit staat **los** van #9 hierboven — de booker heeft niets in gang gezet; een manager heeft de ruimte ingetrokken. RoomVox doet daarnaast:
- Verwijdert de ruimte-deelnemer uit het eigen agenda-event van de booker
- Wist het veld `LOCATION`

Zo komt het tijdvak direct weer vrij in de Room Finder en wordt de booker niet verrast door een agenda-regel die naar een onbeschikbare ruimte wijst.

**Bevat:**
- Ruimte-naam, event-samenvatting, datum en tijd
- Uitleg dat de boeking is geannuleerd door een ruimte-manager en dat de ruimte is vrijgegeven

## iCalendar-bijlagen

Notificatie-e-mails bevatten waar van toepassing iCalendar-bijlagen (`.ics`):

- **REPLY**-bijlagen voor accepteer-/afwijs-responses
- **CANCEL**-bijlagen voor annulerings-berichten

Agenda-apps werken de event-status automatisch bij wanneer ze deze bijlagen lezen.

## Afzenderadres van e-mails

Het `From`-adres op notificatie-e-mails hangt af van de configuratie van de ruimte:

| Configuratie | From-adres |
|---|---|
| Ruimte heeft een eigen extern e-mailadres (`boardroom@company.com`) | E-mailadres van de ruimte |
| Ruimte heeft SMTP per ruimte geconfigureerd | SMTP-gebruikersnaam (envelope-sender), e-mailadres van de ruimte als Reply-To |
| Ruimte gebruikt automatisch gegenereerd `<id>@roomvox.local` | Nextcloud-systeem-afzender |
| Geen e-mailadres voor de ruimte geconfigureerd | Nextcloud-systeem-afzender |

Zie [E-mail-configuratie](../admin/email-configuration.md) voor de details.

## Wanneer notificaties worden verstuurd (samenvatting)

| Gebeurtenis | Organisator krijgt | Managers krijgen |
|---|---|---|
| Boeking automatisch geaccepteerd | Bevestiging | — |
| Boeking wacht op goedkeuring | — | Goedkeurings-verzoek |
| Manager keurt goed | Bevestiging | — |
| Manager wijst af | Afwijzing | — |
| Permissie geweigerd (auto-afwijzing) | Permissie geweigerd | — |
| Planning-conflict (auto-afwijzing) | Conflict | — |
| Boekings-horizon overschreden (auto-afwijzing) | Horizon overschreden (met N dagen + afkap-datum) | — |
| Buiten beschikbaarheids-uren (auto-afwijzing) | Beschikbaarheid (met samenvatting van de regels) | — |
| Ruimte-sync bezig (tijdelijke fout) | Sync-bezig | — |
| Organisator annuleert eigen boeking | Annulering | Annulering |
| Manager annuleert geaccepteerde boeking | Annulering-door-manager | — |

## Troubleshooting

Zie [Troubleshooting voor beheerders → E-mail-problemen](../admin/troubleshooting.md#e-mail-problemen) voor de diagnose-stappen.

## Zie ook

- [E-mail-configuratie](../admin/email-configuration.md) — SMTP-opzet, SMTP per ruimte
- [Goedkeurings-workflow](approval-workflow.md) — hoe goedkeurings-verzoeken gerouteerd worden
- [Notificaties (gebruikers-handleiding)](../user/notifications.md) — dezelfde inhoud vanuit gebruikers-perspectief
