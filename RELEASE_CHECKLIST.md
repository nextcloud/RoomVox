# RoomVox App Store Release Checklist

Follow this checklist for every release to the Nextcloud App Store.

---

## 0. Certificate & Signing Key (CRITICAL!)

**Before every release**, verify that your signing key matches the App Store certificate!

- [ ] Copy signing key to project root:
  ```bash
  cp ~/.nextcloud/certificates/roomvox.key .
  ```
- [ ] Verify signing key exists in project root:
  ```bash
  ls -la roomvox.key
  ```
- [ ] Verify key is NOT tracked in git:
  ```bash
  git ls-files | grep roomvox.key  # Should return nothing
  ```
- [ ] Verify certificate is stored safely:
  ```bash
  ls -la ~/.nextcloud/certificates/roomvox.crt
  ```

### Certificate Warnings:
- **NEVER request a new certificate unnecessarily** — this automatically revokes the old one!
- Only request a new certificate if the private key is compromised or lost
- Keep your `.key` file safe (backup in `~/.nextcloud/certificates/` and Gitea, NOT in app git!)
- Backup location: `https://gitea.rikdekker.nl/rik/NextcloudApps`

---

## 1. Code Quality & Security

- [ ] Remove all debug `console.log()` statements from JavaScript (`src/`)
  - `console.error()` in catch blocks is OK (useful in production)
  - Search: `grep -rn "^\s*console\." src/ --include="*.js" --include="*.vue" | grep -v "// console"`
- [ ] Verify no `error_log()`, `var_dump()`, `print_r()` in PHP (`lib/`)
  - `$this->logger->debug()` is OK (proper logging)
- [ ] Check for hardcoded credentials, API keys, or passwords
- [ ] Verify SMTP passwords are encrypted (ICrypto) — no plaintext storage
- [ ] Ensure `.gitignore` is up-to-date (keys, certificates, .env files)
- [ ] Verify that sensitive files are NOT tracked in git:
  ```bash
  git ls-files | grep -iE '\.(key|crt|pem|env)$'
  ```
- [ ] PHP syntax check — verify no syntax errors in backend:
  ```bash
  find lib/ -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
  ```
- [ ] Check for `debugger` statements in JavaScript:
  ```bash
  grep -rn "debugger" src/ --include="*.js" --include="*.vue"
  ```
- [ ] Run `npm audit` — fix critical issues if possible
  - Upstream @nextcloud dependency vulnerabilities are usually not fixable
- [ ] Run `composer audit` for PHP dependency vulnerabilities
- [ ] **Check tarball for sensitive data** (see Section 7.2)

---

## 1a. OWASP Top 10 (2025) release gate

Loop deze lijst af bij **elke** release. De checks zijn bewust *triggers*, geen
pass/fail-gates: een hit betekent "kijk hier met je ogen naar", niet per se een bug.
Noteer bij een genegeerde hit kort *waarom* in de PR/commit.

Referentie: <https://owasp.org/Top10/2025/> · Cheat Sheets: <https://cheatsheetseries.owasp.org/>

### A01 — Broken Access Control

- [ ] Elke nieuwe/gewijzigde controller-methode heeft een bewuste access-attribute.
      Geen attribute = admin-only (NC-default). Controleer dat dat ook de bedoeling was:
  ```bash
  grep -rn --include='*.php' -B4 'public function' lib/Controller/ \
    | grep -E '#\[(NoAdminRequired|PublicPage|AuthorizedAdminSetting)\]|public function'
  ```
- [ ] Bij elke `#[NoAdminRequired]`: wordt de *ownership* van het object nog apart
      gecheckt? Ingelogd zijn is geen autorisatie — een user mag niet via een geraden
      `fileId`/`id` bij andermans data (IDOR).
- [ ] Bij elke `#[PublicPage]`: is er een token/secret-check, en gebeurt die met
      `hash_equals()` (niet `===`)?
- [ ] Share-scope: bij wijzigingen aan share-/permissie-logica, test expliciet als
      **anonieme** gebruiker én als user *zonder* rechten — niet alleen als eigenaar.

### A02 — Security Misconfiguration

- [ ] Geen debug-/verbose-output in de release-build (zie sectie 1).
- [ ] Foutmeldingen naar de client lekken geen paden, stacktraces of SQL.
- [ ] Nieuwe appconfig-defaults zijn *secure by default* (dicht, niet open).
- [ ] Als de app externe content of iframes rendert: CSP-policy nog passend?

### A03 — Software Supply Chain Failures *(nieuw in 2025, #3)*

- [ ] `npm audit` — kritieke issues opgelost of expliciet verantwoord.
      Upstream `@nextcloud/*` issues zijn vaak niet fixbaar; noteer dat dan.
- [ ] Lockfile is gecommit en hoort bij deze release-build.
- [ ] Nieuwe dependency toegevoegd sinds vorige release? Check even:
      onderhouden, redelijk gebruikt, en de licentie past.
  ```bash
  git diff <vorige-tag>..HEAD -- package.json composer.json
  ```

### A04 — Cryptographic Failures

- [ ] Alle nieuwe tokens/secrets via `random_bytes()` — nooit `rand()`, `uniqid()` of `md5()`.
      (`md5()` voor cache-keys/ETags is prima; voor security niet.)
- [ ] Alle secret-vergelijkingen via `hash_equals()`:
  ```bash
  grep -rn --include='*.php' -E '\$(token|secret|key|hash|signature)[A-Za-z]*\s*===' lib/
  ```
- [ ] Secrets staan versleuteld (`ICrypto`) opgeslagen, niet plaintext in appconfig.

### A05 — Injection (incl. XSS)

- [ ] **`v-html` zonder zichtbare sanitizer** — elke hit handmatig nalopen:
  ```bash
  grep -rn --include='*.vue' 'v-html' src/ \
    | grep -viE 'sanitiz|dompurify|escapehtml'
  ```
  Regel: alles wat een *gebruiker* kan beïnvloeden (bestandsinhoud, paginatekst,
  veldwaarden, zoek-snippets) moet door DOMPurify of `escapeHtml()` vóór het in
  `v-html` belandt. Server-side HTML samenstellen en "vertrouwen" telt niet.
- [ ] Geen string-interpolatie in SQL — altijd query-builder met named parameters:
  ```bash
  grep -rn --include='*.php' -E 'executeQuery|executeStatement' lib/ \
    | grep -E '"[^"]*\$|\x27[^\x27]*\$'
  ```
- [ ] Elke `shell_exec`/`proc_open`/`exec` gebruikt `escapeshellarg()` op *elk* argument:
  ```bash
  grep -rn --include='*.php' -E 'shell_exec|proc_open|passthru|\bexec\(|\bsystem\(' lib/
  ```

### A06 — Insecure Design

- [ ] Nieuwe feature met een security-dimensie (sharing, upload, externe API,
      tokens)? Beschrijf in de PR kort wie wát mag en hoe dat afgedwongen wordt.

### A07 — Authentication Failures

- [ ] Endpoints die een wachtwoord/token accepteren hebben
      `#[BruteForceProtection]` en `#[AnonRateLimit]`.
- [ ] Tokens hebben een geldigheidsduur en zijn intrekbaar.

### A08 — Software or Data Integrity Failures

- [ ] Tarball gesigneerd met de juiste key; `.sig` gearchiveerd (zie release-sectie).
- [ ] Build gemaakt vanaf een schone `npm ci` op het release-commit
      (appVersion wordt in de bundle gestempeld).
- [ ] Import-/restore-paden valideren hun input vóór verwerking.

### A09 — Security Logging and Alerting Failures

- [ ] Auth-fouten, permissie-weigeringen en admin-acties worden gelogd via
      `LoggerInterface` (niet `error_log()`).
- [ ] Logs bevatten **geen** wachtwoorden, tokens of volledige persoonsgegevens:
  ```bash
  grep -rn --include='*.php' -iE 'logger->[a-z]+\(.*(password|token|secret|apikey)' lib/
  ```

### A10 — Mishandling of Exceptional Conditions *(nieuw in 2025)*

- [ ] Geen lege `catch {}` die een fout stil opslokt — zeker niet rond een
      permissie- of validatie-check:
  ```bash
  grep -rn --include='*.php' -A2 'catch (' lib/ | grep -B1 '^\s*}' | head -20
  ```
- [ ] Faalt de code *dicht*? Bij een exception in een access-check moet toegang
      geweigerd worden, niet toegestaan.
- [ ] Een mislukte upload/import laat geen half-verwerkte staat achter.

### RoomVox-specifiek

- [ ] **Webhook-validatie**: `WebhookController` vergelijkt `clientState` met
      `hash_equals()` — die check staat er nog en faalt dicht bij een mismatch.
- [ ] SMTP-wachtwoorden en API-tokens blijven via `ICrypto` opgeslagen.
- [ ] `#[PublicPage]`-endpoints (booking-links) valideren hun token vóór ze
      roomdata teruggeven.
- [ ] `composer audit` draaien.

---

## 2. Translations (l10n/)

Supported languages: **EN (source), NL, DE, FR**

### The model (read once)

Since v1.3.0 RoomVox uses the POT-based Nextcloud community workflow, the same as
IntraVox and MetaVox. The old hand-maintained `l10n/*.json` model is gone.

- **Source strings live in code** — `t('roomvox', …)` in `src/**`, `$l->t(…)` in
  `lib/**`. The committed manifest `l10n/.source-strings.json` (sha256 + the sorted
  msgid list) records the exact set that has been handed off for translation.
- **The moment you add, change or remove a translatable string, regenerate and push
  it — the same day, not at release.** A prebuild guard
  (`scripts/check-l10n-sync.js`) fails `npm run build` until you do:
  ```bash
  npm run l10n:push        # extract → lint → regenerate POT + manifest (all local)
  git add translationfiles/templates/roomvox.pot l10n/.source-strings.json l10n/.source-count.json
  git commit -s -m "l10n: push <N> new source strings for translation"
  git push github main     # the Nextcloud bot reads the POT from GitHub only
  ```
  Note `npm run l10n:push` does **not** talk to Transifex — it only regenerates local
  files. The handoff is the GitHub push.
- **Never commit the manifest ahead of the code that uses the strings.** The guard
  compares manifest against code *per commit*, so an l10n commit landing before its
  feature commit fails CI — and reports the new strings as *removed*, which reads as
  the opposite of what happened. Commit the feature first and the manifest second, or
  both in one commit.
- **Never hand-edit `l10n/<lang>.{js,json}`, and never run `npm run l10n:generate-js`
  to "fix" a gap.** It regenerates `.js` from `.json` and silently drops any string
  missing from `.json`, desyncing the pair. Those files are the bot's output, not
  ours.

### ⚠️ The Transifex resource does not exist yet

RoomVox is **not yet on Transifex**. The request is
[nextcloud/docker-ci#986](https://github.com/nextcloud/docker-ci/issues/986), open
since 13-08-2026; Nextcloud has new resources on hold because translators cannot keep
up with the influx ([#977](https://github.com/nextcloud/docker-ci/issues/977)).
FormVox is queued behind it ([#989](https://github.com/nextcloud/docker-ci/issues/989)).

Consequences while this is open — none of these block a release:

- `tx push` / `tx pull` **will fail**. `.tx/config` points at
  `o:nextcloud:p:nextcloud:r:roomvox`, which is not created yet. Do not try to work
  around this.
- `translationfiles/{de,fr,nl}/roomvox.po` are **seeded and frozen** at the 13-08
  migration: 359 of 467 strings carried over from the old hand-maintained files. They
  will not move until the resource goes live. Do not hand-edit them either.
- **~110 strings ship untranslated** and fall back to English, including everything
  added since 13-08. That is expected, not a defect.
- Pushing the POT to GitHub is still the right thing to do every time. When the
  resource goes live the bot reads it from there.

- [ ] Check whether the resource has gone live yet:
  ```bash
  gh issue view 986 --repo nextcloud/docker-ci --json state,updatedAt,comments
  ```
  Once it is closed and the bot has landed its first `fix(l10n)` commit, delete this
  subsection and treat the workflow as normal. The bot **deletes the POT** in that
  commit — that is normal; the POT is a transient handoff file and the manifest is
  the durable record.

### Checks

- [ ] Source strings are in sync with the manifest (also enforced by the prebuild hook):
  ```bash
  node scripts/check-l10n-sync.js
  ```
- [ ] If it reports new strings, push them **before** cutting the release (see above).
- [ ] Measure the real gap — do not read it off the PO files, which overstate it:
  ```bash
  python3 -c "
  import json
  src = set(json.load(open('l10n/.source-strings.json'))['strings'])
  for l in ['nl','de','fr']:
      d = json.load(open(f'l10n/{l}.json'))['translations']
      print(f'{l}: {len(src - set(d))} untranslated of {len(src)}')
  "
  ```
  A missing key falls back to English. `msgstr == msgid` is **not** a gap — for
  proper nouns and technical terms that is the correct translation.
- [ ] Validate JSON syntax in all translation files:
  ```bash
  for f in l10n/*.json; do python3 -c "import json; json.load(open('$f')); print('✓ $f')" 2>&1 || echo "✗ $f"; done
  ```
- [ ] `l10n/*.js` and `l10n/*.json` hold the same keys per language. If they diverge,
  something hand-edited them — investigate, do **not** "repair" with
  `l10n:generate-js`, which would drop strings and widen the split.
  ```bash
  python3 -c "
  import json, re, pathlib
  for l in ['nl','de','fr']:   # not 'en' — en.json/en.js are gitignored build artefacts
      j = set(json.loads(pathlib.Path(f'l10n/{l}.json').read_text())['translations'])
      raw = pathlib.Path(f'l10n/{l}.js').read_text()
      body = raw[raw.index('{'):raw.rindex('}')+1]
      js = set(json.loads(re.sub(r',(\s*[}\]])', r'\1', body)))
      print(f'{l}: json={len(j)} js={len(js)}', 'OK' if j == js else f'DIVERGED: {len(j ^ js)} keys differ')
  "
  ```
- [ ] Note untranslated strings in the CHANGELOG release notes, so administrators know
      why parts of the interface are English (see the v1.3.0 note for wording).

---

## 3. Version Management

- [ ] Determine new version number (semantic versioning: MAJOR.MINOR.PATCH)
- [ ] Update version — both files must match:
  - `package.json` → `"version": "X.Y.Z"`
  - `appinfo/info.xml` → `<version>X.Y.Z</version>`
- [ ] Verify versions match:
  ```bash
  echo "package.json: $(python3 -c "import json; print(json.load(open('package.json'))['version'])")"
  echo "info.xml:     $(grep '<version>' appinfo/info.xml | sed 's/.*<version>\(.*\)<\/version>.*/\1/')"
  ```
- [ ] Update `CHANGELOG.md`:
  - [ ] Move items from `[Unreleased]` to `[X.Y.Z] - date - Label`
  - [ ] Sections: Added, Changed, Fixed, Removed, Security

---

## 4. Build & Testing

- [ ] Run `npm run build` without errors
- [ ] Test core functionalities on test server:
  - [ ] Room CRUD (create, edit, activate/deactivate, delete)
  - [ ] Room groups and shared permissions
  - [ ] CalDAV resource discovery in Nextcloud Calendar
  - [ ] Booking creation via calendar app
  - [ ] Auto-accept workflow
  - [ ] Manual approval workflow (approve/decline)
  - [ ] Conflict detection
  - [ ] Availability rules enforcement
  - [ ] Booking horizon enforcement
  - [ ] Recurring event handling
  - [ ] Permission system (viewer/booker/manager roles)
  - [ ] Email notifications (confirmed, declined, conflict, cancelled)
  - [ ] Per-room SMTP configuration
  - [ ] Admin panel: room list, editor, booking overview
- [ ] Test CalDAV client compatibility:
  - [ ] Nextcloud Calendar
  - [ ] Apple Calendar (iOS CUTYPE fix)
  - [ ] eM Client (LOCATION match fix)
- [ ] Check browser console for errors
- [ ] Verify virtual user accounts (`rb_*`) are hidden from user search

---

## 5. Nextcloud Compatibility

- [ ] Check `appinfo/info.xml`:
  ```
  <nextcloud min-version="32" max-version="34"/>
  <php min-version="8.2"/>
  ```
- [ ] Test on target Nextcloud version(s)

---

## 6. Git & Repository

- [ ] All changes committed
- [ ] No uncommitted changes: `git status`
- [ ] Sensitive files not tracked:
  ```bash
  git ls-files | grep -iE '\.(key|crt|pem|env)$'
  ```

---

## 7. Release Package

### 7.1 Create Tarball

**Root folder must be `roomvox` (lowercase, no version number)**

Required files in tarball:

| Directory    | Contents                          |
|--------------|-----------------------------------|
| `appinfo/`   | info.xml, routes.php              |
| `lib/`       | PHP backend                       |
| `js/`        | Compiled JavaScript               |
| `css/`       | Stylesheets                       |
| `img/`       | App icons                         |
| `l10n/`      | Translations (.json + .js)        |
| `templates/` | PHP templates                     |
| Root files   | CHANGELOG.md, LICENSE, README.md  |

**Exclude from tarball:** `src/`, `node_modules/`, `docs/`, `.git/`, `*.key`, `*.sh`, `nc-calendar-patch/`, `webpack.config.js`, `package.json`, `composer.json`

```bash
TEMP_DIR=$(mktemp -d) && \
mkdir -p "$TEMP_DIR/roomvox" && \
cp -r appinfo lib l10n templates css img js "$TEMP_DIR/roomvox/" && \
cp CHANGELOG.md LICENSE README.md "$TEMP_DIR/roomvox/" && \
cd "$TEMP_DIR" && \
tar -czf roomvox-X.Y.Z.tar.gz roomvox && \
mv roomvox-X.Y.Z.tar.gz /Users/rikdekker/Documents/Development/voxcloud-apps/roomvox/ && \
rm -rf "$TEMP_DIR"
```

### 7.2 Tarball Security Check (CRITICAL!)

```bash
# Verify no sensitive files
tar -tzf roomvox-X.Y.Z.tar.gz | grep -iE '(credential|\.key|\.env|deploy|\.git/|node_modules|src/|\.pem|\.crt|\.sh$)'

# Verify root folder is "roomvox/"
tar -tzf roomvox-X.Y.Z.tar.gz | head -1

# Verify required directories exist
for dir in appinfo lib l10n templates js img css; do
  echo -n "$dir: "; tar -tzf roomvox-X.Y.Z.tar.gz | grep "^roomvox/$dir/" | wc -l
done

# Verify src/ is NOT included (should be 0)
tar -tzf roomvox-X.Y.Z.tar.gz | grep 'src/' | wc -l
```

### 7.3 Commit, Tag & Push

```bash
git add -A
git commit -m "Release vX.Y.Z - [Label]"
git tag -a vX.Y.Z -m "Release vX.Y.Z - [Label]"
git push origin main --tags
git push github main --tags
```

### 7.4 Deploy to Test Server

```bash
./deploy.sh
```

### 7.5 Generate Signature (for App Store)

```bash
# Generate signature using the LOCAL key in project root:
openssl dgst -sha512 -sign roomvox.key roomvox-X.Y.Z.tar.gz | openssl base64 -A
```

### 7.6 GitHub Release

```bash
gh release create vX.Y.Z roomvox-X.Y.Z.tar.gz \
  --repo nextcloud/roomvox \
  --title "vX.Y.Z - [Label]" \
  --notes "$(cat <<'EOF'
## What's New in vX.Y.Z

[Summary from CHANGELOG.md]

Full changelog: https://github.com/nextcloud/roomvox/blob/main/CHANGELOG.md
EOF
)"
```

**Download URL:**
```
https://github.com/nextcloud/roomvox/releases/download/vX.Y.Z/roomvox-X.Y.Z.tar.gz
```

### 7.7 App Store Upload

- **URL:** GitHub release download URL (lowercase `roomvox` in filename!)
- **Signature:** Output from step 8.5
- **Note:** Regenerate signature after any tarball change!

**Upload via de API met Riks eigen token** (gemeten 29-08-2026: roomvox staat op
Riks App Store-account, zijn token authenticeert daar):

```bash
TOKEN=$(tr -d '[:space:]' < ~/Documents/Development/.claude/NextcloudApps/Keys/appstore-api-token-rikdekker.txt)
SIG=$(tr -d '\r\n' < ~/Documents/Development/voxcloud-infra/app-tooling/roomvox/roomvox-X.Y.Z.sig)
curl -s -w "\nHTTP %{http_code}\n" -X POST https://apps.nextcloud.com/api/v1/apps/releases \
  -H "Authorization: Token $TOKEN" -H "Content-Type: application/json" \
  -d "{\"download\":\"https://github.com/nextcloud/roomvox/releases/download/vX.Y.Z/roomvox-X.Y.Z.tar.gz\",\"signature\":\"$SIG\"}"
```

`201` = gelukt. **`403 You do not have permission` betekent bijna altijd de
verkeerde token**, niet een verlopen token: `appstore-api-token.txt` is van Sam
en bezit alleen `metavox`. De rechtencheck komt vóór de signature-check, dus een
`403` zegt niets over je pakket. Web-UI blijft als terugval beschikbaar.


---

## 8. Post-Release Verification

- [ ] Install from App Store on clean test server
- [ ] Verify version displayed correctly
- [ ] Test upgrade path from previous version
- [ ] Verify CalDAV resources appear after install
- [ ] Test booking workflow end-to-end

---

## 9. Rollback Plan

- [ ] Previous release tarball available
- [ ] Test server available for emergencies
- [ ] `git revert` or `git checkout v<previous>` ready

---

## Quick Release Flow

```bash
# 1. Build
npm run build

# 2. Commit & tag
git add -A
git commit -m "Release vX.Y.Z - [Label]"
git tag -a vX.Y.Z -m "Release vX.Y.Z - [Label]"

# 3. Push
git push origin main --tags
git push github main --tags

# 4. Tarball (see section 7.1)

# 5. Deploy & test
./deploy.sh

# 6. Sign
openssl dgst -sha512 -sign roomvox.key roomvox-X.Y.Z.tar.gz | openssl base64 -A

# 7. GitHub release & App Store upload (see sections 7.6-7.7)
```

---

## Notes

- **App ID:** `roomvox`
- **Minimum Nextcloud version:** 32
- **Maximum Nextcloud version:** 34
- **PHP version:** >= 8.2
- **Supported languages:** EN, NL, DE, FR
- **App Store:** https://apps.nextcloud.com
- **Gitea:** https://gitea.rikdekker.nl/sam/RoomVox
- **GitHub:** https://github.com/nextcloud/roomvox
- **Signing key backup:** https://gitea.rikdekker.nl/rik/NextcloudApps
- **Signing key (for releases):** `roomvox.key` in project root (NOT in git!)

---

*Last updated: February 2026*
