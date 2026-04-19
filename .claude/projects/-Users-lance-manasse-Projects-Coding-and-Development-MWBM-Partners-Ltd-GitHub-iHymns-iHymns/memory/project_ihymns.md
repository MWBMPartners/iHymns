---
name: iHymns Project Context
description: iHymns is a multiplatform Christian lyrics app. v0.10.0 uses MySQL with 30+ tables, 50+ API endpoints, organisations, content licensing, accessibility. Phase 2 will use iLyrics dB API.
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

**Database (v0.10.0+):**

- MySQL 5.7+, InnoDB, utf8mb4
- Naming: tblCamelCase tables, CamelCase columns
- 30+ tables covering: songs, users, orgs, licensing, content restrictions, keys, chords, scheduling, templates, collaboration, tags, history, notifications, revisions, preferences, push subscriptions, rate limiting, migrations
- Song data: MySQLi prepared statements (db_mysql.php, SongData.php)
- Auth/admin: PDO MySQL (manage/includes/db.php, auth.php)
- Interactive installer: php appWeb/.sql/install.php
- JSON migrator: php appWeb/.sql/migrate-json.php
- Token cleanup: php appWeb/.sql/cleanup.php (cron-safe)

**API (50+ endpoints in api.php):**

- Songs: search, song_data, songs, songbooks, random, stats, songs_json, missing_songs, song_key, suggest, popular_songs, related_songs, tags, songs_by_tag
- Auth: register, login, logout, me, email_login_request, email_login_verify, update_profile, change_password, forgot_password, reset_password
- User data: favorites, favorites_sync, favorites_remove, user_setlists, user_setlists_sync, user_preferences, user_preferences_sync, song_history, song_view, user_access, my_organisations
- Setlists: setlist_share, setlist_get, setlist_schedule, setlist_schedule_save, setlist_templates, setlist_template_save, setlist_collaborators, setlist_collaborator_add, setlist_collaborator_remove
- Song requests: song_request, my_song_requests
- Languages: languages, song_translations
- Admin: admin_users, admin_groups, admin_organisations, admin_activity_log, admin_song_requests, admin_song_request_update, admin_restrictions, admin_restriction_create, admin_restriction_delete, admin_export, admin_songbook_health, admin_pending_revisions, admin_revision_review, admin_cleanup
- Push: push_subscribe, push_unsubscribe
- System: app_status, content_access, organisation, organisation_create

**Organisations & Licensing (#326):**

- tblOrganisations with nested hierarchy (ParentOrgId)
- tblOrganisationMembers (owner/admin/member roles)
- tblContentLicences (ihymns_basic/pro, ccli, custom)
- tblContentRestrictions — priority-based rule engine for content lockout
- content_access.php — checkContentAccess() evaluates by org, user, platform, licence

**Authentication (3 methods):**

- **Magic-link is the primary sign-in path** (email + 6-digit code); username/password behind a "Sign in with a password instead" link on the login modal
- Password login (username + password → bearer token) — still supported
- Admin session (PHP session + CSRF) for `/manage/` pages; every write-performing admin page validates a hidden `csrf_token` input via `validateCsrf()`
- Bearer token: `Authorization: Bearer` header **and** `Set-Cookie: ihymns_auth; Domain=.ihymns.app; HttpOnly; SameSite=Lax; Secure` — cross-subdomain sign-in plus ITP-resistant persistence (#390)
- Sliding 30-day expiry — `slideAuthTokenExpiry()` bumps at most once per day per token (#390)
- `user-auth.js#verify()` hardened: only 401/403 clears credentials (#390)
- Brute force protection: tblLoginAttempts, 10 failures / 15 min lockout
- Future: SIGNula.id OAuth2/SSO + TOTP 2FA (#309)

**Entitlements (#407):**

- Capability-based permission layer on top of roles. `includes/entitlements.php` (PHP, authoritative) + `js/modules/entitlements.js` (UI affordance only).
- Admins reassign at `/manage/entitlements`; overrides in `tblAppSettings.SettingKey = 'entitlements_overrides'`.
- Default entitlements: `edit_songs`, `delete_songs`, `bulk_edit_songs`, `verify_songs`, `view_users`, `edit_users`, `change_user_roles`, `assign_global_admin`, `delete_users`, `view_admin_dashboard`, `view_analytics`, `run_db_install/migrate/backup/restore`, `drop_legacy_tables`, `review_song_requests`, `access_alpha`, `access_beta`, `manage_entitlements`.
- `alpha.` / `beta.ihymns.app` gated by `access_alpha` / `access_beta` via `includes/channel_gate.php`; gate page embeds magic-link form.

**Admin portal (`/manage/`, alias `/admin/`):**

- Dashboard with Library + Activity stat groups (Songs, Songbooks, Synced setlists, Pending requests, Active/Total users, Logins 24h, Song views 24h).
- `/manage/editor/` — per-song auto-save via `/api?action=save_song`, multi-select bulk delete, revision audit log.
- `/manage/users` — accounts/roles/passwords.
- `/manage/requests` — song-request triage queue.
- `/manage/analytics` — 7/30/90-day top songs/books/queries, zero-result queries, CSV export.
- `/manage/entitlements` — role × capability matrix editor.
- `/manage/setup-database` — install/migrate/backup/restore/drop-legacy, credentials form, backup upload.

**Recent shipped features (Apr 2026, alpha):**

- Scripture-aware search (abbreviation expansion + tag match).
- Practice mode (Full/Dimmed/Hidden + tap-to-reveal).
- Misc songbook supports NULL Number; book-glyph badge.
- Title Case renderer everywhere; admin area re-skinned to main palette (`css/admin.css`).
- SW update toast restored; on-demand audio cache + bulk_audio manifest + Settings toggle.
- Song-request public form (rate-limited, honeypot) + admin triage queue.
- Backup restore UI (server-list + upload-from-device) with audit log on upload.
- Setlist scheduling API endpoints (UI + collaboration deferred to follow-up).

**Accessibility:**

- accessibility.css: high contrast mode (WCAG AAA), RTL support, reduced motion
- Colour blind modes: protanopia, deuteranopia, tritanopia, achromatopsia (SVG filters)
- Songbook badges use distinct border patterns in CVD modes

**Key integrations:**

- SIGNula.id — OAuth2/SSO + 2FA (future, #309)
- CueRCode — QR code generation (#306)
- OpenSong / VideoPsalm — export formats (#314)

**Key files:**

- api.php — 50+ endpoints, comprehensive REST-like API
- SongData.php — MySQL-backed song queries with prepared statements
- content_access.php — rule-based content lockout engine
- rate_limit.php — centralised rate limiting middleware
- pdf_export.php — setlist PDF generation
- transpose.js — chord transposition (semitone math, capo calculator)
- accessibility.css — high contrast, CVD, RTL
- api-docs.yaml — OpenAPI 3.0 specification
