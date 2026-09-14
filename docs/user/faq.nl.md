# Gebruikers-FAQ

Veelgestelde vragen van RoomVox-gebruikers.

## Boekings-basis

### Hoe boek ik een ruimte?

Voeg de ruimte toe als deelnemer aan een agenda-event in elke agenda-app — Nextcloud Calendar, Apple Calendar, Outlook, Thunderbird of eM Client. De ruimte reageert met Geaccepteerd, Voorlopig of Afgewezen, net als een menselijke deelnemer. Zie [Ruimtes boeken](booking-rooms.md) voor instructies per app.

### Hoe weet ik of een ruimte beschikbaar is?

In **Nextcloud Calendar met de calendar-patch geïnstalleerd** toont de ruimte-browser real-time beschikbaarheid per ruimte met groene/rode/blauwe badges. Zonder de patch toont de standaard-resource-picker beschikbaarheid in de zoekresultaten. In andere agenda-apps reageert de ruimte direct op je uitnodiging met Geaccepteerd/Afgewezen.

### Wat is het verschil tussen Geaccepteerd en Voorlopig?

- **Geaccepteerd** — de ruimte is bevestigd. Geen verdere actie nodig.
- **Voorlopig** — de ruimte vereist manager-goedkeuring. Je krijgt een e-mail zodra het goedgekeurd of afgewezen is.

### Kan ik een ruimte boeken zonder Nextcloud-account?

Nee. RoomVox gebruikt de authenticatie van Nextcloud. Je hebt een Nextcloud-account nodig met minimaal de Booker-rol voor de ruimte. Externe deelnemers kunnen wel voor je event uitgenodigd worden, maar alleen Nextcloud-gebruikers kunnen ruimtes boeken.

## Permissies

### Waarom kan ik een ruimte die ik zie niet boeken?

Je hebt de **Viewer**-rol, maar niet **Booker**. Vraag je beheerder om jou (of je groep) als Booker toe te voegen. Het veld **Verantwoordelijke contactpersoon** in **Persoonlijke instellingen → Mijn ruimtes** vertelt je wie je moet vragen.

### Waarom zie ik sommige ruimtes wel en andere niet?

Ruimtes zijn beperkt via permissies. Je ziet alleen ruimtes waar je minimaal de Viewer-rol hebt — direct of via groepslidmaatschap. Nextcloud-beheerders zien altijd alle ruimtes.

### Kunnen mijn collega's mijn boekingen zien?

Boekingen staan in CalDAV-agenda's die eigendom zijn van de ruimte. Managers van de ruimte kunnen alle boekingen zien; andere gebruikers zien doorgaans alleen dát de ruimte bezet is op een bepaald moment (vrij/bezet), niet de event-details. Je event in **je eigen** agenda volgt je gebruikelijke agenda-privacy-regels.

## Terugkerende events

### Kan ik een terugkerend event boeken?

Ja. RoomVox ondersteunt terugkerende events, met kanttekeningen:

- **Beschikbaarheids-regels** gelden voor **elke** voorkomende keer
- **Boekings-horizon** wordt gecheckt tegen de **verste** voorkomende keer
- **Oneindige terugkerende events** (geen `UNTIL` / `COUNT`) worden altijd afgewezen wanneer een boekings-horizon is ingesteld
- **Conflict-checking** geldt voor elke voorkomende keer — de tweede instantie van een wekelijkse serie boeken wordt correct als conflict gemarkeerd (gefixt in v1.1.0)

### Kan ik één enkele voorkomende keer van een terugkerende boeking annuleren?

Ja (sinds v1.1.1). Wanneer je een terugkerende boeking annuleert via de admin-UI, kies je tussen **Deze keer annuleren** en **Hele serie annuleren**. Eén keer annuleren schrijft een `EXDATE` op het master-event en markeert de ruimte als `DECLINED` voor die ene instantie in je eigen agenda.

## Notificaties

### Waarom ontvang ik geen e-mails?

Veelvoorkomende oorzaken:

- Je beheerder heeft e-mail-notificaties niet ingeschakeld in de RoomVox-instellingen
- Je Nextcloud-profiel heeft geen e-mailadres — stel er een in bij **Persoonlijke instellingen → E-mail**
- De SMTP van Nextcloud is niet geconfigureerd — vraag je beheerder
- De e-mails staan in je spam-map — check daar

Zie [Notificaties](notifications.md) voor de volledige lijst met notificatie-types.

### Waarom komt de e-mail van `noreply@…`?

Ruimtes zonder echt e-mailadres gebruiken de systeem-afzender van Nextcloud. Je beheerder kan per ruimte een echt e-mailadres instellen (bijvoorbeeld `boardroom@company.com`), dat dan de afzender wordt voor de notificaties van die ruimte.

## Persoonlijke instellingen

### Waarom heb ik geen tabblad "Goedkeuringen" of "Boekingen"?

Beide tabbladen zijn **alleen voor managers**:

- **Goedkeuringen** — verschijnt als je minstens één ruimte beheert. Toont boekingen die op goedkeuring wachten.
- **Boekingen** — verschijnt als je minstens één ruimte beheert. Toont het volledige boekings-overzicht, beperkt tot jouw ruimtes (sinds v1.1.0).

Als je Booker of Viewer bent, zie je alleen het tabblad **Mijn ruimtes**.

### Kan ik me abonneren op de agenda van een ruimte in mijn externe agenda-app?

Ja. Een beheerder of ruimte-manager kan een **externe agenda-feed** voor de ruimte inschakelen (in de ruimte-editor) en de resulterende URL met je delen. Omdat de URL zijn eigen geheim per ruimte bevat, kun je je erop abonneren in Nextcloud Calendar, Outlook, Apple Calendar of Thunderbird zonder Bearer-token. De feed is read-only. Behandel de URL als een geheim — iedereen die hem heeft kan de boekingen van de ruimte zien; als hij lekt, kan een manager hem opnieuw genereren.

Er is ook een Bearer-token-feed op `/api/v1/rooms/{id}/calendar.ics` voor programmatische integraties, maar externe agenda-apps kunnen de vereiste Authorization-header niet meesturen — gebruik daarvoor de feed-URL per ruimte.

## Client-specifiek

### iOS stuurt het verkeerde deelnemer-type — handelt RoomVox dat af?

Ja. iOS / macOS Calendar verstuurt ruimte-deelnemers met `CUTYPE=INDIVIDUAL` in plaats van `CUTYPE=ROOM`. RoomVox **detecteert en corrigeert** dit transparant. Geen actie nodig.

### eM Client zet alleen LOCATION — handelt RoomVox dat af?

Ja. eM Client voegt een ruimte soms alleen toe via `LOCATION` zonder `ATTENDEE`. RoomVox **detecteert de ruimte door de LOCATION te matchen tegen bekende ruimte-namen** en voegt transparant de juiste CalDAV-deelnemer toe.

### Welke agenda-apps werken het beste?

| Client | Opmerkingen |
|---|---|
| Nextcloud Calendar (met patch) | Beste ervaring — visuele ruimte-browser, real-time beschikbaarheid, multi-filter |
| Nextcloud Calendar (standaard) | Werkt volledig, meer basale resource-picker |
| Apple Calendar (macOS, iOS) | Volledige ondersteuning |
| Outlook (CalDAV) | Volledige ondersteuning |
| Thunderbird | Volledige ondersteuning |
| eM Client | Volledige ondersteuning |

Zie [Tips](tips.md) voor client-specifieke trucs.

## Zie ook

- [Overzicht](overview.md)
- [Ruimtes boeken](booking-rooms.md)
- [Notificaties](notifications.md)
- [Persoonlijke instellingen](personal-settings.md)
- [Tips](tips.md)
- [Troubleshooting](troubleshooting.md)
