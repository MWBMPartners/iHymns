---
name: iHymns Project Context
description: iHymns is a multiplatform Christian lyrics app. v0.10.0 uses MySQL with 35+ tables, 60+ API endpoints, organisations, content tiers, CCLI licensing, accessibility. Phase 2 will use iLyrics dB API.
type: project
---

**iHymns** — Multiplatform Christian lyrics application for worship.

**Domain**: iHymns.app | **Repo**: https://github.com/MWBMPartners/iHymns
**Copyright**: MWBM Partners Ltd | **License**: Proprietary
**Current version**: 0.10.0 (pre-release, Phase 1)

**Why:** Enhance worship by providing searchable hymn lyrics across platforms.

**How to apply:**

- Phase ONE (v0.x.x): Parse `.SourceSongData/` → JSON → MySQL, serve via API to all platforms
- Phase TWO (v2.x.x): Switch to iLyrics dB API
- Platforms: Web PWA → Apple (Swift/SwiftUI) → Android (Kotlin/Compose)
- 6 songbooks: CP (243), JP (617), MP (1355), SDAH (695), CH (702), Misc (0) = 3,612 songs
- DreamHost shared hosting — NO CLI access; all scripts run via web dashboard

**PHP Standards:**

- Use DIRECTORY_SEPARATOR for ALL filesystem path concatenations (never hardcode '/')
- Use $app["Application"][...] array directly (no shortcut alias variables)
- Use PHP predefined constants throughout (PHP_EOL, DIRECTORY_SEPARATOR, etc.)
- declare(strict_types=1) on all PHP files
- MySQLi prepared statements for song data; PDO for auth/admin
- All tokens (API + password reset) stored as SHA-256 hashes in database

**Database (v0.10.0+):**

- MySQL 5.7+, InnoDB, utf8mb4
- Naming: tblCamelCase tables, CamelCase columns
- 35+ tables covering: songs, users, orgs, licensing, content restrictions, keys, chords, scheduling, templates, collaboration, tags, history, notifications, revisions, preferences, push subscriptions, rate limiting, access tiers, purchases, migrations
- Song data: MySQLi prepared statements (db_mysql.php, SongData.php)
- Auth/admin: PDO MySQL (manage/includes/db.php, auth.php)
- SongData.php has JSON fallback mode when MySQL not configured
- Interactive installer: /manage/setup-database (web dashboard, no CLI required)
- JSON migrator, user migrator, backup script, cleanup script — all web-accessible
- Token cleanup: appWeb/.sql/cleanup.php (cron-safe)

**Content Access Tiers (#346):**

- 5 tiers: public (0), free (10), ccli (20), premium (30), pro (40)
- Effective tier = MAX(personal tier, org-level tier) — highest wins
- CCLI licence validation (5-8 digit numeric, auto-upgrades free→ccli)
- tblAccessTiers defines tier capabilities (lyrics, copyrighted, audio, MIDI, PDF, offline)
- tblUserPurchases tracks one-off purchases and subscriptions
- Content gating OFF by default (content_gating_enabled=0 in tblAppSettings)

**Organisations:**

- tblOrganisations with nested hierarchy (ParentOrgId)
- tblOrganisationMembers (owner/admin/member roles)
- tblContentLicences (ihymns_basic/pro, ccli, custom)
- tblContentRestrictions — priority-based rule engine for content lockout
- Org licence type maps to access tier; user inherits highest

**API (60+ endpoints in api.php):**

- Songs: search, song_data, songs, songbooks, random, stats, songs_json, missing_songs, song_key, suggest, popular_songs, related_songs, tags, songs_by_tag
- Auth: register, login, logout, me, email_login_request, email_login_verify, update_profile, change_password, forgot_password, reset_password
- Tiers: ccli_validate, tier_check, access_tiers
- User data: favorites, favorites_sync, favorites_remove, user_setlists, user_setlists_sync, user_preferences, user_preferences_sync, song_history, song_view, user_access, my_organisations
- Setlists: setlist_share, setlist_get, setlist_schedule, setlist_schedule_save, setlist_templates, setlist_template_save, setlist_collaborators, setlist_collaborator_add, setlist_collaborator_remove
- Song requests: song_request, my_song_requests
- Languages: languages, song_translations
- Admin: admin_users, admin_groups, admin_organisations, admin_activity_log, admin_song_requests, admin_song_request_update, admin_restrictions, admin_restriction_create, admin_restriction_delete, admin_export, admin_songbook_health, admin_pending_revisions, admin_revision_review, admin_cleanup, admin_set_user_tier, admin_set_user_ccli
- Push: push_subscribe, push_unsubscribe
- System: app_status, content_access, organisation, organisation_create

**Authentication (3 methods):**

- Password login (username + password → bearer token, SHA-256 hashed)
- Email magic link / 6-digit code (tblEmailLoginTokens, 10-min expiry, disabled when email_service=none)
- Admin session (PHP session + CSRF)
- Brute force protection: tblLoginAttempts, 10 failures / 15 min lockout
- Registration mode: open, admin_only (configurable via tblAppSettings)
- Future: SIGNula.id OAuth2/SSO + TOTP 2FA (#309)

**Accessibility:**

- accessibility.css: high contrast mode (WCAG AAA), RTL support, reduced motion
- Colour blind modes: protanopia, deuteranopia, tritanopia, achromatopsia (SVG filters)
- CVD mode selector in settings page, persists via localStorage
- Songbook badges use distinct border patterns in CVD modes

**Key integrations:**

- SIGNula.id — OAuth2/SSO + 2FA (future, #309)
- CueRCode — QR code generation (#306)
- OpenSong / VideoPsalm — export formats (#314)

**Key files:**

- api.php — 60+ endpoints, comprehensive REST-like API
- SongData.php — MySQL-backed song queries with JSON fallback
- content_access.php — rule-based content lockout engine
- ccli_validator.php — CCLI validation + tier access checking + org tier resolution
- rate_limit.php — centralised rate limiting middleware
- pdf_export.php — setlist PDF generation
- transpose.js — chord transposition (semitone math, capo calculator)
- accessibility.css — high contrast, CVD, RTL
- api-docs.yaml — OpenAPI 3.0 specification
- /manage/setup-database.php — web-accessible DB admin dashboard

**URL policy:** No .php visible in any user-facing URL. All routes rewritten via .htaccess.
