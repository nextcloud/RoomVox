# Permissies

RoomVox gebruikt zijn eigen rol-gebaseerde permissie-systeem om te bepalen wie ruimtes mag bekijken, boeken en beheren. Dit systeem staat **los van de deel-permissies van Nextcloud Calendar** — een agenda in Nextcloud delen of het delen stoppen heeft geen effect op de ruimte-toegang in RoomVox.

> **Belangrijk:** zonder geconfigureerde permissies kunnen alle ingelogde gebruikers alle ruimtes boeken. Wil je de toegang beperken, dan moet je permissies configureren in RoomVox — de deel-instellingen van de Nextcloud-agenda gelden niet. Zie [Standaard-permissies](#default-permissions) hieronder.

## Rollen

Er zijn drie rollen, die elk de mogelijkheden van de vorige overnemen:

| Rol | Mag bekijken | Mag boeken | Mag beheren |
|------|----------|----------|------------|
| Viewer | Ja | Nee | Nee |
| Booker | Ja | Ja | Nee |
| Manager | Ja | Ja | Ja |

### Viewer

- Kan de ruimte zien in agenda-apps (via de CalDAV-resource-lijst)
- Ziet de ruimte in **Instellingen → Persoonlijk → RoomVox → Mijn ruimtes** met de verantwoordelijke contactpersoon erbij, zodat duidelijk is bij wie ze moeten zijn wanneer ze hem niet zelf kunnen boeken
- Kan geen boekingen aanmaken

### Booker

- Kan de ruimte zien in agenda-apps
- Kan boekingen aanmaken (ruimte aan events toevoegen)
- Kan zijn eigen boekingen annuleren
- Ziet de ruimte (met verantwoordelijke contactpersoon) onder Instellingen → Persoonlijk → RoomVox → Mijn ruimtes

### Manager

- Kan de ruimte zien in agenda-apps
- Kan boekingen aanmaken
- Kan openstaande boekingen goedkeuren of afwijzen
- Kan elke boeking voor de ruimte annuleren (de booker krijgt een e-mail en de ruimte wordt uit zijn agenda-event verwijderd)
- Kan ruimte-instellingen en permissies bewerken
- Ontvangt e-mail-notificaties voor nieuwe openstaande boekingen
- Krijgt een tab **Boekingen** onder Instellingen → Persoonlijk → RoomVox met hetzelfde overzicht dat admins zien, beperkt tot de ruimtes die hij beheert (statistieken, filters, lijst/agenda-schakelaar, drag-and-drop verplaatsen tussen ruimtes)

## Permissie-regels

Permissies kunnen worden toegekend aan individuele gebruikers of aan Nextcloud-groepen.

### Gebruikers-permissies

Ken een rol rechtstreeks toe aan een specifieke Nextcloud-gebruiker:

```
{
  "type": "user",
  "id": "alice"
}
```

### Groeps-permissies

Ken een rol toe aan een hele Nextcloud-groep — alle leden van de groep erven de permissie:

```
{
  "type": "group",
  "id": "developers"
}
```

## Permissies instellen

### Permissies op ruimte-niveau

1. Klik in de ruimte-lijst op het **permissie-icoon** van de ruimte
2. De permissie-editor opent met drie secties: Viewers, Bookers, Managers
3. Zoek gebruikers of groepen om toe te voegen
4. Klik op **Opslaan**

### Permissies op groeps-niveau

Permissies die je op een ruimte-groep instelt, worden overgeërfd door alle ruimtes in die groep.

1. Klik in de sectie met ruimte-groepen op het **permissie-icoon** van de groep
2. Voeg viewers, bookers en managers toe via de zoekvelden
3. Klik op **Permissies opslaan**

![Permissie-editor op groeps-niveau — viewers, bookers en managers toekennen aan een ruimte-groep](../../screenshots/rooms-permissions.png)

### Hoe overerving werkt

De effectieve permissies van een ruimte zijn de **vereniging** van:
- Zijn eigen permissies op ruimte-niveau
- De permissies van de toegewezen ruimte-groep (indien aanwezig)

**Voorbeeld:**

```
Room Group "Building A":
  - bookers: [group: "staff"]

Room "Meeting Room 1" (in Building A):
  - managers: [user: "bob"]
  - bookers: [user: "alice"]

Effective permissions for "Meeting Room 1":
  - managers: [user: "bob"]
  - bookers: [user: "alice", group: "staff"]  ← merged
```

### Overgeërfde permissies bekijken

Wanneer je de permissies bewerkt van een ruimte die bij een groep hoort, toont de permissie-editor beide:

- **Overgeërfde permissies** — van de ruimte-groep, weergegeven als grijze regels met een "inherited"-badge. Deze kun je niet vanuit de ruimte-editor verwijderen; bewerk de groeps-permissies om ze te wijzigen.
- **Ruimte-specifieke permissies** — extra regels die alleen voor deze ruimte gelden. Deze kun je vrij toevoegen en verwijderen.

Zo zie je in één oogopslag wie er toegang heeft tot een ruimte, zonder te wisselen tussen de ruimte- en de groeps-editor.

![Permissie-editor met overgeërfde groeps-permissies naast ruimte-specifieke permissies](../../screenshots/room-inheritedpermissions.png)

## Standaard-permissies

Als er voor een ruimte geen permissies zijn geconfigureerd (en er gelden geen groeps-permissies):

- Kunnen **alle ingelogde gebruikers** de ruimte bekijken en boeken
- Kunnen alleen **Nextcloud-beheerders** hem beheren

Zodra er ook maar één permissie is geconfigureerd, hebben alleen de opgegeven gebruikers/groepen toegang.

## Nextcloud-admin-bypass

Gebruikers in de Nextcloud-groep **admin** hebben altijd volledige toegang tot alle ruimtes, ongeacht de permissie-instellingen. Zij kunnen:

- Alle ruimtes bekijken
- Elke ruimte boeken
- Elke ruimte beheren (goedkeuren/afwijzen, bewerken, verwijderen)

## CalDAV-zichtbaarheid

Permissies bepalen ook welke ruimtes zichtbaar zijn in agenda-apps:

- **Groeps-regels** in permissies worden gebruikt als CalDAV-`group_restrictions`
- Nextcloud Calendar toont ruimtes alleen aan gebruikers die lid zijn van minstens één van de beperkte groepen
- **Gebruikers-regels** worden op boekings-moment afgedwongen door de scheduling-plugin, niet op het niveau van CalDAV-zichtbaarheid

Dit betekent:
- Een gebruiker die individueel als Booker is toegevoegd, moet de ruimte mogelijk op naam zoeken in plaats van er doorheen te bladeren
- Een groep die als Booker is toegevoegd, ziet de ruimte automatisch in de resource-lijst verschijnen

> **Let op:** permissie-wijzigingen worden direct gesynchroniseerd naar de ruimte-cache van Nextcloud. Na het opslaan van permissies wordt de bijgewerkte ruimte-zichtbaarheid actief zodra een gebruiker de Room Finder opent of zijn agenda ververst.

## Permissie-checks in de praktijk

### Ruimtes bekijken in het beheerpaneel

Het beheerpaneel toont ruimtes gefilterd op de effectieve permissies van de gebruiker. Niet-admin-gebruikers zien alleen ruimtes waar ze minstens Viewer-toegang op hebben.

### Boeken via CalDAV

Wanneer een gebruiker een ruimte aan een agenda-event toevoegt:

1. De scheduling-plugin herleidt het e-mailadres/principal van de afzender naar een Nextcloud-gebruikers-ID
2. Hij checkt de `canBook()`-permissie van de gebruiker voor de ruimte
3. Als de gebruiker geen permissie heeft:
   - De boeking wordt afgewezen met status `3.7`
   - De ruimte-deelnemer wordt **verwijderd** uit het event van de organisator
   - Het veld **LOCATION** van het event wordt gewist
   - De organisator ontvangt een **"Boeking niet toegestaan"**-e-mail met de uitleg dat hij geen permissie heeft om de ruimte te boeken

### Boekingen beheren

Het boekings-overzicht in het beheerpaneel toont boekingen van alle ruimtes waarop de gebruiker Manager-toegang heeft. Goedkeuren/afwijzen vereist de Manager-rol.

## Best practices

1. **Gebruik groepen** voor veelvoorkomende toegangspatronen — makkelijker te onderhouden dan individuele gebruikers-permissies
2. **Gebruik ruimte-groepen** voor gebouwen of afdelingen — stel gedeelde permissies één keer in
3. **Wijs minstens één manager toe** per ruimte voor goedkeurings-workflows
4. **Houd Viewer-permissies ruim** — laat gebruikers de beschikbaarheid van een ruimte zien, ook als ze hem niet mogen boeken
5. **Beoordeel permissies periodiek** — verwijder vertrekkende gebruikers en werk groeps-lidmaatschappen bij
