# Release Process

The release process for RoomVox covers version management, build, GitHub release, and App Store upload. For the authoritative per-release checklist see [RELEASE_CHECKLIST.md](https://github.com/nextcloud/RoomVox/blob/main/RELEASE_CHECKLIST.md) in the repository root.

## Versioning

RoomVox follows [Semantic Versioning](https://semver.org/):

- **MAJOR** — breaking API or storage-format changes
- **MINOR** — backwards-compatible features
- **PATCH** — backwards-compatible bug fixes

## Version Synchronization

Two files declare the RoomVox version and **must stay in sync**:

| File | Field |
|---|---|
| `package.json` | `version` |
| `appinfo/info.xml` | `<version>` |

Verify they match before every release:

```bash
grep '"version"' package.json
grep '<version>' appinfo/info.xml
```

## Release Flow

### 1. Prepare

```bash
# Verify versions match
grep version package.json appinfo/info.xml

# Run tests
vendor/bin/phpunit --testsuite unit

# Production build
npm ci
npm run build
```

### 2. Update the CHANGELOG

Add a new section at the top of `CHANGELOG.md` following the existing pattern. Every entry that maps to a GitHub issue should include a markdown link to that issue:

```markdown
## [X.Y.Z] - YYYY-MM-DD - Title

### Added
- **Feature name** ([#NN](https://github.com/nextcloud/RoomVox/issues/NN)): description

### Changed
- Description

### Fixed
- **Bug name** ([#NN](https://github.com/nextcloud/RoomVox/issues/NN)): description

### Security
- Description
```

### 3. Commit and Push

```bash
git add -A
git commit -m "Release vX.Y.Z - [Title]"
git push gitea main
git push github main
```

### 4. Tag and Push the Tag

```bash
git tag -a vX.Y.Z -m "Release vX.Y.Z"
git push gitea vX.Y.Z
git push github vX.Y.Z
```

### 5. Build the Tarball

The tarball's root folder must be `roomvox` (lowercase, no version suffix):

```bash
TEMP_DIR=$(mktemp -d) && \
mkdir -p "$TEMP_DIR/roomvox" && \
cp -r appinfo lib l10n templates css img js vendor "$TEMP_DIR/roomvox/" && \
cp CHANGELOG.md LICENSE README.md "$TEMP_DIR/roomvox/" && \
cd "$TEMP_DIR" && \
tar -czf roomvox-X.Y.Z.tar.gz roomvox && \
mv roomvox-X.Y.Z.tar.gz /path/to/RoomVox/ && \
rm -rf "$TEMP_DIR"
```

**Exclude:** `src/`, `node_modules/`, `.git/`, `*.key`, `*.crt`, `tests/`, `nc-calendar-patch/`, deployment scripts, any sample data.

### 6. Tarball Security Check

Before uploading, verify no sensitive content slipped in:

```bash
# List files for human review
tar -tzf roomvox-X.Y.Z.tar.gz | grep -iE '(internal|credential|\.key|\.env|deploy)'
```

For content scanning, **extract** the tarball and `grep -r` per file extension. Don't pipe `tar -xzf -O` into one big blob — minified bundle bytes can coincidentally match patterns like `password=`.

### 7. Sign the Tarball

```bash
openssl dgst -sha512 -sign roomvox.key roomvox-X.Y.Z.tar.gz | openssl base64 -A > roomvox-X.Y.Z.sig
```

The signature must be base64-encoded with no newlines. Verify with `wc -c roomvox-X.Y.Z.sig`.

### 8. Create the GitHub Release

```bash
gh release create vX.Y.Z roomvox-X.Y.Z.tar.gz \
  --repo nextcloud/RoomVox \
  --title "vX.Y.Z - [Title]" \
  --notes "$(sed -n '/^## \[X.Y.Z\]/,/^## \[/p' CHANGELOG.md | head -n -1)"
```

The resulting download URL:

```
https://github.com/nextcloud/RoomVox/releases/download/vX.Y.Z/roomvox-X.Y.Z.tar.gz
```

### 9. Submit to the App Store

See [App Store Submission](app-store-submission.md) for the full flow. TL;DR — try the API first, fall back to the web UI if the token returns HTTP 403.

```bash
TOKEN=$(tr -d '[:space:]' < /path/to/appstore-api-token.txt)
SIG=$(cat roomvox-X.Y.Z.sig)

curl -s -w "\nHTTP %{http_code}\n" -X POST \
  -H "Authorization: Token $TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"download\":\"https://github.com/nextcloud/RoomVox/releases/download/vX.Y.Z/roomvox-X.Y.Z.tar.gz\",\"signature\":\"$SIG\",\"nightly\":false}" \
  https://apps.nextcloud.com/api/v1/apps/releases
```

### 10. Post-Release Verification

- Install the app from the App Store on a test server (wait for it to appear after approval)
- Verify the version is displayed correctly in **Settings → Apps**
- Run the smoke test:
  1. `Settings → Administration → RoomVox` renders without errors
  2. `Settings → Personal → RoomVox` (3 tabs: My Rooms, Approvals, Bookings) renders
  3. Booking via NC Calendar with a room → room calendar receives the event
  4. iCal feed `/apps/roomvox/api/v1/rooms/{id}/calendar.ics` returns a valid VCALENDAR
  5. Notification mails (accept/decline/cancel) deliver correctly
- Sync all git remotes
- Make a release announcement if it's a major version

## Translations Workflow

RoomVox is translated into English (source), Dutch, German, and French. Translation files live in `l10n/<lang>.{json,js}`.

Before release:

```bash
# Verify all source strings are in the en bundle
grep -rE "t\('roomvox'" src/ lib/ | sort -u

# Build l10n/{lang}.js from l10n/{lang}.json
python3 regenerate_js_translations.py  # if applicable, or via webpack

# Re-run npm build
npm run build
```

## NC 34 Release (Planned for v1.2.0)

The NC 34 audit ([NC 34 Compatibility](../architecture/nc34-compatibility.md)) concluded RoomVox is already NC34-ready. The 1.2.0 release will:

1. Bump `info.xml` `max-version` from `33` to `34`
2. Bump version `1.1.x` → `1.2.0`
3. Add a CHANGELOG entry under `[1.2.0]`
4. Smoke-test on `nc-34-dev` (Hetzner) before release

No API surface changes are required.

## Lessons Learned

These are real incidents — keep them in mind for future releases:

### API Token Expires Silently

The Nextcloud App Store API token at `appstore-api-token.txt` has expired without notification in the past. The release returns HTTP 403 from the API route. **Always have the web-UI route ready** as a fallback. See [App Store Submission → Refreshing the API Token](app-store-submission.md#refreshing-the-api-token).

### Tarball Content Scans Can False-Positive

The "sensitive content" grep `(password=|api_key=|...)` matches webpack-minified bundle bytes coincidentally. **Extract the tarball first**, then `grep -r` per text-file extension. Don't pipe `tar -xzf -O` into one big binary blob.

### `apps.nextcloud.com/api/v1/apps.json` Redirects

The cert-verification endpoint now redirects to `garm2.nextcloud.com`. Use `curl -sL` (follow redirects) for the signing-key vs. App Store cert MD5 comparison — without `-L`, the comparison silently fails on empty input.

### Tarball Root Folder Naming

Must be `roomvox` (lowercase, no version suffix). Get it wrong and the App Store install fails with `App not found in archive`.

## Quick Commands Reference

```bash
# Unit tests
vendor/bin/phpunit --testsuite unit

# Single test
vendor/bin/phpunit tests/Unit/Service/RoomServiceTest.php

# Production build
npm ci
npm run build

# Security audit
npm audit

# Verify versions match
grep version package.json appinfo/info.xml
```

## See Also

- [RELEASE_CHECKLIST.md](https://github.com/nextcloud/RoomVox/blob/main/RELEASE_CHECKLIST.md) — authoritative per-release checklist
- [App Store Submission](app-store-submission.md) — submission details
- [Installation](installation.md) — installing RoomVox
- [NC 34 Compatibility](../architecture/nc34-compatibility.md) — version audit
