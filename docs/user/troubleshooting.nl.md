# Problemen oplossen voor gebruikers

Veelvoorkomende problemen die gebruikers met RoomVox ervaren en hoe je ze oplost. Voor problemen aan de beheerders-kant, zie [Problemen oplossen voor beheerders](../admin/troubleshooting.md).

## Ruimtes verschijnen niet in agenda-apps

### Ruimte niet zichtbaar in Nextcloud Calendar

**Waarschijnlijke oorzaak:** de ruimte is inactief, of je hebt niet de vereiste permissies.

**Probeer:**

1. Vraag je beheerder te verifiëren dat de ruimte **actief** is in het admin-paneel
2. Vraag of je account of groep is toegevoegd met minstens de **Viewer**-rol
3. Ververs de agenda hard: `Ctrl+Shift+R` / `Cmd+Shift+R`

### Ruimte niet zichtbaar in Apple Calendar / Outlook / Thunderbird

**Waarschijnlijke oorzaak:** je CalDAV-account synchroniseert geen resources, of de client heeft nieuwe resources nog niet opgepikt.

**Probeer:**

1. Verifieer dat het CalDAV-account geconfigureerd is en synchroniseert
2. Forceer een volledige resync van het CalDAV-account
3. Sommige clients (met name Apple Calendar op iOS) vereisen een herstart om nieuwe resources op te pikken

## Boeking afgewezen

### "Geen permissie"

Je hebt niet de Booker- of Manager-rol voor deze ruimte.

**Vraag je beheerder** om jou (of je groep) toe te voegen als Booker.

### "Planning-conflict"

Er is al een ander event geboekt op het gevraagde tijdstip. Geannuleerde en afgewezen boekingen tellen niet als conflict.

**Probeer:**

1. Kies een ander tijdslot
2. Check bij terugkerende events of één enkele voorkomende keer botst (de boeking wordt afgewezen wanneer **welke** voorkomende keer dan ook overlapt)

### "Buiten beschikbaarheid"

Het gevraagde tijdstip valt buiten de beschikbaarheids-regels van de ruimte (bijvoorbeeld doordeweeks 09:00–17:00).

**Probeer:**

1. Boek binnen de toegestane dagen en het toegestane tijdvenster
2. Vraag je beheerder of de beperking nog nodig is

### "Voorbij boekings-horizon"

Het event ligt te ver in de toekomst.

**Probeer:**

1. Boek binnen de maximale horizon van de ruimte (bijvoorbeeld max. 90 dagen vooruit)
2. Zorg bij terugkerende events dat de laatste voorkomende keer binnen de horizon valt
3. Oneindige terugkerende events (geen `UNTIL` of `COUNT`) worden altijd afgewezen wanneer een horizon is ingesteld

### Boeking blijft hangen op "Voorlopig" / in afwachting

De ruimte heeft automatisch accepteren uitgeschakeld en nog geen manager heeft de boeking goedgekeurd.

**Een ruimte-manager** moet de boeking goedkeuren. Managers ontvangen e-mail-notificaties over boekingen in afwachting.

## Problemen met agenda-clients

### Apple Calendar (iOS) verstuurt het verkeerde deelnemer-type

iOS verstuurt ruimte-deelnemers met `CUTYPE=INDIVIDUAL` in plaats van `CUTYPE=ROOM`. **RoomVox detecteert en fixt dit automatisch** — geen actie nodig.

### eM Client voegt de ruimte niet toe als deelnemer

eM Client zet soms alleen het `LOCATION`-veld zonder de ruimte als deelnemer toe te voegen. **RoomVox detecteert dit automatisch door de locatie te matchen tegen bekende ruimte-namen** en voegt de juiste CalDAV-deelnemer toe — geen actie nodig.

## Taal-problemen

### Tour of notificaties verschijnen in de verkeerde taal

RoomVox is beschikbaar in het Engels, Nederlands, Duits en Frans. De taal wordt bepaald door je Nextcloud-taalinstelling.

**Probeer:**

1. Check je Nextcloud-taal in **Persoonlijke instellingen → Taal**
2. Ververs hard: `Ctrl+Shift+R` / `Cmd+Shift+R`

Wil je RoomVox in een andere taal, neem dan contact op met je beheerder — vertalingen kunnen via Transifex bijgedragen worden.

## Zie ook

- [FAQ](faq.md) — veelgestelde vragen van gebruikers
- [Ruimtes boeken](booking-rooms.md) — hoe je boekt vanuit verschillende agenda-apps
- [Persoonlijke instellingen](personal-settings.md) — de tabbladen Mijn ruimtes, Goedkeuringen, Boekingen
- [Problemen oplossen voor beheerders](../admin/troubleshooting.md) — als je beheerder bent
