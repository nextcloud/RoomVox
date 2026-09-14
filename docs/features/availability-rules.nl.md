# Beschikbaarheids-regels

Elke ruimte kan worden beperkt tot **specifieke dagen en tijdvensters** (bijvoorbeeld weekdagen 08:00–18:00) en tot een **maximale boekings-horizon** (bijvoorbeeld maximaal 90 dagen vooruit). Boekingen buiten deze beperkingen worden **automatisch afgewezen** met een duidelijke uitleg per e-mail.

## Beschikbaarheids-regels (dag / tijd)

Configureer dit in de ruimte-editor onder **Boekings-uren beperken**:

1. Zet de schakelaar aan
2. Voeg een of meer regels toe:
   - **Dagen** — kies welke dagen van de week (ma–zo)
   - **Van / Tot** — tijdvenster voor die dagen
3. Gebruik presets voor veelvoorkomende patronen

### Presets

| Preset | Dagen | Venster |
|---|---|---|
| Weekdagen 08–18 | ma–vr | 08:00–18:00 |
| Weekdagen 09–17 | ma–vr | 09:00–17:00 |

Je kunt ook eigen regels bouwen — bijvoorbeeld aparte regels voor weekdagen (08–18) en zaterdagochtend (09–13).

### Weekenden tonen/verbergen

App-breed kun je instellen of de boekings-agenda weekenden **toont** — maar dat is alleen een UI-instelling. Wil je weekend-boekingen **voorkomen**, gebruik dan beschikbaarheids-regels met alleen weekdagen. De twee instellingen staan los van elkaar.

## Boekings-horizon

Beperk hoe ver vooruit een ruimte geboekt kan worden:

- **0** (standaard) — geen limiet
- **N** dagen — boekingen voorbij `today + N` worden afgewezen

Handig voor veelgevraagde ruimtes om speculatieve reserveringen maanden vooruit te voorkomen.

## Hoe boekingen worden beoordeeld

Wanneer een boeking binnenkomt, checkt RoomVox (in volgorde):

1. **Permissie** — gebruiker heeft minstens de Booker-rol op de ruimte
2. **Beschikbaarheids-regels** — elke voorkomende keer valt binnen de toegestane dagen/tijden
3. **Boekings-horizon** — elke voorkomende keer valt binnen `today + horizon` dagen
4. **Conflict-detectie** — geen overlappende geaccepteerde/voorlopige boekingen

Een fout in een van de stappen leidt tot een **AFGEWEZEN**-respons met een e-mail die de reden noemt.

## Terugkerende events

Zowel de beschikbaarheids-regels als de boekings-horizon gelden voor **elke voorkomende keer** van een terugkerend event:

- Een wekelijkse vergadering faalt als **een willekeurige** week buiten de beschikbaarheids-regels valt
- Een wekelijkse vergadering faalt als **een willekeurige** voorkomende keer voorbij de boekings-horizon ligt
- **Oneindige terugkerende events** (geen `UNTIL` / `COUNT`) worden **altijd afgewezen** wanneer er een boekings-horizon is ingesteld — er is geen manier om te verifiëren of ze er allemaal in passen

De conflict-detectie-laag expandeert sinds v1.1.0 ook terugkerende events correct — het boeken van de tweede instantie van een wekelijkse serie triggert nu terecht een conflict-mail. Vóór v1.1.0 werd alleen het master-event gecheckt, waardoor conflicten in latere voorkomende keren gemist werden.

## E-mail-terugkoppeling aan de organisator

Wanneer een boeking automatisch wordt afgewezen vanwege beschikbaarheid of horizon, vertelt de e-mail de organisator **waarom**, zodat hij kan verplaatsen zonder te gokken:

### Buiten beschikbaarheids-uren

> De ruimte is alleen beschikbaar op **ma, di, wo, do, vr 09:00–17:00**.

### Boekings-horizon overschreden

> Deze ruimte kan maximaal **60 dagen** vooruit geboekt worden.
> De vroegste datum die niet langer boekbaar is: **2026-09-15**.

### Planning-conflict

> Er bestaat al een andere boeking van **14:00 tot 15:30** op **2026-06-10**.

Zie [E-mail-notificaties](email-notifications.md) voor de volledige lijst.

## Toepassingen

### Alleen kantooruren

Beperk de bestuurskamer tot weekdagen 09:00–17:00 om boekingen buiten kantooruren te voorkomen die sleutel-/verlichtings-toegang zouden vereisen.

### Gebouw met twee diensten

Twee aparte regels — weekdagen 08:00–17:00, zaterdag 09:00–13:00 — om weekend-ochtendboekingen toe te staan zonder de zondag open te zetten.

### Kwartaal-planning-beperking

Zet de boekings-horizon op 90 dagen voor veelgevraagde trainingsruimtes, zodat de agenda niet nu al volgeboekt raakt voor volgend jaar.

### Hek tegen oneindige herhalingen

Combineer een horizon van 365 dagen met **geen** oneindige herhalingen — gebruikers die een wekelijkse vergadering "voor onbepaalde tijd" proberen te boeken, krijgen een duidelijke afwijzing en kunnen opnieuw boeken met een `UNTIL`-clausule van 1 jaar.

## Architectuur-notities

- Beschikbaarheid wordt gepubliceerd als een CalDAV-`VAVAILABILITY`-object op de agenda van elke ruimte, zodat clients die dit ondersteunen het beschikbare venster direct in hun UI kunnen tonen
- Regels worden per ruimte opgeslagen in IAppConfig (`room/{roomId}` → `availabilityRules`)
- De boekings-horizon wordt opgeslagen als `maxBookingHorizon` (geheel getal dagen, 0 = geen limiet)
- De evaluatie gebeurt server-side in de [CalDAV-scheduling-plugin](../architecture/caldav-scheduling.md) — geen client-side handhaving, geen manier om eromheen te komen

## Zie ook

- [Ruimte-beheer](../admin/room-management.md#boekings-gedrag) — waar je de regels configureert
- [E-mail-notificaties](email-notifications.md) — de afwijzings-e-mails
- [CalDAV-scheduling](../architecture/caldav-scheduling.md) — hoe de plugin boekingen beoordeelt
- [Best practices](../admin/best-practices.md#gebruik-beschikbaarheids-regels-in-plaats-van-handmatige-goedkeuring) — wanneer gebruik je regels en wanneer manager-goedkeuring
