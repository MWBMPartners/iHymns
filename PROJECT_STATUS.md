# 📊 iHymns — Project Status

> **Quick reference for current project state**

---

## 🚦 Overall Status: 🟢 In Progress

| Area | Status | Notes |
| --- | --- | --- |
| 📋 Project Plan | ✅ Complete | See [Project_Plan.md](Project_Plan.md) |
| 🗂 Project Structure | ✅ Complete | Directories, .gitignore, deployment structure |
| 📖 Help Documentation | ✅ Complete | 8 guides in `help/` + in-app help (21 public topics, 39 admin sections) |
| 🎫 GitHub Issues | 🟢 Active | Highest issue now #1587+ — see GitHub for live open/closed counts |
| 🔧 Song Data | ✅ Active | ~14,000 songs across 30+ songbooks (live count in `tblSongs` — query the DB, don't trust this file); served **live from MySQL** (DB-direct #1010; the static cache was decommissioned #1020) |
| 🌐 Web PWA | ✅ Core + Enhanced | Search (Fuse.js), songbooks, lyrics, favourites, themes (Light/Dark/High-contrast/CVD/System #956), deep linking, WCAG 2.1 AA, offline support |
| 🛠 Song Editor | ✅ Complete | `appWeb/public_html/manage/editor/` — bulk import (ZIP / VideoPsalm / OpenSong), structure tab, media uploads, per-component language overrides |
| 🛠 Admin Portal | ✅ Active | 38 nav-registered admin destinations under `/manage/*`, organised as Dashboard + 6 groups (Songs / Catalogue / Access / People / Operations / Help). People hosts Service Mode (Venues, Service Projection, Lead a Service); Songs hosts the unified Duplicates & Links page (#1215, absorbed the old song-link-suggestions) |
| 🚀 CI/CD Pipeline | ✅ Complete | 14 workflows: deploy, version-bump, changelog, release, test, lint, apple, apple-deploy, apple-dmg, auto-merge-alpha, build-android, maintenance-ha-integrity-audit, maintenance-issues-sweep, promotion-deploy-bridge |
| 🍎 Apple App | 🟡 Consolidated, unreleased | Phase 1 + Phase 2 code-complete (iHymnsKit SwiftPM package; watch relay, tvOS projector, Live Activities, App Intents); consolidated and CI-compiled but unreleased; device matrices and APNs provisioning owner-gated |
| 🤖 Android App | 🟡 Scaffold / in progress | Kotlin / Jetpack Compose — ~12 Kotlin files; scaffold, not yet feature-complete |

---

## 📌 Completed Milestones

### Milestone 1: Project Setup & Data Pipeline ✅

Project structure, .gitignore, help docs, GitHub Issues, package.json, song parser, songs.json seed (now a migration **input** only — runtime reads are live MySQL, #1010).

### Milestone 2: Web PWA Core ✅

Layout, songbook browser, song detail, search (Fuse.js), responsive design, dark mode, favourites, PWA.

### Milestone 3: Web PWA Enhanced ✅

Deep linking (.htaccess), accessibility (WCAG 2.1 AA), in-app help, colourblind-friendly mode, numpad search, iLyrics dB colour scheme alignment, print stylesheet.

### Milestone 6: Song Editor ✅

Web-based admin tool at `/manage/editor/`: metadata, structure/arrangement, writers/composers, CCLI / ISWC, JSON validation/save, bulk import/export, preview, accompanying media uploads (audio / sheet music / MIDI / MusicXML).

### Infrastructure ✅

14 GitHub Actions workflows: SFTP deployment, semver bumping, changelog generation, GitHub Releases, CI lint/test, workflow-YAML lint, Apple CI/deploy/DMG, alpha auto-merge, Android build, and the two monthly maintenance sweeps.

### 2026-05 catalogue & platform work ✅ (highlights)

- **Works composition grouping** (#840) — `tblWorks` self-FK nesting + `tblWorkSongs`; public `/work/<slug>` page; admin CRUD at `/manage/works`.
- **External-links registry** (#833 / #845) — MusicBrainz-style `tblExternalLinkTypes` + per-entity `tblXxxExternalLinks`; provider patterns in `tblExternalLinkPatterns`; auto-detect URL → provider via `js/modules/external-link-detect.js`; curator CRUD at `/manage/external-link-types`.
- **Activity-log resilience** (#917–#931) — per-request rows, IPv6/proxy/VPN resolution, every PHP fatal mirrored, defensive `bindParamSafe()` helper.
- **Real email delivery** (#922) — magic-link, password reset, register, admin force-reset (closes #898 P0/security).
- **Credit-people structured-name split** (#935) — `FirstNames` / `Surname` / `Suffix` alongside canonical `Name`.
- **Quick-wins batch** (#948) — clickable Tune / CCLI / ISWC + Translations section; Catalogues many-to-many grouping (#941); Works ISWC backfill (#942); various UX/bug fixes.
- **Centralised link styling** (#952) — kills Bootstrap default `<a>` blue + underline site-wide; `.song-meta-link` muted convention everywhere.

### 2026-06 data-layer & worship program ✅ (highlights)

- **DB-direct read layer** (epic #1010, WS-A–WS-K) — song reads now go **live to MySQL**; the whole-corpus `songs.json` cache / `songs_json` endpoint were removed (WS-J #1020). Scoped reads only: `?action=songs_index` (slim index), editor `?action=songbook_export` (one book), `?action=song_detail` (one record). A DB outage returns a themed **503** (WS-K #1021), never stale JSON. `songs.json` remains a migration **input** only.
- **Lyrics normalisation** (#1235) — `tblLyricLines` is the **source of truth** for lyric lines (one shared read path `includes/lyric_lines_read.php`, one write path `lyricLinesWriteComponents()`); the `tblSongComponents` `LinesJson`/`ChordsJson`/`NotesJson` columns are a gated shadow being retired.
- **Duplicate & counterpart detection** (#1215 / #1216) — unified `/manage/duplicate-songs` (absorbed the old `/manage/song-link-suggestions`, now a 302 redirect); shared scorer `includes/song_similarity.php`.
- **Standard theme vocabulary** (#1152 / #1222) — CCLI / OpenLyrics theme taxonomy seeded into `tblSongTags`; curator canonicalisation on `/manage/tags`.
- **Service Mode — congregation Live-Follow** (#1323 / #1335) — org venues + recurring schedules, rotating-code join, anonymous presence, the two broadcaster UIs (`/manage/service-projection` + `/manage/service-lead`), dormant CCLI content gate. Currently dormant behind `content_gating_enabled='0'`.
- **Songbook DisplayAbbr** (#1332) — optional display-only label distinct from the SongId-prefix `Abbreviation`; **Catalogues** are user-labelled "Collections" (#1223, internal name stays `catalogue`); unofficial-songbook badging (#1223).
- **API gating + enforcement + rate limiting** (#1352 / #1353 / #1354) — content gating is now **server-enforced** (`includes/content_gating.php` strips gated fields from the API by tier cap) on an **extensible one-line capability registry** (`TIER_CAPS` + JSON-backed caps, no schema change); **dormant** until `content_gating_enabled='1'`. The heaviest public reads carry a per-requester (token-or-IP) windowed **rate limit** (`429` + `Retry-After`, fail-open, dormant until migrated). CSRF hardened to a robust same-origin `validateCsrfRequest()` (ends the sporadic stale-token errors on merge/delete/edits); `save_song` moved to the shared v2 editor API core under its X-Requested-With gate.

### 2026-07 highlights ✅

- **Public Export fixed** (#1565–#1570) — the enforcing nonce CSP silently killed the SPA fragment's inline `<script>` wiring, breaking the public Export ▾ menu (all 8 formats, both surfaces) and the Present button for about 7 weeks with no visible failure. Fixed by wiring `export-ui.js` as a real ES module from `router.js`'s `afterPageLoad()`; new CI guard `tests/php/test-fragment-inline-scripts.php` bans executable inline `<script>` in any page/partial fragment going forward.
- **Live Follow & Service Mode documented** (#1577) — the two features share one DB table but are functionally distinct (any signed-in user vs. venue/org-based); previously-conflated docs made Live Follow look permanently broken. New `help/live-follow.md`, `wiki/Live-Follow-&-Service-Mode.md`, and an Apple HelpView section.
- **Observability batch** (#1581 / #1582 / #1583) — event names unified behind `js/constants.js` with a CI literal-ban guard; uncaught client errors surface one toast + a throttled, scrubbed beacon into the Activity Log; a `/whats-new` page extracts the top CHANGELOG sections on every deploy.
- **Deploy media guard** (#1584) — `data/audio/` and `data/music/` excluded from the docroot mirror; every prior deploy had been silently wiping uploaded/downloadable song media.
- **Apple branch consolidation** — Phase 1 + Phase 2 Apple work (watch relay, tvOS projector, Live Activities, App Intents) merged into the single active branch; CI-compiled, still unreleased.

---

## 📌 Next Milestones

### Milestone 4 & 5: Apple App (consolidated, unreleased)

- Phase 1 + Phase 2 code-complete (iHymnsKit SwiftPM package; watch relay, tvOS projector, Live Activities, App Intents); consolidated and CI-compiled but unreleased; device matrices and APNs provisioning owner-gated
- Song data model, browser, search, detail view, favourites — shipped
- Remaining: device matrices, APNs provisioning, App Store submission, signing & notarisation (owner-gated)

### Milestone 7: Android App (scaffold / in progress)

- Kotlin / Jetpack Compose — ~12 Kotlin files, not yet feature-complete
- Feature parity with Apple app

### Major catalogue follow-ups (issue-tracked, full design captured)

- **#943** — Works ISWC API integration (ISWCnet + MusicBrainz + MRO IDs)
- **#944** — UI i18n + Translator role + Roles admin area
- **#945** — Naming cleanup: User Groups / Access Tiers / Roles / Entitlements / Licence Types vocabulary audit
- **#946** — Analytics expansion + external platform integration (GA4 / Plausible / Matomo)
- **#947** — Login forms: Cloudflare Turnstile / reCAPTCHA / hCaptcha admin-configurable

---

## 📈 Progress Summary

- **Songs**: ~14,000 across 30+ songbooks (multilingual: English, Afrikaans, Spanish, French, Swahili, Portuguese, and others; live count in `tblSongs` — query the DB, don't trust this file), served **live from MySQL** (DB-direct #1010)
- **Web PWA**: Feature-complete (core + enhanced + admin portal + editor)
- **GitHub Issues**: highest issue now #1587+ — see GitHub for live open/closed counts
- **Phase**: ONE (v0.x.x — pre-release)
- **Version**: 0.4000.0 Alpha (authoritative: `includes/infoAppVer.php`)
- **CI/CD**: 14 GitHub Actions workflows live

---

## 🔑 Legend

| Symbol | Meaning |
| --- | --- |
| ✅ | Complete |
| 🟢 | In Progress — on track |
| 🟡 | In Progress — needs attention |
| 🔴 | Blocked |
| 🔲 | Not Started |
| ⏳ | Waiting (on external input) |

---

Last updated: 2026-07-28
