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

15 workflow files live in `.github/workflows/`:

| Workflow | File | Purpose |
|---|---|---|
| Deploy | `deploy.yml` | SFTP mirror to the environment matching the pushed branch, **plus** the tag-free version-anchor bump (see Versioning below) |
| Changelog | `changelog.yml` | Regenerates the four `CHANGELOG.md` files from conventional commit messages on push to `beta` |
| Release | `release.yml` | ⚠️ **Dormant / manual-only** (#1965) — creates a GitHub Release for a `v*` tag, but nothing in the automated pipeline pushes a tag any more; it fires only if a human pushes one by hand or runs it via `workflow_dispatch` |
| CI Lint & Validation | `test.yml` | Runs linting + the PHP/JS unit test suites |
| Lint Workflows | `lint.yml` | Lints the workflow YAML files themselves on any change under `.github/workflows/` |
| Language Registry Refresh | `language-registry-refresh.yml` | Scheduled monthly: refreshes the git-tracked BCP 47/IANA/CLDR snapshot files and re-pokes the keyed `/language-registry-refresh` endpoint to re-import them (dormant until an admin has run the registry's own setup card once) |
| Apple CI | `apple.yml` | Builds + runs Swift tests on push to the Apple integration branch |
| Apple Deploy | `apple-deploy.yml` | Signs and ships an Apple build on push to `alpha`/`beta`/`main` touching `appApple/**` |
| Apple macOS DMG | `apple-dmg.yml` | Manual/tag-triggered: builds and attaches a signed macOS DMG to a release |
| Android Build & Distribution | `build-android.yml` | Manual: builds a debug or release Android APK |
| Auto-Merge Alpha PRs | `auto-merge-alpha.yml` | Auto-merges eligible PRs targeting `alpha` once checks pass |
| Promotion Deploy Bridge | `promotion-deploy-bridge.yml` | Fires follow-up automation when a PR merges into a promotion branch |
| Maintenance — HA Integrity Audit | `maintenance-ha-integrity-audit.yml` | Scheduled monthly (14th) integrity audit |
| Maintenance — Issues Sweep | `maintenance-issues-sweep.yml` | Scheduled monthly (28th) GitHub Issues hygiene sweep |
| Dependabot Security Backport | `dependabot-security-backport.yml` | Cherry-picks a Dependabot/GitHub security fix merged to `main` (which is where GitHub restricts security PRs to) onto each release branch (`alpha`/`beta`/`release-candidate`) as its own PR — `dependabot.yml` already handles routine version updates per-branch directly; this closes the gap for security-only fixes |

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
- **Build info injection** — alongside the pre-existing SHA/date injection, `Version.Build.Number` in `infoAppVer.php` is set to `git rev-list --count HEAD` — a monotonic per-commit build number, distinct from the semver `Version.Number` (which only changes on a bump). `NULL` in the source tree; only ever set on a deployed copy. Shown as its own row in Settings → About (the commit-SHA row is separately labelled "Commit").
- `[skip ci]` in commit message skips all workflows
- Kill switch: `vars.SFTP_ENABLED` must be `true`

### Versioning pipeline (tag-free, #1963 → #1965 → the 2026-08-30 marketing-version/build-number split)

`deploy.yml` (not a separate `version-bump.yml`, which is retired) also owns the version bump, on every push to `alpha`. Two numbers travel separately and are never folded together:

- **The marketing version** — the full `MAJOR.MINOR.PATCH` string committed as `Version.Number` in `includes/infoAppVer.php`. This is the human-facing "what release is this" number (e.g. `1.3.0`).
- **The build number** — `git rev-list --count HEAD`, a monotonic count of every commit ever made. It only ever goes up, on every single deploy, whether or not the marketing version moved.

The bump logic on `alpha`:

1. It resolves the committed `MAJOR.MINOR.PATCH` anchor from `includes/infoAppVer.php`'s `Version.Number` line.
2. `.github/workflows/scripts/classify-bump.sh` reads the commits since that line last changed and classifies them by Conventional-Commit prefix: `feat:` → **minor**, `feat!:`/`fix!:`/any `!`/a line-anchored `BREAKING CHANGE:` → **major**, an explicit whole-line `Release: patch` footer (case-insensitive) on the merge message → **patch** (a deliberate "this is a bug-fix release" signal — meaningful for the native app stores even though the web ships continuously), everything else (`fix`/`chore`/`docs`/`refactor`/`perf`/`ci`/an unlabelled subject with no `Release: patch` footer) → **build-only** (the safe default — a mislabelled commit under-bumps rather than over-bumps, and moves only the always-incrementing build number below).
3. On a major/minor/patch signal, the workflow edits `Version.Number` in place and commits it back to the branch as a normal push (`[skip ci]`, worktree-isolated so the build-count arithmetic stays intact) — **never a git tag**.
4. The build number (`git rev-list --count HEAD`) is injected on every deploy regardless of whether the marketing version moved.

`beta`/`main` display their own committed anchor as content is promoted onto them — no tag reachability is needed. `release.yml` is dormant (see the workflow table above); it is **not** dispatched anywhere in this pipeline. CI guard: `tests/test-versioning-pipeline.js` (tag-free assertions + the classifier's producer/consumer format-string lockstep) and `tests/test-bump-classifier.js` (the classifier truth table, including the `Release: patch` footer).

**Companion obligation:** every user-visible `feat:` push should also add a plain-language bullet to `WHATS-NEW.md` (the source for the in-app `/whats-new` page) — never internals, never file/table/endpoint names.

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

The app footer shows both numbers together but never merged: `iHymns v<MAJOR.MINOR.PATCH> · build <commit-count>[ · Alpha|Beta]` (tapping the version text goes through to `/whats-new`). The admin footer under `/manage/*` renders the identical `· build <n>` suffix from the same `Version.Build.Number` field, and the per-commit build number is also shown as its own row in Settings → About.

## Per-channel search-engine visibility (#2024/#2025)

Each of the three channels can be told, independently, whether search engines are allowed to list it — a "Search engine visibility" card on `/manage/configuration` with a switch per channel (Production / Beta / Alpha-dev). It hangs off ONE `tblAppSettings` row (`search_visibility_channels`, a CSV of the VISIBLE channels — same storage shape as `webhooks_enabled_channels`/`intappsapi_enabled_channels`) and ONE helper, `includes/search_visibility.php`, so the sitemap gate, `robots.txt.php`, and every noindex header all read the same answer and can never disagree.

**Defaults (no admin action, no database migration needed):**

| Channel | Listed by default? |
|---|---|
| Production | Yes |
| Beta | No |
| Alpha (dev) | No |

**Switching a channel OFF is a full search-engine hide, made of three pieces:**

1. Every response on that channel carries `X-Robots-Tag: noindex` (`index.php` also renders the matching `<meta name="robots" content="noindex">` in `<head>`).
2. That channel's `/sitemap.xml` (and every child, e.g. `/sitemap-songs-2.xml`) answers a plain 404.
3. That channel's `robots.txt` (now generated by `robots.txt.php` — the static file was removed) drops its `Sitemap:` line.

It deliberately does **not** add `Disallow: /` — a crawler that's told not to fetch a page can never see the `noindex` on it, so staying crawlable is what makes the noindex signal actually work. `robots.txt.php` is wrapped in a total `try/catch` so it can never answer a server error (a robots.txt that 5xxs can make Google pause crawling the whole host).

A channel's own answer always comes from `ihymns_environment()` (the docroot it's actually running from), never the request's `Host:` header — so a forged header can never flip the decision, and each channel only ever advertises its own sitemap host (not the other two channels').

A switch takes days to weeks to show up in search results — search engines only notice on their next crawl of a page, in either direction.
