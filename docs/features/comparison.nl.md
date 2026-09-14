# RoomVox vs. Nextcloud Calendar Resource Management

Dit document vergelijkt RoomVox met Nextclouds ingebouwde app [Calendar Resource Management](https://github.com/nextcloud/calendar_resource_management) (v0.12.0-dev.1, februari 2026).

## Overzicht

| | **RoomVox** | **Calendar Resource Management** |
|---|---|---|
| Versie | 1.5.0 | 0.12.0-dev.1 |
| Licentie | AGPL-3.0 | AGPL-3.0 |
| Nextcloud | 32–35 | 31–34 |
| PHP | 8.2+ | 8.1–8.5 |
| Data-opslag | IAppConfig (geen database) | Database (6+ tabellen) |
| Beheer-interface | Volledige web-UI | Alleen CLI (`occ`-commando's) |

## Functie-vergelijking

| Functie | **RoomVox** | **Calendar Resource Management** |
|---|:---:|:---:|
| Ruimtes als CalDAV-resources | Ja | Ja |
| Web-gebaseerd beheerpaneel | Ja | Nee |
| Conflict-detectie | Ja | Nee (kapot — [#199](https://github.com/nextcloud/calendar_resource_management/issues/199)) |
| Boekingen auto-accepteren | Ja (per ruimte) | Deels (onbetrouwbaar — [#192](https://github.com/nextcloud/calendar_resource_management/issues/192)) |
| Goedkeurings-workflow | Ja | Nee ([#198](https://github.com/nextcloud/calendar_resource_management/issues/198)) |
| Beschikbaarheids-regels | Ja (dag/tijd) | Nee |
| Boekings-horizon | Ja (max. dagen vooruit) | Nee |
| E-mail-notificaties | Ja (5 types) | Nee ([#196](https://github.com/nextcloud/calendar_resource_management/issues/196)) |
| SMTP per ruimte | Ja (versleuteld) | Nee |
| Permissie-systeem | Ja (3 rollen) | Alleen groeps-beperkingen |
| Ruimte-groepen | Ja (met overgeërfde permissies) | Nee |
| Eigen ruimte-types | Ja | Nee |
| Ruimte-faciliteiten | Ja (aanpasbaar) | Ja (vaste set) |
| Gebouw-/verdieping-hiërarchie | Nee | Ja |
| Voertuigen & algemene resources | Nee | Ja |
| iOS-compatibiliteits-fix | Ja (CUTYPE) | Nee |
| eM Client-compatibiliteits-fix | Ja (LOCATION) | Nee |
| Validatie van terugkerende events | Ja (horizon + beschikbaarheid) | Geen conflict-check |
| Publieke REST-API | Ja (Bearer token-auth) | Nee |
| CSV-import/-export | Ja (RoomVox + MS365) | Nee |
| Bulk-ruimtebeheer | Ja (CSV-import) | Alleen CLI |
| Nul database-migraties | Ja | Nee |

## Waarom RoomVox

### Betrouwbaar boekings-beheer

RoomVox biedt **werkende conflict-detectie** die dubbele boekingen automatisch voorkomt. De app Calendar Resource Management heeft een bekend, onopgelost probleem waarbij overlappende boekingen niet gedetecteerd worden ([#199](https://github.com/nextcloud/calendar_resource_management/issues/199)), waardoor hij onbetrouwbaar is voor echte ruimte-boekings-scenario's.

### Goedkeurings-workflows

RoomVox ondersteunt per ruimte zowel **auto-accept** als **manager-goedkeuring**. Wanneer een ruimte goedkeuring vereist, wordt de boeking op voorlopig gezet en ontvangen alle aangewezen managers een e-mail-notificatie. Zij kunnen de boeking vervolgens goedkeuren of afwijzen vanuit het beheerpaneel. Calendar Resource Management heeft geen goedkeurings-workflow — boekingen worden direct geaccepteerd, zonder toezicht.

### Volledige beheer-interface

RoomVox bevat een **compleet web-gebaseerd beheerpaneel** om ruimtes aan te maken en te beheren, permissies te configureren, boekingen te beoordelen en instellingen aan te passen. Calendar Resource Management vereist dat beheerders voor alle beheertaken CLI-commando's (`occ`) gebruiken, wat onpraktisch is voor niet-technische beheerders.

### E-mail-notificaties

RoomVox verstuurt **vijf types e-mail-notificaties**:

| Notificatie | Wanneer |
|---|---|
| Boeking bevestigd | Nadat een boeking automatisch is geaccepteerd |
| Boeking afgewezen | Afwijzing op permissie, beschikbaarheid of conflict |
| Boekings-conflict | Wanneer een tijd-overlap wordt gedetecteerd |
| Goedkeurings-verzoek | Wanneer handmatige goedkeuring nodig is (naar de managers) |
| Boeking geannuleerd | Wanneer de organisator annuleert (naar organisator + managers) |

Calendar Resource Management verstuurt geen enkele e-mail-notificatie.

### SMTP per ruimte

Elke ruimte kan zijn **eigen SMTP-configuratie** hebben, zodat boekings-bevestigingen vanaf het eigen e-mailadres van de ruimte verstuurd worden. SMTP-wachtwoorden worden versleuteld via Nextclouds ICrypto. Ruimtes zonder eigen SMTP vallen terug op de globale mail-configuratie van Nextcloud.

### Fijnmazige permissies

RoomVox implementeert een **permissie-systeem met drie rollen**:

| Rol | Mag bekijken | Mag boeken | Mag beheren |
|---|:---:|:---:|:---:|
| Viewer | Ja | Nee | Nee |
| Booker | Ja | Ja | Nee |
| Manager | Ja | Ja | Ja |

Permissies kunnen worden toegekend aan **individuele gebruikers** of aan **Nextcloud-groepen**, zowel op ruimte-niveau als op ruimte-groep-niveau. De effectieve permissies zijn de vereniging van beide. Calendar Resource Management ondersteunt alleen groeps-gebaseerde beperkingen, zonder onderscheid in rollen.

### Beschikbaarheids-regels & boekings-horizon

RoomVox laat beheerders vastleggen **wanneer ruimtes geboekt kunnen worden** (bijv. doordeweeks 08:00–18:00) en **hoe ver vooruit** (bijv. maximaal 90 dagen). Deze regels worden afgedwongen voor zowel losse als terugkerende events. Calendar Resource Management heeft geen beschikbaarheids- of horizon-functies.

### Compatibiliteit met agenda-clients

RoomVox bevat **automatische compatibiliteits-fixes** voor veelvoorkomende problemen met CalDAV-clients:

- **iOS/macOS Calendar**: stuurt `CUTYPE=INDIVIDUAL` in plaats van `CUTYPE=ROOM` — RoomVox corrigeert dit automatisch en vult het LOCATION-veld aan
- **eM Client**: stuurt boekingen met alleen een LOCATION-veld (geen ATTENDEE) — RoomVox detecteert de ruimte via een locatie-match en voegt de juiste CalDAV-deelnemer toe

Deze fixes gebeuren transparant tijdens het schedulen, zonder tussenkomst van de gebruiker.

### Nul database-overhead

RoomVox slaat alle configuratie op in Nextclouds key-value-store **IAppConfig**. Dat betekent:

- Geen database-migraties bij installatie of upgrade
- Geen schema-conflicten met andere apps
- Boekings-data leeft in standaard CalDAV-agenda's
- Werkt met elke database-backend (PostgreSQL, MySQL, SQLite)

## Waarin Calendar Resource Management verschilt

Calendar Resource Management ondersteunt **resource-types buiten ruimtes**:

- **Gebouwen** met adressen en toegankelijkheids-vlaggen
- **Verdiepingen** binnen gebouwen
- **Voertuigen** met merk, model, actieradius en elektrisch-status
- **Algemene resources** voor apparatuur en middelen

Het biedt ook een **gebouw-verdieping-ruimte-hiërarchie** voor organisaties met meerdere locaties. RoomVox richt zich momenteel uitsluitend op ruimtes, georganiseerd in platte groepen.

## Roadmap

RoomVox is ontworpen met een uitbreidbare architectuur. In toekomstige versies kunnen extra resource-types (voertuigen, apparatuur, gedeelde ruimtes) worden toegevoegd, waarmee de scope verder reikt dan ruimte-boekingen, met behoud van hetzelfde niveau van scheduling-betrouwbaarheid, permissies en notificaties.

## Samenvatting

RoomVox is speciaal gebouwd voor **betrouwbare ruimte-boekingen** met de functies die organisaties nodig hebben: conflict-preventie, goedkeurings-workflows, e-mail-notificaties, beschikbaarheids-regels en een intuïtieve beheer-interface. Calendar Resource Management biedt ondersteuning voor een breder scala aan resource-types, maar mist de kern-boekings-functionaliteit die nodig is voor betrouwbaar dagelijks ruimte-beheer.

| Behoefte | Aanbeveling |
|---|---|
| Betrouwbare ruimte-boekingen met conflict-detectie | **RoomVox** |
| Goedkeurings-workflows voor ruimte-aanvragen | **RoomVox** |
| E-mail-notificaties bij boekingen | **RoomVox** |
| Web-gebaseerd beheer | **RoomVox** |
| Beheer van voertuigen of apparatuur | Calendar Resource Management |
| Hiërarchie met meerdere gebouwen | Calendar Resource Management |
