# RoomVox vs. Nextcloud Calendar Resource Management

Deze pagina vergelijkt RoomVox met Nextclouds eigen app [Calendar Resource Management](https://github.com/nextcloud/calendar_resource_management) (CRM). CRM heeft onlangs een webinterface gekregen, en daarom krijgen we vaak de vraag: *wat voegt RoomVox toe?*

Getoetst in **oktober 2026** aan RoomVox 1.6.1 en aan de code en de issuetracker van CRM: stabiele release 0.12.2, release candidate 1.0.0-rc.2 (de eerste release met de web-UI) en `main`. CRM 1.0.0 zelf was op het moment van schrijven nog niet uitgebracht. Wat over Agenda staat, geldt voor Nextcloud Agenda 6.6 op Nextcloud 35.

## In het kort

De twee apps lossen een ander probleem op.

- **Calendar Resource Management is een ruimteregister.** Je voert gebouwen, verdiepingen, ruimtes en middelen in. Nextcloud toont die ruimtes daarna in de Agenda-app, zodat gebruikers ze voor een afspraak kunnen uitnodigen. CRM bevat zelf geen boekingslogica. Wat er na de uitnodiging gebeurt, doet de Nextcloud-server: een vrij/bezet-controle die de ruimte accepteert of weigert.
- **RoomVox is een boekingssysteem.** Naast het publiceren van ruimtes in de agenda bepaalt het *wie* *welke* ruimte mag boeken, *wanneer* en *hoe ver vooruit*. Het kan boekingen via een beheerder laten goedkeuren, mailt iedereen die erbij betrokken is, en koppelt ruimtes aan Microsoft 365/Exchange, schermen en andere systemen.

Hoeven ruimtes alleen in de agenda te verschijnen en volstaat het vrij/bezet-antwoord, dan is CRM een degelijke en gratis keuze. Zodra je regels, goedkeuring, meldingen of toegangsbeheer per ruimte nodig hebt, heb je RoomVox nodig.

## In één oogopslag

| | **RoomVox** | **Calendar Resource Management** |
|---|---|---|
| Versie | 1.6.1 | 0.12.2 stabiel · 1.0.0-rc.2 met web-UI |
| Onderhouden door | VoxCloud | Nextcloud GmbH |
| Licentie | AGPL-3.0 | AGPL-3.0 |
| Nextcloud | 32–35 | 31–35 (0.12) · 34–35 (1.0) |
| PHP | 8.2+ | 8.1–8.5 (0.12) · 8.3–8.5 (1.0) |
| Beheerinterface | Web-UI (beheerder) + persoonlijke pagina voor ruimtebeheerders | Web-UI in de beheerinstellingen (1.0: tonen, aanmaken, verwijderen) + `occ` |
| Talen van de interface | Engels, Nederlands, Duits, Frans | Alleen Engels |
| Wie beslist over een boeking | De eigen boekingsregels van RoomVox | De vrij/bezet-controle van de Nextcloud-server |

## Functievergelijking

### Boekingsregels

| | **RoomVox** | **CRM** |
|---|:---:|:---:|
| Ruimtes verschijnen in de ruimtezoeker van Nextcloud Agenda 6.6+ | Ja | Ja |
| Controle op dubbele boekingen, losse afspraken | Ja | Ja (door de Nextcloud-server) |
| Controle op dubbele boekingen, terugkerende afspraken | Ja, elke herhaling | Nee — de server beantwoordt terugkerende uitnodigingen niet |
| Automatisch accepteren | Per ruimte | Altijd, als de ruimte vrij is |
| Goedkeuring door een beheerder | Per ruimte | Nee ([#198](https://github.com/nextcloud/calendar_resource_management/issues/198), open) |
| Openingstijden per ruimte (dagen en tijden) | Ja, meerdere regels | Nee |
| Boekingshorizon (max. dagen vooruit) | Ja, per ruimte | Nee |
| Ruimte tijdelijk deactiveren | Ja, bestaande boekingen blijven staan | Nee |

### Meldingen

| | **RoomVox** | **CRM** |
|---|:---:|:---:|
| Mail aan de organisator | Geaccepteerd, geweigerd (met reden), geannuleerd | Nee |
| Mail aan ruimtebeheerders | Goedkeuringsverzoeken, annuleringen | Nee |
| Mails in de taal van de ontvanger | Ja | — |
| Versturen vanaf het eigen mailaccount van de ruimte (SMTP per ruimte) | Ja, wachtwoord versleuteld | Nee |

### Toegangsbeheer

| | **RoomVox** | **CRM** |
|---|:---:|:---:|
| Beperken wie een ruimte mag boeken | Ja, per gebruiker of groep (ook LDAP) | Per groep, alleen via `occ` |
| Rollen | Kijker, Boeker, Beheerder | — |
| Rechten op een groep ruimtes, overgeërfd door de ruimtes | Ja | Nee |
| Ruimtes verbergen voor wie ze niet mag zien | Ja | Ja (afgeschermde ruimtes) |
| Ruimtebeheerders handelen boekingen van hun eigen ruimtes af | Ja, zonder beheerdersrechten | Nee |
| Inrichten van ruimtes delegeren aan een niet-beheerder | Nee, alleen beheerder | Ja, via Nextclouds *Beheerrechten* |

### Beheer

| | **RoomVox** | **CRM** |
|---|:---:|:---:|
| Ruimtes aanmaken en verwijderen in een web-UI | Ja | Ja (1.0) |
| Een bestaande ruimte bewerken | Ja | Nog niet — geen bewerken in de UI of via `occ` ([PR #342](https://github.com/nextcloud/calendar_resource_management/pull/342) in review) |
| Overzicht van alle boekingen (agenda en lijst) | Ja | Nee |
| Gebruiksstatistieken | Ja | Nee |
| Bulkimport en -export (CSV) | Ja, ook een Microsoft 365 `Get-Place`-export | Nee |
| Ruimtetypes | Instelbare lijst | Vrije tekst per ruimte |
| Voorzieningen | Instelbare lijst | Vaste set van zes (telefoon, video, tv, beamer, whiteboard, rolstoeltoegankelijk) |

### Koppelingen

| | **RoomVox** | **CRM** |
|---|:---:|:---:|
| Microsoft 365 / Exchange-ruimtepostvakken, synchronisatie in twee richtingen | Ja | Nee |
| Publieke REST API (schermen, kiosken, signage) | Ja, tokens met scope | Nee |
| Abonneerbare iCal-feed per ruimte | Ja | Nee |
| Correcties voor Apple Agenda (iOS/macOS) en eM Client | Ja | Nee |

### Wat je kunt beheren

| | **RoomVox** | **CRM** |
|---|:---:|:---:|
| Ruimtes | Ja | Ja |
| Gebouwen en verdiepingen | Als velden van een ruimte (gebouw, verdieping, adres) | Aparte onderdelen |
| Apparatuur en andere middelen | Nee | Ja |
| Voertuigen | Nee | Ja (alleen via `occ`) |
| Databasetabellen | Geen — instellingen in de app-config, boekingen in CalDAV | Eigen tabellen |

## Wat RoomVox toevoegt

### Ook terugkerende afspraken worden gecontroleerd

De Nextcloud-server beantwoordt een uitnodiging voor een ruimte met een controle van de vrij/bezet-tijden. Dat doet hij **alleen bij losse afspraken**: een uitnodiging met een herhaalregel krijgt helemaal geen antwoord, dus een wekelijks overleg kan zonder dat iemand het merkt op een bezette ruimte terechtkomen. Er staan ook meldingen open over dubbele boekingen vanuit andere agenda-apps ([#199](https://github.com/nextcloud/calendar_resource_management/issues/199), [#192](https://github.com/nextcloud/calendar_resource_management/issues/192)).

RoomVox controleert **elke herhaling** van een reeks tegen bestaande boekingen, openingstijden en de boekingshorizon. Botst één datum, dan wordt de reeks geweigerd en noemt de mail precies de datums die het probleem zijn, zodat de organisator alleen die hoeft aan te passen.

### Regels per ruimte

Elke ruimte kan hebben:

- **Openingstijden** — bijvoorbeeld werkdagen 08:00–18:00, met meerdere regels per ruimte.
- **Een boekingshorizon** — bijvoorbeeld maximaal 90 dagen vooruit. Een reeks die eindeloos doorloopt, wordt bij een ruimte met horizon geweigerd.
- **Automatisch accepteren of goedkeuring** — sommige ruimtes zijn vrij te boeken, andere gaan via een beheerder.

CRM heeft geen van deze. De Nextcloud-server kan openingstijden wel respecteren als ze op de agenda van de ruimte staan, maar CRM biedt geen manier om ze in te stellen.

### Goedkeuring door een ruimtebeheerder

Bij ruimtes die goedkeuring vragen, staat de boeking op *voorlopig* en krijgen de beheerders van de ruimte een mail. Zij keuren goed of af op hun persoonlijke RoomVox-pagina in Nextcloud, en de organisator krijgt daarvan bericht per mail. Ruimtebeheerders hebben daarvoor geen beheerdersrechten nodig. Boekt een beheerder zijn eigen ruimte, dan wordt die direct geaccepteerd.

### Iedereen hoort wat er gebeurd is

Bij CRM is de enige terugkoppeling de status van de ruimte in de agenda-app van de organisator. Nextcloud mailt niet namens ruimtes of middelen, en niemand krijgt bericht als er iets misgaat.

RoomVox mailt de organisator als een boeking geaccepteerd, geweigerd of geannuleerd is, en zegt altijd *waarom* een boeking geweigerd is: geen recht, buiten de openingstijden, voorbij de horizon of een conflict. Beheerders krijgen goedkeuringsverzoeken en annuleringen. Mails gaan in de eigen taal van elke ontvanger, en een ruimte kan vanaf haar eigen mailaccount versturen.

### Toegangsbeheer dat bij een organisatie past

RoomVox kent drie rollen — **Kijker**, **Boeker** en **Beheerder** — die je toekent aan gebruikers of groepen, ook LDAP-groepen. Geef een hele groep ruimtes één keer rechten, en elke ruimte in die groep erft ze. Ruimtes die iemand niet mag zien, verschijnen niet in de ruimtekiezer.

CRM kan een ruimte beperken tot bepaalde groepen, maar alleen via de opdrachtregel en zonder onderscheid tussen bekijken, boeken en beheren.

### Microsoft 365 en Exchange

RoomVox kan een ruimte koppelen aan een Exchange-ruimtepostvak en beide kanten gelijk houden:

- Boekingen in Nextcloud worden naar Exchange geschreven.
- Boekingen in Outlook/Exchange komen in Nextcloud binnen, via webhooks en een synchronisatie elk kwartier.
- Exchange-boekingen tellen mee in de controle op dubbele boekingen.

Zo kan een organisatie stap voor stap naar Nextcloud overstappen, of beide naast elkaar gebruiken. Ruimtes zijn ook direct te importeren uit een Microsoft 365 `Get-Place`-export.

### Schermen en andere systemen koppelen

Met de [Public API](public-api.md) kunnen ruimteschermen, kiosken, digital signage en andere toepassingen de status en beschikbaarheid van ruimtes lezen en boekingen maken of annuleren. Elk token heeft een scope (lezen, boeken of beheer), kan beperkt worden tot bepaalde ruimtes en kan verlopen. Elke ruimte kan daarnaast een abonneerbare iCal-feed publiceren.

### Werkt met de agenda-apps die mensen al gebruiken

Boeken gebeurt in de eigen agenda-app van de gebruiker. RoomVox corrigeert bekende eigenaardigheden, zodat boeken ook werkt vanuit:

- **Apple Agenda (iOS/macOS)**, dat ruimtes uitnodigt als persoon in plaats van als ruimte.
- **eM Client**, dat alleen het locatieveld invult.

Net als CRM publiceert RoomVox van elke ruimte het gebouw, de verdieping, de capaciteit en de voorzieningen. De ruimtezoeker in Nextcloud Agenda 6.6 en later gebruikt die om ruimtes te filteren en per gebouw te groeperen. Per gebouw groeperen gebeurt alleen als het gebouwveld van de ruimte is ingevuld; ruimtes zonder gebouw staan samen onder een aparte kop onderaan.

### Dagelijks beheer

- Een **boekingsoverzicht** in agenda- en lijstweergave, voor beheerders en voor ruimtebeheerders van hun eigen ruimtes.
- **Statistieken** over het gebruik van ruimtes.
- **CSV-import en -export** met een voorbeeld vooraf, voordat er iets verandert.
- **Bewerken** van elke instelling van een ruimte, op elk moment. In CRM 1.0 kan een ruimte alleen aangemaakt of verwijderd worden; bewerken ligt nog in review.

## Waar Calendar Resource Management beter past

- **Apparatuur, voertuigen en andere middelen.** RoomVox doet alleen ruimtes.
- **Gebouwen en verdiepingen als aparte onderdelen.** RoomVox slaat gebouw en verdieping op als velden van een ruimte. Dat volstaat om te filteren en te groeperen, maar is geen volledige locatiestructuur.
- **Inrichten delegeren.** De instellingen van CRM zijn via Nextclouds *Beheerrechten* aan een niet-beheerder toe te wijzen. In RoomVox handelen ruimtebeheerders boekingen af, maar ruimtes aanmaken en rechten instellen blijft bij de beheerder.
- **Een minimale opzet.** Hoeven ruimtes alleen zichtbaar te zijn en volstaat vrij/bezet, dan doet CRM het werk met minder instellingen.

## Welke kies je?

| Je hebt nodig… | Kies |
|---|---|
| Ruimtes zichtbaar in de agenda, eenvoudig vrij/bezet | Calendar Resource Management |
| Apparatuur of voertuigen boeken | Calendar Resource Management |
| Betrouwbare controle van terugkerende afspraken | **RoomVox** |
| Openingstijden of een boekingshorizon per ruimte | **RoomVox** |
| Goedkeuring door een ruimtebeheerder | **RoomVox** |
| Bevestigingen per mail en de reden van een weigering | **RoomVox** |
| Rollen Kijker/Boeker/Beheerder per ruimte of groep ruimtes | **RoomVox** |
| Synchronisatie met Microsoft 365 / Exchange, of migratie daarvandaan | **RoomVox** |
| Ruimteschermen, kiosken of andere koppelingen via een API | **RoomVox** |
