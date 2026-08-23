# Plan — Subscribe-bare per-room ICS-feed (issue #16)

**Doel:** Rooms abonneerbaar maken in externe agenda-apps (NC Calendar, Outlook, Apple
Calendar) en signage/kiosk, zónder `Authorization`-header. Dit maakt het al bestaande maar
onbruikbare endpoint `/api/v1/rooms/{id}/calendar.ics` (Bearer-only) eindelijk bruikbaar
voor zijn hoofd-usecase.

**Gekozen aanpak (na security-afweging):** een **aparte, read-only, per-room feed-secret**,
NIET een `?token=rvx_…` query-param op de bestaande API-tokens.

**Waarom deze en niet de query-param variant:**
- API-tokens kunnen `book`/`admin`-scope hebben. Zo'n token in een agenda-URL (die in
  access-logs, browser-history en proxy-caches belandt) = scope-escalatie bij leak.
- Een feed-secret is *intrinsiek* read-only en room-gebonden: een leak exposeert alleen de
  bookings van die ene room, en is per room in te trekken zonder andere integraties te breken.

**Scope-grenzen (bewust):**
- ❌ GÉÉN tokenloze publieke feed (door Rik bevestigd). Elke feed vereist een geheim.
- ❌ GÉÉN gecombineerde "alle rooms"-feed in deze iteratie (aparte issue).
- ❌ GÉÉN CalDAV read-write; alleen read-only `text/calendar` output.

**Erkend gat:** `docs/user/faq.md:83` en `docs/user/personal-settings.md:55` documenteren de
huidige beperking al — dit plan lost precies dat op.

---

## Architectuur

Nieuw veld in het room-config JSON (`room/{id}` in `IAppConfig`), naast het bestaande model
in [RoomService::createRoom](../lib/Service/RoomService.php#L124):

```
'feedSecret'  => string|null   // rvxf_<40 char random>, of null = geen feed actief
'feedEnabled' => bool          // expliciete aan/uit los van het bestaan van een secret
```

Nieuwe subscribe-bare route (secret in het pad, niet als query-param → minder log-lek):

```
GET /api/v1/rooms/{id}/feed/{secret}/calendar.ics   → text/calendar
```

Deze route wordt afgehandeld door `PublicApiController` maar **omzeilt de bearer-middleware**
(secret-in-pad is de auth). De bestaande bearer-route `/api/v1/rooms/{id}/calendar.ics` blijft
ongewijzigd bestaan voor programmatische consumenten.

---

## Taken

### Backend — feed-secret model
- [ ] `RoomService`: `feedSecret`/`feedEnabled` defaults toevoegen in `createRoom()`
      ([L124](../lib/Service/RoomService.php#L124)) en meenemen in `updateRoom()`.
      Nieuwe rooms krijgen `feedEnabled=false`, `feedSecret=null` (opt-in).
- [ ] `RoomService::rotateFeedSecret(string $roomId): string` — genereert
      `rvxf_` + `ISecureRandom->generate(40, CHAR_ALPHANUMERIC)`, slaat op, retourneert secret.
- [ ] `RoomService::findRoomByFeedSecret(string $secret): ?array` — timing-safe lookup met
      `hash_equals()` over rooms met `feedEnabled=true`. (Iteratie over `rooms_index`; klein.)
- [ ] `RoomService::disableFeed(string $roomId)` — zet `feedEnabled=false` (secret blijft
      staan zodat re-enable dezelfde URL geeft; expliciet roteren wist 'm effectief).

### Backend — route + controller
- [ ] `appinfo/routes.php`: nieuwe route `public_api#room_feed`
      (`/api/v1/rooms/{id}/feed/{secret}/calendar.ics`, GET) toevoegen na regel 84.
- [ ] `PublicApiController::roomFeed(string $id, string $secret)`:
      - `#[PublicPage] #[NoCSRFRequired] #[NoAdminRequired]`
      - Valideer secret via `findRoomByFeedSecret()`; mismatch of `id`-mismatch → lege
        `error.ics` met 404-achtig gedrag (géén detail lekken over bestaan room).
      - Hergebruik de bestaande ICS-generatie: refactor de body van
        [calendarFeed()](../lib/Controller/PublicApiController.php#L536) naar een private
        `buildIcs(array $room): string` en roep die aan vanuit zowel `calendarFeed()`
        (bearer) als `roomFeed()` (secret). GEEN duplicatie van VCALENDAR-logica.

### Backend — middleware
- [ ] `ApiTokenMiddleware::beforeController` ([L25](../lib/Middleware/ApiTokenMiddleware.php#L25)):
      de nieuwe `roomFeed`-methode uitzonderen van de bearer-eis (bv. via methodnaam-allowlist),
      zodat het secret-in-pad de enige auth is. Bestaande methodes ongewijzigd.

### Backend — hardening (verplicht)
- [ ] `#[BruteForceProtection(action: 'roomvox_feed')]` op `roomFeed()` + `throttle()` bij
      mismatch → tegen secret-enumeratie. (NC-native; geen eigen rate-limit bouwen.)
- [ ] Bevestig read-only: `roomFeed` mag uitsluitend `DataDownloadResponse` met
      `text/calendar` teruggeven, nooit een schrijf-pad raken.
- [ ] Secret nooit loggen. `LoggerInterface`-calls in dit pad checken.

### Frontend — RoomEditor UI
- [ ] `src/views/RoomEditor.vue`: sectie "Kalenderfeed (extern abonnement)":
      - `NcCheckboxRadioSwitch` "Externe feed inschakelen" → `feedEnabled` (mapt op
        rotate bij eerste keer aanzetten).
      - Read-only feed-URL veld + kopieerknop (volledige `webcal://`/`https://` URL tonen).
      - "Secret opnieuw genereren"-knop (met bevestiging: breekt bestaande abonnementen).
      - **Privacy-waarschuwing** (helper-text): "De feed bevat titels en organisatoren van
        boekingen. Deel de URL alleen met vertrouwde partijen." → volgt darkmode/NC-tokens
        (zie memory [[darkmode_contrast]]).
- [ ] Internal API endpoint(s) voor rotate/disable (via bestaande `RoomApiController` of nieuw
      `POST /api/rooms/{id}/feed/rotate`, admin-only, sessie-auth). Retourneert de nieuwe URL.
- [ ] Version-bump `appinfo/info.xml` + `package.json` (frontend-deploy vereist het;
      zie memory [[nc_cache_buster_pattern]] / [[version_bump_discipline]] → PATCH bump).

### Tests (PHPUnit, standalone suite)
- [ ] `RoomServiceTest`: rotate genereert `rvxf_`-prefix + 45 char; `findRoomByFeedSecret`
      match/mismatch; disabled room wordt niet gevonden; `hash_equals`-gebruik.
- [ ] `PublicApiControllerTest` (of nieuw `PublicApiFeedTest`): geldig secret → valide
      VCALENDAR; fout secret → geen data-leak; disabled feed → geen data; ID/secret-mismatch.
- [ ] Bevestig dat `buildIcs()`-refactor de bestaande
      `PublicApiCalendarFeedTest` (7 tests) groen houdt — zelfde output.

### Docs
- [ ] `docs/user/faq.md:83` en `docs/user/personal-settings.md:55` bijwerken: externe
      subscription is nu wél mogelijk via de per-room feed-URL. Verwijder de "kan niet direct".
- [ ] `docs/features/public-api.md` + `docs/architecture/api-reference.md`: nieuwe route
      documenteren met privacy-/security-noot (read-only, per-room intrekbaar, secret in URL).
- [ ] `CHANGELOG.md`: entry met `([#16](https://github.com/nextcloud/RoomVox/issues/16))`
      (zie memory [[changelog_issue_links]]).

### Verificatie (voordat "done")
- [ ] `vendor/bin/phpunit --testsuite unit` groen.
- [ ] `npm run build` slaagt.
- [ ] Handmatig: feed-URL uit RoomEditor kopiëren → abonneren in NC Calendar (of `curl`) →
      valide VCALENDAR met de juiste bookings. Foute secret → geen data.
- [ ] Regressie: bestaande bearer-route `/api/v1/rooms/{id}/calendar.ics` nog identiek.

---

## Security-samenvatting (antwoord op "ontstaan er security-issues?")

| Risico | Mitigatie in dit plan |
|---|---|
| Secret lekt in logs/history | Read-only + per-room intrekbaar; leak ≠ escalatie, alleen 1 room leesbaar |
| Scope-escalatie | Vermeden: feed-secret kan per definitie niet booken/beheren (vs. query-param op API-token) |
| Secret-enumeratie | `BruteForceProtection` + 40-char random (praktisch onraadbaar) |
| Booking-data exposure (titels/organisatoren) | Geen tokenloze publieke feed; expliciete privacy-waarschuwing in UI; opt-in per room |
| Timing-attack op lookup | `hash_equals()` |

**Netto:** geen nieuwe klasse van kwetsbaarheden mits secret-in-pad + read-only + brute-force
protection. Dit is strikt veiliger dan de query-param-op-API-token variant.

---

## Review

**Status: geïmplementeerd + geverifieerd (2026-08-03), versie 1.2.0.**

Gedaan:
- Backend: `feedSecret`/`feedEnabled` model + `rotateFeedSecret`/`findRoomByFeedSecret`/`disableFeed`
  (timing-safe, `ISecureRandom` geïnjecteerd). Publieke route
  `/api/v1/rooms/{id}/feed/{secret}/calendar.ics` → `roomFeed()`, ICS-logica gedeeld via
  `buildIcs()` (geen duplicatie). Middleware zondert `roomFeed` uit van bearer-eis.
  `BruteForceProtection` + `throttle()` bij mismatch. Interne `POST /api/rooms/{id}/feed`
  (admin/manager) voor enable/rotate/disable, geeft absolute URL via `linkToRouteAbsolute`.
- Secret-lek-preventie: `index()` str: `feedSecret` weg; `show()` geeft `feedUrl` i.p.v. raw secret.
- Frontend: feed-sectie in RoomEditor (toggle, kopieerbare URL, regenerate, privacy-waarschuwing).
- Tests: 10 nieuwe (3 controller + 7 service). **270/270 groen.** `npm run build` exit 0. PHP lint clean.
- Docs: faq / personal-settings / api-reference / public-api bijgewerkt. CHANGELOG 1.2.0.

Bewuste keuzes: opt-in per room (Rik), MINOR bump (nieuwe feature-mijlpaal), geen tokenloze
publieke feed, geen gecombineerde alle-rooms-feed (aparte issue waard).

Nog te doen door Rik: App Store-upload; daarna pas reageren op #16 (memory
[[reply_issue_after_appstore_confirm]]).
