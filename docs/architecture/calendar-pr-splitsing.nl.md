# Nextcloud Calendar PR-splitsing — Visuele Room Finder

Documentatie van het opsplitsen van [nextcloud/calendar#7996](https://github.com/nextcloud/calendar/pull/7996) in twee gerichte, reviewbare PR's, en de status na indiening.

> **Herkomst:** dit document stond ongecommit in `NC-MS365-calendar`, een repo
> die per 23-08-2026 is opgegaan in ViaVox. De rest van die code zit in ViaVox,
> maar dit stuk niet — het beschrijft werk in `calendar-pr` (fork van
> nextcloud/calendar) en RoomVox. Daarom hier ondergebracht in plaats van
> verloren te gaan. Voor de broncode zie de respectievelijke repo's.

## Aanleiding

PR #7996 in `nextcloud/calendar` bundelde vier onafhankelijke verbeteringen in één commit:
1. Mapping van CalDAV room metadata naar het frontend principal-model
2. Visuele room finder ter vervanging van de search-only resource picker
3. Modal-layout fixes in `EditFull.vue` (auto-height, header-hide, overflow)
4. Hybrid meeting toggles (In-person + Online via Talk)

De PR liep vast op review-feedback van drie maintainers:

| Reviewer | Datum | Strekking |
|---|---|---|
| Sebastian Krupinski | 2026-02-19 | Te veel info in een hoekje; gebruik liever een modal/aparte plek. Code-niveau: TS+Composition+`<script setup>`, ongebruikte files weghalen, copyright-jaar fixen, `isViewedByOrganizer` guard behouden |
| Jan Borchardt ([review-3853776138](https://github.com/nextcloud/calendar/pull/7996#pullrequestreview-3853776138)) | 2026-02-25 | "Very very busy", graag MS Outlook-stijl rechtersidebar met `NcSelect` dropdowns voor Building/Features (geen filter chips). Latere variant: "Room finder"-knop in de rechterkolom op hetzelfde niveau als sharing-input |
| Nimisha Vijay | 2026-04-13 | Scope inperken tot **alleen room management** zodat developers het kunnen reviewen. UI volgens Jan's feedback (Outlook-stijl, NC-design language) |

PR #7996 is daarna 7+ weken stil blijven liggen. Doel van deze sessie: PR opsplitsen, UI alsnog conform Jan's review, indienen en #7996 sluiten.

## Aanpak: 2 gestapelde PR's

### PR #8263 — `feat(principal): map CalDAV room metadata properties`

**Commit:** `a66d94fb0` op fork-branch `feature/room-metadata`
**Risico:** laag · onafhankelijk · 2 files, +177 regels (incl. tests)

Mapt 7 standaard CalDAV-properties van `dav` naar het principal-object: `roomSeatingCapacity`, `roomType`, `roomFeatures`, `roomBuildingAddress`, `roomBuildingName` (afgeleid uit eerste segment), `roomNumber`, `roomAddress` (samengesteld). Defensieve sanitization:
- `roomBuildingAddress` strip leading/trailing whitespace + komma's via `replace(/^[\s,]+|[\s,]+$/g, '')`
- `roomType`, `roomFeatures`, `roomNumber` getrimd; lege strings → `null`
- `roomSeatingCapacity` blijft as-is (kan number of string zijn)

Defaults voor non-room principals zijn `null`, dus volledig backward compatible.

**Files:**
- `src/models/principal.js` — +42 regels
- `tests/javascript/unit/models/principal.test.js` — +135 regels (bestaande tests aangevuld + nieuwe test voor leading-comma input)

### PR #8264 — `feat(resources): browsable room finder with availability and dropdown filters`

**Commit:** `3edf2ae84` op fork-branch `feature/visual-room-browser-v2` (gestapeld op PR #8263)
**Risico:** medium · hangt af van PR #8263 · 6 files, +860/-753 regels

Vervangt de search-based picker met een browsbare lijst in de bestaande rechterkolom van `EditFull.vue`. Alle room principals laden bij mount; availability via `checkResourceAvailability()` uit `freeBusyService.js` (bestaande service). Filters via `NcSelect` dropdowns (Building/Floor/Features), tekst-zoekveld, "Available only"-toggle, minimum capaciteit. Selectie van een ruimte vult LOCATION via `roomAddress` (uit PR #8263).

**Files:**
- `src/components/Editor/Resources/ResourceList.vue` — herschreven (244 → ~600 regels), TypeScript Composition API + `<script setup>`
- `src/components/Editor/Resources/ResourceRoomCard.vue` — nieuw (~205 regels), TS+Composition
- `src/components/Editor/Resources/ResourceListItem.vue` — verwijderd
- `src/components/Editor/Resources/ResourceListSearch.vue` — verwijderd (294 regels)
- `src/models/resourceProps.js` — `formatFacility()` helper, uitgebreide `getAllRoomTypes()` (+37 regels)
- `vitest.config.js` — coverage stabiliteit fixes (+15)

### Wat is bewust **uit** scope gehouden

Per Nimisha's verzoek:
- ❌ `EditFull.vue` modal-CSS (auto-height, modal-header hide, time picker alignment, attendees overflow)
- ❌ Hybrid meeting toggles (In-person/Online switches, AddTalkModal-flow, RFC 7986 CONFERENCE-property)
- ❌ Aanpassingen aan `PropertyTitleTimePicker.vue`, `InviteesChipList.vue`, `AttendeeChip.vue`, `eventClick.js`, `select.js`

Deze worden later in eigen PR's voorgesteld zodra de room finder gemerged is.

## Status na indiening

| PR | Status | URL |
|---|---|---|
| **#7996** (origineel) | ✅ gesloten met verwijzing naar #8263 + #8264 | https://github.com/nextcloud/calendar/pull/7996 |
| **#8263** principal mapping | ✅ alle CI checks groen, wacht op reviewer-actie | https://github.com/nextcloud/calendar/pull/8263 |
| **#8264** room finder | ✅ alle CI checks groen, wacht op reviewer-actie | https://github.com/nextcloud/calendar/pull/8264 |

CI-checks die we onderweg gefixt hebben:
- **DCO fail** op beide PR's: commit-author was `Rikdekker <noreply>`, sign-off was `Rik Dekker <rik@rikdekker.nl>`. Mismatch → DCO eist gelijke email. Opgelost met `git commit --amend --reset-author` met `git -c user.email=rik@rikdekker.nl`.
- **ESLint fail** op #8264: `:close-on-select="false"` op `NcSelect` veroorzaakte twee errors (`vue/attribute-hyphenation` + `@nextcloud/vue/no-deprecated-props`). Opgelost door `:keepOpen="true"` te gebruiken (functioneel equivalent voor multi-select).
- **Build release tarball OOM** op #8264 (eenmalig): webpack heap-out-of-memory tijdens `krankerl package` op CI runner. Lokaal geen issue (build slaagt binnen 2GB). Rerun zonder code-changes was succesvol → transient runner-resource issue, geen probleem in onze code.

Live test-omgeving: **dev.rikdekker.nl** (calendar v6.4.0-dev.0 + RoomVox 1.0.6) — beide PR's zijn daar actief en getest met echte room data.

## Voldoen we aan de feedback?

| Reviewer | Feedback | Status |
|---|---|---|
| Sebastian Krupinski | TS + Composition + `<script setup>` | ✅ |
| Sebastian Krupinski | Verwijder ongebruikte ResourceListItem/Search | ✅ |
| Sebastian Krupinski | Behoud `isViewedByOrganizer` guard | ✅ |
| Sebastian Krupinski | Copyright 2026 | ✅ |
| Sebastian Krupinski | Modal voor ruimte-selectie i.p.v. inline | ⚠️ overruled door Jan's design-review (rechterkolom) |
| Jan Borchardt | Outlook-stijl rechtersidebar | ✅ stays in existing right column van `EditFull.vue:313-318` |
| Jan Borchardt | NcSelect dropdowns i.p.v. chips | ✅ Building/Floor/Features als `NcSelect` |
| Jan Borchardt | NC design language, alignment | ✅ NC color tokens (geen hex), standaard NC-componenten |
| Nimisha Vijay | Scope alleen room management | ✅ alleen `principal.js`, `resourceProps.js`, `ResourceList.vue`, `ResourceRoomCard.vue`, tests |
| Nimisha Vijay | UI volgens Jan's feedback | ✅ |

## Lokale staat (calendar-pr)

```
backup/visual-room-browser-pre-split   ← veilige backup van originele PR #7996 code
feature/visual-room-browser            ← origineel onaangetast
feature/room-metadata                  ← PR #8263 (a66d94fb0)
feature/visual-room-browser-v2         ← PR #8264 (3edf2ae84) op PR #8263
```

Werkboom is schoon op beide branches. Beide branches zijn gepusht naar `Rikdekker/calendar`.

## Logische vervolgstappen

### Korte termijn — afhankelijk van merge

1. **Wacht op reviewer-feedback op #8263**. Eerstvolgende ronde komt waarschijnlijk van Sebastian Krupinski (code-review) en/of Jan Borchardt / Nimisha Vijay (UI/scope-review).
2. **Bij review-comments op #8263**: amend op `feature/room-metadata`, force-push, automatisch ook `feature/visual-room-browser-v2` rebasen om de keten coherent te houden.
3. **Bij review-comments op #8264 alleen**: alleen die branch amenden + force-pushen.
4. **Na merge #8263**: `feature/visual-room-browser-v2` rebasen op upstream main zodat #8264 stand-alone kan mergen.

### Middellange termijn — afgesplitste features (niet nu indienen)

Zodra #8263 en #8264 gemerged zijn, kunnen de volgende features in **aparte** PR's:

- **PR 3: Modal-layout fixes voor `EditFull.vue`**
  - Auto-height + max-height i.p.v. `height: 100%`
  - Vertical centering via `transform`
  - Modal-header verbergen (rendert buiten container, oorzaak van overflow)
  - Title row met actions menu in content area
  - Time picker `flex-start` alignment
  - Attendees `width: calc(100% - 53px)` overflow-fix
  - Volledig CSS, geen logic changes — laagrisico

- **PR 4: Hybrid meeting support (In-person + Online toggles)**
  - In-person disclosure panel met room finder
  - Online toggle met `AddTalkModal` integratie
  - RFC 7986 `CONFERENCE`-property i.p.v. legacy URL in description/location
  - Beide tegelijk actief mogelijk voor hybrid meetings
  - Hangt af van #8264 (room finder)

Voor beide PR's bestaat al implementatie in [RoomVox/nc-calendar-patch/](../../RoomVox/nc-calendar-patch/) — die kan als startpunt dienen.

### Lange termijn — RoomVox-side: kaart-rendering in client apps

Onderzoek heeft uitgewezen dat het LOCATION-veld voor optimale kaart-rendering in macOS/iOS Calendar een `geo:` URI nodig heeft via `X-APPLE-STRUCTURED-LOCATION`, plus een schoon postaal adres zonder room-suffix. Optioneel `GEO:lat;lng` voor RFC 5545-conformiteit. Outlook desktop heeft geen kaart-render. Google Calendar / Android delegeert aan Maps bij klik. Thunderbird / eM Client / Nextcloud: plain text only. RFC 9073 VLOCATION: bijna geen adoptie.

**Niet in #8263/#8264** omdat:
- Vereist lat/lng-data per room → hoort bij **RoomVox-backend**, niet bij Calendar-frontend
- `X-APPLE-STRUCTURED-LOCATION` zetten op events vereist event-property-manipulatie tijdens iTIP-scheduling → in RoomVox `lib/Dav/SchedulingPlugin.php`
- Splitsen van adres in postaal-adres (LOCATION) en room-info (DESCRIPTION/X-TITLE) zou Nimisha's "alleen room management"-eis overschrijden

**Plan voor later (na merge #8263+#8264):**
1. RoomVox: `roomLatitude` / `roomLongitude` velden toevoegen aan room-config (CSV-import + admin UI)
2. RoomVox: in `lib/Dav/SchedulingPlugin.php` na booking de event-VCALENDAR uitbreiden met `GEO` + `X-APPLE-STRUCTURED-LOCATION` op basis van room data
3. Optioneel later in calendar-app: render een kaart-preview voor events met `GEO` / `X-APPLE-STRUCTURED-LOCATION` — issue [nextcloud/calendar#915](https://github.com/nextcloud/calendar/issues/915) staat al open

Aanbevolen LOCATION-formaat (uit onderzoek):

```
LOCATION:SURF Amsterdam\, Science Park 140\, 1098 XG Amsterdam\, Netherlands
GEO:52.356;4.955
X-APPLE-STRUCTURED-LOCATION;VALUE=URI;X-ADDRESS=Science Park 140\n1098 XG Amsterdam\nNetherlands;X-APPLE-RADIUS=49;X-TITLE=SURF Amsterdam – Room 0.11:geo:52.356,4.955
DESCRIPTION:Room 0.11 ...
```

## Belangrijke commits & branches (ter referentie)

```
calendar-pr fork (Rikdekker/calendar):
  feature/room-metadata          a66d94fb0   feat(principal): map CalDAV room metadata properties
  feature/visual-room-browser-v2 3edf2ae84   feat(resources): browsable room finder with availability and dropdown filters

  backup/visual-room-browser-pre-split  27c347bf0   ← snapshot van originele PR #7996 code
  feature/visual-room-browser           27c347bf0   ← origineel, onaangetast
```

## Lessen uit deze ronde

- **Splits-strategie werkt**: scope-onduidelijkheid op een grote PR levert maandenlange stilstand op. Een PR die één ding doet kan gericht gereviewd worden.
- **DCO-checks vereisen gematchte commit-author en Signed-off-by email**. Bij `git config user.email = noreply` en losse `Signed-off-by: <echte email>` faalt DCO. Een hele branch kan in één keer worden hersteld met `git rebase` + `--reset-author`.
- **Linting-rules van `@nextcloud/vue` evolueren**: deprecated props (`close-on-select` → `keep-open`) zijn niet altijd zichtbaar in lokale lint maar wel in CI. Lokaal `npx eslint <file>` op specifieke files draaien is sneller dan een full lint.
- **Transient CI-OOM kan bij grote builds optreden** op gedeelde GitHub-runners. Voordat je in code gaat zoeken naar oorzaak: vergelijk met andere PRs die dezelfde build draaien. Rerun zonder wijziging is een geldige eerste actie.
- **Visuele review op echte data is essentieel**. Unit tests vingen de leading-comma-bug niet — pas live deployment op dev.rikdekker.nl met RoomVox-rooms toonde het probleem.
