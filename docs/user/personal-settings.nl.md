# Persoonlijke instellingen

Bekijk je RoomVox-gerelateerde informatie via **Instellingen → Persoonlijk → RoomVox**. De pagina heeft maximaal drie tabbladen, afhankelijk van je rol.

## Persoonlijke instellingen openen

1. Klik op je **profielfoto** of **gebruikersnaam** rechtsboven
2. Selecteer **Persoonlijke instellingen**
3. Scroll omlaag in de linker-zijbalk en klik op **RoomVox**

## Tabbladen

### Mijn ruimtes

Toont alle ruimtes waar je minstens Viewer-permissie voor hebt.

| Kolom | Beschrijving |
|---|---|
| Naam | De weergavenaam van de ruimte |
| Type | Vergaderruimte, studio, collegezaal, etc. |
| Capaciteit | Maximaal aantal personen |
| Locatie | Gebouw, adres |
| Rol | Je effectieve rol (Viewer / Booker / Manager / Admin) |
| Verantwoordelijke contactpersoon | Vrije-tekst-contactinfo, ingesteld door de beheerder |

**Waarom dit tabblad belangrijk is voor Viewers**: ook als je een ruimte niet kunt boeken, vertelt de verantwoordelijke contactpersoon je bij wie je moet zijn. Handig wanneer je ontdekt dat een vergaderruimte bestaat maar niet direct voor jou beschikbaar is.

### Goedkeuringen (alleen managers)

Toont boekingen in afwachting (status **Voorlopig**) voor ruimtes die je beheert. Van hieruit kun je:

- De event-titel, ruimte, organisator en het gevraagde tijdstip zien
- Klikken op **Goedkeuren** om de boeking te bevestigen — de organisator krijgt een bevestigings-e-mail
- Klikken op **Afwijzen** om te weigeren — de organisator krijgt een afwijzings-e-mail

Je ontvangt ook een e-mail wanneer er een nieuwe boeking in afwachting binnenkomt, dus je hoeft dit tabblad niet open te houden.

### Boekingen (alleen managers, v1.1.0+)

Het derde tabblad geeft managers hetzelfde boekings-overzicht dat beheerders zien onder **Instellingen → Beheer → RoomVox → Boekingen**, maar **beperkt tot de ruimtes die je beheert**.

De component wordt gedeeld met de beheerders-weergave:

- **Stats-kaarten** — totaal aantal boekingen, in afwachting, geaccepteerd, afgewezen
- **Filters** — op ruimte, status en datumbereik
- **Lijst / Agenda-schakelaar** — wissel tussen lijst-weergave en FullCalendar
- **Drag-and-drop** in agenda-weergave — verplaats een boeking tussen ruimtes (onder voorbehoud van de conflict-check)
- **Boeking annuleren** — dezelfde flow als het beheerders-tabblad Boekingen

Dit tabblad verschijnt alleen als je minstens één ruimte beheert.

## Waar deze pagina niet voor is

- **Je eigen boekingen bekijken** — die staan in je **Nextcloud Calendar**. Ruimtes zijn CalDAV-resources, dus je boekingen verschijnen in je agenda als elk ander event met de ruimte als deelnemer.
- **Abonneren op de agenda van een ruimte in een externe app** — dat gaat via een feed-URL per ruimte, niet vanaf deze pagina. Een beheerder of ruimte-manager schakelt de **externe agenda-feed** in in de ruimte-editor en deelt de URL; die voeg je vervolgens toe als alleen-lezen-abonnement in je agenda-app. Zie [Kan ik me abonneren op de agenda van een ruimte?](faq.md#kan-ik-me-abonneren-op-de-agenda-van-een-ruimte-in-mijn-externe-agenda-app) en de [Public API](../features/public-api.md)-referentie.

## Zie ook

- [Boekingen beheren](managing-bookings.md) — hoe je goedkeurt, afwijst, verplaatst
- [Ruimtes boeken](booking-rooms.md) — hoe je boekt vanuit agenda-apps
- [Overzicht](overview.md) — wat RoomVox is
