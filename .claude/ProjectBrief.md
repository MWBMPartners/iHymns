# 📋 iHymns — Project Brief

> **Claude Context File** — This file ensures continuity across development sessions.

---

## 🎯 What Is iHymns?

A multiplatform Christian lyrics application providing searchable hymn and worship song lyrics from multiple songbooks, designed to enhance worship.

- **Domain**: [iHymns.app](https://ihymns.app)
- **Copyright**: © 2026– MWBM Partners Ltd
- **License**: Proprietary (third-party components retain their own licenses)
- **GitHub Repo**: <https://github.com/MWBMPartners/iHymns>
- **Current Version**: 0.1.7 (pre-release, Phase 1)

---

## 📐 Two-Phase Approach

### Phase ONE (Current) — v0.x.x (pre-release)

- Songs sourced from local `.SourceSongData/` text files
- Parsed into structured JSON (`data/songs.json`) — single canonical copy
- 6 songbooks, 3,612 songs: CP (243), JP (617), MP (1355), SDAH (695), CH (702), Misc (0)
- Some songbooks include MIDI audio and PDF sheet music
- Song Editor (developer tool) in `appWeb/private_html/editor/` (HTTP Basic Auth)
- Phase 1 is a first iteration — don't over-engineer file-based data distribution

### Phase TWO (Future) — v2.x.x

- Songs sourced from iLyrics dB API (<https://github.com/MWBMPartners/iLyricsDB>)
- MySQL backend, Christian songs only
- Same frontend UI, different data source
- Apple TV Remote Control: iPhone/iPad controls tvOS lyrics display over LAN

---

## 🖥 Target Platforms

| Platform | Technology | Directory | Status |
| --- | --- | --- | --- |
| Web/PWA | PHP 8.5+, Bootstrap 5.3.6, Vanilla JS (ES modules), Fuse.js | `appWeb/` | Core + Enhanced complete |
| Apple (iOS/iPadOS/tvOS/visionOS/macOS/watchOS) | Swift 6.3, SwiftUI | `appApple/` | Code complete |
| Android (+ Fire OS, Android TV) | Kotlin 2.1, Jetpack Compose | `appAndroid/` | Code complete |

### Application IDs

- Web/PWA: `Ltd.MWBMPartners.iHymns.PWA`
- Apple: `Ltd.MWBMPartners.iHymns.Apple`
- Android: `Ltd.MWBMPartners.iHymns.Android`

---

## 🚀 Deployment & Versioning

### Branches

| Branch | Purpose | Deploys To |
| --- | --- | --- |
| `alpha` | Experimental | `public_html/` → remote `public_html_dev/` |
| `beta` | Active development | `public_html/` → remote `public_html_beta/` |
| `main` | Production releases | `public_html/` → remote `public_html/` |

### Web Directory Structure

- `appWeb/public_html/` — Single source directory (deployed to all environments)
- `appWeb/data_share/` — Shared data (songs.json, setlists; deployed alongside public_html)
- `appWeb/private_html/` — Private admin tools, song editor (separate SFTP path)

### Automated Deployment

- GitHub Actions with `lftp` for SFTP mirroring (modelled on phpWhoIs)
- lftp `--exclude` uses **regex patterns**, NOT shell globs (e.g. `\.xcodeproj$` not `*.xcodeproj`)
- All branches deploy from `appWeb/public_html/`; branch determines remote SFTP path
- `appWeb/data_share/` deployed alongside (without `--delete` to preserve runtime data)
- `.env-channel` file injected by CI for server-side environment detection
- `vars.SFTP_ENABLED` kill switch
- `[deploy all]` commit flag forces full upload
- `[skip ci]` skips all workflows

### Version Numbering

- `v0.x.x` = Phase 1 pre-release (current)
- `v1.x.x` = Phase 1 stable
- `v2.x.x` = Phase 2 (iLyrics dB integration)
- Auto-bumped via conventional commits on push to `beta` (single source of truth)
- Alpha builds display commit date timestamp (yyyymmddhhmmss) in footer

---

## 🎨 Design

- **Colour scheme**: Clean neutral slate/grey — professional, easy on the eyes
- **Navbar**: Solid dark slate `#1e293b`, no gradient
- **Songbook cards**: ALL same soft grey gradient, no rainbow
- **Accent**: Muted teal `#0d9488`
- **Dark mode**: Charcoal blue `#0f172a`
- **Colourblind mode**: CVD-safe palette (Wong 2011)
- **Accessibility**: WCAG 2.1 AA, skip-to-content, focus indicators, reduced motion

---

## 📏 Development Standards

- **PHP**: 8.5+ with `declare(strict_types=1)`, `str_contains()`, match expressions
- **JS**: ES modules architecture (25+ modules in `js/modules/`, utilities in `js/utils/`)
- **Security**: Content Security Policy with per-request nonces, SRI hashes on CDN resources
- **Analytics**: GA4, Plausible, Clarity, Matomo, Fathom — GDPR consent banner required
- **Accessibility**: WCAG 2.1 AA, automated badge contrast via relative luminance
- **Detailed code annotations**: Comments on every code block (ideally every line)
- **Modular architecture**: PHP components (`includes/components/`), JS ES modules
- **Automated copyright year**: `© 2026–<current year>` resolved at runtime
- **Clean code**: All linting/security checks must pass with zero issues

---

## ✅ Standing Tasks (After Every Prompt)

1. Create GitHub Issue before work; close when done
2. Run syntax/lint/security checks; fix ALL issues
3. Ensure accessibility compliance
4. Update ALL documentation (README, CHANGELOG, PROJECT_STATUS, DEV_NOTES, help, .claude/)
5. Update .gitignore
6. COMMIT changes (push only when asked)
7. Clean up temp files

---

## 🗂 Key Files

| File | Purpose |
| --- | --- |
| `data/songs.json` | Canonical song database (single source of truth) |
| `tools/parse-songs.js` | Parses .SourceSongData/ → songs.json |
| `tools/build-web.js` | Web build/packaging script |
| `appWeb/public_html/includes/infoAppVer.php` | App version metadata |
| `appWeb/public_html/includes/components/*.php` | Modular PHP components |
| `appWeb/public_html/includes/pages/*.php` | Page templates (song, writer, privacy, terms, settings) |
| `appWeb/public_html/js/modules/*.js` | ES modules (router, analytics, gestures, settings, etc.) |
| `appWeb/public_html/js/utils/*.js` | JS utilities (html.js, text.js) |
| `appWeb/public_html/js/constants.js` | Centralised localStorage key constants (#139) |
| `appWeb/public_html/api.php` | Server-side API (songs, setlists, search) |
| `appWeb/public_html/og-image.php` | Dynamic OG image generator (1200×630, contextual song images) |
| `appWeb/public_html/sitemap.xml.php` | Dynamic XML sitemap from song database |
| `appWeb/public_html/includes/config.php` | App configuration (analytics, features) |
| `appWeb/private_html/editor/` | Song editor (dev tool) |
| `appApple/iHymns/iHymns/Services/AppInfo.swift` | Apple app info |
| `appAndroid/.../AppInfo.kt` | Android app info |
| `tests/test-song-parser.js` | 33 unit tests |

---

## 📝 SFTP Secrets Required

| Secret | Purpose |
| --- | --- |
| `SFTP_HOST`, `SFTP_USER`, `SFTP_KEY`/`SFTP_PASSWORD` | Server connection |
| `SFTP_LIVE_PATH`, `SFTP_BETA_PATH`, `SFTP_DEV_PATH` | Deploy directories |
| `SFTP_PRIVATE_PATH` | Song editor deploy directory |
| `SFTP_ENABLED` (Variable) | Kill switch (`true` to enable) |

See `DEV_NOTES.md` for full setup guide including Apple, Android, and Fire OS.

---

Last updated: 2026-04-07
