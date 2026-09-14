# API-referentie

> **Let op:** dit is ontwikkelaarsdocumentatie en blijft Engelstalig. Veldnamen, foutmeldingen en HTTP-statussen zijn Engels, en een API-referentie verandert te vaak om in twee talen betrouwbaar te houden. Deze pagina geeft een Nederlandse introductie; voor de volledige specificatie met alle endpoints, request- en response-schema's zie [de Engelse versie](api-reference.md).

## Inleiding

RoomVox heeft twee API's, en het verschil tussen die twee bepaalt alles: hoe je
authenticeert, wat je mag, en welke paden er bestaan.

| API | Basispad | Authenticatie | Bedoeld voor |
|-----|----------|---------------|--------------|
| **Internal API** | `/api/...` | Nextcloud-sessie (cookie + CSRF-token) | de beheerinterface van RoomVox zelf |
| **Public API v1** | `/api/v1/...` | Bearer-token | externe integraties |

Alle paden zijn relatief aan `/apps/roomvox/`. Voor POST-, PUT- en DELETE-verzoeken
gebruik je het `/index.php/apps/roomvox/`-voorvoegsel.

De Public API v1 is gebouwd voor systemen buiten Nextcloud: ruimte-displays bij de
deur, kiosken, digital signage, Power Automate, Zapier en eigen applicaties. De
Internal API is het gereedschap van de Vue-beheerinterface; je kunt hem aanroepen,
maar je hebt er een ingelogde browsersessie voor nodig en de meeste endpoints
vereisen een beheerder.

## Authenticatie en tokens

Elk v1-endpoint verwacht een token in de `Authorization`-header:

```
Authorization: Bearer rvx_abc123def456...
```

Tokens beheer je in de RoomVox-beheerinterface onder **Instellingen > API Tokens**.
Let op één ding bij het aanmaken: het ruwe token staat alleen in het antwoord op de
aanmaak-call (`201`), in het veld `token`. Daarna is het niet meer op te vragen —
RoomVox bewaart alleen een hash. Sla het dus meteen op; ben je het kwijt, dan maak je
een nieuw token en verwijder je het oude.

## Scopes

Een token draagt één scope, en die scopes zijn hiërarchisch: een `book`-token kan
alles wat een `read`-token kan.

| Scope | Niveau | Wat je ermee mag |
|-------|--------|------------------|
| `read` | 1 | ruimtes, beschikbaarheid, boekingen en de iCal-feed lezen |
| `book` | 2 | alles van `read`, plus boekingen aanmaken en annuleren |
| `admin` | 3 | alles van `book`, plus statistieken |

Daarnaast kun je een token beperken tot specifieke ruimtes. Zonder beperking heeft het
token toegang tot alle ruimtes. Een token dat een ruimte buiten zijn lijst benadert,
krijgt `403` — dezelfde status als bij een te lage scope.

## Vorm van de antwoorden

Twee dingen die clients regelmatig verkeerd inschatten.

**Collecties komen terug als platte JSON-arrays**, niet in een object-wrapper. `GET
/api/rooms` geeft `[{...}, {...}]`, niet `{"rooms": [...]}`. Datzelfde geldt voor
boekingen per ruimte, ruimtegroepen, sharees, de persoonlijke endpoints en de
tokenlijst. De enige uitzondering is `GET /api/all-bookings`, dat wél wrapt omdat het
naast `bookings` ook een `stats`-object meestuurt.

**Sommige endpoints melden mislukking met HTTP 200** en een vlag in de body — soft
failures. `POST /api/exchange/test` bijvoorbeeld antwoordt met `200` en
`{"success": false, "error": "..."}` als de verbinding niet lukt. Alleen naar de
statuscode kijken is daar niet genoeg; je moet de body lezen. Bij de
license-endpoints zit de echte uitkomst zelfs genest: de buitenste `success` zegt dat
de aanroep is afgehandeld, het binnenste object zegt of het gelukt is.

## Foutafhandeling

Er zijn drie foutvormen in RoomVox. Een generieke parser die er één verwacht, breekt
op de andere twee.

```json
{ "error": "Description of the error" }
```

Dat is de vorm van verreweg de meeste endpoints. Daarnaast:

- `/api/license/*` en `/api/settings/license` antwoorden met
  `{"success": false, "message": "..."}`.
- `POST /api/webhook/exchange` stuurt een **lege body** en communiceert uitsluitend
  via de statuscode.

De statuscodes van de Public API v1:

| Status | Betekenis |
|--------|-----------|
| 400 | ongeldige invoer (datums, ontbrekende velden, bereik groter dan 365 dagen) |
| 401 | Bearer-token ontbreekt of is ongeldig |
| 403 | scope te laag, of token heeft geen toegang tot deze ruimte |
| 404 | ruimte of boeking niet gevonden |
| 409 | dubbele boeking op dezelfde tijd |
| 422 | validatie mislukt (buiten openingstijden, voorbij de boekingshorizon) |
| 500 | serverfout |

Let op het onderscheid tussen `409` en `422`: `409` betekent dat de ruimte al bezet
is, `422` dat de boeking op zichzelf niet mag — buiten de beschikbaarheidsregels van
de ruimte, of te ver vooruit.

## Snelstart

Maak eerst een token aan in de beheerinterface, dan:

```bash
# Alle ruimtes opvragen
curl -H "Authorization: Bearer rvx_your_token_here" \
  https://cloud.example.com/apps/roomvox/api/v1/rooms

# Is een ruimte nu vrij?
curl -H "Authorization: Bearer rvx_your_token_here" \
  https://cloud.example.com/apps/roomvox/api/v1/rooms/meeting-room-1/status

# Beschikbaarheid voor een specifieke dag
curl -H "Authorization: Bearer rvx_your_token_here" \
  "https://cloud.example.com/apps/roomvox/api/v1/rooms/meeting-room-1/availability?date=2026-02-16"

# Een boeking aanmaken — let op het /index.php/-voorvoegsel bij POST
curl -X POST \
  -H "Authorization: Bearer rvx_your_token_here" \
  -H "Content-Type: application/json" \
  -d '{"title":"Team sync","start":"2026-02-16T10:00:00+01:00","end":"2026-02-16T11:00:00+01:00"}' \
  https://cloud.example.com/index.php/apps/roomvox/api/v1/rooms/meeting-room-1/bookings
```

Of een boeking `accepted` of `pending` wordt, hangt af van de auto-accept-instelling
van de ruimte: staat auto-accept aan, dan is de boeking direct bevestigd; anders komt
hij in de goedkeuringsstroom terecht.

Voor ruimte-displays en agenda-apps die geen `Authorization`-header kunnen sturen is
er een aparte feed-route met een geheim in het pad
(`/api/v1/rooms/{id}/feed/{secret}/calendar.ics`). Die is per ruimte aan te zetten,
alleen-lezen, en bij een lek draai je het geheim opnieuw zonder andere ruimtes of
tokens te raken.

## Endpoint-categorieën

De Engelstalige referentie behandelt:

| Categorie | Inhoud |
|-----------|--------|
| **Public API v1** | ruimtestatus, beschikbaarheid, ruimtes, boekingen, iCal-feed, statistieken |
| **API Tokens** | tokens aanmaken, tonen en verwijderen (beheerder) |
| **Import/Export** | CSV-import en -export, inclusief het MS365/Exchange-formaat |
| **Rooms / Bookings** | CRUD op ruimtes; boekingen tonen, aanmaken, goedkeuren, verwijderen |
| **Permissions / Room Groups** | permissies per ruimte, ruimtegroepen en groepspermissies |
| **Personal** | eigen ruimtes, zichtbare ruimtes en openstaande goedkeuringen |
| **Exchange** | verbindingstest, resource-validatie, sync- en webhook-status |
| **Settings / Sharees** | app-brede instellingen, ruimtetypes, groepen zoeken |
| **License & Telemetry** | licentiesleutel, validatie en telemetrie-rapport |
| **Debug / Error Responses** | registratie controleren; statuscodes, foutvormen, soft failures |

## Voor de complete referentie

Zie de [Engelse API-referentie](api-reference.md) voor:

- Volledige request- en response-schema's per endpoint
- Alle queryparameters met hun standaardwaarden en grenzen
- De exacte foutteksten per situatie
- Kolomtoewijzing voor CSV-import (RoomVox- en MS365-formaat)
- Gedrag bij terugkerende afspraken in de iCal-feed
- De volledige lijst met soft failures

## Zie ook

- [Architectuur-overzicht](overview.md) — hoe RoomVox in elkaar zit
- [Backend-architectuur](backend-architecture.md) — controllers, services en opslag
- [Aan de slag](../getting-started.md)
