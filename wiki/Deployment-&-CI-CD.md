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
| `appWeb/data_share/` | Shared data (songs.json, setlists, SQLite DB) | Deployed alongside public_html (without `--delete`) |
| `appWeb/private_html/` | Private admin tools, song editor | Separate SFTP path (`SFTP_PRIVATE_PATH`) |

---

## GitHub Actions Workflows

### 1. Deploy (`deploy.yml`)

SFTP mirroring using `lftp`. Triggered on push to `alpha`, `beta`, or `main`.

- Uses `lftp mirror --reverse` for one-way sync
- `--exclude` uses **regex patterns** (NOT shell globs): e.g., `\.xcodeproj$` not `*.xcodeproj`
- `appWeb/data_share/` deployed **without** `--delete` to preserve runtime data (SQLite DB, shared setlists)
- `.env-channel` file injected by CI for server-side environment detection
- `[deploy all]` in commit message forces full upload (ignores change detection)
- `[skip ci]` in commit message skips all workflows
- Kill switch: `vars.SFTP_ENABLED` must be `true`

### 2. Version Bump (`version-bump.yml`)

Auto-bumps version via conventional commits on push to `beta`.

### 3. Changelog Generation (`changelog.yml`)

Auto-generates `CHANGELOG.md` from conventional commit messages.

### 4. GitHub Releases (`release.yml`)

Creates GitHub Releases with tagged versions.

### 5. CI Lint/Test (`test.yml`)

Runs linting and the 33 unit tests.

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
