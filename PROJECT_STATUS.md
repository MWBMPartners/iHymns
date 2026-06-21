# 📊 iHymns — Project Status

> **Quick reference for current project state**

---

## 🚦 Overall Status: 🟢 In Progress

| Area | Status | Notes |
| --- | --- | --- |
| 📋 Project Plan | ✅ Complete | See [Project_Plan.md](Project_Plan.md) |
| 🗂 Project Structure | ✅ Complete | Directories, .gitignore, deployment structure |
| 📖 Help Documentation | ✅ Complete | 6 guides in `help/` + in-app help |
| 🎫 GitHub Issues | 🟢 Active | Highest issue now #1340 — see GitHub for live open/closed counts |
| 🔧 Song Data | ✅ Active | ~12,370 songs across 30+ songbooks; served **live from MySQL** (DB-direct #1010; the static cache was decommissioned #1020) |
| 🌐 Web PWA | ✅ Core + Enhanced | Search (Fuse.js), songbooks, lyrics, favourites, themes (Light/Dark/High-contrast/CVD/System #956), deep linking, WCAG 2.1 AA, offline support |
| 🛠 Song Editor | ✅ Complete | `appWeb/public_html/manage/editor/` — bulk import (ZIP / VideoPsalm / OpenSong), structure tab, media uploads, per-component language overrides |
| 🛠 Admin Portal | ✅ Active | 41 admin surfaces under `/manage/*` across 7 nav groups (Dashboard / Songs / Catalogue / Access / People / Operations / Help). People now hosts Service Mode (Venues, Service Projection, Lead a Service); Songs hosts the unified Duplicates & Links page (#1215, absorbed the old song-link-suggestions) |
| 🚀 CI/CD Pipeline | ✅ Complete | 5 workflows: deploy, version-bump, changelog, release, test |
| 🍎 Apple App | 🟡 Scaffold / in progress | Swift 6.3 / SwiftUI — ~14 Swift files; scaffold, not yet feature-complete |
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

5 GitHub Actions workflows: SFTP deployment, semver bumping, changelog generation, GitHub Releases, CI lint/test.

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

---

## 📌 Next Milestones

### Milestone 4 & 5: Apple App (scaffold / in progress)

- Xcode project scaffolded (Swift 6.3 / SwiftUI), universal app (iPhone / iPad / Apple TV) — ~14 Swift files, not yet feature-complete
- Song data model, browser, search, detail view, favourites
- Spotlight, share sheet, App Store submission, signing & notarisation

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

- **Songs**: ~12,370 across 30+ songbooks (multilingual: English, Afrikaans, Spanish, French, Swahili, Portuguese, and others), served **live from MySQL** (DB-direct #1010)
- **Web PWA**: Feature-complete (core + enhanced + admin portal + editor)
- **GitHub Issues**: highest issue now #1340 — see GitHub for live open/closed counts
- **Phase**: ONE (v0.x.x — pre-release)
- **Version**: 0.990.0 Alpha
- **CI/CD**: 5 GitHub Actions workflows live

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

Last updated: 2026-06-21
