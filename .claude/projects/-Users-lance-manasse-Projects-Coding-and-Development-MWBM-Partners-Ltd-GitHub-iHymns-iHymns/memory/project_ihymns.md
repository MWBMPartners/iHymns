---
name: iHymns Project Context
description: iHymns is a multiplatform Christian lyrics app. v0.10.1 uses MySQL with 30+ tables, 106 public API endpoints + 5 editor-API endpoints, organisations, content licensing, accessibility. Phase 2 will use iLyrics dB API.
type: project
---

**iHymns** — Multiplatform Christian lyrics application for worship.

**Domain**: iHymns.app | **Repo**: https://github.com/MWBMPartners/iHymns
**Copyright**: MWBM Partners Ltd | **License**: Proprietary
**Current version**: 0.10.1 (pre-release, Phase 1 — April 2026 alpha batch, PR #407)

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

**API (106 endpoints in api.php + 5 in manage/editor/api.php, fully documented in api-docs.yaml — OpenAPI 3.0.3):**

- **Songs:** search, search_num, song, song_data, songs, songbook, songbooks, random, stats, songs_json, missing_songs, song_key, song_key_save, suggest, popular_songs, related_songs, tags, songs_by_tag, writer
- **Offline / PWA:** bulk_songs, bulk_audio (#401)
- **Auth:** register, login, logout, me, email_login_request, email_login_verify, update_profile, change_password, forgot_password, reset_password
- **User data:** favorites, favorites_sync, favorites_remove, user_setlists, user_setlists_sync, user_preferences, user_preferences_sync, song_history, song_view, song_revisions, user_access, my_organisations
- **Setlists (current):** setlist, setlist_get, setlist_share, setlist_template_save, setlist_templates
- **Setlists — scheduling (#398):** setlist_schedule_set, setlist_schedule_clear, setlist_schedule_current, setlist_schedule_upcoming
- **Setlists — collaboration (#398):** setlist_collab_invite, setlist_collab_list, setlist_collab_remove, setlist_collab_shared_with_me
- **Setlists (deprecated, pre-#398):** setlist_schedule, setlist_schedule_save, setlist_collaborators, setlist_collaborator_add, setlist_collaborator_remove
- **Song requests:** song_request, song_request_submit, my_song_requests
- **Languages:** languages, song_translations
- **Admin:** admin_users, admin_groups, admin_organisations, admin_activity_log, admin_song_requests, admin_song_request_update, admin_restrictions, admin_restriction_create, admin_restriction_delete, admin_export, admin_songbook_health, admin_pending_revisions, admin_revision_review, admin_cleanup, admin_set_user_ccli, admin_set_user_tier
- **Push:** push_subscribe, push_unsubscribe
- **System:** app_status, content_access, organisation, organisation_create, access_tiers, ccli_validate, tier_check
- **Pages (HTML / alternate-format):** home, help, terms, privacy, settings, csv, xml, json, opensong, videopsalm
- **Editor API (session-auth, /manage/editor/api.php):** load, save, save_song (#394), bulk_tag (#399), list_revisions (#400), restore_revision (#400), get_translations, add_translation, remove_translation

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
- `/manage/editor/` — per-song auto-save via `/api?action=save_song`, multi-select bulk **delete / verify / tag / move / export** (#399), **History modal** with side-by-side JSON diff + Restore button per revision (#400).
- `/manage/users` — accounts/roles/passwords.
- `/manage/requests` — song-request triage queue.
- `/manage/analytics` — 7/30/90-day top songs/books/queries, zero-result queries, CSV export.
- `/manage/revisions` — global audit log across every song-edit revision, filterable by user/song/action/N-day (#400).
- `/manage/entitlements` — role × capability matrix editor.
- `/manage/setup-database` — install/migrate/backup/restore/drop-legacy, credentials form, backup upload, **Pre-flight** mode for safe restore preview (#405).

**April 2026 alpha batch — shipped in PR #407 (v0.10.1):**

- **#390** Cross-subdomain auth cookie (`.ihymns.app`, HttpOnly, SameSite=Lax, Secure) + sliding 30-day expiry.
- **#392** Misc songbook supports NULL Number; book-glyph badge.
- **#394** Per-song save endpoint (`save_song`) + editor auto-save.
- **#395** Magic-link promoted to primary sign-in path; password as fallback.
- **#396** Service-worker update toast restored.
- **#397** Scripture-tag-aware search (abbreviation expansion + curated-tag match merged to the top).
- **#398** Setlist scheduling UI (schedule / clear / upcoming / current) + **collaboration** (invite / permission / remove / shared-with-me) + "Up next" overview card.
- **#399** Editor multi-select + bulk **delete / verify / tag / move / export** (bulk_tag is a new transactional endpoint).
- **#400** Revision capture on every save; editor History modal with diff + restore; `/manage/revisions` admin audit page.
- **#401** Bulk-audio manifest + Settings toggle + on-demand SW cache + per-songbook real-byte size readout + "Remove from offline" per-songbook eviction.
- **#402** Practice / memorisation mode (Full / Dimmed / Hidden + tap-to-reveal).
- **#403** Song-request public form (rate-limited, honeypot) + admin triage queue at `/manage/requests`.
- **#404** Analytics CSV export + `tblSearchQueries` logging + zero-result-queries panel.
- **#405** Backup restore upload + `tblActivityLog` audit + **pre-flight summary** + **pre-restore auto-snapshot** + quote-aware splitter with **transactional INSERT rollback**.
- **#406** Settings → Accessibility toggle to disable keyboard shortcuts.
- Docs: `api-docs.yaml` refreshed to cover every endpoint (106 + 5 editor); help.php / README / CHANGELOG / DEV_NOTES updated.

**Tooling-gap tracking issues (blocked on a human — MCP tool surface limits):**

- **#408** Refresh the repo Wiki (MCP has no wiki endpoints).
- **#409** Create + populate Milestones (MCP can assign, not create).
- **#410** Stand up a GitHub Projects (v2) board (no Projects tools in MCP).
- **#411** Roadmap umbrella; closes when the three above resolve.

**Accessibility:**

- accessibility.css: high contrast mode (WCAG AAA), RTL support, reduced motion
- Colour blind modes: protanopia, deuteranopia, tritanopia, achromatopsia (SVG filters)
- Songbook badges use distinct border patterns in CVD modes

**Key integrations:**

- SIGNula.id — OAuth2/SSO + 2FA (future, #309)
- CueRCode — QR code generation (#306)
- OpenSong / VideoPsalm — export formats (#314)

**Key files:**

- `api.php` — 106 endpoints, comprehensive REST-like API
- `manage/editor/api.php` — 5 editor-only endpoints (session-auth): save_song, bulk_tag, list_revisions, restore_revision, get/add/remove_translation
- `manage/revisions.php` — global revision-audit admin page (#400)
- `manage/analytics.php` — admin analytics + CSV export (#404)
- `manage/entitlements.php` — runtime role × capability editor (#407)
- `manage/requests.php` — song-request triage (#403)
- `includes/entitlements.php` — authoritative capability check (PHP); mirrored in `js/modules/entitlements.js` for UI affordance only
- `includes/channel_gate.php` — subdomain gate for alpha./beta. (uses `access_alpha` / `access_beta`)
- `.sql/restore.php` — pre-flight + quote-aware statement splitter + transactional data-load (#405)
- `SongData.php` — MySQL-backed song queries with prepared statements
- `content_access.php` — rule-based content lockout engine
- `rate_limit.php` — centralised rate limiting middleware
- `pdf_export.php` — setlist PDF generation
- `transpose.js` — chord transposition (semitone math, capo calculator)
- `service-worker.js.php` — SW with CACHE_ALL_SONGS / CACHE_AUDIO_URLS / EVICT_SONGBOOK / GET_CACHE_SIZES handlers (#401)
- `accessibility.css` — high contrast, CVD, RTL
- `api-docs.yaml` — OpenAPI 3.0.3 spec, 106 + 5 endpoints fully covered
