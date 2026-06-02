# Nextcloud 34 Compatibility Audit

Audit of RoomVox's compatibility with Nextcloud 34 (Hub 26 Spring). Performed 2026-05-18 against NC34 RC1, before NC34 GA on 2026-06-09.

**Conclusion**: RoomVox is in essence already NC34-ready. A 1.2.0 compat release will lift the version range; no API surface changes are required.

## NC34 release timeline

| Milestone | Date |
|---|---|
| Beta 1 | 2026-04-28 |
| Beta 5 | 2026-05-12 |
| RC1 | 2026-05-15 |
| **GA** | **2026-06-09** |
| EOL | 2027-06-08 |

Reference: [github.com/nextcloud/server tags](https://github.com/nextcloud/server/tags), `stable34` branch.

## Hard removals in NC34 (and RoomVox impact)

Sources: [server#58808](https://github.com/nextcloud/server/pull/58808) "remove long deprecated `IServerContainer` methods", [server#59544](https://github.com/nextcloud/server/pull/59544) "Remove long-time deprecated methods", [server#58474](https://github.com/nextcloud/server/pull/58474) "drop `OC_JSON`", [server#57648](https://github.com/nextcloud/server/pull/57648) "drop jQuery UI and jQuery".

| Category | Removed | RoomVox impact |
|---|---|---|
| `\OC::$server->getXxx()` legacy getters | `getContactsManager()`, `getRequest()`, `getRootFolder()`, `getUserManager()`, `getGroupManager()`, `getUserSession()`, `getConfig()`, `getCrypto()`, `getHasher()`, `getSecureRandom()` and ~20 more | ✅ Not used. Only PSR-11 `\OC::$server->get(Class::class)` is used (and that survives) |
| Legacy classes | `OC_JSON`, `OC_App::getCurrentApp()` | ✅ Not used |
| Sharing API | `\OCP\Share_Backend*` | ✅ Not used |
| CSP helpers | `StrictContentSecurityPolicy`, `EmptyContentSecurityPolicy::allowEvalScript`, `addAllowedChildSrcDomain` | ✅ Not used |
| Notification/Resource | `IManager::registerNotifier`, `IManager::registerResourceProvider` | ✅ Not used |
| `Util::*` | `recursiveArraySearch`, etc. | ✅ Not used |
| `OC_Util::*` | `encodePath`, `sanitizeHTML`, `redirectToDefaultPage`, `getDefaultPageUrl`, `checkAdminUser` | ✅ Not used |
| Frontend | Bundled jQuery, jQuery-UI, Backbone, Handlebars, `snapper`, `OC.Dialogs.fileexists`, `OC.Notifications`, `OC.Apps`, `OC.Files.Client` | ✅ Not used in `src/` (only minified residue in compiled `js/roomvox-*.js`, not source) |
| PHP cleanup | RFC7231 constant (for PHP 8.5) | ✅ Not used |

`\OC::$server` methods that **survive** NC34 (all that RoomVox needs): `get()` (PSR-11), `getAppContainerForService()`, `getL10N()`, `getRegisteredAppContainer()`, `getUserFolder()`, `getWebRoot()`.

## OCP API surface used by RoomVox — all stable

Stable across NC32, NC33, NC34:

- `OCP\AppFramework\*` — Bootstrap, Controller, JSONResponse, attribute-style middleware
- `OCP\BackgroundJob\TimedJob`, `QueuedJob`, `IJobList`
- `OCP\Calendar\IMetadataProvider`
- `OCP\Calendar\Room\IBackend`, `IManager`, `IRoom`, `IRoomMetadata` — **identical SHAs** in `stable33` and `stable34`
- `OCP\IAppConfig`, `IConfig`, `ICache`, `ICacheFactory`, `IDBConnection`, `IGroupManager`, `IL10N`, `IRequest`, `IURLGenerator`, `IUserManager`, `IUserSession`
- `OCP\Mail\IMailer`
- `OCP\Security\ICrypto`, `ISecureRandom`
- `OCP\Settings\ISettings`, `IIconSection`
- `OCP\User\Backend\ICheckPasswordBackend`, `ICountUsersBackend`, `IGetDisplayNameBackend`
- `OCP\EventDispatcher\Event`, `IEventListener`
- `OCP\Http\Client\IClientService`

None of these are touched by NC34's removals.

## RoomVox `\OC::$server->get()` call sites

All sites verified to use the PSR-11 generic `get()` (which survives) — none use removed `getXxx()` convenience getters.

| File:line | Call | Status |
|---|---|---|
| `lib/Service/MailService.php:196` | `\OC::$server->get(\OCP\IUserManager::class)` | ✅ Safe (PSR-11) |
| `lib/Service/MailService.php:234` | `\OC::$server->get(\OCP\IUserManager::class)` | ✅ Safe (PSR-11) |
| `tests/integration-benchmark.php` (3×) | `\OC::$server->get(SomeClass::class)` | ✅ Safe |
| `tests/integration-fullflow.php` (6×) | `\OC::$server->get(SomeClass::class)` | ✅ Safe |

The two `MailService.php` call sites are working code, but represent a service-locator pattern rather than constructor DI. They function on NC34, but a cleanup to constructor DI is recommended (see below).

## Dependencies

- **PHP** (`composer.json`): `>=8.2` ✓ (NC34 requires PHP 8.2+, ≤8.5)
- **`@nextcloud/dialogs`**: `^7.1.0` ✓ (NC34-compatible)
- **`@nextcloud/vue`**: `^9.1.0` ✓ (NC34-compatible)
- No jQuery, Backbone, Handlebars or snapper packages in `package.json`

## Relevant upstream CalDAV / iTIP fixes in NC34

Not required for compatibility, but worth knowing — RoomVox runs at scheduling priority 99 and intercepts iTIP messages before Sabre's own handler, so most upstream iTIP changes are isolated from us. Worth smoke-testing on NC34 nonetheless:

- [server#50843](https://github.com/nextcloud/server/pull/50843) — iTipBroker message generation refactor. Verify our `SchedulingPlugin` doesn't conflict.
- [server#59299](https://github.com/nextcloud/server/pull/59299) — null-safe `ORGANIZER` handling. Confirms our own issue #5 fix direction.
- [server#58203](https://github.com/nextcloud/server/pull/58203) — `x-nc-scheduling` flag now honored on delete. Future use case: suppress iTIP delivery for admin-initiated deletes (issue #10 already mails directly).
- [server#59142](https://github.com/nextcloud/server/pull/59142) — `ICalendar::search` now allows search by event URI. Future optimization for `CalDAVService::getBookingByUid()` if performance becomes an issue.

None of these are blockers; all are upstream improvements that may benefit RoomVox over time.

## Changes required for 1.2.0 release

### Mandatory

1. **[appinfo/info.xml:54](../../appinfo/info.xml)** — bump:
   ```xml
   <nextcloud min-version="32" max-version="34"/>
   ```
   (was `max-version="33"`). Range stays NC 32–34.

2. **Version bump** in `package.json` and `appinfo/info.xml`: `1.1.0` → `1.2.0`.

3. **CHANGELOG entry** under a new `## [1.2.0]` section:

   ```markdown
   ### Compatibility
   - **Nextcloud 34 support**: declared in info.xml. No API surface changes were
     needed — RoomVox uses only PSR-11 `\OC::$server->get()` (which survives
     NC34's removal of legacy `getXxx()` convenience methods) and stable OCP
     interfaces (`Calendar\Room\IBackend`/`IRoom` unchanged between stable33
     and stable34).
   ```

4. **Live verification on a `nc-34-dev` container** before release: provision a
   fresh Hetzner container with NC34 GA, deploy RoomVox 1.2.0 via `docker cp`,
   then smoke-test the full booking flow and tail `nextcloud.log` for any
   `Call to undefined method` errors. See [Admin: Installation](../deployment/installation.md)
   for deploy steps.

### Optional cleanup (may slip to a later release)

5. **`lib/Service/MailService.php`** — replace the two `\OC::$server->get(\OCP\IUserManager::class)` calls with constructor DI. Works as-is under NC34, but matches the canonical NC convention. ~5 line diff.

### Out of scope for the 1.2.0 release

6. **Calendar patch (`nc-calendar-patch/`)** — pinned to NC Calendar `v6.2.0` in [deploy-calendar.sh](../../deploy-calendar.sh). NC34 likely ships a newer Calendar (`v6.3.x` or `v7.x.x`). Re-bake the patch on the new upstream, separately from the RoomVox app release. See [Admin: Calendar Patch](../features/calendar-patch.md).

7. **NC35 forward-compatibility** — `stable35` branch does not exist as of 2026-05-18, no documentation. Re-audit when NC35 beta appears (typically Q4 2026).

8. **Adoption of NC34-only APIs** — `OCP\DB\QueryBuilder\ITypedQueryBuilder` and `OCP\Migration\IRepairStepExpensive` are interesting but unavailable on NC32/33. Adopting them would force `min-version=34`, dropping support for two LTS-ish versions. Not worth it for marginal gains.

## Verification

### Local (unchanged)

```bash
cd /Users/rikdekker/Documents/Development/RoomVox
vendor/bin/phpunit --testsuite unit
# Expected: identical baseline (no NC34 regression)
```

### nc-34-dev (Hetzner)

```bash
ssh hetzner-ax42 'docker exec nc-34-dev tail -f /var/www/html/data/nextcloud.log | grep -i roomvox'
# Expected: no "Call to undefined method" for \OC::$server->getXxx()
```

End-to-end smoke after deploy of 1.2.0:

1. `Settings → Administration → RoomVox` renders without errors.
2. `Settings → Personal → RoomVox` (3 tabs: My Rooms, Approvals, Bookings) renders.
3. Booking via NC Calendar with a room → room calendar receives the event.
4. iCal feed `/apps/roomvox/api/v1/rooms/{id}/calendar.ics` returns a valid VCALENDAR.
5. Notification mails (accept/decline/cancel) deliver correctly.

## Summary table

| Aspect | Status |
|---|---|
| Legacy `\OC::$server->getXxx()` calls | ✅ None — all sites use PSR-11 `get()` |
| Hard-removed PHP APIs | ✅ Zero matches |
| Hard-removed JS APIs | ✅ Zero matches in `src/` |
| `OCP\Calendar\Room\*` interfaces | ✅ Unchanged stable33 ↔ stable34 |
| Composer / npm dependencies | ✅ Compatible (PHP 8.2+, vue@9, dialogs@7) |
| `appinfo/info.xml` max-version | ⚠️ Bump from 33 to 34 in 1.2.0 |
| `MailService` service-locator pattern | ⚠️ Optional DI cleanup (still works on NC34) |
| Calendar-patch upstream pin | ⚠️ Separate workstream (depends on NC Calendar release for NC34) |
