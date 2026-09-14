# Nextcloud 35 Compatibility Audit

Audit of RoomVox's compatibility with Nextcloud 35 (Hub 26 Summer). Performed 2026-09-10 against a **running Nextcloud 35.0.0 RC3 on PHP 8.5.9**, before NC35 GA on 2026-09-16.

**Conclusion**: RoomVox runs on NC35 unchanged. The audit ran against the 1.4.x tree; the raised ceiling shipped in 1.5.0. Only the declared `max-version` blocked installation; no API surface changes are required.

This closes item 7 of [NC 34 Compatibility](./nc34-compatibility.md) ("re-audit when NC35 beta appears").

## NC35 release timeline

| Milestone | Date |
|---|---|
| Beta 1 | 2026-08-11 |
| RC1 | 2026-08-25 |
| RC3 | 2026-09-03 |
| RC4 | 2026-09-10 |
| **GA** | **2026-09-16** |

Reference: [Maintenance and Release Schedule](https://github.com/nextcloud/server/wiki/Maintenance-and-Release-Schedule).

## How this was verified

Unlike the NC34 audit, which was a source-level review, this audit was performed **against a live instance** — the `nc-next` container on Hetzner AX42, running 35.0.0 RC3. Static review alone cannot catch the failure mode that matters most: when an OCP interface gains a method, the class implementing it silently becomes abstract and fatals on load. That is what a running instance surfaces and a grep does not.

| Check | Result |
|---|---|
| All 50 `OCP\` symbols imported by `lib/` resolve | 50 / 50 |
| All 44 `lib/` classes load | 44 / 44, zero fatals |
| Classes unexpectedly abstract (missing interface method) | 0 |
| `occ app:enable roomvox` | Succeeds |
| `/status.php` and `/login` after enable | Both 200 |
| `roomvox`-scoped entries in `nextcloud.log` | 0 |
| CalDAV room backend registers | ✅ `RoomBackend (id=roomvox)` |
| Create room → virtual user → CalDAV exposure | ✅ Full chain works |
| Room metadata published | ✅ Including `room-building-story` |
| `IRoom` / `IBackend` / `IMetadataProvider` implemented in full | ✅ 3 / 3, no missing methods |

The `/login` check is deliberate: a broken app can leave `/status.php` answering 200 while `/login` returns 500, so checking only the former hides the failure.

## API changes in NC35 (and RoomVox impact)

| Change | RoomVox impact |
|---|---|
| `IBootContext::getServerContainer()` retyped from `IServerContainer` to `Psr\Container\ContainerInterface` (`@since 35.0.0`) | ✅ No change needed. [`Application.php`](../../lib/AppInfo/Application.php) only calls `->get(...)`, which `ContainerInterface` provides |
| `IUserManager::get()` now declares `: ?\OCP\IUser`; `userExists()` gained optional `$excludeBackends` | ✅ Consumer-side narrowing, harmless |
| `ICrypto` parameters gained `#[SensitiveParameter]` | ✅ No signature break |
| `IClientService::newClient()` gained optional `$handler` | ✅ Not used |

**Nothing RoomVox uses was removed in NC35.**

### Newly deprecated (still present, no action required)

| API | Status | Where |
|---|---|---|
| `ISecureRandom::generate` | `@deprecated 35.0.0` → `Randomizer::getBytesFromString()` | `ApiTokenService`, `RoomService` (4 call sites) |
| `ICountUsersBackend` | `@deprecated 31.0.0` → `ILimitAwareCountUsersBackend` | [`RoomUserBackend.php`](../../lib/UserBackend/RoomUserBackend.php) |
| `IConfig::getAppValue` / `setAppValue` / `deleteAppValue` | `@deprecated 29.0.0` → `IAppConfig` | `LicenseService`, `TelemetryService`, `SettingsController` |
| `Calendar\Room\IManager::getBackends` | `@deprecated 24.0.0` | One call site |

These are cleanup candidates for a later release, not blockers. All four still function in NC35.

## Sabre / CalDAV

The scheduling plugin's priority-99 assumption still holds: NC35's `apps/dav/lib/CalDAV/Schedule/Plugin.php` calls `parent::initialize()` and adds only `propFind` (90) plus write handlers — it does not re-register or re-prioritise the `schedule` hook at 100. All ten `CalDavBackend` methods RoomVox calls are present with identical signatures.

`RoomVisibilityPlugin`'s reason to exist is unchanged: `AbstractPrincipalBackend::getPrincipalsByPrefix` still selects only `id, backend_id, resource_id, email, displayname` without applying `group_restrictions`, while `searchPrincipals` / `findByUri` do filter. The workaround is still required and still correct.

## PHP

NC35 requires **PHP 8.3–8.5** (`lib/versioncheck.php` rejects `< 80300` and `>= 80600`).

RoomVox declares `php min-version="8.2"`. This is **not** a conflict: `DependencyAnalyzer` treats the value purely as a floor, erroring only when the server's PHP is *lower* than declared. There is no upper bound, so 8.2 imposes nothing on an 8.3/8.4/8.5 server. IntraVox (8.2) and MetaVox (8.1) likewise kept their floors when they declared NC35 support.

Verified empirically: the app runs on the RC3 container's PHP 8.5.9, and the unit suite is green on PHP 8.5.4 locally.

## Frontend

RoomVox bundles its frontend through webpack, so `@nextcloud/vue` is compiled in rather than shared with the server at runtime — there is no version negotiation to break. For the record, the majors match: RoomVox ships `@nextcloud/vue` 9.x and Vue 3.5.x; NC35 RC3 ships `@nextcloud/vue` ^9.10.0 and Vue ^3.5.41.

## Follow-up (not blocking this release)

1. **Adopt the NC35 replacements** for the four deprecated APIs listed above. Note `ISecureRandom::generate` and `ILimitAwareCountUsersBackend` are unavailable on NC32, so migrating either forces `min-version` up. Not worth it while NC32 is still supported.
2. **Calendar patch** (`nc-calendar-patch/`) is pinned to a specific NC Calendar release and is versioned separately from the app. Re-bake on the NC35 Calendar release as its own workstream.

## Summary table

| Aspect | Status |
|---|---|
| `OCP\` symbols resolved on NC35 | ✅ 50 / 50 |
| Classes loading without fatal | ✅ 44 / 44 |
| Classes unexpectedly abstract | ✅ 0 |
| Removed APIs in use | ✅ None |
| `IBootContext` retyping | ✅ No impact — PSR-11 `get()` only |
| CalDAV room backend + metadata | ✅ Verified on running RC3 |
| Sabre scheduling priority 99 | ✅ Assumption still holds |
| PHP floor 8.2 vs NC35's 8.3 | ✅ Floor only, no conflict |
| `@nextcloud/vue` / Vue majors | ✅ Same majors as NC35 |
| `appinfo/info.xml` max-version | ⚠️ Bumped from 34 to 35 in this release |
| Newly deprecated APIs | ⚠️ Four, all still functional — cleanup later |
