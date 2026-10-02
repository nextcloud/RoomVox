# Gebruiksstatistieken

Met toestemming van een beheerder stuurt RoomVox eens per dag gebruiksstatistieken over de installatie naar `licenses.voxcloud.nl`, beheerd door VoxCloud. **Er wordt niets verstuurd tot een beheerder dit aanzet.** Er gaan geen persoonsgegevens, inhoud of namen mee.

RoomVox volgt de telemetrieregels van VoxCloud (design `TELEMETRY.md` 1.1.0).

## Hoe je gevraagd wordt

Na installatie of upgrade van RoomVox krijgt elke Nextcloud-beheerder een melding in de bel met drie antwoorden:

| Antwoord | Effect |
|---|---|
| **Gebruiksstatistieken delen** | Gebruiksstatistieken gaan aan |
| **Niet nu** | Ze blijven uit; je wordt bij de volgende RoomVox-versie opnieuw gevraagd |
| **Nooit meer vragen** | Ze blijven uit; je wordt niet meer gevraagd |

Gewone gebruikers krijgen de vraag nooit. Installaties die van een versie vóór 1.6.0 komen en nooit een keuze maakten, worden door de upgrade uitgezet; een expliciet "aan" blijft staan.

## Wat er verstuurd wordt

De tab Support in de beheerinstellingen van RoomVox toont elk veld met waarvoor het gebruikt wordt. Die lijst komt uit dezelfde definitie als het rapport zelf, dus hij klopt altijd met wat er verstuurd wordt:

| Veld | Gebruikt voor |
|---|---|
| Installatie-ID | Een SHA-256-hash van het adres van de server, om installaties uit elkaar te houden en de rapporten van de VoxCloud-apps op één server te koppelen aan de licentiegegevens. Het adres zelf gaat niet mee |
| Versie van de veldenlijst | Welke versie van deze lijst het rapport volgt; later toegevoegde velden gaan pas mee nadat je ermee instemt |
| Versie van RoomVox, Nextcloud en PHP | Welke versies nog in gebruik zijn en ondersteund moeten blijven. PHP alleen als `major.minor` |
| Aantal gebruikersaccounts, gebruikers actief in de laatste 30 dagen, uitgeschakelde accounts | Om een licentie te bepalen en installaties te vinden die er mogelijk een nodig hebben |
| Nextcloud-abonnement (ja of nee) | Servers met een Nextcloud Enterprise-abonnement worden als Enterprise-klant geteld en niet benaderd over een licentie |
| Land | Een wereldkaart van installaties. Uit `default_phone_region`, of op de server afgeleid uit `default_timezone`; de tijdzone zelf gaat niet mee |
| Ruimtes, ruimtegroepen, ruimtes die boekingen automatisch accepteren, ruimtes met een eigen mailserver, ruimtes gekoppeld aan Microsoft Exchange, Exchange-sync aan (ja of nee) | Hoe de functies van RoomVox gebruikt worden. Tenant-ID, client-ID en secret van Exchange gaan nooit mee |

Er gaat niets mee over afzonderlijke gebruikers, ruimtes of boekingen: geen namen, e-mailadressen, ruimtenamen, boekingsinhoud of inloggegevens.

## Het licentiegebruik-rapport staat hier los van

Zolang er een abonnementssleutel is ingevuld, meldt RoomVox ook de sleutel, de installatie-ID en het aantal ruimtes, ruimtegroepen, gebruikersaccounts en uitgeschakelde accounts aan `licenses.voxcloud.nl`, zodat het abonnement gecontroleerd en het aantal plekken geteld kan worden. Dat rapport hoort bij het abonnement, niet bij deze keuze, en stopt als de sleutel wordt verwijderd.

## Van gedachten veranderen

### Via het beheerpaneel

1. Ga naar **Instellingen > Beheer > RoomVox**
2. Klik op de tab **Support**
3. Zet **Gebruiksstatistieken delen** aan of uit

### Via de commandline

```bash
sudo -u www-data php occ config:app:set roomvox telemetry_enabled --value false
```

## Handmatig rapport

Zolang gebruiksstatistieken aan staan, verstuurt **Nu rapport versturen** op de tab Support direct een rapport. De knop weigert zolang ze uit staan, en meldt "Onlangs al verstuurd" als er in het afgelopen uur al een rapport is verstuurd.

## Technische details

- Rapporten worden verstuurd door een Nextcloud-achtergrondtaak (`TelemetryJob`), elke 24 uur, met een per installatie vaste jitter van maximaal 2 uur
- Mislukte rapporten worden bij het volgende interval opnieuw geprobeerd; timeout 15 seconden
- De keuze staat in de app-config: `telemetry_enabled`, `telemetry_consent_schema`, `telemetry_asked_version` en `telemetry_never_ask`
