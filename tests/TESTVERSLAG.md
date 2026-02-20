# Testverslag RoomVox — Dubbele Boekingen Preventie

**Datum:** 20 februari 2026
**Totaal:** 124 tests, 201 assertions — alle groen
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

### 6. Bestaande Tests (67 tests)

Naast de nieuwe conflict-tests zijn er 67 bestaande tests die de overige logica dekken:

| Bestand | Tests | Beschrijving |
|---------|-------|-------------|
| RoomServiceTest | 13 | stripMailto, isRoomAccount, extractUserIdFromPrincipal, buildRoomLocation, getRoomByUserId |
| CalDAVServiceTest | 4 | iCal escaping, VAVAILABILITY generatie |
| PermissionServiceTest | 6 | Rolhiërarchie, groepsovererving, admin bypass |
| ImportExportServiceTest | 13 | CSV delimiter, kolomdetectie, faciliteiten, adres parsing |
| ApiTokenServiceTest | 8 | Scope hiërarchie (read < book < admin), kamertoegang |
| ExchangeSyncServiceTest | 6 | Exchange room validatie, sync skip |
| WebhookServiceTest | 6 | Webhook renewal, HTTPS-vereiste |
| SchedulingPluginTest | 9 | bookingFitsRule, isWithinAvailability |

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
