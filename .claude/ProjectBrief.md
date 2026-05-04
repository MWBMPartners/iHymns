# 📋 iHymns — Project Brief

> **Claude Context File** — This file ensures continuity across development sessions.

---

## 🎯 What Is iHymns?

A multiplatform Christian lyrics application providing searchable hymn and worship song lyrics from multiple songbooks, designed to enhance worship.

- **Domain**: [iHymns.app](https://ihymns.app)
- **Copyright**: © 2026– MWBM Partners Ltd
- **License**: Proprietary (third-party components retain their own licenses)
- **GitHub Repo**: <https://github.com/MWBMPartners/iHymns>
- **Current Version**: 0.50.0 Alpha (pre-release, Phase 1) — feature flow continues through the 2026-05 #840–#852 catalogue-refresh batch (Works composition grouping, DB-driven URL auto-detect, responsive admin lists, sortable headers everywhere, bulk-promote credit-people, plus three CI/auto-merge hotfixes)
- **Database**: MySQL 5.7+ (~50 tables, tblCamelCase naming). 2026-04 added songbook metadata extensions (#672), an Affiliation registry (#670), optional Language column (#673 → composite IETF BCP 47 with `tblScripts` + `tblRegions` in #681), `tblBulkImportJobs` async-job table (#676), and Activity Log Result/Details columns (#695). 2026-05 added the MusicBrainz-style external-links registry (#833 — `tblExternalLinkTypes` + `tblSongExternalLinks` + `tblSongbookExternalLinks` + `tblCreditPersonExternalLinks`), Works composition grouping (#840 — `tblWorks` with self-FK nesting + `tblWorkSongs` + `tblWorkExternalLinks`, plus `AppliesTo` SET widened to `'work'`), and a curator-editable URL → provider rule table (#845 — `tblExternalLinkPatterns`)
- **API**: 60+ JSON endpoints via `api.php` (now including public `action=scripts` + `action=regions` listings for native clients, #682, and `?page=work&slug=…` for the Works public page, #840), plus the editor's separate `/manage/editor/api.php` (load / save_song / bulk_import_zip / bulk_import_status / typeaheads). OpenAPI 3.0 spec at `appWeb/public_html/api-docs.yaml` (refreshed for Works + ExternalLink shared schemas in #843)

---

## 📐 Two-Phase Approach

### Phase ONE (Current) — v0.x.x (pre-release)

- Songs sourced from local `.SourceSongData/` text files
- Parsed into JSON (`data/songs.json`), then migrated into **MySQL database**
- ~30 songbooks, 12,370+ songs after the CIS scrape (#663 / 2026-04-29). Original five English: CP (243), JP (617), MP (1355), SDAH (695), CH (702); plus 23 multi-language CIS hymnals (Spanish HA, Portuguese HASD, French DLG, Russian GASD, Twi TWI, Tonga TKMN, Tswana KMK, Sotho KP, Chichewa KMN, Shona KMNz, Venda NYD, Swahili NZK, Ndebele UKE, Xhosa UKEng, Xitsonga RRV, Gikuyu NCA, Abagusii OKON, Dholuo WN, Kinyarwanda IZGI, Tumbuka NMSDA, Sepedi KKK, Bemba BKMN, English CIS) plus Misc + AH/AYS/NAH placeholder books
- Multilingual sister-site scraper expansion (#699 Phase A + B, 2026-04-30): the SDAHymnal scraper now covers 12 sites — sdah, ch, ha (es), nha (es), hasd (pt), hl (fr), ia (it), hac (sr-Latn), hp (bg), hjp (mk), pes (sr-Cyrl), pj (hr) — plus an opt-in cross-source integrity check (`--prefer-source`) that diffs ChristInSong extracts against fresh scrapes. Audit findings in `.importers/audits/2026-04-30-cross-source-integrity.md`: English sources match perfectly; HASD has ~11% real data-quality issues (Latin-1 → Latin-2 encoding corruption + OCR-style errors in CIS — SDAHymnal is the cleaner source).
- MySQL with mysqli prepared statements throughout (PDO removed via #554/#555; project-wide auto-memory enforces this)
- Database naming: `tblCamelCase` tables, `CamelCase` columns
- User accounts with role hierarchy (global_admin/admin/editor/user)
- User groups with version access control (Alpha/Beta/RC/RTW channel gating)
- Song requests, multi-language support, activity logging, favorites sync
- Song Editor in `/manage/editor/` (session-based auth)
- Comprehensive REST-like API for PWA and native app consumption

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
8. Keep `data/songs.schema.json` in sync with any `songs.json` structure changes (#226)

---

## 🗂 Key Files

| File | Purpose |
| --- | --- |
| `data/songs.json` | Canonical song database (single source of truth) |
| `data/songs.schema.json` | JSON Schema (draft 2020-12) for songs.json validation (#226) |
| `tools/parse-songs.js` | Parses .SourceSongData/ → songs.json |
| `tools/build-web.js` | Web build/packaging script |
| `appWeb/public_html/includes/infoAppVer.php` | App version metadata |
| `appWeb/public_html/includes/components/*.php` | Modular PHP components |
| `appWeb/public_html/includes/pages/*.php` | Page templates (song, writer, privacy, terms, settings) |
| `appWeb/public_html/js/modules/*.js` | ES modules (router, analytics, gestures, settings, etc.) |
| `appWeb/public_html/js/utils/*.js` | JS utilities (html.js, text.js) |
| `appWeb/public_html/js/constants.js` | Centralised localStorage key constants (#139) |
| `appWeb/public_html/api.php` | Server-side API (songs, setlists, search, user auth, password reset) |
| `appWeb/public_html/og-image.php` | Dynamic OG image generator (1200×630, contextual song images) |
| `appWeb/public_html/sitemap.xml.php` | Dynamic XML sitemap from song database |
| `appWeb/public_html/includes/config.php` | App configuration (analytics, features) |
| `appWeb/public_html/manage/includes/auth.php` | Authentication middleware with role hierarchy |
| `appWeb/public_html/includes/db_mysql.php` | Single mysqli connection factory (`getDbMysqli()`) shared by main app + admin since #555 |
| `appWeb/public_html/js/modules/user-auth.js` | Public user auth (register, login, sync, password reset) |
| `appWeb/public_html/js/utils/components.js` | Shared song component tag utility (12 types) |
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

---

## User Account System

### Role Hierarchy (highest to lowest)

| Role | Level | Capabilities |
| --- | --- | --- |
| `global_admin` | 4 | All powers, auto-assigned to first user |
| `admin` | 3 | Manage users (assign roles up to admin) |
| `editor` | 2 | Edit songs via /manage/editor/ |
| `user` | 1 | Save setlists centrally, cross-device sync |

- Each role inherits capabilities of roles below it
- Non-logged-in (anonymous) users: local-only setlists (localStorage)
- Public API uses bearer tokens (64-char hex, 30-day expiry)
- Admin panel uses PHP sessions (session-based auth)
- Password reset via secure tokens (48-char hex, 1-hour expiry, single-use)
- Future: SIGNula ID integration

### Custom Song Arrangements

- Per-song arrangement editor in setlists (ProPresenter 7-style)
- 12 component types with short tags: V, C, R, PC, B, T, CD, I, O, IL, VP, AL
- Drag-and-drop reordering, auto-generate, sequential reset
- Arrangements persisted in setlist data and shared setlist links

---

Last updated: 2026-05-04 — refreshed at the close of the #840–#852 catalogue-refresh batch:

- **#840** — Works composition grouping (`tblWorks` with self-FK unlimited nesting, optional ISWC, member-songs across the catalogue, public `/work/<slug>` page, "Part of work" panel on song pages, admin CRUD at `/manage/works`).
- **#841** — Global URL → provider auto-detect for the external-links card-list editor (`js/modules/external-link-detect.js`, exposed on `window.iHymnsLinkDetect`, loaded on every `/manage/*` page).
- **#842** — Responsive admin list-view convention (`.admin-table-responsive` + `data-col-priority="primary|secondary|tertiary"`). Opted in: Credit People, Songbooks, Songbook Series, Works.
- **#843** — Comprehensive docs refresh (visitor in-app help, admin in-app help, `DEV_NOTES.md`, `CHANGELOG.md`, OpenAPI `Work` + `ExternalLink` schemas).
- **#844** — Sortable headers across every admin list page (10 pages opted in).
- **#845** — URL-detect rules moved into MySQL (`tblExternalLinkPatterns`); new `/manage/external-link-types` curator-editable CRUD page; JS module reads patterns from `window._iHymnsLinkTypes[].patterns`, falls back to bundled `RULES` on pre-migration deployments.
- **#846** — Bulk-promote in-use Credit People into the register (Levenshtein + token-set Jaccard fuzzy-match, single-transaction submit with shared `bulk_run_id`).
- **#848 / #849** — Hotfixes for #847's two follow-on bugs (migration cards not rendering on no-action visit; CI guard tripping its own block-comment).
- **#850 / #852** — CI/auto-merge plumbing made resilient: workflow tolerates `gh pr merge --auto` non-zero exits on fast-mergeable PRs; `Lint & Validate` now runs on every PR (no path filter on the `pull_request` trigger), so workflow-only / docs-only PRs can no longer deadlock auto-merge.

Active in-flight items deferred from earlier batches (will land in their own PRs):
- **#706** — Songbook cascade-delete with two-step confirmation modal.
- **#707** — Org-admin role + per-org member/licence management at /manage/my-organisations.
- **#709** — tblUserSetlists empty despite migrations + legacy JSON files not imported.
- **#713** — Rolling Manage-area sweep tracker for catch-all-with-error_log-no-logActivityError pattern.
- **#719** — Comprehensive API parity audit + OpenAPI refresh + in-app docs + Wiki refresh.
- **#722** — Schema Audit drift: 3 uncovered columns + 18 orphans-in-DB.

New deferred items from the 2026-05 batch:
- **#838** — credit-people external-links editor on the new schema (legacy `tblCreditPersonLinks` still read-fallback).
- **#839** — chip-list editor for song external links in `/manage/editor`.
