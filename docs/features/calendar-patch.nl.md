# Calendar-patch — visuele ruimte-browser

RoomVox bevat een optionele patch voor de Nextcloud Calendar-app (v6.2.0) die een visuele ruimte-browser toevoegt. Deze vervangt de standaard minimale resource-picker door een volwaardige ruimte-browse-ervaring.

## Wat de patch doet

### Standaard resource-picker van Nextcloud Calendar

De standaard resource-picker in Nextcloud Calendar is minimaal:

- **Alleen zoeken** — je moet de ruimte-naam kennen om hem te vinden
- **Maximaal 3 suggesties** — toont slechts 3 ruimtes op basis van capaciteit
- **Niet bladeren** — je kunt niet alle beschikbare ruimtes in één keer zien
- **Geen groepering** — geen gebouw-/verdieping-structuur
- **Vaste filters** — alleen Beamer, Whiteboard, Rolstoeltoegankelijk

### Gepatchte ruimte-browser

De patch vervangt de resource-picker door een volledige ruimte-browser:

![Ruimte-browser — alle ruimtes gegroepeerd per gebouw met filters](../../screenshots/bookroom-filter.png)

| Functie | Standaard | Gepatcht |
|---------|----------|---------|
| Alle ruimtes zichtbaar | Nee (zoeken of max. 3 suggesties) | Ja, alle ruimtes in één keer |
| Groepering per gebouw | Nee | Ja, per groep uit-/inklapbaar |
| Beschikbaarheids-status | Alleen in zoekresultaten | Per ruimte, real-time |
| Tekst-zoeken | Alleen op naam via DAV-roundtrip | Client-side op naam, gebouw, adres, verdieping |
| Filter "alleen beschikbaar" | Checkbox in het zoekformulier | Schakelaar die de hele lijst filtert |
| Filter min. capaciteit | In het zoekformulier | Inline-filter |
| Gebouw-filter | Nee | Dynamische chips, meerdere selecteerbaar |
| Faciliteit-filter | 3 vaste checkboxen | Dynamische chips op basis van de daadwerkelijke ruimte-kenmerken |
| Ruimte-kaarten | Alleen naam in dropdown | Kaart met status, capaciteit, verdieping, toevoegen/verwijderen |

### Ruimte-kaart-component

![Ruimte geselecteerd en gereserveerd in de browser](../../screenshots/bookroom-selected.png)

Elke ruimte wordt getoond als een compacte kaart met:

- Ruimte-naam (vet)
- Status-badge: Beschikbaar (groen) / Onbeschikbaar (rood) / Gereserveerd (blauw)
- Capaciteit (bijv. "120p")
- Verdieping/locatie
- Volledig adres bij hover (tooltip)
- Toevoegen/verwijderen-knop (+/-)
- Visuele statussen: toegevoegd (blauwe rand), onbeschikbaar (gedimd)

## Installatie

### Vereisten

- Nextcloud Calendar-app v6.2.0 geïnstalleerd
- De map `nc-calendar-patch/` in de RoomVox-repository

### Uitrollen

```bash
# Build and deploy the calendar patch to a server
./deploy-calendar.sh <target>
```

Het deploy-script:
1. Kloont NC Calendar v6.2.0 naar `/tmp/nc-calendar-build`
2. Kopieert de patch-bestanden over de stock-bestanden heen
3. Bouwt de hele calendar-app met webpack
4. Uploadt alleen de map `js/` naar de server

### Terugdraaien

Elke uitrol maakt een backup. Om die terug te zetten:

```bash
# Restore the backup created during deployment
ssh user@SERVER 'sudo rm -rf /var/www/nextcloud/apps/calendar/js && sudo mv /var/www/nextcloud/apps/calendar/js.bak.YYYYMMDD_HHMMSS /var/www/nextcloud/apps/calendar/js'
```

## Gepatchte bestanden

```
nc-calendar-patch/src/components/Editor/Resources/
  ResourceList.vue          # Replaces stock completely (613 lines vs 244 stock)
  ResourceRoomCard.vue      # New component
  ResourceListSearch.vue    # Unchanged (no longer used)

nc-calendar-patch/src/components/Editor/Invitees/
  AttendeeChip.vue          # Adds 'Busy at this time' indicator
  InviteesChipList.vue      # 'Show less' / 'Drop here to make optional'

nc-calendar-patch/src/models/
  principal.js              # Extended with room metadata fields
  resourceProps.js          # Room types + facility labels (translatable)

nc-calendar-patch/src/views/
  EditFull.vue              # Hybrid meeting toggles (In-person / Online (Talk))
```

## Vertalingen

Strings die specifiek zijn voor de RoomVox-patch — `In-person`, `Online (Talk)`, `Reserved`, `Suggested conference rooms`, ruimte-types als `Meeting room`, faciliteit-labels als `Projector`, enzovoort — zitten niet in de upstream-vertaalbundels van Nextcloud Calendar. De patch haalt ze op via de `roomvox`-vertaal-namespace met `$t('roomvox', '…')` (of `t('roomvox', '…')` uit `@nextcloud/l10n` in JS-bestanden), zodat ze de vertalingen uit RoomVox' eigen `l10n/{lang}.{json,js}`-bundels oppikken.

Momenteel meegeleverde talen: Engels (bron), Duits, Nederlands, Frans. Een nieuwe taal toevoegen betekent een `l10n/<lang>.{json,js}` toevoegen aan de RoomVox-app — er zijn geen wijzigingen in de calendar-app nodig.

Strings die al upstream bestaan (`Accepted`, `Declined`, `Cancel`, `Optional`, `Required`, `Available`, enzovoort) blijven de `calendar`-namespace gebruiken, zodat ze synchroon blijven met de reguliere vertalingen van Nextcloud.

## Data-flow

1. Bij mount: laad alle ruimte-principals via `principalsStore.getRoomPrincipals`
2. Check beschikbaarheid via `checkResourceAvailability()` (free/busy-query)
3. Groepeer ruimtes op `roomBuildingName`, sorteer op beschikbaarheid + naam
4. Client-side filteren op tekst, gebouw, capaciteit, faciliteiten, beschikbaarheid
5. Toevoegen: roep `calendarObjectInstanceStore.addAttendee()` aan met de ruimte-data
6. Verwijderen: zoek de deelnemer op e-mailadres, roep `removeAttendee()` aan

## DAV-property-mapping

De patch breidt `principal.js` uit om extra DAV-properties te mappen:

| Property | DAV-namespace | Gebruik |
|----------|---------------|-------|
| `roomBuildingName` | `{urn:ietf:params:xml:ns:caldav}room-building-name` | Groepering per gebouw + filter-chips |
| `roomBuildingAddress` | `{urn:ietf:params:xml:ns:caldav}room-building-address` | Adres-tooltip |
| `roomFloor` | `{http://nextcloud.com/ns}room-building-story` | Weergave van de verdieping op de ruimte-kaart |

Deze properties worden gevuld vanuit de adres- en ruimte-nummer-velden van de ruimte in RoomVox.

## Compatibiliteit met Nextcloud 33

Bij het upgraden naar Nextcloud 33:

1. **Structuur van de calendar-app ongewijzigd** — v6.2.0 wordt ook met NC33 meegeleverd
2. **`resource_booking_enabled`-guard** — NC33 voegt een check toe die de resource-picker verbergt als er geen ruimte-backend geregistreerd is. RoomVox registreert een backend, dus de vlag staat automatisch op true
3. **Full-page event-editor** — NC33 vervangt de zijbalk door een full-page-layout. De CSS van de patch moet getest worden
4. **`@nextcloud/vue` v8.x** — Geen breaking changes voor de componenten die de patch gebruikt
5. **`X-NC-DISABLE-SCHEDULING`** — Nieuwe property die scheduling overslaat. De SchedulingPlugin zou deze moeten respecteren

## Bijwerken na NC-upgrades

Als Nextcloud of de Calendar-app wordt bijgewerkt:

1. Check of de versie van de Calendar-app veranderd is
2. Is die ongewijzigd, rol de patch dan opnieuw uit: `./deploy-calendar.sh <target>`
3. Is de Calendar-app wél bijgewerkt, bekijk dan de wijzigingen in de stock-bestanden en werk de patch dienovereenkomstig bij
4. Test de ruimte-browser altijd na een update
