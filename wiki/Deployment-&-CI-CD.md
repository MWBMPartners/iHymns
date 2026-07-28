# Deployment & CI/CD

> Automated deployment pipelines, branch strategy, and secrets configuration

---

## Branch Strategy

| Branch | Purpose | Deploys To |
|---|---|---|
| `alpha` | Experimental | `public_html/` to remote `public_html_dev/` |
| `beta` | Active development | `public_html/` to remote `public_html_beta/` |
| `main` | Production releases | `public_html/` to remote `public_html/` |

All branches deploy from `appWeb/public_html/` — the branch determines the remote SFTP target path.

---

## Web Directory Structure

| Directory | Purpose | Deployment |
|---|---|---|
| `appWeb/public_html/` | Single source directory | Deployed to all environments |
| `appWeb/data_share/` | Runtime shared data (shared setlists, etc.) — the song-corpus JSON/SQLite mirror was removed in WS-J #1020 | Deployed alongside public_html (without `--delete`) |
| `appWeb/private_html/` | Private admin tools, song editor | Separate SFTP path (`SFTP_PRIVATE_PATH`) |

---

## GitHub Actions Workflows

14 workflow files live in `.github/workflows/`:

| Workflow | File | Purpose |
|---|---|---|
| Deploy | `deploy.yml` | SFTP mirror to the environment matching the pushed branch (see pipeline below) |
| Version Bump | `version-bump.yml` | Auto-bumps `infoAppVer.php` via conventional commits on push to `beta` |
| Changelog | `changelog.yml` | Regenerates the four `CHANGELOG.md` files from conventional commit messages on push to `beta` |
| Release | `release.yml` | Creates a GitHub Release with a tagged version |
| CI Lint & Validation | `test.yml` | Runs linting + the PHP/JS unit test suites |
| Lint Workflows | `lint.yml` | Lints the workflow YAML files themselves on any change under `.github/workflows/` |
| Apple CI | `apple.yml` | Builds + runs Swift tests on push to the Apple integration branch |
| Apple Deploy | `apple-deploy.yml` | Signs and ships an Apple build on push to `alpha`/`beta`/`main` touching `appApple/**` |
| Apple macOS DMG | `apple-dmg.yml` | Manual/tag-triggered: builds and attaches a signed macOS DMG to a release |
| Android Build & Distribution | `build-android.yml` | Manual: builds a debug or release Android APK |
| Auto-Merge Alpha PRs | `auto-merge-alpha.yml` | Auto-merges eligible PRs targeting `alpha` once checks pass |
| Promotion Deploy Bridge | `promotion-deploy-bridge.yml` | Fires follow-up automation when a PR merges into a promotion branch |
| Maintenance — HA Integrity Audit | `maintenance-ha-integrity-audit.yml` | Scheduled monthly (14th) integrity audit |
| Maintenance — Issues Sweep | `maintenance-issues-sweep.yml` | Scheduled monthly (28th) GitHub Issues hygiene sweep |

There is also a `.github/workflows/scripts/` subdirectory of helper scripts — not a workflow itself.

### Deploy (`deploy.yml`)

Triggered on push to `alpha`, `beta`, or `main`; SFTP mirroring via `lftp`. The pipeline runs, in order:

1. **Change detection** — diffs against the previous deployed commit to decide what needs uploading (`[deploy all]` in the commit message forces a full upload).
2. **What's New extraction** — the top three `## ` sections of the root `CHANGELOG.md` are extracted into `public_html/data/whats-new.md`, rendered at `/whats-new` via `includes/markdown_lite.php` (#1583). A malformed top section is user-visible here.
3. **Vendor populate** — `tools/download-vendor.sh` fetches/refreshes the offline-fallback copies of CDN libraries.
4. **Minify** — `*.js`, `*.css`, `*.html`/`*.htm`, and `*.xml` files are minified before upload. YAML is deliberately **not** minified.
5. **SFTP mirror** — `lftp mirror --reverse --delete` one-way sync. `--exclude` uses **regex patterns** (NOT shell globs): e.g. `\.xcodeproj$`, not `*.xcodeproj`. `^data/audio/` and `^data/music/` are excluded from the docroot mirror (#1584) — server-side song audio/sheet-music files are not tracked in git and a `--delete` mirror would otherwise wipe them on every deploy. `appWeb/data_share/` is deployed **without** `--delete` to preserve runtime data.

Other deploy behaviour:
- `.env-channel` file injected by CI for server-side environment detection
- `[skip ci]` in commit message skips all workflows
- Kill switch: `vars.SFTP_ENABLED` must be `true`

---

## Secrets & Variables

### Web/PWA — SFTP Deployment

| Secret/Variable | Required | Type | Description |
|---|---|---|---|
| `SFTP_HOST` | Yes | Secret | SFTP server hostname |
| `SFTP_PORT` | No | Secret | SFTP port (defaults to 22) |
| `SFTP_USER` | Yes | Secret | SFTP username |
| `SFTP_KEY` | * | Secret | SSH private key (preferred) |
| `SFTP_PASSWORD` | * | Secret | SFTP password (fallback) |
| `SFTP_LIVE_PATH` | Yes | Secret | Remote path for production |
| `SFTP_BETA_PATH` | Yes | Secret | Remote path for beta |
| `SFTP_DEV_PATH` | No | Secret | Remote path for alpha/dev |
| `SFTP_PRIVATE_PATH` | No | Secret | Remote path for private_html |
| `SFTP_ENABLED` | Yes | **Variable** | Kill switch (`true` to enable) |

> \* Either `SFTP_KEY` or `SFTP_PASSWORD` is required. SSH key auth is preferred.

#### SSH Key Setup

```bash
# Generate a dedicated deploy key (no passphrase)
ssh-keygen -t ed25519 -C "github-deploy@ihymns.app" -f ~/.ssh/ihymns_deploy -N ""

# Copy the public key to your server
ssh-copy-id -i ~/.ssh/ihymns_deploy.pub user@ihymns.app

# The PRIVATE key goes into the SFTP_KEY secret
cat ~/.ssh/ihymns_deploy
```

### Apple — App Store, TestFlight, Direct

| Secret | Required | Description |
|---|---|---|
| `APPLE_TEAM_ID` | Yes | Apple Developer Team ID (10-char alphanumeric) |
| `ASC_KEY_ID` | Yes | App Store Connect API Key ID |
| `ASC_ISSUER_ID` | Yes | App Store Connect API Issuer ID |
| `ASC_API_KEY` | Yes | App Store Connect API Private Key (.p8 contents) |
| `MATCH_GIT_URL` | Yes | Git repo URL for Fastlane Match certificate storage |
| `MATCH_PASSWORD` | Yes | Encryption password for Fastlane Match |

### Android — Google Play Store

| Secret | Required | Description |
|---|---|---|
| `ANDROID_KEYSTORE_BASE64` | Yes | Release keystore, base64-encoded |
| `ANDROID_KEYSTORE_PASSWORD` | Yes | Keystore password |
| `ANDROID_KEY_ALIAS` | Yes | Signing key alias |
| `ANDROID_KEY_PASSWORD` | Yes | Key password |
| `PLAY_SERVICE_ACCOUNT_JSON` | No | Google Play Console service account JSON |
| `PLAY_STORE_ENABLED` | No | Variable — `true` to enable Play Store upload |

### Amazon Fire OS

Uses the same Android release APK. Manual upload to Amazon Developer Console. No Google Play Services dependencies.

---

## Quick Setup Checklists

### Minimum for Web/PWA

- [ ] Set `SFTP_HOST`, `SFTP_USER` secrets
- [ ] Set `SFTP_KEY` or `SFTP_PASSWORD` secret
- [ ] Set `SFTP_LIVE_PATH`, `SFTP_BETA_PATH` secrets
- [ ] Set `SFTP_ENABLED` **variable** to `true`

### Minimum for Apple

- [ ] Set `APPLE_TEAM_ID` secret
- [ ] Set `ASC_KEY_ID`, `ASC_ISSUER_ID`, `ASC_API_KEY` secrets
- [ ] Set `MATCH_GIT_URL`, `MATCH_PASSWORD` secrets
- [ ] Run `fastlane match appstore` locally once

### Minimum for Android

- [ ] Generate release keystore, set `ANDROID_KEYSTORE_BASE64`
- [ ] Set `ANDROID_KEYSTORE_PASSWORD`, `ANDROID_KEY_ALIAS`, `ANDROID_KEY_PASSWORD`

---

## Environment Detection

The CI pipeline injects a `.env-channel` file during deployment, allowing server-side PHP to detect which environment is running:

| Channel | `.env-channel` content |
|---|---|
| Alpha/Dev | `alpha` |
| Beta | `beta` |
| Production | `main` |

Alpha builds display a commit date timestamp (yyyymmddhhmmss) in the footer for deploy tracking.
