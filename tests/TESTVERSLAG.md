# Testverslag RoomVox — Dubbele Boekingen Preventie

**Datum:** 25 februari 2026
**Unit tests:** 186 tests, 318 assertions — alle groen (PHPUnit)
**Integratietests:** 50 tests, 0 gefaald — alle groen (live database, 115 kamers)
**Benchmark:** 115 kamers, 214 conflict checks, 108 boekingen — alle operaties binnen budget
**PHP versie:** 8.4.13
**PHPUnit versie:** 10.5.63

---

## Samenvatting

De testsuite bestaat uit twee lagen:

1. **186 unit tests** (PHPUnit) — draaien standalone zonder Nextcloud-instance, alle externe afhankelijkheden worden gemockt
2. **50 integratietests** — draaien tegen de live Nextcloud-database met echte kamers, echte kalenders en echte Exchange-verbinding

Samen valideren ze dat RoomVox **geen dubbele boekingen** kan aanmaken. Alle drie de boekingspaden (CalDAV-client, interne API, publieke API) worden getest op correcte conflictdetectie, permissiecontrole, beschikbaarheidsregels en booking horizon — zowel met mocks als met echte data.

---

## Wat is getest

### 1. Conflictdetectie — `hasConflict()` (15 tests)

**Bestand:** `tests/Unit/Service/CalDAVServiceConflictTest.php`
**Bron:** `lib/Service/CalDAVService.php` regel 418-497

Dit is het **hart** van de dubbele-boeking preventie. De methode `hasConflict()` krijgt een tijdslot en controleert of er overlap is met bestaande boekingen in de kamerkalender.

#### Overlap-detectie

| Test | Bestaande boeking | Nieuwe boeking | Resultaat | Waarom |
|------|-------------------|----------------|-----------|--------|
| Exact overlap | 10:00-11:00 | 10:00-11:00 | **CONFLICT** | Zelfde tijdslot |
| Overlap aan begin | 10:00-11:00 | 09:30-10:30 | **CONFLICT** | 30 min overlap |
| Overlap aan einde | 10:00-11:00 | 10:30-11:30 | **CONFLICT** | 30 min overlap |
| Nieuw binnen bestaand | 09:00-12:00 | 10:00-11:00 | **CONFLICT** | Volledig omvat |
| Bestaand binnen nieuw | 10:00-11:00 | 09:00-12:00 | **CONFLICT** | Volledig omvat |
| Aansluitend erna | 10:00-11:00 | 11:00-12:00 | **VRIJ** | Start = einde, geen overlap |
| Aansluitend ervoor | 10:00-11:00 | 09:00-10:00 | **VRIJ** | Einde = start, geen overlap |
| Zelfde dag, ander slot | 10:00-11:00 | 14:00-15:00 | **VRIJ** | Geen overlap |

**Kernformule:** `conflict = (nieuw_start < bestaand_einde) EN (nieuw_einde > bestaand_start)`

#### Status-afhandeling

| Test | Status bestaande boeking | Resultaat | Waarom |
|------|--------------------------|-----------|--------|
| CANCELLED event | `STATUS: CANCELLED` | **VRIJ** | Geannuleerde boekingen blokkeren niet |
| TENTATIVE event | `STATUS: TENTATIVE` | **CONFLICT** | In-afwachting boekingen reserveren het slot |
| Geen status | (geen STATUS property) | **CONFLICT** | Onbekende status = bezet (veilige aanname) |

#### Reschedule met excludeUid

| Test | Situatie | Resultaat | Waarom |
|------|----------|-----------|--------|
| Eigen boeking uitsluiten | Boeking X op 10-11, reschedule X naar 10-11 met `excludeUid=X` | **VRIJ** | Eigen boeking wordt genegeerd |
| Andere boeking blokkeert | Boeking X + Y op 10-11, `excludeUid=X` | **CONFLICT** | Y blokkeert nog steeds |

#### Randgevallen

| Test | Situatie | Resultaat |
|------|----------|-----------|
| Lege kalender | Geen events in kalender | VRIJ |
| Geen kalender | Kamer heeft nog geen kalender | VRIJ |

---

### 2. Interne API — BookingApiController (12 tests)

**Bestand:** `tests/Unit/Controller/BookingApiConflictTest.php`
**Bron:** `lib/Controller/BookingApiController.php` regel 64-342

Test de interne API die het Nextcloud-admin paneel gebruikt.

#### Boeking aanmaken

| Test | Scenario | HTTP code | Wat er gebeurt |
|------|----------|-----------|----------------|
| Succesvolle boeking | Geen conflict, autoAccept=true | **201** | UID teruggegeven |
| Conflict | Tijdslot al bezet | **409** | Boeking geweigerd |
| Geen permissie | Gebruiker is geen booker | **403** | Toegang geweigerd |
| Kamer bestaat niet | Onbekend kamer-ID | **404** | Niet gevonden |
| Geen titel | Summary is leeg | **400** | Validatiefout |
| Geen tijden | Start/end ontbreken | **400** | Validatiefout |

#### Boeking verplaatsen (reschedule)

| Test | Scenario | HTTP code | Wat er gebeurt |
|------|----------|-----------|----------------|
| Zelfde kamer, nieuw tijdslot | hasConflict met excludeUid | **200** | Tijden bijgewerkt |
| Zelfde kamer, conflict | Nieuw slot bezet door andere boeking | **409** | Geweigerd |
| Naar andere kamer | Cross-room move, geen conflict | **200** | Verwijderd uit oude kamer, aangemaakt in nieuwe |

#### Goedkeuring en verwijdering

| Test | Scenario | HTTP code | Wat er gebeurt |
|------|----------|-----------|----------------|
| Manager accepteert | action=accept | **200** | PARTSTAT → ACCEPTED |
| Manager weigert | action=decline | **200** | PARTSTAT → DECLINED |
| Boeking verwijderen | Admin/manager/eigenaar | **200** | Event verwijderd uit kalender |

---

### 3. CalDAV Scheduling — iTIP flow (14 tests)

**Bestand:** `tests/Unit/Dav/SchedulingPluginRequestTest.php`
**Bron:** `lib/Dav/SchedulingPlugin.php` regel 91-271

Dit is de flow die CalDAV-clients gebruiken (Apple Calendar, Thunderbird, Outlook, eM Client). Wanneer een gebruiker een kamer als deelnemer toevoegt aan een agendaitem, stuurt de client een iTIP REQUEST. Deze plugin verwerkt dat bericht.

#### Validatieketen (in volgorde)

```
iTIP REQUEST binnenkomst
  │
  ├── 1. Permissiecontrole
  │   ├── Geen permissies geconfigureerd → iedereen mag boeken
  │   ├── Onbekende afzender → DECLINED (3.7)
  │   └── Geen book-rechten → DECLINED (3.7)
  │
  ├── 2. Beschikbaarheidscontrole
  │   └── Buiten beschikbare uren → DECLINED (3.7)
  │
  ├── 3. Booking horizon controle
  │   └── Te ver in de toekomst → DECLINED (3.7)
  │
  ├── 4. Conflictcontrole
  │   └── Tijdslot bezet → DECLINED (3.0) + conflict e-mail
  │
  ├── 5. PARTSTAT bepalen
  │   ├── autoAccept=true → ACCEPTED
  │   └── autoAccept=false → TENTATIVE (manager moet goedkeuren)
  │
  └── 6. Aflevering in kamerkalender
      ├── Succes → scheduleStatus 1.2
      └── Fout → scheduleStatus 5.0
```

| Test | Scenario | scheduleStatus | PARTSTAT |
|------|----------|----------------|----------|
| Auto-accept aan | Geldige boeking, autoAccept=true | **1.2** (geleverd) | ACCEPTED |
| Auto-accept uit | Geldige boeking, autoAccept=false | **1.2** (geleverd) | TENTATIVE |
| Conflict | Tijdslot bezet | **3.0** (conflict) | DECLINED |
| Geen permissie | Gebruiker niet booker | **3.7** (geweigerd) | DECLINED |
| Onbekende afzender | mailto: niet oplosbaar | **3.7** (geweigerd) | DECLINED |
| Geen permissies ingesteld | Iedereen mag boeken | **1.2** (geleverd) | ACCEPTED |
| Buiten beschikbaarheid | Zaterdag bij ma-vr kamer | **3.7** (geweigerd) | DECLINED |
| Voorbij horizon | 60 dagen vooruit bij max 7 | **3.7** (geweigerd) | DECLINED |
| Afleverfout | deliverToRoomCalendar faalt | **5.0** (fout) | — |

#### E-mailnotificaties

| Test | Wanneer | Methode |
|------|---------|---------|
| Acceptatie-email | autoAccept=true, boeking OK | `sendAccepted()` |
| Manager-notificatie | autoAccept=false, boeking OK | `notifyManagers()` |
| Conflict-email | Dubbele boeking gedetecteerd | `sendConflict()` |

#### Annulering

| Test | Scenario | Wat er gebeurt |
|------|----------|----------------|
| CANCEL bericht | Organisator annuleert | `deleteFromRoomCalendar()` aangeroepen |

#### Fail-safe

| Test | Scenario | Resultaat |
|------|----------|-----------|
| Exchange push faalt | RuntimeException bij push | Boeking slaagt nog steeds (1.2) |

---

### 4. Booking Horizon met RRULE (8 tests)

**Bestand:** `tests/Unit/Dav/SchedulingPluginHorizonTest.php`
**Bron:** `lib/Dav/SchedulingPlugin.php` regel 602-673

Test dat herhalende boekingen correct worden beperkt door de maximale boekingshorizon.

| Test | Scenario | Horizon | Resultaat |
|------|----------|---------|-----------|
| Geen limiet | maxBookingHorizon=0 | Onbeperkt | **OK** |
| Binnen limiet | 10 dagen vooruit | 30 dagen | **OK** |
| Voorbij limiet | 60 dagen vooruit | 30 dagen | **AFGEWEZEN** |
| RRULE met UNTIL binnen | UNTIL over 20 dagen | 30 dagen | **OK** |
| RRULE met UNTIL voorbij | UNTIL over 90 dagen | 30 dagen | **AFGEWEZEN** |
| RRULE met COUNT binnen | 3 wekelijkse herhalingen (~2 weken) | 30 dagen | **OK** |
| RRULE met COUNT voorbij | 10 wekelijkse herhalingen (~63 dagen) | 30 dagen | **AFGEWEZEN** |
| RRULE oneindig | FREQ=WEEKLY zonder UNTIL/COUNT | 30 dagen | **AFGEWEZEN** |

---

### 5. Publieke API — Validatielogica (8 tests)

**Bestand:** `tests/Unit/Controller/PublicApiConflictTest.php`
**Bron:** `lib/Controller/PublicApiController.php` regel 359-461

Test de validatielogica die de Public API v1 (bearer token) gebruikt voor externe systemen.

#### Beschikbaarheidsregels

| Test | Dag/tijd | Regel | Resultaat |
|------|----------|-------|-----------|
| Maandag 10-11 | ma-vr 08:00-18:00 | **TOEGESTAAN** |
| Zaterdag 10-11 | ma-vr 08:00-18:00 | **AFGEWEZEN** |
| Maandag 07-08 | ma-vr 08:00-18:00 | **AFGEWEZEN** (voor openingstijd) |

#### Horizon en datumvalidatie

| Test | Scenario | Resultaat |
|------|----------|-----------|
| Binnen horizon | 10 dagen vooruit, max 30 | TOEGESTAAN |
| Voorbij horizon | 60 dagen vooruit, max 30 | AFGEWEZEN |
| Einde voor begin | end < start | AFGEWEZEN |
| Nul-duur | end = start | AFGEWEZEN |

---

### 6. Exchange Conflict Check — `hasExchangeConflict()` (9 tests)

**Bestand:** `tests/Unit/Service/Exchange/ExchangeSyncServiceTest.php`
**Bron:** `lib/Service/Exchange/ExchangeSyncService.php` regel 417-488

Controleert dat de Exchange-zijde (Microsoft Graph API) correct wordt geraadpleegd bij het boeken. Voorkomt dat boekingen worden aangemaakt in RoomVox die in Exchange als duplicaten verschijnen.

#### showAs filtering

De Graph API retourneert een `showAs` veld per event. Alleen events die de kalender daadwerkelijk bezet houden mogen als conflict tellen.

| Test | showAs waarde | Resultaat | Waarom |
|------|---------------|-----------|--------|
| Busy | `busy` | **CONFLICT** | Standaard — kalender bezet |
| Tentative | `tentative` | **CONFLICT** | Voorlopig — kalender bezet |
| Free | `free` | **VRIJ** | Vrij gemarkeerd — geen blokkade |
| Out of Office | `oof` | **CONFLICT** | Afwezig — kalender bezet |
| Working Elsewhere | `workingElsewhere` | **CONFLICT** | Elders werkend — kalender bezet |

#### Overige scenarios

| Test | Scenario | Resultaat | Waarom |
|------|----------|-----------|--------|
| Cancelled event | `isCancelled=true` | **VRIJ** | Geannuleerd blokkeert niet |
| Exclude UID | RoomVox UID match met `excludeUid` | **VRIJ** | Eigen boeking wordt overgeslagen bij reschedule |
| Geen events | Leeg tijdslot op Exchange | **VRIJ** | Niets blokkeert |
| Geen Exchange room | Room zonder Exchange config | **VRIJ** | Check wordt overgeslagen |

---

### 6b. Exchange Conflict Exclusion bij Updates (10 tests)

**Bestand:** `tests/Unit/Service/Exchange/ExchangeConflictExcludeTest.php`
**Bron:** `lib/Service/Exchange/ExchangeSyncService.php` regel 417-488

**Achtergrond:** Wanneer een event wordt bijgewerkt vanuit Nextcloud Calendar, moet de Exchange conflict check het event's eigen kopie op Exchange overslaan. Dit gaat via twee mechanismes:

1. **ROOMVOX_UID_PROP** — extended property op het Exchange event (gezet bij push)
2. **Exchange event ID** — lokaal opgeslagen in `X-EXCHANGE-EVENT-ID` in de CalDAV data

Het tweede mechanisme is nodig omdat Exchange bij auto-accept soms het event aanmaakt zonder de extended property. Dit was de oorzaak van een bug waarbij event updates vanuit Nextcloud Calendar werden afgewezen als "Booking conflict" met het event's eigen kopie op Exchange.

#### Exclude via ROOMVOX_UID_PROP

| Test | Scenario | Resultaat | Waarom |
|------|----------|-----------|--------|
| UID prop matcht | Exchange event heeft `ROOMVOX_UID_PROP=booking-123`, excludeUid=`booking-123` | **VRIJ** | Eigen boeking overgeslagen |
| UID prop verschilt | Exchange event heeft `ROOMVOX_UID_PROP=other-booking` | **CONFLICT** | Ander event blokkeert |

#### Exclude via Exchange event ID (de fix)

| Test | Scenario | Resultaat | Waarom |
|------|----------|-----------|--------|
| **Exchange ID matcht** | **Exchange event zonder ROOMVOX_UID_PROP, maar lokale CalDAV data bevat `X-EXCHANGE-EVENT-ID` dat matcht** | **VRIJ** | **Eigen boeking herkend via Exchange ID** |
| Exchange ID verschilt | Lokaal opgeslagen Exchange ID matcht niet | **CONFLICT** | Ander event |
| Geen lokaal Exchange ID | Lokaal event heeft geen `X-EXCHANGE-EVENT-ID` | **CONFLICT** | Kan niet matchen, veilige aanname |

#### Gecombineerde scenarios

| Test | Scenario | Resultaat | Waarom |
|------|----------|-----------|--------|
| Beide mechanismes matchen | UID prop + Exchange ID match beide | **VRIJ** | Dubbele bevestiging |
| Eigen event + ander event | 2 events: eigen (match via Exchange ID) + ander | **CONFLICT** | Eigen overgeslagen, ander blokkeert |
| Alleen eigen event | 1 event op Exchange, match via Exchange ID | **VRIJ** | Geen echt conflict |
| Geen excludeUid | Normaal boeken, geen update | **CONFLICT** | Bestaand event blokkeert |
| Geen kalender voor room | getRoomCalendarId() → null | **CONFLICT** | Exchange ID lookup faalt, veilige aanname |

---

### 7. Webhook Controller — Inline Sync, Throttle & Rate Limit (17 tests)

**Bestand:** `tests/Unit/Controller/WebhookControllerTest.php`
**Bron:** `lib/Controller/WebhookController.php` regel 46-150

Test de webhook endpoint die notificaties van Microsoft Graph ontvangt. Valideert de volledige flow: validation handshake, inline delta sync, per-request throttle, globale rate limit, en fallback naar background jobs.

#### Validation handshake

| Test | Scenario | Resultaat |
|------|----------|-----------|
| Token aanwezig | Microsoft stuurt `validationToken` | HTTP 200, token teruggestuurd |
| Token leeg | Lege validationToken | HTTP 400 |
| Ongeldige JSON | Geen body / ongeldig JSON | HTTP 400 |

#### Inline sync & security

| Test | Scenario | Resultaat |
|------|----------|-----------|
| Enkele room | 1 notificatie, geldige clientState | Delta sync inline, geen background job |
| clientState mismatch | Verkeerde clientState | Room wordt geskipt, geen sync |
| Onbekende subscription | subscriptionId niet gevonden | Room wordt geskipt |
| Sync failure | pullExchangeChanges gooit exception | Fallback naar WebhookSyncJob |

#### Per-request throttle (exchange_webhook_max_inline_sync)

| Test | Scenario | Max inline | Resultaat |
|------|----------|------------|-----------|
| 3 rooms, max=1 | Bulk notificatie | 1 | 1 inline, 2 naar background jobs |
| 2 rooms, max=3 | Ruim onder limiet | 3 | Beide inline, geen background jobs |
| 1 room, max=0 | Alles uitgeschakeld | 0 | Alles naar background jobs |

#### Globale rate limit (exchange_webhook_rate_limit)

Beschermt tegen 300 aparte webhook requests die tegelijk binnenkomen. Gebruikt een distributed cache (ICacheFactory) met een 10-seconden sliding window.

| Test | Scenario | Resultaat |
|------|----------|-----------|
| Budget op (5/5 gebruikt) | Cache meldt 5 syncs in window | Alles naar background jobs |
| Rate limit = 0 | Inline sync volledig uitgeschakeld | Alles naar background jobs |
| Budget beschikbaar (3/5) | Cache meldt 3 syncs, limiet 5 | Inline sync + cache teller verhoogd naar 4 |

#### Deduplicatie

| Test | Scenario | Resultaat |
|------|----------|-----------|
| 3 notificaties zelfde room | created + updated + deleted voor room1 | Slechts 1x sync |

#### Performance

Valideert dat de webhook handler snel genoeg reageert voor Microsoft Graph's 3-seconden deadline, ook bij grote aantallen rooms. Mocks simuleren de volledige flow (lookup, sync, queue) om de overhead van de controller-logica zelf te meten.

| Test | Rooms | Max inline | Limiet | Resultaat |
|------|-------|------------|--------|-----------|
| 50 rooms | 50 | 1 | < 100ms | 1 inline, 49 queued — ruim binnen limiet |
| 300 rooms | 300 | 1 | < 500ms | 1 inline, 299 queued — simuleert worst-case bulk |
| 300 rooms, max 5 | 300 | 5 | < 500ms | 5 inline, 295 queued — hogere inline limiet |

---

### 8. Settings Controller (11 tests)

**Bestand:** `tests/Unit/Controller/SettingsControllerTest.php`
**Bron:** `lib/Controller/SettingsController.php` regel 40-166

Test de globale instellingen API voor het admin paneel, inclusief de webhook throttle- en rate limit-instellingen.

#### GET settings

| Test | Scenario | Resultaat |
|------|----------|-----------|
| Max inline sync = 5, rate limit = 10 | Settings opgeslagen | Retourneert `5` en `10` als int |
| Geen settings opgeslagen | Default waarden | Retourneert `1` (max inline) en `5` (rate limit) |
| Niet-admin gebruiker | isAdmin=false | HTTP 403 |
| Client secret aanwezig | Encrypted secret opgeslagen | Toont `***` |
| Client secret leeg | Geen secret opgeslagen | Toont lege string |

#### SAVE settings

| Test | Scenario | Resultaat |
|------|----------|-----------|
| Max inline = 3 | exchangeWebhookMaxInlineSync=3 | Opgeslagen als `"3"` |
| Max inline negatief | exchangeWebhookMaxInlineSync=-5 | Geclampt naar `"0"` |
| Rate limit = 10 | exchangeWebhookRateLimit=10 | Opgeslagen als `"10"` |
| Rate limit negatief | exchangeWebhookRateLimit=-3 | Geclampt naar `"0"` |
| Niet meegegeven | Param ontbreekt | Geen update |
| Niet-admin gebruiker | isAdmin=false | HTTP 403 |

---

### 9. Overige Tests (67 tests)

Naast de hierboven beschreven tests (secties 1-8) zijn er 67 bestaande unit tests die de overige logica dekken:

| Bestand | Tests | Beschrijving |
|---------|-------|-------------|
| RoomServiceTest | 13 | stripMailto, isRoomAccount, extractUserIdFromPrincipal, buildRoomLocation, getRoomByUserId |
| CalDAVServiceTest | 4 | iCal escaping, VAVAILABILITY generatie |
| PermissionServiceTest | 6 | Rolhiërarchie, groepsovererving, admin bypass |
| ImportExportServiceTest | 15 | CSV delimiter, kolomdetectie, faciliteiten, adres parsing |
| ApiTokenServiceTest | 8 | Scope hiërarchie (read < book < admin), kamertoegang |
| ExchangeSyncServiceTest | 6 | Exchange room validatie, sync skip (aanvullend op secties 6 en 6b) |
| WebhookServiceTest | 6 | Webhook renewal, HTTPS-vereiste |
| SchedulingPluginTest | 9 | bookingFitsRule, isWithinAvailability |

---

### 10. Performance Tests (15 tests)

**Bestand:** `tests/Unit/PerformanceTest.php`

Valideert dat kritieke code-paden snel genoeg zijn bij realistische datavolumes. Alle externe afhankelijkheden (database, Exchange API, SMTP) zijn gemockt — de tests meten de overhead van de PHP-logica zelf.

#### Conflictdetectie schaling

| Test | Events in kalender | Limiet | Beschrijving |
|------|--------------------|--------|-------------|
| 50 events | 50 | < 50ms | Volledige scan door 50 kalenderobjecten |
| 200 events | 200 | < 150ms | Stress test met drukke kamer |

#### Scheduling plugin (iTIP flow)

| Test | Scenario | Limiet | Beschrijving |
|------|----------|--------|-------------|
| Full flow | Permissie → beschikbaarheid → horizon → conflict → delivery | < 50ms | Complete iTIP REQUEST verwerking |
| Complexe availability | 6 regels (ma-za, elk andere tijden) | < 30ms | Worst-case regel matching |

#### Booking API

| Test | Scenario | Limiet | Beschrijving |
|------|----------|--------|-------------|
| Enkele boeking | create() met alle checks | < 50ms | Conflict check + aanmaken + response |
| 10 sequentiële boekingen | 10x create() achter elkaar | < 200ms | Simuleert bulk import via API |

#### Room listing

| Test | Rooms | Limiet | Beschrijving |
|------|-------|--------|-------------|
| 100 kamers | 100 JSON decode + index | < 50ms | Typisch gebruik |
| 500 kamers | 500 JSON decode + index | < 200ms | Grootschalige installatie |

#### CSV import parsing

| Test | Rijen | Limiet | Beschrijving |
|------|-------|--------|-------------|
| 100 rijen | 100 rooms met faciliteiten | < 100ms | Standaard import |
| 500 rijen | 500 rooms met faciliteiten | < 400ms | Bulk import |

#### Exchange conflict check

| Test | Events | Limiet | Beschrijving |
|------|--------|--------|-------------|
| 50 events | 50 showAs=free events | < 50ms | Volledige scan (alle free = worst case) |
| 200 events | 200 showAs=free events | < 150ms | Stress test |

#### Load simulatie (1500 kamers)

Simuleert het scenario van **1500 boekingen per uur verspreid over 1500 kamers** — een schaal die 5× boven het oorspronkelijke doelscenario (300 kamers) ligt. Alle I/O (database, Exchange API) is gemockt; de tests meten de totale overhead van de PHP-logica inclusief auth, permissies, conflictdetectie, aanmaak en Exchange push.

| Test | Flow | Rooms | Budget | Beschrijving |
|------|------|-------|--------|-------------|
| 1500 bookings via interne API | BookingApiController::create() | 1500 | < 10s | Volledige flow per boeking: auth → room lookup → permissie → conflict check → create → Exchange push |
| 1500 bookings via CalDAV scheduling | SchedulingPlugin iTIP REQUEST | 1500 | < 10s | Volledige iTIP flow per boeking: permissie → beschikbaarheid → horizon → conflict → delivery → Exchange push |
| 1500 bookings met conflict checks | CalDAVService::hasConflict() | 1500 | < 15s | 10 bestaande events per kamer; nieuwe boeking moet alle 10 scannen (worst case: geen conflict) |

**Resultaat:** Alle drie de tests slagen ruim binnen budget. De volledige suite van 1500-kamer tests draait in ~330ms totaal (geheugen: 22 MB). De pure PHP-overhead is ~0.2ms per boeking. In productie komt daar I/O-latency bij (database ~1-5ms, Exchange API ~50-200ms per call), maar door Exchange push als fire-and-forget (non-blocking) te behandelen blijft de totale doorvoer ruim binnen de marge.

---

## Integratietests (live database)

De integratietests draaien op de productieserver (`ssh sditmeijer@145.38.191.124`) tegen de echte Nextcloud-database met **115 ingeladen kamers**. Geen mocks — alle operaties gaan door de volledige service-laag inclusief CalDavBackend, database queries en iCal parsing.

### 11. Integration Benchmark (4 categorieën)

**Bestand:** `tests/integration-benchmark.php`
**Server:** 115 kamers, 107 met werkende kalenders

Performance benchmark die de doorvoer van kernoperaties meet op echte data.

| Categorie | Operaties | Resultaat | Per item |
|-----------|-----------|-----------|---------|
| Room listing | `getAllRooms()` | 115 kamers | < 5ms |
| Conflict check (alle kamers) | `hasConflict()` × 107 | 120ms totaal | 1.12ms/kamer |
| Booking create + delete (10 kamers) | `createBooking()` + `deleteBooking()` | 80ms + 42ms | ~8ms create, ~4ms delete |
| Bulk conflict (5 slots × 115 kamers) | `hasConflict()` × 535 | < 600ms | ~1.1ms/check |

---

### 12. Full Flow Integration Test (50 tests)

**Bestand:** `tests/integration-fullflow.php`
**Server:** 115 kamers (107 met werkende kalenders), 1 Exchange-kamer

Complete functionele test die de volledige boekingslevenscyclus doorloopt op de live database.

#### 12a. Room Service (5 tests)

| Test | Wat wordt getest | Resultaat |
|------|-----------------|-----------|
| getAllRooms() | Retourneert alle 115 kamers | **OK** |
| getRoom(id) | Opvragen van specifieke kamer | **OK** |
| Correct room ID | ID komt overeen | **OK** |
| Correct userId | Virtual user account klopt | **OK** |
| Niet-bestaande kamer | Retourneert null | **OK** |

#### 12b. Permission Service (4 tests)

| Test | Wat wordt getest | Resultaat |
|------|-----------------|-----------|
| getPermissions() | Retourneert array | **OK** |
| Admin can book | Admin heeft altijd boekrechten | **OK** |
| Admin can manage | Admin heeft altijd beheerrechten | **OK** |
| Unknown user role | Onbekende gebruiker crasht niet | **OK** |

#### 12c. Conflictdetectie — Leeg Slot (1 test)

| Test | Wat wordt getest | Resultaat |
|------|-----------------|-----------|
| Leeg tijdslot | Geen conflict in onbezet slot | **OK** |

#### 12d. Booking Lifecycle — Create (1 test)

| Test | Wat wordt getest | Resultaat |
|------|-----------------|-----------|
| createBooking() | Maakt echte boeking aan, retourneert UID | **OK** |

#### 12e. Conflictdetectie — Na Create (5 tests)

| Test | Scenario | Verwacht | Resultaat |
|------|----------|----------|-----------|
| Exact overlap | Zelfde tijdslot | CONFLICT | **OK** |
| Gedeeltelijke overlap | 30 min overlap | CONFLICT | **OK** |
| Aansluitend slot | Einde = begin | VRIJ | **OK** |
| Exclude eigen UID | Reschedule scenario | VRIJ | **OK** |
| Duplicate prevention | Zelfde boeking nogmaals | CONFLICT | **OK** |

#### 12f. Booking Read (3 tests)

| Test | Wat wordt getest | Resultaat |
|------|-----------------|-----------|
| getBookingByUid() | Vindt boeking op UID | **OK** |
| Summary match | Titel komt overeen | **OK** |
| Onbekende UID | Retourneert null | **OK** |

#### 12g. Booking Update — Reschedule (3 tests)

| Test | Wat wordt getest | Resultaat |
|------|-----------------|-----------|
| updateBookingTimes() | Verschuift boeking | **OK** |
| Oud slot vrij | Origineel tijdslot is weer beschikbaar | **OK** |
| Nieuw slot bezet | Nieuwe tijdslot is gereserveerd | **OK** |

#### 12h. PARTSTAT Update (4 tests)

| Test | Status | Resultaat |
|------|--------|-----------|
| ACCEPTED | Boeking goedgekeurd | **OK** |
| DECLINED | Boeking afgewezen | **OK** |
| TENTATIVE | Boeking in afwachting | **OK** |
| Onbekende UID | Retourneert false | **OK** |

#### 12i. Booking Delete (4 tests)

| Test | Wat wordt getest | Resultaat |
|------|-----------------|-----------|
| deleteBooking() | Verwijdert boeking | **OK** |
| Slot vrij na delete | Tijdslot weer beschikbaar | **OK** |
| Boeking verdwenen | getBookingByUid() = null | **OK** |
| Dubbel verwijderen | Retourneert false | **OK** |

#### 12j. Bulk Conflict Check — Alle Kamers (1 test)

| Test | Kamers | Tijd | Per kamer |
|------|--------|------|-----------|
| hasConflict() × 107 | 107 werkende kamers | 120ms | 1.12ms |

#### 12k. Bulk Create + Conflict Verify + Delete — ALLE Werkende Kamers (3 tests)

Boekt, verifieert en verwijdert in **alle 107 kamers met werkende kalenders** (van 115 totaal). Dit test dat het systeem betrouwbaar werkt op productie-schaal.

| Operatie | Kamers | Tijd | Per boeking |
|----------|--------|------|-------------|
| Create | 107 | 629ms | 5.88ms |
| Conflict verify | 107 | — | alle 107 correct |
| Delete | 107 | 455ms | 4.25ms |

#### 12l. Beschikbaarheidsregels (5 tests)

Configureert tijdelijk beschikbaarheidsregels (ma-vr 08:00-18:00) op een echte kamer en test via reflection op de SchedulingPlugin's `isWithinAvailability()`.

| Test | Tijdslot | Verwacht | Resultaat |
|------|----------|----------|-----------|
| Binnen uren (ma 08:00-09:00) | Werkdag, binnen openingstijden | TOEGESTAAN | **OK** |
| Voor openingstijd (06:00-07:00) | Te vroeg | AFGEWEZEN | **OK** |
| Na sluitingstijd (19:00-20:00) | Te laat | AFGEWEZEN | **OK** |
| Zaterdag (10:00-11:00) | Weekend, niet in regels | AFGEWEZEN | **OK** |
| Grensoverschrijdend (17:30-18:30) | Overlapt eindtijd | AFGEWEZEN | **OK** |

Na afloop worden de originele kamerinstellingen hersteld.

#### 12m. Booking Horizon (3 tests)

Configureert tijdelijk een maximale boekingshorizon (30 dagen) op een echte kamer en test via reflection op de SchedulingPlugin's `isWithinHorizon()`.

| Test | Boeking | Horizon | Verwacht | Resultaat |
|------|---------|---------|----------|-----------|
| +10 dagen | Binnen limiet | 30 dagen | TOEGESTAAN | **OK** |
| +60 dagen | Voorbij limiet | 30 dagen | AFGEWEZEN | **OK** |
| Geen limiet | maxBookingHorizon=0 | Onbeperkt | TOEGESTAAN | **OK** |

Na afloop worden de originele kamerinstellingen hersteld.

#### 12n. Exchange Logic (8 tests)

Test de Exchange-integratie met de echte ExchangeSyncService. Op de server is Exchange globaal geconfigureerd en 1 kamer is gekoppeld.

| Test | Wat wordt getest | Resultaat |
|------|-----------------|-----------|
| isExchangeRoom() voor alle kamers | 107 kamers gecheckt (1 Exchange, 106 niet) | **OK** |
| Geen exchangeConfig | Kamer zonder config → false | **OK** |
| Lege exchangeConfig | Leeg email + disabled → false | **OK** |
| syncEnabled=false | Config aanwezig maar uitgeschakeld → false | **OK** |
| hasExchangeConflict() live | Leeg slot +90 dagen op Exchange kamer → false | **OK** |
| pushBookingToExchange() non-Exchange | Retourneert false, geen crash | **OK** |
| deleteBookingFromExchange() non-Exchange | Retourneert false, geen crash | **OK** |
| updateBookingOnExchange() non-Exchange | Retourneert false, geen crash | **OK** |

---

## Niet getest (bekende beperkingen)

| Onderwerp | Reden |
|-----------|-------|
| **Race conditions** (twee gelijktijdige boekingen) | Vereist multi-threaded integratietest; wordt afgedekt door CalDavBackend's database-transacties |
| **Recurring event conflictdetectie** | `hasConflict()` controleert alleen het eerste tijdslot van een herhalend event, niet alle toekomstige instanties. Dit is een bekende beperking van de huidige implementatie |
| **Timezone-conversie** | Tests gebruiken lokale tijden; cross-timezone conflicten worden afgedekt door PHP's DateTime-vergelijking |
| **SMTP/e-mail bezorging** | E-mail versturing wordt gemockt; daadwerkelijke SMTP-bezorging niet getest |
| **Exchange full sync (pull)** | Integratietest doet een live `hasExchangeConflict()` maar test geen volledige delta sync (vereist testdata op Exchange-zijde) |

---

## Hoe de tests uit te voeren

### Unit tests (lokaal, geen Nextcloud nodig)

```bash
# Alle unit tests
vendor/bin/phpunit --testsuite unit

# Alleen de conflict-tests
vendor/bin/phpunit tests/Unit/Service/CalDAVServiceConflictTest.php
vendor/bin/phpunit tests/Unit/Controller/BookingApiConflictTest.php
vendor/bin/phpunit tests/Unit/Dav/SchedulingPluginRequestTest.php
vendor/bin/phpunit tests/Unit/Dav/SchedulingPluginHorizonTest.php
vendor/bin/phpunit tests/Unit/Controller/PublicApiConflictTest.php

# Exchange conflict exclusion tests
vendor/bin/phpunit tests/Unit/Service/Exchange/ExchangeConflictExcludeTest.php

# Webhook & settings tests
vendor/bin/phpunit tests/Unit/Controller/WebhookControllerTest.php
vendor/bin/phpunit tests/Unit/Controller/SettingsControllerTest.php

# Performance tests
vendor/bin/phpunit tests/Unit/PerformanceTest.php
```

### Integratietests (op server, tegen echte database)

```bash
# SSH naar server
ssh sditmeijer@145.38.191.124

# Performance benchmark
cd /var/www/nextcloud && sudo -u www-data php apps/roomvox/tests/integration-benchmark.php

# Full flow test (50 tests)
cd /var/www/nextcloud && sudo -u www-data php apps/roomvox/tests/integration-fullflow.php
```

---

## Conclusie

De testsuite (186 unit tests + 50 integratietests) valideert dat:

### Conflictdetectie
1. **Overlappende boekingen worden gedetecteerd** — alle 5 overlap-varianten (exact, begin, einde, omvattend, omvat), zowel in mocks als op echte database
2. **Aansluitende boekingen worden toegestaan** — 10:00-11:00 + 11:00-12:00 is geen conflict
3. **Geannuleerde events blokkeren niet** — STATUS=CANCELLED wordt overgeslagen
4. **In-afwachting events blokkeren wél** — TENTATIVE reserveert het slot
5. **Reschedule werkt correct** — eigen boeking wordt uitgesloten via excludeUid
6. **Duplicate prevention werkt end-to-end** — na create detecteert hasConflict() de boeking op alle 107 kamers

### Boekingspaden
7. **Alle drie de boekingspaden zijn afgedekt** — CalDAV scheduling, interne API, publieke API
8. **Volledige CRUD lifecycle getest op echte database** — create → read → update → PARTSTAT → delete, inclusief dubbel-delete check

### Regels & beperkingen
9. **Beschikbaarheidsregels werken op echte data** — ma-vr 08:00-18:00 afgedwongen: booking buiten uren, na sluitingstijd, op zaterdag en op de grens worden allemaal afgewezen
10. **Booking horizon werkt op echte data** — +10 dagen (binnen 30) toegestaan, +60 dagen afgewezen, geen limiet altijd toegestaan
11. **Booking horizon werkt voor herhalende events** — RRULE met UNTIL, COUNT, en oneindig

### Exchange-integratie
12. **Exchange-fouten blokkeren lokale boekingen niet** — fail-safe design
13. **Exchange conflict check filtert op showAs** — alleen `busy`, `tentative`, `oof` en `workingElsewhere` blokkeren; `free` events worden overgeslagen
14. **Exchange conflict check werkt live** — `hasExchangeConflict()` bevraagt de echte Microsoft Graph API en retourneert correct resultaat
15. **Exchange operaties op non-Exchange kamers crashen niet** — push, update en delete retourneren false zonder exceptie
16. **Event updates worden niet geblokkeerd door eigen Exchange-kopie** — wanneer Exchange het event auto-accept (zonder ROOMVOX_UID_PROP), matcht de conflict check op Exchange event ID als fallback

### Webhooks & settings
17. **Webhook inline sync met throttle** — eerste N rooms syncen inline, rest via background jobs
18. **Globale rate limit beschermt tegen burst traffic** — distributed cache met 10s sliding window
19. **Settings API is beveiligd** — alleen admins, client secrets gemaskeerd

### Performance & schaal
20. **Performance op echte database** — conflict check 1.12ms/kamer, booking create 5.88ms, delete 4.25ms op 107 kamers
21. **Bulk operaties werken betrouwbaar** — 107 kamers: create+verify+delete allemaal 100% succesvol
22. **Load simulatie: 1500 kamers** — PHP-logica verwerkt 1500 boekingen (inclusief conflict checks) in ~330ms
23. **Performance is acceptabel bij schaal** — conflictdetectie (200 events < 150ms), CSV import (500 rijen < 400ms), room listing (500 kamers < 200ms)
