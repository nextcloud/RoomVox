# CalDAV-scheduling

> **Let op:** dit is ontwikkelaarsdocumentatie en blijft Engelstalig. Deze pagina geeft een Nederlandse introductie op de werking van de scheduling-plugin; voor de volledige flow-diagrammen, statuscodes en versienotities zie [de Engelse versie](caldav-scheduling.md).

## Waar dit over gaat

RoomVox heeft geen eigen boekingsscherm waarin je een ruimte reserveert. Je voegt een ruimte toe als deelnemer aan een afspraak in je gewone agenda-client, en RoomVox beslist of die boeking doorgaat. De plek waar dat besluit valt is `SchedulingPlugin` — een Sabre DAV-plugin die meeluistert op het CalDAV-verkeer van de server.

Deze pagina is bedoeld voor ontwikkelaars die aan RoomVox werken of die willen begrijpen waarom een boeking geaccepteerd, tentatief of geweigerd wordt. Ben je beheerder en zoek je hoe je permissies of beschikbaarheidsregels instelt, dan hoor je eerder thuis bij de beheerdersdocumentatie.

## Concepten die je vooraf moet kennen

**iTIP** is het protocol waarmee agenda's onderling afspraken uitwisselen: de organisator stuurt een `REQUEST` naar de deelnemers, een deelnemer antwoordt met een `REPLY`, en een afzegging gaat als `CANCEL`. RoomVox behandelt een ruimte als een deelnemer, dus een boeking is niets anders dan een iTIP-bericht waarin de ruimte als `ATTENDEE` staat.

**Room principals zijn virtueel.** Elke ruimte krijgt een verborgen Nextcloud-gebruiker met het prefix `rb_*`. Die gebruiker bezit de agenda van de ruimte, maar kan niet inloggen en verschijnt niet in de gebruikerszoekfunctie.

**PARTSTAT** is de iCalendar-property die de status van een deelnemer draagt: `ACCEPTED`, `TENTATIVE` of `DECLINED`. Voor RoomVox is dat het antwoord van de ruimte op je verzoek.

**Schedule status codes** zijn de numerieke codes die de server terugkoppelt aan de client — `1.2` voor afgeleverd, `3.0` voor een conflict, `3.7` voor een voorwaardelijke weigering en `5.3` voor een tijdelijke storing. Agenda-clients tonen die codes in hun eigen bewoordingen, wat verklaart waarom dezelfde weigering er in Outlook anders uitziet dan in Apple Calendar.

## Waarom er een eigen plugin is

Sabre's standaard scheduling-handler draait op prioriteit 100. Om een iTIP-bericht af te leveren bij een deelnemer roept die `getPrincipalByUri()` aan, en dat vereist een actieve gebruikerssessie — Nextclouds user backend leest namelijk de sessie.

Een ruimte-principal heeft nooit een sessie, want de bijbehorende gebruiker kan niet inloggen. De standaard-handler kan de principal dus niet oplossen, levert niets af, en de boeking gebeurt stilletjes niet.

RoomVox registreert zijn plugin daarom op **prioriteit 99**. Plugins vuren in aflopende prioriteitsvolgorde, dus 99 gaat vóór 100. RoomVox kijkt eerst of het bericht voor een ruimte-principal bestemd is. Zo ja, dan handelt RoomVox het zelf af en geeft `false` terug, waarmee Sabre's eigen aflevering wordt gestopt. Zo nee — het bericht gaat naar een echte gebruiker — dan geeft RoomVox `true` terug en doet Sabre gewoon zijn werk. Die selectieve onderschepping houdt de reikwijdte van RoomVox zo klein mogelijk.

## De REQUEST-flow in het kort

Komt er een boekingsverzoek binnen, dan loopt de plugin een vaste reeks controles af. Elke controle die faalt leidt tot een weigering met een eigen statuscode en een eigen e-mail:

1. **Afzender oplossen** naar een Nextcloud-gebruikers-ID.
2. **Permissiecheck** — mag deze gebruiker deze ruimte boeken? Zo nee: `DECLINE` met status `3.7`.
3. **Beschikbaarheid** — valt de afspraak binnen de regels van de ruimte? Zo nee: `DECLINE` met `3.7`.
4. **Boekingshorizon** — ligt elke herhaling binnen het maximale aantal dagen vooruit? Zo nee: `DECLINE` met `3.7`.
5. **Conflictdetectie** — overlapt de afspraak met een bestaande boeking? Zo ja: `DECLINE` met `3.0`.
6. **PARTSTAT bepalen** — `ACCEPTED` bij een ruimte met auto-accept, anders `TENTATIVE`.
7. **Deelnemer verrijken** — `CUTYPE=ROOM` corrigeren, `LOCATION` en `CN` zetten.
8. **Afleveren** in de agenda van de ruimte.
9. **Status op `1.2`** zetten.
10. **Notificeren** — de organisator bij een acceptatie, de managers bij een tentatieve boeking die goedkeuring nodig heeft.

Een geweigerde boeking om permissieredenen doet nog iets extra's: RoomVox haalt de ruimte als deelnemer uit de agenda van de organisator en leegt het `LOCATION`-veld. Zo blijft er geen afspraak achter die een ruimte toont die je niet mag boeken.

## Terugkerende afspraken

Conflictdetectie kijkt niet alleen naar de hoofdafspraak. De plugin klapt de `RRULE` uit via Sabre's `EventIterator` en controleert élke losse herhaling binnen het zoekvenster. `EXDATE` en `RECURRENCE-ID`-overrides worden daarbij netjes gerespecteerd, zodat het uitsluiten van één instantie werkt zoals je verwacht.

Het annuleren van één enkele herhaling gaat via een optionele `?recurrenceId=`-parameter op het delete-endpoint van een boeking. Er wordt dan een `EXDATE` op de master geschreven in plaats van de hele reeks te verwijderen, en in de agenda van de boeker verschijnt een `RECURRENCE-ID`-override waarin de ruimte op `DECLINED` staat.

## De post-write hook

Niet elke agenda-client stuurt correcte iCalendar-data. Na elke schrijfactie op een `.ics`-bestand corrigeert de plugin de kopie van de organisator:

- **iOS en macOS** sturen ruimtes als `CUTYPE=INDIVIDUAL`. De hook herkent de ruimte aan het `rb_*`-patroon in de principal-URI en zet `CUTYPE=ROOM` terug, zodat de ruimte niet als gewone persoon in de deelnemerslijst staat.
- **De juiste `PARTSTAT`** wordt teruggeschreven. Omdat RoomVox de aflevering zelf deed, heeft Sabre die nooit weggeschreven.
- **eM Client** vult soms alleen het `LOCATION`-veld in zonder de ruimte als `ATTENDEE` toe te voegen. De hook leest `LOCATION`, herkent een bekende ruimtenaam, en voegt de deelnemer alsnog toe — waarna de normale flow bij de volgende opslag zijn werk doet.

Deze correcties zijn onzichtbaar voor de gebruiker. Er is geen instelling voor en er valt niets te doen; ze gebeuren tijdens het scheduling-proces.

## Prestaties

De permissiecheck is O(1) voor rechten die direct op de ruimte staan, en O(g) bij overerving via groepen. Conflictdetectie draait op de CalDAV-tijdvakindexen (`firstoccurence` en `lastoccurence`) en is daarmee O(log n).

Een contra-intuïtief gevolg: een drukbezette ruimte antwoordt vaak *sneller* dan een lege. De tijdvakquery vindt eerder een conflict, waarna de plugin direct `DECLINED` teruggeeft zonder verder te zoeken.

## Verder lezen

De [Engelse versie van deze pagina](caldav-scheduling.md) bevat de volledige flow-diagrammen, de tabel met schedule status codes, de opbouw van de `ORGANIZER`-property en de versiespecifieke wijzigingen per release.

## Zie ook

- [Architectuuroverzicht](overview.nl.md) — systeemcontext
- [Backend-architectuur](backend-architecture.nl.md) — servicelaag en opslag
- [Exchange-integratie](exchange-integration.nl.md) — hoe gesynchroniseerde boekingen hierop ingrijpen
- [E-mailnotificaties](../features/email-notifications.md) — de mails die de plugin verstuurt
- [Beschikbaarheidsregels](../features/availability-rules.md) — semantiek van de regels
- [Goedkeuringsworkflow](../features/approval-workflow.md) — tentatieve boekingen en managers
