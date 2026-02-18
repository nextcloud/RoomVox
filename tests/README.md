# RoomVox Unit Tests

PHPUnit 10 testsuite voor de RoomVox Nextcloud app. Alle tests draaien standalone — er is **geen draaiende Nextcloud-instance nodig**. Alle Nextcloud interfaces worden gemockt met PHPUnit mocks.

## Vereisten

- PHP 8.2+
- Composer

## Uitvoeren

```bash
# Installeer dev dependencies (eenmalig)
composer install

# Alle unit tests
vendor/bin/phpunit --testsuite unit

# Specifiek testbestand
vendor/bin/phpunit tests/Unit/Service/RoomServiceTest.php

# Met code coverage rapport (vereist Xdebug of PCOV)
vendor/bin/phpunit --coverage-html build/report
```

## Testoverzicht

**67 tests, 130 assertions** verdeeld over 8 testbestanden.

---

### RoomServiceTest — 13 tests

Test de core kamer-logica in `lib/Service/RoomService.php`.

| Test | Wat wordt gevalideerd |
|------|----------------------|
| `testStripMailto` | Verwijdert het `mailto:` prefix correct (case-insensitive), bijv. `MAILTO:user@example.com` → `user@example.com` |
| `testStripMailtoWithoutPrefix` | Retourneert het e-mailadres ongewijzigd als er geen `mailto:` prefix is |
| `testIsRoomAccountValid` | Herkent een geldig kamer-account (`rb_*` prefix) als room-principal |
| `testIsRoomAccountInvalidPrefix` | Wijst gewone gebruikersaccounts (zonder `rb_` prefix) af |
| `testIsRoomAccountNonExistent` | Retourneert `false` als de kamer niet bestaat in de configuratie |
| `testExtractUserIdFromPrincipal` | Parseert CalDAV principal URIs (`principals/users/rb_xxx`) naar userId |
| `testExtractUserIdFromPrincipalUnknown` | Retourneert `null` voor onbekende principal-formaten |
| `testExtractUserIdFromMailto` | Matcht `mailto:` adressen aan kamer-userId via de gecachte email→userId map |
| `testBuildRoomLocationFullAddress` | Bouwt locatiestring op met straat, postcode, gebouw en kamernummer: `"Straat 1, 1234 AB (Gebouw, Kamer 3.01)"` |
| `testBuildRoomLocationNoAddress` | Valt terug op de kamernaam als er geen adresgegevens zijn |
| `testBuildRoomLocationOnlyBuilding` | Handelt gedeeltelijke adresdata correct af (alleen gebouw + kamernummer) |
| `testGetRoomByUserId` | Haalt de juiste kamerconfiguratie op via userId |
| `testGetRoomByUserIdInvalidPrefix` | Retourneert `null` voor niet-kamer userIds |

---

### CalDAVServiceTest — 4 tests

Test iCal-generatie in `lib/Service/CalDAVService.php`.

| Test | Wat wordt gevalideerd |
|------|----------------------|
| `testEscapeIcsText` | Escaped speciale tekens voor iCal-formaat: newlines → `\n`, puntkomma's → `\;`, komma's → `\,`, backslashes → `\\` |
| `testBuildVAvailability` | Genereert een geldig VCALENDAR met VAVAILABILITY-blok voor beschikbaarheidsregels (ma-vr 09:00-17:00 → `RRULE:FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR`) |
| `testBuildVAvailabilityEmptyRules` | VAVAILABILITY zonder AVAILABLE-blokken als er geen regels zijn gedefinieerd |
| `testBuildVAvailabilityMultipleRules` | Meerdere beschikbaarheidsregels (werkdagen + zaterdag) genereren meerdere AVAILABLE-blokken; laat ORGANIZER weg als e-mail leeg is |

---

### PermissionServiceTest — 6 tests

Test het rollenmodel in `lib/Service/PermissionService.php`.

| Test | Wat wordt gevalideerd |
|------|----------------------|
| `testGetEffectiveRoleManager` | Een gebruiker met directe `manager`-toewijzing krijgt de manager-rol |
| `testGetEffectiveRoleBooker` | Een `booker` mag boeken en bekijken, maar niet beheren |
| `testGetEffectiveRoleViaGroupMembership` | Rollen worden overgeërfd via Nextcloud-groepen: als groep `staff` de `booker`-rol heeft en de gebruiker lid is van `staff`, krijgt die ook `booker` |
| `testCanViewBookManage` | Valideert de rolhiërarchie: viewer kan alleen bekijken, booker kan bekijken+boeken, manager kan alles |
| `testAdminBypassesPermissions` | Nextcloud-admins krijgen altijd alle rechten, ongeacht expliciete roltoewijzingen |
| `testEffectivePermissionsMergesGroupPerms` | Kamer-level en groep-level permissies worden samengevoegd tot effectieve permissies (union) |

---

### ImportExportServiceTest — 13 tests

Test CSV-import/export logica in `lib/Service/ImportExportService.php`.

| Test | Wat wordt gevalideerd |
|------|----------------------|
| `testNormalizeFacilityDirectMatch` | Directe match op faciliteitsnaam (case-insensitive), bijv. `"Projector"` → `"projector"` |
| `testNormalizeFacilityAlias` | Aliassen worden correct vertaald: `"beamer"` → `"projector"`, `"tv"` → `"display-screen"` |
| `testNormalizeFacilityUnknown` | Onbekende faciliteiten worden lowercase teruggegeven |
| `testNormalizeFacilityEmpty` | Lege strings en whitespace retourneren `null` |
| `testDetectDelimiterComma` | Herkent komma als delimiter in CSV-headers |
| `testDetectDelimiterSemicolon` | Herkent puntkomma als delimiter (Europees CSV-formaat) |
| `testDetectDelimiterTab` | Herkent tab als delimiter (TSV-bestanden) |
| `testDetectDelimiterNoDelimiter` | Valt terug op komma als geen delimiter gevonden wordt |
| `testDetectColumnMappingRoomVox` | Herkent het RoomVox CSV-formaat aan kolommen als `name`, `email`, `capacity` |
| `testDetectColumnMappingMs365` | Herkent het MS365/Exchange formaat aan kolommen als `DisplayName`, `PrimarySmtpAddress`, `Capacity` |
| `testDetectColumnMappingUnknown` | Retourneert `unknown` als geen herkenbaar formaat wordt gevonden |
| `testParseAddressFull` | Parseert een volledig adres naar onderdelen: `"Keizersgracht 100, 1015 AA Amsterdam"` → building, street, postal code, city |
| `testParseAddressEmpty` | Lege adressen retourneren een leeg resultaat |

---

### ApiTokenServiceTest — 8 tests

Test de API-token autorisatie in `lib/Service/ApiTokenService.php`.

| Test | Wat wordt gevalideerd |
|------|----------------------|
| `testHasScopeReadCanRead` | Een token met `read`-scope mag lezen |
| `testHasScopeReadCannotBook` | Een token met `read`-scope mag **niet** boeken of beheren |
| `testHasScopeBookCanReadAndBook` | Een token met `book`-scope mag zowel lezen als boeken, maar niet beheren |
| `testHasScopeAdminCanDoAll` | Een token met `admin`-scope heeft volledige toegang (read + book + admin) |
| `testHasScopeUnknownScope` | Een onbekende scope geeft nergens toegang toe |
| `testHasRoomAccessAllRooms` | Een leeg `roomIds`-veld betekent toegang tot **alle** kamers |
| `testHasRoomAccessSpecificRoomAllowed` | Een token met specifieke kamer-IDs geeft alleen toegang tot die kamers |
| `testHasRoomAccessSpecificRoomDenied` | Kamers die niet in de token-lijst staan worden geweigerd |

---

### ExchangeSyncServiceTest — 6 tests

Test de Exchange-synchronisatie validatie in `lib/Service/Exchange/ExchangeSyncService.php`.

| Test | Wat wordt gevalideerd |
|------|----------------------|
| `testIsExchangeRoomEnabled` | Een kamer met Exchange-sync enabled, geconfigureerde GraphApiClient en resourceEmail retourneert `true` |
| `testIsExchangeRoomDisabledGlobally` | Retourneert `false` als de GraphApiClient niet geconfigureerd is (geen tenant/client credentials) |
| `testIsExchangeRoomNoConfig` | Retourneert `false` als de kamer geen `exchangeConfig` heeft |
| `testIsExchangeRoomSyncDisabled` | Retourneert `false` als `syncEnabled` op `false` staat in de kamer-config |
| `testIsExchangeRoomNoEmail` | Retourneert `false` als het `resourceEmail` veld leeg is |
| `testPullChangesSkipsNonExchangeRoom` | `pullExchangeChanges()` retourneert een leeg SyncResult (0/0/0) als de kamer geen Exchange-kamer is |

---

### WebhookServiceTest — 6 tests

Test de webhook-lifecycle in `lib/Service/Exchange/WebhookService.php`.

| Test | Wat wordt gevalideerd |
|------|----------------------|
| `testNeedsRenewalExpired` | Een webhook waarvan de expirationDateTime in het verleden ligt moet hernieuwd worden |
| `testNeedsRenewalExpiringWithin36Hours` | Een webhook die binnen 36 uur verloopt moet hernieuwd worden (safety margin) |
| `testNeedsRenewalFresh` | Een webhook die pas over 48+ uur verloopt hoeft **niet** hernieuwd te worden |
| `testNeedsRenewalNoSubscription` | Retourneert `false` als er geen actief webhook-abonnement is (`webhookExpiresAt` is null) |
| `testGetNotificationUrlHttps` | Genereert een correcte HTTPS notificatie-URL voor Microsoft Graph |
| `testGetNotificationUrlRejectsHttp` | Weigert HTTP-URLs — Microsoft Graph vereist HTTPS; retourneert `null` |

---

### SchedulingPluginTest — 9 tests

Test de boekingsvalidatie in `lib/Dav/SchedulingPlugin.php`.

| Test | Wat wordt gevalideerd |
|------|----------------------|
| `testBookingFitsRuleWeekday` | Een boeking op maandag 09:00-10:00 past binnen een ma-vr 08:00-18:00 regel |
| `testBookingFitsRuleWeekendRejected` | Een boeking op zaterdag wordt afgewezen door een ma-vr regel |
| `testBookingFitsRuleOutsideHours` | Een boeking van 07:00-08:00 wordt afgewezen als de regel pas om 08:00 begint |
| `testBookingFitsRuleEndAfterRuleEnd` | Een boeking van 17:00-19:00 wordt afgewezen als de regel om 18:00 eindigt |
| `testBookingFitsRuleEmptyDays` | Een regel zonder toegestane dagen wijst altijd af |
| `testIsWithinAvailabilityNoRules` | Als beschikbaarheidsregels uitgeschakeld zijn, is elke boeking toegestaan |
| `testIsWithinAvailabilityAllowed` | Een boeking die binnen minstens één actieve regel valt wordt geaccepteerd |
| `testIsWithinAvailabilityRejected` | Een boeking buiten alle regels (zaterdag bij alleen ma-vr regels) wordt afgewezen |
| `testIsWithinAvailabilityMultipleRules` | Bij meerdere regels (ma-vr + za) wordt een zaterdagboeking geaccepteerd als die binnen de za-regel valt |

---

## Architectuur

### Standalone approach

De tests hebben **geen Nextcloud server** nodig. Dit wordt bereikt door:

1. **PHPUnit mocks** — Alle Nextcloud interfaces (`IAppConfig`, `IGroupManager`, `IURLGenerator`, etc.) worden gemockt
2. **Sabre stubs** (`tests/stubs/sabre.php`) — Minimale class definities voor `Sabre\DAV\ServerPlugin`, `Sabre\VObject\Reader`, `CalDavBackend`, etc.
3. **OCP autoloader** (`tests/bootstrap.php`) — Handmatige autoloader voor `OCP\` en `NCU\` namespaces uit het `nextcloud/ocp` package

### Bestandsstructuur

```
phpunit.xml                                  # PHPUnit 10 configuratie
tests/
├── bootstrap.php                            # Autoloader + stubs setup
├── README.md                                # Dit bestand
├── stubs/
│   └── sabre.php                            # Sabre DAV/VObject stubs
└── Unit/
    ├── Dav/
    │   └── SchedulingPluginTest.php         # Booking rule validatie
    └── Service/
        ├── ApiTokenServiceTest.php          # API token scopes & access
        ├── CalDAVServiceTest.php            # iCal generatie
        ├── ImportExportServiceTest.php      # CSV import/export
        ├── PermissionServiceTest.php        # Rollen & permissies
        ├── RoomServiceTest.php              # Kamer CRUD helpers
        └── Exchange/
            ├── ExchangeSyncServiceTest.php  # Exchange sync validatie
            └── WebhookServiceTest.php       # Webhook lifecycle
```

## CI / Gitea Actions

De workflow `.gitea/workflows/tests.yml` draait automatisch bij push naar `main` en bij pull requests:

- **phpunit** — PHP 8.2 + 8.3 matrix, `composer install`, `vendor/bin/phpunit`
- **frontend** — Node 20, `npm ci`, `npm run build`

## Private methods testen

Sommige tests gebruiken `ReflectionMethod` om private methoden te testen. Dit is een bewuste keuze: deze methoden bevatten core business logic (beschikbaarheidsregels, iCal generatie, CSV parsing) die direct gevalideerd moet worden, onafhankelijk van de publieke API.
