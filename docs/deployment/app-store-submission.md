# Nextcloud App Store Submission

This guide walks through submitting a RoomVox release to the Nextcloud App Store. For the full per-release checklist see [RELEASE_CHECKLIST.md](https://github.com/nextcloud/RoomVox/blob/main/RELEASE_CHECKLIST.md) in the repository root.

## Prerequisites

Before submitting a release you need:

1. A working RoomVox build (`npm run build` succeeds, `vendor/bin/phpunit --testsuite unit` passes)
2. A GitHub repository with the code published
3. A valid `appinfo/info.xml` with the correct version
4. An up-to-date `CHANGELOG.md`
5. Screenshots in `docs/screenshots/`
6. **The App Store certificate** (one-time setup, below)
7. **The signing key** (paired with the certificate above)

## One-Time Setup: App Store Certificate

The Nextcloud App Store requires a certificate to verify your identity and sign each release.

### Generate Private Key and CSR

```bash
# Generate the private key (KEEP SECRET — never commit!)
openssl genrsa -out roomvox.key 4096

# Generate a Certificate Signing Request
openssl req -new -key roomvox.key -out roomvox.csr \
  -subj "/CN=roomvox"
```

> **⚠️ Store `roomvox.key` securely.** Without this key, you cannot upload new releases. Recommended storage: encrypted USB backup plus a local working copy in a directory excluded from git. Never include `.key` files in App Store tarballs or source distributions.

### Submit CSR for Approval

1. Go to [github.com/nextcloud/app-certificate-requests](https://github.com/nextcloud/app-certificate-requests)
2. Open a new issue
3. Paste the contents of `roomvox.csr` (omit the BEGIN/END lines)
4. Wait for approval (typically 1–2 days)
5. The Nextcloud team commits a signed certificate (`roomvox.crt`)

### Register the App

After receiving the certificate:

1. Go to [apps.nextcloud.com/developer/register](https://apps.nextcloud.com/developer/register)
2. Log in with your GitHub account
3. Upload `roomvox.crt`
4. Sign a challenge to prove ownership of the private key

## Per-Release: Verify the Certificate Pair

Before every release, verify the signing key still matches the App Store certificate:

```bash
# MD5 of local signing key's public component
openssl rsa -in roomvox.key -pubout 2>/dev/null | openssl md5

# MD5 of the App Store certificate's public key (must follow redirects!)
curl -sL "https://apps.nextcloud.com/api/v1/apps.json" | \
  python3 -c "import json,sys; [print(a['certificate']) for a in json.load(sys.stdin) if a['id']=='roomvox']" | \
  openssl x509 -pubkey -noout 2>/dev/null | openssl md5
```

The two MD5 hashes **must be identical**. If they differ, the certificate has been replaced (e.g., revoked and reissued) and your local key is no longer valid.

> **Don't request a new certificate unnecessarily** — issuing a new one automatically revokes the old one and breaks all existing tooling.

> **Always use `curl -sL`** (follow redirects) — `apps.nextcloud.com/api/v1/apps.json` now returns HTTP 302 to `garm2.nextcloud.com`. Without `-L`, the comparison silently fails on empty input and gives `d41d8cd98f00b204e9800998ecf8427e` (the MD5 of an empty string).

## Per-Release: Build the Release Package

### Build the App

```bash
# Clean previous builds
rm -rf js/
rm -f roomvox-*.tar.gz

# Install dependencies
composer install --no-dev
npm ci

# Production build
npm run build

# Verify build output
ls -lh js/
```

### Regenerate Translations (if needed)

```bash
# After editing l10n/*.json files
npm run build  # rebuild also picks up updated translations
```

### Create the Tarball

**Important:** the tarball's root folder must be `roomvox` (lowercase, no version suffix).

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

### Exclude These From the Tarball

- `src/` — source code (only compiled `js/` ships)
- `node_modules/` — dependencies (`vendor/` from composer is included)
- `.git/` — git history
- `*.key`, `*.crt`, `*.pem` — certificates and keys
- `nc-calendar-patch/` — separate workstream
- `tests/` — PHPUnit suites
- `deploy-calendar.sh` and other deployment scripts with server details
- Any sample/test data

### Tarball Security Check

Verify no sensitive content slipped in:

```bash
# List all files
tar -tzf roomvox-X.Y.Z.tar.gz | grep -iE '(internal|credential|\.key|\.env|deploy)'
```

For content scanning, **extract** the tarball and `grep -r` per file extension. Don't pipe `tar -xzf -O` into one big blob — webpack-minified bundle bytes can coincidentally match patterns like `Math.pow(2,...)` and trigger false positives on substrings like `password=`.

### Sign the Tarball

```bash
openssl dgst -sha512 -sign roomvox.key roomvox-X.Y.Z.tar.gz | openssl base64 -A > roomvox-X.Y.Z.sig
```

The signature must be base64 encoded with no newlines. Verify with `wc -c roomvox-X.Y.Z.sig` — it should be a single long line.

## Per-Release: Publish on GitHub

1. Go to [github.com/nextcloud/RoomVox/releases](https://github.com/nextcloud/RoomVox/releases)
2. Click **Draft a new release**
3. Tag: `vX.Y.Z`
4. Title: `vX.Y.Z - Brief description`
5. Notes: copy the relevant section from `CHANGELOG.md`
6. Upload `roomvox-X.Y.Z.tar.gz` as a release asset
7. Publish

Or use the CLI:

```bash
gh release create vX.Y.Z roomvox-X.Y.Z.tar.gz \
  --repo nextcloud/RoomVox \
  --title "vX.Y.Z - Description" \
  --notes-file <(sed -n '/^## \['"X.Y.Z"'/,/^## /p' CHANGELOG.md | head -n -1)
```

The resulting download URL is:

```
https://github.com/nextcloud/RoomVox/releases/download/vX.Y.Z/roomvox-X.Y.Z.tar.gz
```

## Per-Release: Submit to the App Store

There are two routes — try the API first, fall back to the web UI if the token is rejected.

### Route A — API Upload (Preferred)

```bash
TOKEN=$(tr -d '[:space:]' < /path/to/appstore-api-token.txt)
SIG=$(cat roomvox-X.Y.Z.sig)
DOWNLOAD_URL="https://github.com/nextcloud/RoomVox/releases/download/vX.Y.Z/roomvox-X.Y.Z.tar.gz"

curl -s -w "\nHTTP %{http_code}\n" -X POST \
  -H "Authorization: Token $TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"download\":\"$DOWNLOAD_URL\",\"signature\":\"$SIG\",\"nightly\":false}" \
  https://apps.nextcloud.com/api/v1/apps/releases
```

- **HTTP 200** — success
- **HTTP 403 "You do not have permission"** — the token is expired or revoked. Go to Route B and refresh the token afterwards.

### Route B — Web UI Upload (Fallback)

1. Log in at [apps.nextcloud.com](https://apps.nextcloud.com)
2. Go to your developer dashboard → RoomVox → **New Release**
   - URL pattern: `https://apps.nextcloud.com/developer/apps/roomvox/releases/new` (only reachable when logged in as the app owner)
3. Paste:
   - **Download URL** — the GitHub release URL
   - **Signature** — contents of `roomvox-X.Y.Z.sig`
   - **Release notes** — copy the relevant CHANGELOG section

### Refreshing the API Token

If the API returns HTTP 403, the token expired or was revoked:

1. Log in at [apps.nextcloud.com](https://apps.nextcloud.com)
2. Click your username top-right → look for "API Token" / "Account" / "Profile"
3. Generate a new token, copy it, overwrite the local token file
4. Retry Route A — should return HTTP 200

## Wait for Approval

The Nextcloud team reviews submissions. This can take days to weeks. They check:

- Code quality
- Security
- Compliance with App Store guidelines
- Proper use of Nextcloud APIs

## Common Issues

### Certificate Mismatch

If the §"Verify the Certificate Pair" MD5 comparison shows different hashes, your local key no longer matches the App Store certificate. Do **not** generate a new certificate unless absolutely necessary — investigate first (was the key replaced? is there a USB backup of the right key?).

### Build Issues

- Ensure all dependencies are installed (`composer install --no-dev`, `npm ci` — not `npm install` for reproducibility)
- Check that the webpack build completes without errors or warnings
- Verify `js/roomvox-*.js` is at least ~200 KB (smaller usually means a build failure)

### Signature Issues

- Must be base64-encoded
- Use the exact same `.tar.gz` file that was uploaded to GitHub — re-signing a regenerated tarball produces a different signature
- No newlines in the signature (one long line)

### Wrong Tarball Root Folder

The root folder must be `roomvox` (lowercase, no version suffix). Get it wrong and the App Store install fails with `App not found in archive`. Verify with `tar -tzf roomvox-X.Y.Z.tar.gz | head -3`.

### `vendor/` Missing From Tarball

Unlike pure-JS Nextcloud apps, RoomVox depends on Composer packages (Symfony Mailer for per-room SMTP, Microsoft Graph SDK for Exchange). The `vendor/` directory **must** be included. Run `composer install --no-dev` before building, and verify with `tar -tzf roomvox-X.Y.Z.tar.gz | grep vendor/ | head`.

### API Token Expired

The RoomVox API token at `appstore-api-token.txt` may expire silently. Always have Route B (web UI) ready as a fallback.

## See Also

- [Release Process](release-process.md) — full per-release flow
- [RELEASE_CHECKLIST.md](https://github.com/nextcloud/RoomVox/blob/main/RELEASE_CHECKLIST.md) — authoritative per-release checklist
- [Installation](installation.md) — installing RoomVox on a Nextcloud instance
