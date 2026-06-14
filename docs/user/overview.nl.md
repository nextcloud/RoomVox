# RoomVox-overzicht (gebruikersgids)

Welkom bij **RoomVox** — boek vergaderruimtes direct vanuit de agenda-app die je al gebruikt.

## Wat is RoomVox?

RoomVox maakt vergaderruimtes beschikbaar als **standaard CalDAV-resources** in elke agenda-app — Nextcloud Calendar, Apple Calendar, Outlook, Thunderbird, eM Client. Er is geen aparte boekings-interface om te leren: je boekt een ruimte op dezelfde manier waarop je een collega uitnodigt voor een vergadering.

Wanneer je een ruimte toevoegt aan een agenda-event, doet RoomVox:

1. Checkt of je **permissie** hebt om de ruimte te boeken
2. Verifieert of de ruimte **beschikbaar** is op het gevraagde tijdstip
3. Past de **beschikbaarheids-regels** van de ruimte toe (toegestane dagen/tijden)
4. Past de **boekings-horizon** toe (max dagen vooruit)
5. Of **accepteert automatisch** de boeking, of markeert hem als **Voorlopig** (in afwachting van manager-goedkeuring)
6. Stuurt **e-mail-notificaties** naar jou en naar de managers van de ruimte

## Hoe een ruimte verschijnt

Een ruimte gedraagt zich als een CalDAV-agenda-resource:

- Het heeft een naam, capaciteit, locatie en faciliteiten (beamer, whiteboard, videoconferencing, enz.)
- Het heeft een e-mailadres — meestal een echte mailbox (`vergaderzaal@bedrijf.nl`) of een interne
- Het antwoordt **Geaccepteerd**, **Voorlopig** of **Afgewezen** op je uitnodiging, net als een menselijke deelnemer

Je beheerder maakt en onderhoudt de lijst van ruimtes. Je ziet alle ruimtes waar je minstens Viewer-permissie voor hebt.

## Boekings-responses

| Status | Betekenis |
|---|---|
| **Geaccepteerd** | De ruimte is bevestigd — geen verdere actie nodig |
| **Voorlopig** | De ruimte vereist manager-goedkeuring — je krijgt bericht wanneer goedgekeurd of afgewezen |
| **Afgewezen** | De boeking is afgewezen — zie de reden in de e-mail |

### Waarom een boeking afgewezen kan worden

- **Planning-conflict** — een andere boeking bestaat op dat tijdstip
- **Geen permissie** — je hebt geen Booker- of Manager-rol voor die ruimte
- **Buiten beschikbaarheid** — het gevraagde tijdstip valt buiten de beschikbare uren van de ruimte
- **Voorbij boekings-horizon** — het event is te ver in de toekomst
- **Ruimte-sync bezig** — Exchange-gekoppelde ruimte synchroniseert nog; probeer kort opnieuw

Je ontvangt in elk geval een duidelijke e-mail met de reden.

## Permissie-rollen

| Rol | Kan bekijken | Kan boeken | Kan beheren |
|---|:-:|:-:|:-:|
| Viewer | ✓ | | |
| Booker | ✓ | ✓ | |
| Manager | ✓ | ✓ | ✓ |

Managers kunnen openstaande boekingen goedkeuren/afwijzen, ruimte-instellingen bewerken en elke boeking annuleren. Viewers zien de ruimte en haar **Verantwoordelijke contact** in **Persoonlijke instellingen → Mijn ruimtes** — handig om te weten wie je moet vragen als je zelf niet kunt boeken.

## Ondersteunde agenda-clients

| Client | Opmerkingen |
|---|---|
| Nextcloud Calendar | Volledige ondersteuning. Optionele [visuele ruimte-browser](../features/calendar-patch.md) |
| Apple Calendar (macOS / iOS) | Volledige ondersteuning. Auto-fix voor `CUTYPE=INDIVIDUAL` |
| Microsoft Outlook | Volledige ondersteuning via CalDAV-account |
| Thunderbird | Volledige ondersteuning via CalDAV-account |
| eM Client | Volledige ondersteuning. Auto-detectie via LOCATION |

## Zie ook

- [Ruimtes boeken](booking-rooms.md) — hoe boeken vanuit elke agenda-app
- [Boekingen beheren](managing-bookings.md) — goedkeuren, afwijzen, verplaatsen, annuleren
- [Notificaties](notifications.md) — alle e-mail-types
- [Persoonlijke instellingen](personal-settings.md) — je ruimtes en boekingen
- [Tips](tips.md) — client-specifieke trucs
- [Problemen oplossen](troubleshooting.md) — wanneer iets niet werkt
- [FAQ](faq.md) — veelgestelde vragen
