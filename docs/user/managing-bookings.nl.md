# Boekingen beheren

Deze handleiding behandelt hoe je boekingen in RoomVox bekijkt, goedkeurt, afwijst, verplaatst en annuleert.

## Boekings-overzicht

Het tabblad **Boekingen** toont alle boekingen over alle ruimtes heen. Het is op twee plekken beschikbaar:

- **Beheerders**: Instellingen → Beheer → RoomVox → Boekingen
- **Managers**: Instellingen → Persoonlijk → RoomVox → Boekingen (derde tabblad, verschijnt alleen als je minstens één ruimte beheert). Beperkt tot de ruimtes die je beheert, zodat je geen boekingen ziet van ruimtes buiten je verantwoordelijkheid.

Beide weergaven delen dezelfde component — stats-kaarten, filters, lijst/agenda-schakelaar, drag-and-drop verplaatsen tussen ruimtes, en de flow voor boeking annuleren.

### Terugkerende events

Terugkerende boekingen (bijvoorbeeld wekelijkse vergaderingen) worden als individuele voorkomende keren getoond in het overzicht. Elke voorkomende keer kan onafhankelijk beheerd worden — je kunt specifieke datums binnen een serie goedkeuren, afwijzen of annuleren.

Conflict-checking respecteert RRULE: dezelfde ruimte boeken op de tweede (of een latere) voorkomende keer van een bestaande wekelijkse serie wordt nu correct als conflict gemarkeerd, niet alleen de eerste keer.

### Boekingen filteren

Gebruik de filters bovenaan om de boekingslijst te verfijnen:

- **Ruimte** — selecteer een specifieke ruimte of bekijk alle ruimtes
- **Status** — filter op Alle, In afwachting, Geaccepteerd of Afgewezen
- **Datumbereik** — stel een start- en einddatum in (terugkerende events worden binnen dit bereik uitgeklapt)

![Boekings-overzicht — lijst van alle boekingen met status en filters](../../screenshots/bookings-overview-list.png)

### Boekings-informatie

Elke boeking toont:

| Veld | Omschrijving |
|-------|-------------|
| Event | De event-titel/samenvatting |
| Ruimte | Welke ruimte geboekt is |
| Locatie | Adres van de ruimte |
| Wanneer | Start- en einddatum/-tijd |
| Organisator | Wie de boeking heeft aangemaakt |
| Status | Geaccepteerd, Voorlopig (in afwachting) of Afgewezen |

## Boekingen goedkeuren

Wanneer een ruimte auto-accept uitgeschakeld heeft, komen boekingen binnen met de status **Voorlopig** (in afwachting) en vereisen ze manager-goedkeuring.

### Hoe keur je goed

1. Ga naar het tabblad **Boekingen**
2. Filter op **Status: In afwachting** om boekingen te zien die op goedkeuring wachten
3. Klik op de knop **Goedkeuren** (vinkje) bij de boeking
4. De boekings-status verandert naar **Geaccepteerd**
5. De organisator ontvangt een bevestigings-e-mail

### Hoe wijs je af

1. Ga naar het tabblad **Boekingen**
2. Zoek de boeking die in afwachting is
3. Klik op de knop **Afwijzen** (X) bij de boeking
4. De boekings-status verandert naar **Afgewezen**
5. De organisator ontvangt een afwijzings-notificatie-e-mail

## Boekingen aanmaken

Managers kunnen boekingen direct vanuit het admin-paneel aanmaken:

1. Navigeer naar de boekingslijst van de ruimte
2. Klik op **Boeking aanmaken**
3. Vul in:
   - **Samenvatting** — event-titel (verplicht)
   - **Start** — startdatum en -tijd (verplicht)
   - **Einde** — einddatum en -tijd (verplicht)
   - **Omschrijving** — optionele details
4. Klik op **Opslaan**

De boeking wordt aangemaakt met de huidige gebruiker als organisator. Conflict-checking geldt.

## Boekingen verplaatsen

Boekingen kunnen naar een ander tijdstip verplaatst worden of naar een andere ruimte.

### De tijd wijzigen

1. Zoek de boeking in het tabblad Boekingen
2. Klik op **Bewerken**
3. Pas de starttijd en/of eindtijd aan
4. Klik op **Opslaan**

Conflict-checking wordt uitgevoerd tegen het nieuwe tijdstip (met uitsluiting van de huidige boeking).

### Naar een andere ruimte verplaatsen

Er zijn twee manieren om een boeking naar een andere ruimte te verplaatsen:

**Via de bewerk-dialoog (lijstweergave):**

1. Zoek de boeking in het tabblad Boekingen
2. Klik op **Bewerken**
3. Selecteer een andere ruimte
4. Klik op **Opslaan**

**Via drag-and-drop (agenda-weergave):**

1. Schakel over naar de agenda-weergave (icoon rechtsboven in het tabblad Boekingen)
2. Sleep een boeking naar een andere ruimte-kolom of een ander tijdslot
3. De conflict-check draait automatisch en de verplaatsing wordt geweigerd als het doel-slot bezet is

In beide gevallen wordt de boeking verwijderd uit de oorspronkelijke ruimte en aangemaakt in de nieuwe ruimte met een nieuwe UID. De booker krijgt geen nieuwe notificatie — die houdt hetzelfde event in zijn eigen agenda; alleen de ruimte-deelnemer verandert.

## Boekingen annuleren

### Wie kan annuleren

- **De organisator** — de gebruiker die de boeking heeft aangemaakt
- **Ruimte-managers** — gebruikers met de Manager-rol voor de ruimte
- **Nextcloud-beheerders** — hebben altijd volledige toegang

### Hoe annuleer je

1. Zoek de boeking in het tabblad Boekingen
2. Klik op de knop **Boeking annuleren** (prullenbak-icoon)
3. Bevestig in de dialoog (Boeking behouden / Boeking annuleren)
4. De boeking wordt uit de ruimte-agenda verwijderd
5. De booker krijgt een e-mail dat de boeking door een ruimte-manager is geannuleerd
6. De ruimte wordt ook verwijderd uit het eigen agenda-event van de booker (LOCATION wordt gewist en de ruimte-deelnemer wordt verwijderd), zodat het slot vrijkomt in de Room Finder

### Annuleren vanuit agenda-apps

Gebruikers kunnen boekingen ook annuleren door:

1. Het event te openen in hun agenda-app
2. De ruimte-resource uit het event te verwijderen
3. Het event op te slaan

RoomVox ontvangt een CANCEL-iTIP-bericht en verwijdert de boeking uit de ruimte-agenda.

## Boekings-statussen

| Status | CalDAV PARTSTAT | Omschrijving |
|--------|----------------|-------------|
| Geaccepteerd | `ACCEPTED` | Ruimte is bevestigd voor dit event |
| In afwachting | `TENTATIVE` | Wacht op manager-goedkeuring |
| Afgewezen | `DECLINED` | Boeking is afgewezen (conflict, permissie, etc.) |
| Geannuleerd | Event verwijderd | Boeking is geannuleerd door organisator of manager |

## Permissie-vereisten

| Actie | Vereiste rol |
|--------|--------------|
| Boekingen bekijken | Manager of Beheerder |
| Goedkeuren/afwijzen | Manager of Beheerder |
| Boeking aanmaken | Booker, Manager of Beheerder |
| Boeking verplaatsen | Organisator, Manager of Beheerder |
| Boeking annuleren | Organisator, Manager of Beheerder |
| Overzicht van alle boekingen bekijken | Elke geauthenticeerde gebruiker (gefilterd op zichtbare ruimtes) |
