# Goedkeurings-workflow

Elke ruimte in RoomVox heeft een **auto-accept**-instelling. Staat auto-accept uit, dan komen boekingen binnen als **Voorlopig** en vereisen ze manager-goedkeuring voordat ze bevestigd worden.

![Goedkeuringen-tab — openstaande boekingen die wachten op een manager-beslissing](../../screenshots/approval-overview.png)

## Wanneer gebruik je auto-accept en wanneer goedkeuring

| Modus | Het meest geschikt voor |
|---|---|
| **Auto-accept aan** | Omgevingen met veel onderling vertrouwen, ruim voldoende ruimte-capaciteit, snelheid telt |
| **Auto-accept uit** (goedkeuring vereist) | Veelgevraagde ruimtes, formele locaties (bestuurskamer, collegezaal), menselijk oordeel nodig bij elke boeking |

Je stelt de standaard app-breed in via **Instellingen → Standaard auto-accept**, en overschrijft hem per ruimte in de ruimte-editor.

## De goedkeurings-flow

```
User adds room to event
    │
    ├─ RoomVox checks: permission? available? within rules?
    │
    │  If any check fails → DECLINED (auto, with email explanation)
    │
    │  Otherwise:
    │
    ├─ autoAccept = true  →  ACCEPTED
    │                         └─ Confirmation email to organizer
    │
    └─ autoAccept = false →  TENTATIVE
                              ├─ Approval-request email to all managers
                              ├─ Booking shown in admin Bookings tab (status Pending)
                              └─ Shown in Personal Settings → Approvals tab (for managers)
                                  │
                                  ├─ Manager clicks Approve → ACCEPTED
                                  │   └─ Confirmation email to organizer
                                  │
                                  └─ Manager clicks Decline → DECLINED
                                      └─ Decline email to organizer
```

## Wat managers zien

Managers kunnen op twee plekken handelen op openstaande boekingen:

### Instellingen → Persoonlijk → RoomVox → Goedkeuringen

Toont openstaande boekingen voor de ruimtes die de gebruiker beheert — met event-titel, ruimte, organisator, gevraagd tijdstip en Goedkeuren/Afwijzen-knoppen. Dit tabblad verschijnt alleen voor gebruikers met de Manager-rol op minstens één ruimte.

### Instellingen → Beheer → RoomVox → Boekingen (admins)

Het volledige Boekingen-tabblad. Filter op **Status: In afwachting** om alleen boekingen te zien die op goedkeuring wachten.

Managers ontvangen een **e-mail** wanneer er een nieuwe openstaande boeking binnenkomt, zodat ze deze tabbladen niet open hoeven te houden.

## Wat organisatoren krijgen

Wanneer een boeking op Voorlopig wordt gezet, toont de agenda-app de ruimte als Voorlopig (meestal als een gestreepte/gedimde regel). De organisator:

- Ziet de ruimte als Voorlopig in zijn agenda-app
- Ontvangt **geen** directe bevestigings-e-mail (geen "Bevestigd" totdat de manager goedkeurt)
- Ontvangt een bevestigings-e-mail zodra de manager goedkeurt
- Ontvangt een afwijzings-e-mail als de manager afwijst, met de reden (als de manager er een heeft opgegeven)

## Door een manager geannuleerde boekingen

Een manager kan ook een **al geaccepteerde** boeking annuleren via de knop **Boeking annuleren** in het Boekingen-tabblad. Sinds v1.1.0 doet deze flow:

1. Verwijdert de boeking uit de ruimte-agenda
2. Verwijdert de ruimte-deelnemer uit het eigen event van de booker
3. Wist het veld `LOCATION` op het event van de booker
4. Stuurt een "Boeking geannuleerd door manager"-e-mail naar de booker met de uitleg dat de boeking is ingetrokken

Dit staat los van een gebruiker die zijn eigen boeking annuleert — zie [Boekingen beheren](../user/managing-bookings.md#boekingen-annuleren).

## Randgevallen

### Terugkerende events

De goedkeuring geldt **per serie**, niet per voorkomende keer. Een terugkerende boeking goedkeuren bevestigt de hele serie. Wil je individuele voorkomende keren anders behandelen, dan moet de manager achteraf een enkele instantie annuleren — zie [FAQ](../user/faq.md#kan-ik-een-enkele-voorkomende-keer-van-een-terugkerende-boeking-annuleren).

### Via de API aangemaakte boekingen (v1.1.1+)

Boekingen die via REST-API-endpoints worden aangemaakt (zowel intern als via Public API v1) op ruimtes met `autoAccept=false` triggeren nu correct de manager-goedkeurings-e-mails. Eerdere versies schreven rechtstreeks naar de ruimte-agenda zonder de Sabre-scheduling-plugin te doorlopen, waardoor de notificatie-hook werd overgeslagen. Dit is gefixt in v1.1.1 ([#14](https://github.com/nextcloud/RoomVox/issues/14)).

### Boeking aangemaakt door een manager

Wanneer een manager een boeking rechtstreeks vanuit het beheerpaneel aanmaakt (knop "Boeking aanmaken"), wordt de boeking automatisch bevestigd als de manager boekings-permissie heeft — geen aparte goedkeurings-stap. De aangemaakte boeking doorloopt wel dezelfde conflict-check.

### Ruimte-sync bezig

Wanneer een ruimte voor het eerst aan Exchange wordt gekoppeld, draait RoomVox een initiële volledige sync (`-30d` tot `+365d`). Tijdens deze sync worden **alle nieuwe boekingen tijdelijk afgewezen** met schedule-status `5.3` en krijgt de organisator een "Ruimte-sync bezig"-e-mail met het verzoek het opnieuw te proberen. Dit voorkomt dubbele boekingen zolang RoomVox nog niet alle Exchange-events heeft gezien. Zie [Exchange-integratie](../architecture/exchange-integration.md).

## Vereiste permissies om goed te keuren

| Actie | Vereiste rol |
|---|---|
| Openstaande boekingen goedkeuren / afwijzen | Manager (op de specifieke ruimte) of Nextcloud-admin |
| Een geaccepteerde boeking annuleren | Manager, organisator of Nextcloud-admin |
| Een boeking bewerken | Manager, organisator of Nextcloud-admin |

## Zie ook

- [E-mail-notificaties](email-notifications.md) — alle e-mail-types en triggers
- [Permissies](../admin/permissions.md) — het drie-rollen-systeem
- [Boekingen beheren](../user/managing-bookings.md) — goedkeuring en annulering vanuit gebruikers-perspectief
- [Persoonlijke instellingen](../user/personal-settings.md) — de tabbladen Goedkeuringen en Boekingen
