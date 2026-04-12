---
name: iHymns Project Context
description: iHymns is a multiplatform Christian lyrics app. Phase 1 (v0.x.x) uses MySQL with 6 songbooks and 3,612 songs. Phase 2 (v2.x.x) will use iLyrics dB API.
type: project
---

**iHymns** — Multiplatform Christian lyrics application for worship.

**Domain**: iHymns.app | **Repo**: https://github.com/MWBMPartners/iHymns
**Copyright**: MWBM Partners Ltd | **License**: Proprietary
**Current version**: 0.10.0 (pre-release, Phase 1)

**Why:** Enhance worship by providing searchable hymn lyrics across platforms.

**How to apply:**
- Phase ONE (v0.x.x): Parse `.SourceSongData/` text files → JSON → MySQL database, serve to all platforms
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
- lftp `--exclude` uses **regex patterns**, NOT shell globs (critical: `\.xcodeproj$` not `*.xcodeproj`)
- Working branch: `beta` (merge to `main` for production release)
- Version bumps: only on `beta` branch (single source of truth); alpha uses build timestamps
- Colour scheme: clean neutral slate/grey, NOT bright colours
- WCAG contrast: Automated relative luminance calculation for songbook badges
- Application IDs: Ltd.MWBMPartners.iHymns.PWA / .Apple / .Android
- Phase 1 is a first iteration — don't over-engineer file-based data distribution

**Database (v0.10.0+):**
- MySQL 5.7+ / MariaDB 10.3+ with InnoDB, utf8mb4
- Naming convention: Tables = `tblCamelCase`, Columns = `CamelCase`
- Song data: MySQLi with prepared statements (`db_mysql.php`, `SongData.php`)
- Auth/admin: PDO with MySQL driver (`manage/includes/db.php`, `auth.php`)
- Credentials: `appWeb/.auth/db_credentials.php` (git-ignored, installer-generated)
- Schema: `appWeb/.sql/schema.sql` (20 tables including songs, users, groups, translations, requests, activity log)
- Interactive installer: `php appWeb/.sql/install.php` (prompts for credentials, creates tables)
- JSON migrator: `php appWeb/.sql/migrate-json.php` (imports songs.json into MySQL)
- User groups with version access control: Developers/Beta Testers/RC Testers/Public → Alpha/Beta/RC/RTW gating
- Song requests table for user-submitted missing song suggestions
- Language and translation support (tblLanguages, tblSongTranslations)
- Activity log for admin audit trail
- App settings key-value store for runtime configuration

**Key JS Modules:**
- `app.js` — Main application bootstrap, imports all modules
- `router.js` — SPA routing with History API, WCAG badge contrast, TF-IDF related songs
- `analytics.js` — Unified event tracking across 5 analytics platforms
- `gestures.js` — Touch swipe navigation for song pages
- `settings.js` — Theme, display prefs, analytics consent management
- `transitions.js` — Page transition animations with loading bar
- `pwa.js` — PWA install banner with platform detection (iOS Safari/Chrome/Edge/Firefox, macOS Safari, Android, desktop)
- `song-of-the-day.js` — Deterministic date-seeded selection, 16 calendar themes, title + lyrics keyword matching
- `user-auth.js` — Public user auth (register/login/logout/forgot password)
- `favorites.js`, `setlists.js`, `search.js`, etc.

**Key PHP Files:**
- `index.php` — Main SPA shell, CSP headers, OG meta tags, conditional analytics, Android Smart App Banner
- `api.php` — Comprehensive API (30+ endpoints: songs, auth, favorites, setlists, song requests, languages, translations, user access, admin management, activity log)
- `includes/db_mysql.php` — MySQLi singleton connection factory
- `includes/SongData.php` — MySQL-backed song data handler with prepared statements
- `includes/config.php` — App configuration including analytics platform IDs
- `includes/pages/*.php` — Page templates (song, writer, privacy, terms, settings, etc.)
- `og-image.php` — Dynamic OG image generator (1200x630 PNG, 4 modes: generic/song/songbook/setlist)
- `sitemap.xml.php` — Dynamic XML sitemap (all songs, songbooks, writers, static pages)
- `manage/includes/db.php` — PDO connection factory (MySQL, shared credentials)
- `manage/includes/auth.php` — Authentication (sessions, CSRF, role hierarchy, password reset)
- `manage/editor/` — Song editor (read/write via MySQL)
- `manage/setup.php` — First-run admin account creation
- `manage/users.php` — User management (CRUD, roles, activate/deactivate)
