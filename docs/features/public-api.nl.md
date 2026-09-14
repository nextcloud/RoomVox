# Public API

RoomVox biedt een REST-API met **Bearer token-authenticatie** op `/api/v1/*` voor externe integraties — digital signage, ruimte-displays, kiosks, geplande imports en boekings-flows van derden.

Voor de volledige endpoint-referentie, zie [API-referentie → Public API v1](../architecture/api-reference.md#inleiding).

## Wanneer gebruik je de Public API

| Use case | Waarom |
|---|---|
| Ruimte-displays / tablets bij vergaderruimtes | Toon huidige en aankomende boekingen; sta walk-in-boekingen toe |
| Kiosks in de lobby / digital signage | Toon beschikbare ruimtes met capaciteit en faciliteiten |
| Geplande CSV-imports | Periodieke sync vanuit een HR- of facility-management-systeem |
| Reserveringen vanuit een boekings-flow van derden | Eigen front-end met RoomVox als boekings-engine erachter |
| Statistiek-dashboards | Gebruiks-data ophalen voor BI/rapportage |
| Programmatische iCal-feeds | Agenda-sync voor tools die Bearer-geauthenticeerde CalDAV-achtige feeds ondersteunen |

## Een API-token aanmaken

![API-tokens — beheer tokens voor externe integraties](../../screenshots/api-tokens.png)

1. **Instellingen → Beheer → RoomVox → tab Instellingen**
2. Scroll naar **API-tokens**
3. Voer een **naam** in voor het token (bijv. `Lobby Display`)
4. Kies een **scope**: `read`, `book` of `admin`
5. Optioneel: beperk tot specifieke ruimtes
6. Optioneel: stel een vervaldatum in
7. Klik op **Token aanmaken**
8. **Kopieer direct** — het token wordt maar één keer getoond. Daarna zie je alleen nog de prefix (`rvx_xxxx...`)

## Scopes

| Scope | Staat toe |
|---|---|
| `read` | Ruimtes tonen, boekingen lezen, beschikbaarheid lezen, iCal-feed lezen |
| `book` | Bovenstaande + boekingen aanmaken |
| `admin` | Bovenstaande + statistieken + admin-operaties |

Tokens kunnen optioneel worden beperkt tot specifieke ruimtes — handig bij ruimte-displays, waar één apparaat alleen toegang tot één ruimte nodig heeft.

## Authenticatie

Elk request bevat een Bearer token in de `Authorization`-header:

```
Authorization: Bearer rvx_abc123...
```

De header is hoofdletterongevoelig (`bearer` werkt ook). Tokens beginnen altijd met `rvx_`.

### Foutmeldingen

| Status | Betekenis |
|---|---|
| 401 — `Missing or invalid Authorization header` | Geen header of verkeerd formaat |
| 401 — `Invalid or expired API token` | Token ingetrokken of verlopen |
| 403 — `Insufficient permissions` | Token-scope te laag, of een beperkt token probeerde een andere ruimte te benaderen |

## Veelgebruikte endpoints

| Methode | URL | Scope | Doel |
|---|---|---|---|
| `GET` | `/api/v1/rooms` | `read` | Alle ruimtes tonen (gefilterd op de ruimte-beperking van het token) |
| `GET` | `/api/v1/rooms/{id}` | `read` | Ruimte-details opvragen |
| `GET` | `/api/v1/rooms/{id}/status` | `read` | Huidige status — Beschikbaar / Gereserveerd met eindtijd |
| `GET` | `/api/v1/rooms/{id}/availability` | `read` | Beschikbaarheid voor een datum-bereik |
| `GET` | `/api/v1/rooms/{id}/bookings` | `read` | Boekingen van een ruimte tonen |
| `POST` | `/api/v1/rooms/{id}/bookings` | `book` | Een boeking aanmaken |
| `DELETE` | `/api/v1/rooms/{id}/bookings/{uid}` | `book` | Een boeking annuleren (optioneel `?recurrenceId=` voor één voorkomende keer) |
| `GET` | `/api/v1/rooms/{id}/calendar.ics` | `read` | iCalendar-feed (Bearer token) |
| `GET` | `/api/v1/rooms/{id}/feed/{secret}/calendar.ics` | — (secret per ruimte) | Publieke iCalendar-feed voor externe agenda-apps en signage — geen Bearer-header |
| `GET` | `/api/v1/statistics` | `admin` | Gebruiks-statistieken |

Volledige request-/response-schema's, voorbeelden en fout-formaten: [API-referentie → Public API v1](../architecture/api-reference.md#inleiding).

## Snel voorbeeld: lobby-display

Een typische ruimte-display-opstelling die huidige en aankomende boekingen toont:

```bash
# Get current status
curl -H "Authorization: Bearer rvx_abc..." \
  https://nextcloud.example.com/apps/roomvox/api/v1/rooms/boardroom/status
```

Response:

```json
{
  "id": "boardroom",
  "name": "Boardroom",
  "status": "available",
  "nextBooking": {
    "uid": "abc-123",
    "summary": "Quarterly review",
    "start": "2026-06-02T14:00:00+02:00",
    "end": "2026-06-02T15:30:00+02:00",
    "organizer": "alice@example.com"
  }
}
```

```bash
# Get next 24 hours of bookings
curl -H "Authorization: Bearer rvx_abc..." \
  "https://nextcloud.example.com/apps/roomvox/api/v1/rooms/boardroom/bookings?from=2026-06-02T00:00:00Z&to=2026-06-03T00:00:00Z"
```

## Snel voorbeeld: walk-in-boeking vanaf een tablet

Een tablet bij een vergaderruimte laat een voorbijganger de ruimte voor 30 minuten boeken:

```bash
curl -X POST \
  -H "Authorization: Bearer rvx_abc..." \
  -H "Content-Type: application/json" \
  -d '{
    "summary": "Quick sync",
    "start": "2026-06-02T11:00:00+02:00",
    "end": "2026-06-02T11:30:00+02:00",
    "organizerEmail": "walkin@example.com"
  }' \
  https://nextcloud.example.com/apps/roomvox/api/v1/rooms/boardroom/bookings
```

Het gedrag is identiek aan een boeking vanuit een agenda-app:

- Ruimtes met auto-accept bevestigen direct
- Ruimtes zonder auto-accept maken een voorlopige boeking aan en informeren de managers
- Via de API aangemaakte boekingen op ruimtes zonder auto-accept triggeren de goedkeurings-mails correct (gefixt in v1.1.1, zie [#14](https://github.com/nextcloud/RoomVox/issues/14))

## Rate limits en beperkingen

| Beperking | Limiet |
|---|---|
| Datum-bereik-parameters (`from`/`to`) | Max. 365 dagen |
| Bestandsgrootte CSV-import | Max. 5 MB |
| Token-scope | Strikt afgedwongen — `read` kan geen boekingen aanmaken |
| Ruimte-beperking van token | Tokens die beperkt zijn tot specifieke ruimtes geven 403 voor andere ruimtes |

## Beveiliging

- Tokens zijn **geheimen** — behandel ze als wachtwoorden
- Tokens worden **maar één keer getoond** bij het aanmaken — sla ze veilig op
- Tokens kunnen op elk moment **ingetrokken** worden vanuit de tab Instellingen — intrekken is direct van kracht
- Stel **vervaldatums** in op tokens voor kortlopende integraties
- Gebruik **tot ruimtes beperkte tokens** voor displays/kiosks — dat beperkt de schade bij een lek
- Requests naar de Public API worden met de token-prefix gelogd in `nextcloud.log`

## Zie ook

- [API-referentie](../architecture/api-reference.md) — volledige endpoint-documentatie
- [Beheerdersinstellingen → API-tokens](../admin/settings.md#api-tokens) — UI voor token-beheer
- [Backend-architectuur](../architecture/backend-architecture.md) — middleware voor API-tokens
