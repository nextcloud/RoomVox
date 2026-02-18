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

### Service Tests

| Bestand | Tests | Beschrijving |
|---------|-------|--------------|
| `RoomServiceTest.php` | 13 | CRUD helpers, slug generatie, `stripMailto()`, `isRoomAccount()`, `extractUserIdFromPrincipal()`, `buildRoomLocation()` |
| `CalDAVServiceTest.php` | 4 | iCal escaping (`escapeIcsText`), VAVAILABILITY generatie voor beschikbaarheidsregels |
| `PermissionServiceTest.php` | 6 | Rolberekening (Viewer/Booker/Manager), groepsovererving via `IGroupManager`, admin bypass |
| `ImportExportServiceTest.php` | 13 | CSV delimiter detectie, kolom mapping (RoomVox/MS365 formaat), faciliteit normalisatie, adres parsing |
| `ApiTokenServiceTest.php` | 8 | Scope hierarchie (read < book < admin), kamer-specifieke en globale toegang |

### Exchange Sync Tests

| Bestand | Tests | Beschrijving |
|---------|-------|--------------|
| `ExchangeSyncServiceTest.php` | 6 | Validatie of een kamer Exchange-sync mag uitvoeren: globale toggle, per-kamer config, e-mail vereiste |
| `WebhookServiceTest.php` | 6 | Webhook renewal logica (verlopen/vers/geen subscription), notificatie-URL generatie (HTTPS-vereiste) |

### DAV Tests

| Bestand | Tests | Beschrijving |
|---------|-------|--------------|
| `SchedulingPluginTest.php` | 9 | Beschikbaarheidsregels: `bookingFitsRule()` (dag/tijd matching), `isWithinAvailability()` (meerdere regels, uitgeschakeld) |

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
