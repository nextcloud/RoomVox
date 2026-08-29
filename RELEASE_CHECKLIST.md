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

Supported languages: **EN, NL, DE, FR**

- [ ] Check that all languages have identical keys:
  ```bash
  python3 -c "
  import json
  langs = ['en','nl','de','fr']
  ref = set(json.load(open('l10n/en.json'))['translations'].keys())
  for l in langs:
      keys = set(json.load(open(f'l10n/{l}.json'))['translations'].keys())
      missing = ref - keys
      extra = keys - ref
      print(f'{l}: {len(keys)} keys', '✓' if keys == ref else f'✗ missing: {missing}, extra: {extra}')
  "
  ```
- [ ] Validate JSON syntax in all translation files:
  ```bash
  for f in l10n/*.json; do python3 -c "import json; json.load(open('$f')); print('✓ $f')" 2>&1 || echo "✗ $f"; done
  ```
- [ ] Verify JS translation files are up-to-date with JSON files

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
