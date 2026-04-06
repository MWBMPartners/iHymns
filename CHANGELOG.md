## [0.1.4] — 2026-04-06
- app refresh upload (early dev 1)
- chore: alphabetise manifest.json properties
- chore: reorder manifest.json — identity fields first, rest alphabetical
- feat: Android — Kotlin/Jetpack Compose project setup
- feat: Apple universal app — Xcode project setup (Swift 6.3 / SwiftUI)
- feat: Apple — Widgets (Song of the Day + Recent Favourites)
- feat: Song Editor — edit metadata (title, number, songbook, CCLI)
- feat: Song Editor — web-based developer tool UI
- feat: WCAG 2.1 AA accessibility compliance
- feat: Web PWA — MIDI audio playback
- feat: Web PWA — PDF sheet music viewer
- feat: add Miscellaneous songbook (Misc) for non-published songs
- feat: add experimental manifest properties + reorder
- feat: align colour scheme with iLyrics dB + colourblind-friendly mode
- feat: automated Apple app packaging (App Store, TestFlight, direct)
- feat: automated Web PWA build & packaging
- feat: build Web PWA core — Milestone 2 complete
- feat: build song data parser and generate songs.json
- feat: complete colour scheme redesign — clean neutral slate
- feat: comprehensive PWA manifest + update Claude context
- feat: comprehensive in-app help system
- feat: deep linking with clean URLs and browser title updates
- feat: fixed header and footer — always visible on screen
- feat: numeric keypad toggle for song number search
- feat: project setup — new structure, plan, docs, and song data
- feat: redirect to app root on direct include file access
- feat: restrict access to private_html/ via HTTP Basic Auth
- feat: shared data/ directory uploaded one level up from SFTP paths
- feat: unit tests + fix songbook abbreviation badges
- feat: update footer links and enhance copyright display logic
- fix: clean up Web PWA UI — search bar, icons, songbook colours
- fix: comprehensive code review + security hardening
- fix: deploy sync detection + softer songbook card colours
- fix: include data/songs.json inside each SFTP upload directory
- fix: remove CSS background override on songbook card headers
- fix: service worker never caches CDN resources — fixes 503 errors
- fix: set Vendor Parent to NULL — MWBM Partners Ltd is the top-level vendor
- fix: song editor loads songs.json from canonical data/ location
- fix: song list spacing, version bump regex, pre-release version, lyrics resilience
- fix: temporarily remove CSP header to clear poisoned SW cache
- initial folder structure
- refactor: align infoAppVer.php with phpWhoIs structure + platform info files
- refactor: modularise Web/PWA into PHP components
- refactor: single canonical songs.json — copy during build/deploy
- refactor: update PHP code for PHP 8.5 compatibility
- refactor: use DIRECTORY_SEPARATOR in all PHP require/include paths
- refactor: use unique platform-specific Application IDs

## [0.1.3] — 2026-04-06
- app refresh upload (early dev 1)
- chore: alphabetise manifest.json properties
- chore: reorder manifest.json — identity fields first, rest alphabetical
- feat: Android — Kotlin/Jetpack Compose project setup
- feat: Apple universal app — Xcode project setup (Swift 6.3 / SwiftUI)
- feat: Apple — Widgets (Song of the Day + Recent Favourites)
- feat: Song Editor — edit metadata (title, number, songbook, CCLI)
- feat: Song Editor — web-based developer tool UI
- feat: WCAG 2.1 AA accessibility compliance
- feat: Web PWA — MIDI audio playback
- feat: Web PWA — PDF sheet music viewer
- feat: add Miscellaneous songbook (Misc) for non-published songs
- feat: add experimental manifest properties + reorder
- feat: align colour scheme with iLyrics dB + colourblind-friendly mode
- feat: automated Apple app packaging (App Store, TestFlight, direct)
- feat: automated Web PWA build & packaging
- feat: build Web PWA core — Milestone 2 complete
- feat: build song data parser and generate songs.json
- feat: complete colour scheme redesign — clean neutral slate
- feat: comprehensive PWA manifest + update Claude context
- feat: comprehensive in-app help system
- feat: deep linking with clean URLs and browser title updates
- feat: numeric keypad toggle for song number search
- feat: project setup — new structure, plan, docs, and song data
- feat: restrict access to private_html/ via HTTP Basic Auth
- feat: shared data/ directory uploaded one level up from SFTP paths
- feat: unit tests + fix songbook abbreviation badges
- fix: clean up Web PWA UI — search bar, icons, songbook colours
- fix: comprehensive code review + security hardening
- fix: deploy sync detection + softer songbook card colours
- fix: include data/songs.json inside each SFTP upload directory
- fix: remove CSS background override on songbook card headers
- fix: service worker never caches CDN resources — fixes 503 errors
- fix: set Vendor Parent to NULL — MWBM Partners Ltd is the top-level vendor
- fix: song editor loads songs.json from canonical data/ location
- fix: song list spacing, version bump regex, pre-release version, lyrics resilience
- fix: temporarily remove CSP header to clear poisoned SW cache
- initial folder structure
- refactor: align infoAppVer.php with phpWhoIs structure + platform info files
- refactor: modularise Web/PWA into PHP components
- refactor: single canonical songs.json — copy during build/deploy
- refactor: update PHP code for PHP 8.5 compatibility
- refactor: use unique platform-specific Application IDs


## [1.0.0] — 2026-04-06
- app refresh upload (early dev 1)
- feat: Android — Kotlin/Jetpack Compose project setup
- feat: Apple universal app — Xcode project setup (Swift 6.3 / SwiftUI)
- feat: Apple — Widgets (Song of the Day + Recent Favourites)
- feat: Song Editor — edit metadata (title, number, songbook, CCLI)
- feat: Song Editor — web-based developer tool UI
- feat: WCAG 2.1 AA accessibility compliance
- feat: Web PWA — MIDI audio playback
- feat: Web PWA — PDF sheet music viewer
- feat: add Miscellaneous songbook (Misc) for non-published songs
- feat: align colour scheme with iLyrics dB + colourblind-friendly mode
- feat: automated Apple app packaging (App Store, TestFlight, direct)
- feat: automated Web PWA build & packaging
- feat: build Web PWA core — Milestone 2 complete
- feat: build song data parser and generate songs.json
- feat: comprehensive in-app help system
- feat: deep linking with clean URLs and browser title updates
- feat: numeric keypad toggle for song number search
- feat: project setup — new structure, plan, docs, and song data
- feat: restrict access to private_html/ via HTTP Basic Auth
- feat: shared data/ directory uploaded one level up from SFTP paths
- feat: unit tests + fix songbook abbreviation badges
- fix: clean up Web PWA UI — search bar, icons, songbook colours
- fix: comprehensive code review + security hardening
- fix: deploy sync detection + softer songbook card colours
- fix: include data/songs.json inside each SFTP upload directory
- fix: remove CSS background override on songbook card headers
- fix: set Vendor Parent to NULL — MWBM Partners Ltd is the top-level vendor
- fix: song editor loads songs.json from canonical data/ location
- initial folder structure
- refactor: align infoAppVer.php with phpWhoIs structure + platform info files
- refactor: single canonical songs.json — copy during build/deploy
- refactor: update PHP code for PHP 8.5 compatibility
- refactor: use unique platform-specific Application IDs


## [Unreleased] - 2026-04-05
- fix: default SFTP port to 22 if secret is empty or non-numeric
- refactor: rewrite deploy.yml to match phpWhoIs pattern exactly
- feat: unit tests + fix songbook abbreviation badges
- fix: comprehensive code review + security hardening
- feat: automated Android packaging (Play Store, Amazon, direct APK)
- feat: automated Apple app packaging (App Store, TestFlight, direct)
- feat: automated Web PWA build & packaging
- feat: Web PWA — PDF sheet music viewer
- feat: Web PWA — MIDI audio playback
- refactor: update PHP code for PHP 8.5 compatibility
- fix: set Vendor Parent to NULL — MWBM Partners Ltd is the top-level vendor
- refactor: use unique platform-specific Application IDs
- feat: Android — Full feature parity with Apple app
- feat: Android — Kotlin/Jetpack Compose project setup
- refactor: align infoAppVer.php with phpWhoIs structure + platform info files
- feat: Apple — Widgets (Song of the Day + Recent Favourites)
- feat: Apple — Code signing and notarisation
- feat: Apple — App Store submission preparation
- feat: Apple — Auto-update checker
- feat: Apple — In-app help
- feat: Apple — Share sheet
- feat: Apple �� Spotlight integration
- feat: Apple — Audio playback
- feat: Apple — Favorites system
- feat: Apple — Dark mode support
- feat: Apple — tvOS large-screen lyrics display
- feat: Apple — Search functionality
- feat: Apple — Song detail view
- feat: Apple — Song list and songbook browser
- feat: Apple — Song data model (Codable)
- feat: Apple universal app — Xcode project setup (Swift 6.3 / SwiftUI)
- docs: update PROJECT_STATUS with all completed milestones
- feat: CI test/lint workflow
- feat: GitHub Releases workflow
- feat: automated changelog generation
- feat: automated semver version bumping
- feat: GitHub Actions SFTP deployment pipeline
- feat: Song Editor — bulk import/export
- feat: Song Editor — JSON validation and save
- feat: Song Editor — CCLI number support
- feat: Song Editor — edit writers/composers
- feat: Song Editor — edit song structure/arrangement
- feat: Song Editor — edit metadata (title, number, songbook, CCLI)
- feat: Song Editor — web-based developer tool UI
- feat: comprehensive in-app help system
- feat: align colour scheme with iLyrics dB + colourblind-friendly mode
- feat: WCAG 2.1 AA accessibility compliance
- feat: numeric keypad toggle for song number search
- feat: deep linking with clean URLs and browser title updates
- feat: build Web PWA core — Milestone 2 complete
- feat: build song data parser and generate songs.json
- feat: project setup — new structure, plan, docs, and song data
- app refresh upload (early dev 1)
- initial folder structure
- Update README.md
- Initial commit


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
