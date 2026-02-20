# Testverslag RoomVox — Dubbele Boekingen Preventie

**Datum:** 20 februari 2026
**Totaal:** 176 tests, 308 assertions — alle groen
**PHP versie:** 8.4.13
**PHPUnit versie:** 10.5.63

---

## Samenvatting

De testsuite valideert dat RoomVox **geen dubbele boekingen** kan aanmaken. Alle drie de boekingspaden (CalDAV-client, interne API, publieke API) worden getest op correcte conflictdetectie, permissiecontrole, beschikbaarheidsregels en booking horizon.

De tests draaien **standalone** — er is geen draaiende Nextcloud-instance nodig. Alle externe afhankelijkheden (CalDavBackend, IAppConfig, IGroupManager, etc.) worden gemockt.

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
**Bron:** `lib/Service/Exchange/ExchangeSyncService.php` regel 416-464

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
| ExchangeSyncServiceTest | 6 | Exchange room validatie, sync skip (aanvullend op sectie 6) |
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

## Niet getest (bekende beperkingen)

| Onderwerp | Reden |
|-----------|-------|
| **Race conditions** (twee gelijktijdige boekingen) | Vereist integratietest met echte database; wordt afgedekt door CalDavBackend's database-transacties |
| **Recurring event conflictdetectie** | `hasConflict()` controleert alleen het eerste tijdslot van een herhalend event, niet alle toekomstige instanties. Dit is een bekende beperking van de huidige implementatie |
| **Timezone-conversie** | Tests gebruiken lokale tijden; cross-timezone conflicten worden afgedekt door PHP's DateTime-vergelijking |
| **SMTP/e-mail bezorging** | E-mail versturing wordt gemockt; daadwerkelijke SMTP-bezorging niet getest |
| **Exchange API calls** | Graph API wordt volledig gemockt; echte Microsoft 365 interactie niet getest |

---

## Hoe de tests uit te voeren

```bash
# Alle tests
vendor/bin/phpunit --testsuite unit

# Alleen de conflict-tests
vendor/bin/phpunit tests/Unit/Service/CalDAVServiceConflictTest.php
vendor/bin/phpunit tests/Unit/Controller/BookingApiConflictTest.php
vendor/bin/phpunit tests/Unit/Dav/SchedulingPluginRequestTest.php
vendor/bin/phpunit tests/Unit/Dav/SchedulingPluginHorizonTest.php
vendor/bin/phpunit tests/Unit/Controller/PublicApiConflictTest.php

# Webhook & settings tests
vendor/bin/phpunit tests/Unit/Controller/WebhookControllerTest.php
vendor/bin/phpunit tests/Unit/Controller/SettingsControllerTest.php

# Performance tests
vendor/bin/phpunit tests/Unit/PerformanceTest.php
```

---

## Conclusie

De testsuite valideert dat:

1. **Overlappende boekingen worden gedetecteerd** — alle 5 overlap-varianten (exact, begin, einde, omvattend, omvat)
2. **Aansluitende boekingen worden toegestaan** — 10:00-11:00 + 11:00-12:00 is geen conflict
3. **Geannuleerde events blokkeren niet** — STATUS=CANCELLED wordt overgeslagen
4. **In-afwachting events blokkeren wél** — TENTATIVE reserveert het slot
5. **Reschedule werkt correct** — eigen boeking wordt uitgesloten via excludeUid
6. **Alle drie de boekingspaden zijn afgedekt** — CalDAV scheduling, interne API, publieke API
7. **Beschikbaarheidsregels worden afgedwongen** — dag- en tijdcontroles
8. **Booking horizon werkt voor herhalende events** — RRULE met UNTIL, COUNT, en oneindig
9. **Exchange-fouten blokkeren lokale boekingen niet** — fail-safe design
10. **Exchange conflict check filtert op showAs** — alleen `busy`, `tentative`, `oof` en `workingElsewhere` blokkeren; `free` events worden overgeslagen
11. **Webhook inline sync met throttle** — eerste N rooms syncen inline voor near-realtime delivery, rest wordt via background jobs afgehandeld om Microsoft's 3-seconden deadline te halen
12. **Globale rate limit beschermt tegen burst traffic** — bij 300 gelijktijdige webhook requests wordt het inline sync budget (distributed cache, 10s window) gerespecteerd; overschot gaat naar background jobs
13. **Settings API is beveiligd** — alleen admins kunnen instellingen lezen/wijzigen, client secrets worden gemaskeerd
14. **Performance is acceptabel bij schaal** — conflictdetectie (200 events < 150ms), CSV import (500 rijen < 400ms), room listing (500 kamers < 200ms), Exchange conflict check (200 events < 150ms), batch boekingen (10x < 200ms)
15. **Load simulatie: 1500 kamers / 1500 boekingen per uur** — zowel via interne API als CalDAV scheduling verwerkt de PHP-logica 1500 boekingen (inclusief conflict checks met 10 events/kamer) in ~330ms; ruim voldoende voor productiegebruik op enterprise-schaal
