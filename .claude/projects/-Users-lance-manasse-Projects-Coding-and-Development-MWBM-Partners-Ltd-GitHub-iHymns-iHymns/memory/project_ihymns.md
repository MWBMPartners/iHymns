---
name: iHymns Project Context
description: iHymns is a multiplatform Christian lyrics app. Phase 1 (v0.x.x) uses local JSON with 6 songbooks and 3,612 songs. Phase 2 (v2.x.x) will use iLyrics dB API.
type: project
---

**iHymns** — Multiplatform Christian lyrics application for worship.

**Domain**: iHymns.app | **Repo**: https://github.com/MWBMPartners/iHymns
**Copyright**: MWBM Partners Ltd | **License**: Proprietary
**Current version**: 0.1.6 (pre-release, Phase 1)

**Why:** Enhance worship by providing searchable hymn lyrics across platforms.

**How to apply:**
- Phase ONE (v0.x.x): Parse `.SourceSongData/` text files → JSON, serve to all platforms
- Phase TWO (v2.x.x): Switch to iLyrics dB API (https://github.com/MWBMPartners/iLyricsDB)
- Platform order: Web PWA → Apple (Swift 6.3/SwiftUI) → Android (Kotlin/Compose)
- 6 songbooks: CP (243), JP (617), MP (1355), SDAH (695), CH (702), Misc (0) = 3,612 songs
- `.SourceSongData/` must NEVER be deleted or modified — it is the source of truth
- Directories: `appWeb/`, `appApple/`, `appAndroid/` (consistent `app<Platform>/` prefix)
- Web: PHP 8.5+ on shared hosting, modular PHP components, Bootstrap 5.3.6
- JS: ES modules architecture — 25+ modules in `js/modules/`, utilities in `js/utils/`
- Security: CSP with per-request nonces, SRI hashes on all CDN resources
- Analytics: GA4, Plausible, Clarity, Matomo, Fathom — GDPR consent required
- Deployment: GitHub Actions + lftp SFTP (main→live, beta→beta, alpha→dev)
- Working branch: `beta` (merge to `main` for production release)
- Song editor (dev tool) in `appWeb/private_html/editor/` (HTTP Basic Auth protected)
- Colour scheme: clean neutral slate/grey, NOT bright colours
- WCAG contrast: Automated relative luminance calculation for songbook badges
- Application IDs: Ltd.MWBMPartners.iHymns.PWA / .Apple / .Android
- Phase 1 is a first iteration — don't over-engineer file-based data distribution

**Key JS Modules:**
- `app.js` — Main application bootstrap, imports all modules
- `router.js` — SPA routing with History API, WCAG badge contrast, TF-IDF related songs
- `analytics.js` — Unified event tracking across 5 analytics platforms
- `gestures.js` — Touch swipe navigation for song pages
- `settings.js` — Theme, display prefs, analytics consent management
- `transitions.js` — Page transition animations with loading bar
- `favorites.js`, `setlists.js`, `search.js`, `song-of-day.js`, etc.

**Key PHP Files:**
- `index.php` — Main SPA shell, CSP headers, conditional analytics scripts
- `api.php` — AJAX router (pages: home, song, songbook, writer, settings, etc.)
- `includes/SongData.php` — Song data loading, flexible ID matching, alphabetical sort
- `includes/config.php` — App configuration including analytics platform IDs
- `includes/pages/*.php` — Page templates (song, writer, privacy, terms, settings, etc.)
