# 📝 iHymns — Changelog

> All notable changes to this project are documented here.
> Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
> Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
> `v1.x.x` = Phase 1 (local JSON) | `v2.x.x` = Phase 2 (iLyrics dB)

---

## [Unreleased]

### 📋 Added — 2026-04-05

- Created `Project_Plan.md` — comprehensive project plan with technology stack, architecture, milestones
- Created `PROJECT_STATUS.md` — project status tracker
- Created `CHANGELOG.md` — this changelog
- Created `DEV_NOTES.md` — developer notes
- Created `README.md` — project overview with structure diagram
- Created `.claude/ProjectBrief.md` — Claude AI context and project brief
- Created `.gitignore` — comprehensive ignore rules for macOS, Windows, Linux, VS Code, Xcode, Node.js, Android
- Created `help/` documentation — getting started, searching, songbooks, favorites, troubleshooting, FAQ
- Analysed existing song data: 5 songbooks, ~7,415 source files
- Documented song data format and structure
- Created `package.json` — Node.js project configuration (v1.0.0-alpha.1)
- Built `tools/parse-songs.js` — song data parser (fully annotated, ~500 lines)
- Generated `data/songs.json` — 3,612 songs parsed (5.22 MB), with structured components, writer/composer credits, audio/sheet music flags
- 31 songs have no lyrics (empty source files — placeholder entries in source data)
- Built Web PWA core (Milestone 2) in `appWeb/public_html_beta/`:
  - `index.php` — main SPA layout with Bootstrap 5.3, responsive navbar, search, dark mode toggle
  - `includes/infoAppVer.php` — centralised version metadata with auto-computed copyright year
  - `css/styles.css` — custom styles: songbook cards, song list, lyrics view, dark mode, animations
  - `css/print.css` — print-optimised stylesheet for song lyrics
  - `js/app.js` — main entry point: data loading, SPA router, module orchestration
  - `js/utils/helpers.js` — shared DOM helpers, text formatting, hash routing, debounce
  - `js/modules/songbook.js` — songbook grid (home) and song list views
  - `js/modules/song-view.js` — song detail view with formatted lyrics, breadcrumb, actions
  - `js/modules/search.js` — Fuse.js fuzzy search (title, lyrics, songbook, writer, number)
  - `js/modules/favorites.js` — localStorage-based favourites management
  - `js/modules/settings.js` — dark mode, PWA install banner, update checker
  - `manifest.json` — PWA manifest for installability
  - `service-worker.js` — offline caching (cache-first for static, network-first for data)
  - `assets/favicon.svg` — SVG favicon (music notes on purple circle)

### 🏗 Changed — 2026-04-05

- Set up new project directory structure: `appWeb/`, `apple/`, `android/`, `tools/`, `data/`, `help/`, `.github/workflows/`
- Configured `appWeb/` with deployment structure: `public_html/`, `public_html_beta/`, `public_html_dev/`, `private_html/`
- Added song editor feature to Phase 1 plan (developer tool in `appWeb/private_html/editor/`)
- Added smart install banner feature (PWA install or app store links)
- Added auto-update checking with regular polling
- Added Apple TV remote control feature to Phase 2 plan
- Added automated semver versioning (`v1.x.x` Phase 1, `v2.x.x` Phase 2)
- Added SFTP deployment pipeline design (modelled on phpWhoIs)

### 🗑 Removed — 2026-04-05

- Deleted `appFlutter/` — old Flutter implementation (replaced by native apps)
- Deleted `appAppleIOS/` — old Swift implementation (starting fresh with Swift 6.3/SwiftUI)
- Deleted `backend/` — old PHP API + SQL (replaced by JSON data layer in Phase 1)
- Deleted `notes` — old ChatGPT reference link

### 🎫 GitHub Issues — 2026-04-05

- Created 49 Phase 1 issues (#1–#63, odd numbers + extras)
- Created 14 Phase 2 issues (#2–#30, even numbers)
- Created 7 infrastructure issues (#64–#70) for SFTP deployment, semver, CI/CD
- Created custom labels: `phase-1`, `phase-2`, `platform-web`, `platform-apple`, `platform-android`, `infrastructure`, `data`, `tooling`

---

## Version History

| Version | Date | Summary |
| --- | --- | --- |
| 0.1.0-planning | 2026-04-05 | Project planning, documentation, and structure setup |

---

This changelog is maintained alongside all code changes.
