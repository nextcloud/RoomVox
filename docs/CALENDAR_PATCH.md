# NC Calendar Patch — Wat en Waarom

RoomVox patcht de Nextcloud Calendar app (v6.2.0) om een visuele room picker toe te voegen. Dit document beschrijft wat de stock versie biedt, wat onze patch verandert, en waarom dat nodig is.

---

## Stock NC Calendar v6.2.0 Resource Picker

De standaard resource picker in Nextcloud Calendar is minimaal:

### ResourceListSearch.vue — zoekbalk + filters
- **NcSelect dropdown**: type-ahead zoeken op kamernaam via DAV PROPFIND
- Je moet de kamernaam al (deels) kennen om te zoeken
- Resultaten tonen naam, capaciteit en adres in een dropdown lijst
- 3 hardcoded checkboxes: Projector, Whiteboard, Wheelchair accessible
- Capaciteitsinvoer en Room Type dropdown
- "Show all rooms" knop die een free/busy timeline modal opent
- Beschikbaarheidscheck per zoekresultaat

### ResourceList.vue — container
- Toont max 3 "suggestions" op basis van capaciteit (via `advancedPrincipalPropertySearch`)
- Suggestions verschijnen automatisch als er geen kamer geselecteerd is
- Toont al toegevoegde kamers als `ResourceListItem` met verwijder-actie

### ResourceListItem.vue — toegevoegde kamer
- Avatar met participation status (accepted/declined/tentative)
- Kamernaam
- Actions menu: capaciteit, type, features, verwijder-knop
- Wordt alleen getoond voor kamers die al aan het event zijn toegevoegd

### Beperkingen van de stock versie
1. **Geen overzicht** — je kunt niet browsen door beschikbare kamers
2. **Naam moet bekend zijn** — zoeken werkt alleen als je weet wat je zoekt
3. **Geen groepering** — geen gebouw/verdieping structuur
4. **Hardcoded filters** — alleen Projector, Whiteboard, Wheelchair
5. **Max 3 suggesties** — bij 38+ kamers is dit onbruikbaar

---

## Onze Patch — Visuele Room Browser

### ResourceList.vue — volledig vervangen (613 regels vs 244 stock)

Wij vervangen de stock ResourceList.vue met een complete room browser:

| Feature | Stock | Onze patch |
|---|---|---|
| Alle kamers zichtbaar | Nee (zoek of max 3 suggesties) | Ja, allemaal tegelijk |
| Groepering per gebouw | Nee | Ja, expand/collapse per groep |
| Beschikbaarheidsstatus | Alleen in zoekresultaten | Per kamer, realtime |
| Tekst zoeken | Op naam via DAV roundtrip | Client-side op naam, gebouw, adres, verdieping |
| Available-only filter | Checkbox in zoekresultaten | Toggle die de hele lijst filtert |
| Min. capaciteit | In zoekformulier | Inline filter |
| Gebouw filter | Nee | Dynamische chips, multi-select |
| Faciliteit filter | 3 hardcoded checkboxes | Dynamische chips op basis van werkelijke room features |
| Compact card design | Nee (alleen naam in dropdown) | Kaart met status, capaciteit, verdieping, add/remove |

### ResourceRoomCard.vue — nieuw component (196 regels)

Bestaat niet in stock. Compacte kaart per kamer:
- Kamernaam (bold)
- Status badge: Available (groen) / Unavailable (rood) / Reserved (blauw)
- Capaciteit (bijv. "120p")
- Verdieping/locatie
- Hover: volledig adres als tooltip
- Add/remove knop (+ / -)
- Visuele states: added (blauwe rand), unavailable (gedimmed)

### ResourceListSearch.vue — niet meer gebruikt

Onze ResourceList.vue vervangt de zoekfunctionaliteit volledig. De stock ResourceListSearch.vue wordt nog wel gedeployed maar niet meer gerenderd.

---

## Hoe de patch werkt

### Bestanden

```
nc-calendar-patch/src/components/Editor/Resources/
  ResourceList.vue          # Vervangt stock volledig
  ResourceRoomCard.vue      # Nieuw component
  ResourceListSearch.vue    # Ongewijzigd (niet meer gebruikt)

nc-calendar-patch/src/models/
  principal.js              # Uitgebreid met room metadata velden
```

### Dataflow

1. Bij mount: laad alle room principals via `principalsStore.getRoomPrincipals`
2. Check beschikbaarheid via `checkResourceAvailability()` (free/busy query)
3. Groepeer per `roomBuildingName`, sorteer per beschikbaarheid + naam
4. Client-side filtering op tekst, gebouw, capaciteit, faciliteiten, beschikbaarheid
5. Add: roep `calendarObjectInstanceStore.addAttendee()` aan met room data
6. Remove: zoek attendee op email, roep `removeAttendee()` aan

### Principal metadata velden (principal.js patch)

De stock `principal.js` mappt alleen basale DAV properties. Onze patch voegt toe:

| Property | DAV namespace | Gebruik |
|---|---|---|
| `roomBuildingName` | `{urn:ietf:params:xml:ns:caldav}room-building-name` | Groepering + filter chips |
| `roomBuildingAddress` | `{urn:ietf:params:xml:ns:caldav}room-building-address` | Tooltip |
| `roomFloor` | `{urn:ietf:params:xml:ns:caldav}room-building-floor` | Sublocation in kaart |

---

## Deploy

```bash
# Bouw en deploy de calendar patch naar een server
./deploy-calendar.sh 3dev    # of 1dev, prod
```

Het deploy script:
1. Cloned NC Calendar v6.2.0 naar `/tmp/nc-calendar-build`
2. Kopieert onze patches over de stock bestanden
3. Bouwt de hele calendar app met webpack
4. Upload alleen de `js/` directory naar de server

### Rollback

```bash
# Restore de backup die bij elke deploy gemaakt wordt
ssh rdekker@SERVER 'sudo rm -rf /var/www/nextcloud/apps/calendar/js && sudo mv /var/www/nextcloud/apps/calendar/js.bak.YYYYMMDD_HHMMSS /var/www/nextcloud/apps/calendar/js'
```

---

## NC33 Compatibiliteit

Bij upgrade naar Nextcloud 33:

1. **Calendar app structuur ongewijzigd** — v6.2.0 wordt ook met NC33 geleverd
2. **`resource_booking_enabled` guard** — NC33 voegt een check toe die de resource picker verbergt als er geen room backend is. Positief voor ons: RoomVox registreert een backend, dus de flag is automatisch true
3. **Full-page event editor** — De sidebar is vervangen door full-page layout. Onze patch CSS moet getest worden
4. **`@nextcloud/vue` v8.x** — Geen breaking changes voor de componenten die wij gebruiken
5. **`X-NC-DISABLE-SCHEDULING`** — Nieuwe property die scheduling overslaat. Onze SchedulingPlugin zou dit moeten respecteren

---

*Laatst bijgewerkt: 2026-02-13*
