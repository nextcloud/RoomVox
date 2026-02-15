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

## 1. Pre-Release: Remove Evaluation Notices

- [ ] Remove "EVALUATION VERSION" notice from `appinfo/info.xml` description
- [ ] Update any evaluation-related restrictions in the code (if applicable)

---

## 2. Code Quality & Security

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
- [ ] Run `npm audit` — fix critical issues if possible
  - Upstream @nextcloud dependency vulnerabilities are usually not fixable
- [ ] **Check tarball for sensitive data** (see Section 8.2)

---

## 3. Translations (l10n/)

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

## 4. Version Management

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

## 5. Build & Testing

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

## 6. Nextcloud Compatibility

- [ ] Check `appinfo/info.xml`:
  ```
  <nextcloud min-version="32" max-version="33"/>
  <php min-version="8.2"/>
  ```
- [ ] Test on target Nextcloud version(s)

---

## 7. Git & Repository

- [ ] All changes committed
- [ ] No uncommitted changes: `git status`
- [ ] Sensitive files not tracked:
  ```bash
  git ls-files | grep -iE '\.(key|crt|pem|env)$'
  ```

---

## 8. Release Package

### 8.1 Create Tarball

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
mv roomvox-X.Y.Z.tar.gz /Users/rikdekker/Documents/Development/RoomVox/ && \
rm -rf "$TEMP_DIR"
```

### 8.2 Tarball Security Check (CRITICAL!)

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

### 8.3 Commit, Tag & Push

```bash
git add -A
git commit -m "Release vX.Y.Z - [Label]"
git tag -a vX.Y.Z -m "Release vX.Y.Z - [Label]"
git push origin main --tags
git push github main --tags
```

### 8.4 Deploy to Test Server

```bash
./deploy.sh
```

### 8.5 Generate Signature (for App Store)

```bash
# Generate signature using the LOCAL key in project root:
openssl dgst -sha512 -sign roomvox.key roomvox-X.Y.Z.tar.gz | openssl base64 -A
```

### 8.6 GitHub Release

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

### 8.7 App Store Upload

- **URL:** GitHub release download URL (lowercase `roomvox` in filename!)
- **Signature:** Output from step 8.5
- **Note:** Regenerate signature after any tarball change!

---

## 9. Post-Release Verification

- [ ] Install from App Store on clean test server
- [ ] Verify version displayed correctly
- [ ] Test upgrade path from previous version
- [ ] Verify CalDAV resources appear after install
- [ ] Test booking workflow end-to-end

---

## 10. Rollback Plan

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

# 4. Tarball (see section 8.1)

# 5. Deploy & test
./deploy.sh

# 6. Sign
openssl dgst -sha512 -sign roomvox.key roomvox-X.Y.Z.tar.gz | openssl base64 -A

# 7. GitHub release & App Store upload (see sections 8.6-8.7)
```

---

## Notes

- **App ID:** `roomvox`
- **Minimum Nextcloud version:** 32
- **Maximum Nextcloud version:** 33
- **PHP version:** >= 8.2
- **Supported languages:** EN, NL, DE, FR
- **App Store:** https://apps.nextcloud.com
- **Gitea:** https://gitea.rikdekker.nl/sam/RoomVox
- **GitHub:** https://github.com/nextcloud/roomvox
- **Signing key backup:** https://gitea.rikdekker.nl/rik/NextcloudApps
- **Signing key (for releases):** `roomvox.key` in project root (NOT in git!)

---

*Last updated: February 2026*
