# 📋 iHymns — Project Brief

> **Claude Context File** — This file ensures continuity across development sessions.

---

## 🎯 What Is iHymns?

A multiplatform Christian lyrics application providing searchable hymn and worship song lyrics from multiple songbooks, designed to enhance worship.

- **Domain**: [iHymns.app](https://ihymns.app)
- **Copyright**: © 2026– MWBM Partners Ltd
- **License**: Proprietary (third-party components retain their own licenses)
- **GitHub Repo**: <https://github.com/MWBMPartners/iHymns>
- **Current Version**: `0.990.0` (alpha, Phase 1). ✅ **MERGED TO ALPHA 2026-06-05 (PR #1160, merge `586b2265`; auto-merged + SFTP-deployed; CI green).** The **DB-direct data-layer rewrite** (epic #1010, WS-A→WS-K) — every read hits live MySQL; the whole-corpus `songs.json` file cache **decommissioned** (WS-J #1020); a DB outage returns a themed 503 (WS-K #1021) — plus the **v0.550→0.770 multi-format & lyrics-platform program** (one-pass schema #1066/#1088/#1090, importers, the #1147 home-UX rethink) is now **live on alpha** (88-commit PR: 253 files, +45k/−8.5k, 31 additive/dormant migrations). Verify-pass follow-ups also merged: W3C validity fixes (#1161/#1150), the song-link-confidence migration **OR-probe** drift fix + the v0.770.0 bump (#1162). main was promoted from beta on 2026-05-07 (PR #896, merge `76154dc4`) and the release line sits at 0.25.2. Feature flow continues through the 2026-05 #840–#852 catalogue-refresh batch (Works composition grouping, DB-driven URL auto-detect, responsive admin lists, sortable headers everywhere, bulk-promote credit-people, plus three CI/auto-merge hotfixes). Bulk-import UX improvements landed 2026-05-09 in #909 (per-songbook breakdown), #910 (activity-log per-failure rows + summary headers), and #911 (instant + live progress UX with phase labels, XHR upload progress, top-right reposition, auto-dismiss).
- **Database**: MySQL 5.7+ (~50 tables, tblCamelCase naming). All three subdomains (`dev.ihymns.app` = alpha, `beta.ihymns.app` = beta, `www.ihymns.app` = main) connect to a **single shared MySQL** at `mysql.MWBMpartners.ltd:3306` / DB name `ihymns` — confirmed via `/manage/setup-database` connection banner across all three. 2026-04 added songbook metadata extensions (#672), an Affiliation registry (#670), optional Language column (#673 → composite IETF BCP 47 with `tblScripts` + `tblRegions` in #681), `tblBulkImportJobs` async-job table (#676), and Activity Log Result/Details columns (#695). 2026-05 added the MusicBrainz-style external-links registry (#833 — `tblExternalLinkTypes` + `tblSongExternalLinks` + `tblSongbookExternalLinks` + `tblCreditPersonExternalLinks`), Works composition grouping (#840 — `tblWorks` with self-FK nesting + `tblWorkSongs` + `tblWorkExternalLinks`, plus `AppliesTo` SET widened to `'work'`), curator-editable URL → provider rule table (#845 — `tblExternalLinkPatterns`), per-component language override (#858 — `tblSongComponents.Language`), song media uploads (#853 — `tblSongMedia`), arrangement persistence (#892 — `tblSongs.ArrangementJson`), and bulk-import diagnostics (#906 + #907 — `tblBulkImportJobs.PerSongbookJson` + `PhaseLabel`).
- **API**: 60+ JSON endpoints via `api.php` (now including public `action=scripts` + `action=regions` listings for native clients, #682, and `?page=work&slug=…` for the Works public page, #840), plus the editor's separate `/manage/editor/api.php` (load / save_song / bulk_import_zip / bulk_import_status / typeaheads). OpenAPI 3.0 spec at `appWeb/public_html/api-docs.yaml` (refreshed for Works + ExternalLink shared schemas in #843)
- **★ Latest landings (2026-06-21, all merged to alpha):** **Service Mode** (#1323) — congregation-wide Live-Follow — landed its entire server side + congregant client + a dormant CCLI gate via PR #1334 (Phase 1 venues/schedules #1325 + the dormant external-systems/WebMS-Intra hook #1327 + Phase 2a/2b/2c + Phase 3). Also: songbook **`DisplayAbbr`** display-label decouple (#1332), Chorus/Refrain **italics** (#1337), and consistency fixes (hide abbr==title #1328, prune deleted songs from Recently Viewed #1329, dedupe maintenance banner #1330). **Service Mode is ~90% built** — the two **broadcaster song-driver UIs** + the projection **QR** + live multi-device verify are the queued next work; the **CCLI unlock is dormant behind `content_gating_enabled='0'`** and needs `require_licence:ccli` rows + the owner's optional CCLI confirmation (#1324, risk accepted). The 4 new migrations (Org Venues, External-systems, Songbook display label, Service Mode sessions) are **not auto-applied** — run them on `/manage/setup-database`. See **CLAUDE.md rules #26/#27**, `.claude/live-congregant-strategy.md`, and the auto-memory resume + `.claude/sessions/2026-06-21-HANDOFF.md`.

---

## 📐 Two-Phase Approach

### Phase ONE (Current) — v0.x.x (pre-release)

- Songs sourced from local `.SourceSongData/` text files
- Parsed into JSON (`data/songs.json`), then migrated into **MySQL database**
- ~30 songbooks, 12,370+ songs after the CIS scrape (#663 / 2026-04-29). Original five English: CP (243), JP (617), MP (1355), SDAH (695), CH (702); plus 23 multi-language CIS hymnals (Spanish HA, Portuguese HASD, French DLG, Russian GASD, Twi TWI, Tonga TKMN, Tswana KMK, Sotho KP, Chichewa KMN, Shona KMNz, Venda NYD, Swahili NZK, Ndebele UKE, Xhosa UKEng, Xitsonga RRV, Gikuyu NCA, Abagusii OKON, Dholuo WN, Kinyarwanda IZGI, Tumbuka NMSDA, Sepedi KKK, Bemba BKMN, English CIS) plus Misc + AH/AYS/NAH placeholder books
- Multilingual sister-site scraper expansion (#699 Phase A + B, 2026-04-30): the SDAHymnal scraper now covers 12 sites — sdah, ch, ha (es), nha (es), hasd (pt), hl (fr), ia (it), hac (sr-Latn), hp (bg), hjp (mk), pes (sr-Cyrl), pj (hr) — plus an opt-in cross-source integrity check (`--prefer-source`) that diffs ChristInSong extracts against fresh scrapes. Audit findings in `.importers/audits/2026-04-30-cross-source-integrity.md`: English sources match perfectly; HASD has ~11% real data-quality issues (Latin-1 → Latin-2 encoding corruption + OCR-style errors in CIS — SDAHymnal is the cleaner source).
- Non-English SDA scrape complete 2026-05-07 in `SourceSongData/_SDAHymnal Export 2/` — 5,167 hymns across 10 hymnals using **canonical native-script folder names** taken from sdahymnal.org's "Choose another Hymnal" picker (#901): `El Himnario Adventista (1962) [HA]_Spanish-es` (527), `El Himnario Adventista Nuevo (2010) [NHA]_Spanish-es` (613), `Hinário Adventista [HASD]_Portuguese-pt` (610), `Hymnes et Louanges, Cantiques [HL]_French-fr` (654), `Innario Avventista [IA]_Italian-it` (653), `Hrišćanske adventističke himne [HAC]_Serbian (Latin)-sr-Latn` (490), `Християнски песни [HP]_Bulgarian-bg` (300), `Христијански песни [HJP]_Macedonian-mk` (340), `Хришћанске адвентистичке химне [PES]_Serbian (Cyrillic)-sr-Cyrl` (490), `Kršćanske adventističke himne [PJ]_Croatian-hr` (490). Bracketed `[<ABBR>]` stays Latin/ASCII so it round-trips through `tblSongbooks.Abbreviation` (`VARCHAR(10)` indexed) + URL routing. Diagnosis-of-the-day (HA #331 wall): `HymnParser._depth` bug on void HTML tags (`<br>` increments depth without a matching close), not a server block — fix in PR #894.
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
- `appWeb/data_share/` — Shared runtime data (deployed alongside public_html). **NOTE:** the whole-corpus song read cache (`data_share/song_data/songs.json` + the SQLite mirror) was removed in WS-J #1020 — reads now go DB-direct. This dir no longer carries a materialised corpus.
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

### Cross-environment data sharing

The three subdomains (`dev.ihymns.app` = alpha, `beta.ihymns.app` = beta, `www.ihymns.app` = main) connect to **one shared MySQL** at `mysql.MWBMpartners.ltd:3306` / DB name `ihymns`. There is no separate alpha-DB / beta-DB / prod-DB.

State sharing across subdomains is **asymmetric** — easy to misdiagnose if you forget which axis is per-origin and which is `.ihymns.app`-scoped:

| Layer | Scope | Notes |
| --- | --- | --- |
| MySQL row data | shared | One DB. A row written on alpha is immediately visible to beta + main. |
| `ihymns_auth` cookie | `.ihymns.app` | Set by `setAuthTokenCookie()` / `_authCookieOpts()`. Cross-subdomain auth IS designed to work. |
| `ihymns_sync` cookie | `.ihymns.app` | Lightweight settings sync (theme, font size, default songbook) via `js/modules/subdomain-sync.js`. Only path for cross-subdomain settings. |
| Bearer token in localStorage | per-origin | W3C spec — each subdomain has its own. The cookie fallback in `getAuthBearerToken()` covers the gap. |
| Service-worker cache | per-origin | Caches only 2xx responses, so a 503 maintenance page is never cached. With DB-direct reads this is no longer a "stale song data" vector for logged-in reads; it can still serve stale *static assets* per-origin until SW update. |
| Setlist / favourite / tag / history | **DB-first (signed-in)** | Since WS-F/G (#1011/#1012) these sync to MySQL on every edit (authoritative-replace + first-login MERGE backfill) — cross-device + cross-subdomain by design for signed-in users. localStorage is the offline mirror, not the source of truth. Anonymous users remain local-only. |

When debugging "subdomain X has the data but Y doesn't", follow the diagnostic sequence in `.claude/project-rules.md` Section 14.3.

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
| **MySQL `ihymns` DB** | **Canonical song database (single source of truth).** Every runtime read is DB-direct via `SongData` / `getSongsSlimIndex()` / `getSongs($abbr)` / `getSongById()`. |
| `data/songs.json` | Ingestion **seed/build artifact** only (source-files → parse → DB). NOT a runtime read cache — that was removed in WS-J #1020. |
| `data/songs.schema.json` | JSON Schema (draft 2020-12) for the ingestion songs.json validation (#226) |
| `tools/parse-songs.js` | Parses .SourceSongData/ → songs.json (ingestion seed) |
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

---

Last updated: 2026-05-10 — refreshed at the close of the post-#852 catch-up batches (v0.50→v0.110):

### Major batches landed since 2026-05-04

**Activity-log + auth-resilience cluster (#917–#931).** Real email delivery for magic-link, reset, register, admin force-reset (#922 closes #898 P0/security); per-request rows + IPv6/proxy/VPN resolution in `tblActivityLog` (#919); every uncaught throwable + PHP fatal mirrored to the activity log (#918); editor 5xx error detail surfaced in the toast (#927); defensive `bindParamSafe()` wrapper that prevents the silent activity-log regression class of bug (#928, retrofit of `activity_log.php` after `'isssssssssssssi'`-vs-14-placeholder typo); migrations use `getDbMysqli()` instead of bogus `MYSQL_HOST` constants (#930).

**Songs static cache (#933).** Replaces on-demand `SongData::exportAsJson()` rebuild on every editor open / PWA cold-start with a precomputed on-disk cache regenerated on save. Per-request peak memory drops from ~140 MB to <2 MB; wire size drops from 5.96 MB to 928 KB on gzip-9. Save-hook regen wired into editor `save_song` + four bulk-import flows; manual regenerate button on `/manage/data-health`. Reverts #931's 512 MB band-aid.

**Credit-people structured-name split (#935).** Adds `FirstNames` / `Surname` / `Suffix` columns to `tblCreditPeople` alongside the canonical `Name`. Backfill heuristic peels Jr/III/PhD trailing suffixes; comma-inverted "Wesley, Charles" handled. Group / special-case rows leave the three columns NULL. `Name` is recomposed on individual saves so all 30 read sites keep working unchanged.

**Quick-wins batch (#948).** Seven commits in one PR: rebuild-bug fix on `/manage/song-link-suggestions` (script lived at project-root `tools/` but only `appWeb/public_html/` deploys — #937); inline labels on `/manage/my-organisations` licence-row inputs (#936); visual separation between media-kind blocks in the Song Editor's Media tab (#938); design-intent doc-comment for the future PD-gating tier (#939, lyrics-PD and music-PD must be checked independently); public song-page metadata becomes navigation — Tune name → `/tune/<slug>`, CCLI # → SongSelect new tab, ISWC → `/iswc/<code>`, plus credits-block parity + Works-graph translations (#940); Catalogues many-to-many song grouping concept (#941, schema + admin CRUD at `/manage/catalogues`); one-shot Works backfill from existing ISWC values (#942).

**Docs port + version bump (#950).** Recovered durable engineering content stranded on the auto-undeleted `claude/chore-claude-context-sync` branch into `.claude/project-rules.md` Section 14 (HTTP-block triage, void-element parsers, per-origin browser state) and a Cross-environment data sharing subsection here. Version 0.100.0 → 0.110.0.

**Centralised link styling (#952).** Kills Bootstrap default `<a>` blue + solid-underline site-wide; replaces with `.song-meta-link`-style muted dotted-underline + accent on hover. Bootstrap component classes (`.btn`, `.nav-link`, `.dropdown-item`, `.breadcrumb-item a`) keep their styling via specificity. Credits-block author / composer footer is now clickable too (parity with the header).

### In-flight (PRs awaiting merge)

- **#954** — one-line catalogues dark-mode regression fix (will be superseded by #956).
- **#956** — admin pages obey user theme preference (Light / Dark / High-contrast / CVD / System) via the new `admin-theme-init.php` synchronous resolver in `<head>`. Drops hardcoded `data-bs-theme="dark"` from every admin page; admin-nav theme dropdown now persists to `localStorage.ihymns_theme` and round-trips with the public site.
- **`claude/fix-pending-migrations-Vj4SQ`** — migration counter never reached zero on `/manage/setup-database` because five probes were hard-coded `=> true`; replaced with smart probes that detect actual completion. Schema.sql sync added the 18 tables + 10 columns previously orphan-in-DB on the audit page (Catalogues, Works, ExternalLinks family, Multi-language, Songbook Series, Compilers, SongMedia, Alternative Titles + the 10 column-additions on tblUsers/tblSongbooks/tblBulkImportJobs/tblSongs/tblCreditPeople/tblSongComponents). Schema-audit page now surfaces uncovered + missing columns by name in an "Action items" card directly under the banner. Two new CI tests guard the regression classes: `tests/php/test-migration-registry.php` (every `$migrationOrder` slug has a matching `$migrationProbes` entry; no probe is the always-true placeholder) and `tests/php/test-schema-coverage.php` (every migration-created table/column is mirrored in `schema.sql`). CLAUDE.md gained checklist entry 19 + two red-flag bullets codifying the discipline. Run both locally via `bin/audit-schema`.

### New tracking issues (deferred, full design captured)

- **#943** — Works ISWC API integration (ISWCnet + MusicBrainz + MRO IDs).
- **#944** — UI i18n + Translator role + Roles admin area.
- **#945** — Naming cleanup: User Groups / Access Tiers / Roles / Entitlements / Licence Types vocabulary audit.
- **#946** — Analytics expansion (user/referral/entry-exit) + external platform integration (GA4 / Plausible / Matomo).
- **#947** — Login forms: Cloudflare Turnstile / reCAPTCHA / hCaptcha admin-configurable CAPTCHA.

### Open priority items

1. **#945 (naming cleanup)** — most-impactful of the deferred large issues; every other large issue (especially #944 i18n) benefits from clearer vocabulary first.
2. **DB-isolation diagnostic queries** from `project_db_environment_isolation_open.md` memo — cheap to run now that #898's email delivery is live; confirms whether the missing-user-signup hypothesis is closed.

---

Last updated: 2026-06-03 — **DB-direct data-layer rewrite complete + custom error pages + codebase hardening pass.** Branch `claude/db-direct-data-layer`, version `0.200.0`. NOT yet PR'd to alpha (owner gates the push).

### The data-architecture fix (epic #1010 — root cause + remedy)

**Root cause** of the long-running "alpha has the data but main doesn't" class of bug: song *reads* never touched MySQL. They served a **per-environment `songs.json` file cache** (`data_share/song_data/songs.json`, built writes-only on save). Each subdomain had its own file, so a row written on alpha was invisible on main until that env's file was regenerated — staleness was structural, not a SW-cache fluke. Remedy (owner's emphatic decision): **rip out every JSON/SQLite file read cache; make every read DB-direct; make setlists/favourites DB-first with auto-sync.** Full design in `.claude/data-architecture-remediation.md`.

### Workstreams shipped (WS-A → WS-K)

- **WS-A #1014 / WS-B #1015** — live DB search + live Song-of-the-Day (no corpus materialisation).
- **WS-C / WS-D / WS-E** — DB-direct read paths across song/songbook/tune/iswc/work/person pages; live songbook-name JOINs (no denormalised columns; #1013).
- **WS-F / WS-G #1011/#1012** — setlists, favourites, custom tags, view-history are now **DB-first auto-sync**: authoritative-replace per edit (deletions propagate, LWW) + first-login MERGE backfill (union, no loss); `_syncReady` gate arms destructive replace only after merge hydrates the cache; server-side tag-union in merge mode. New `tblUserFavorites.Tags` (JSON) + `tblUserCustomTags`; migration `migrate-user-data-sync.php`.
- **WS-I** — PWA offline uses a **slim song index** only; the whole corpus is precached **nowhere**.
- **WS-H** — lightweight DB-direct paginated song index endpoint (#1012).
- **WS-J #1020** — **decommissioned** `includes/songs_cache.php` + `SongData::exportAsJson()`; deleted `data_share/song_data/songs.json` + the SQLite mirror; editor `?action=load` → `songbook_export` (`getSongs($abbr)`); `SongData` is DB-only (constructor throws if no DB; no JSON fallback).
- **WS-K #1021** — **system maintenance mode** (`includes/maintenance.php`): admin toggle in `/manage/configuration`, themed 503 intercept in `index.php` + `api.php` (exempts `/manage/*` entry point, `app_status`, and `auth_*` so admins can never lock themselves out), `isDbConnectionFailure()` turns an unreachable DB into a graceful 503 — never stale data.

### Custom error pages (theme-aware, PWA-offline-capable)

`includes/error_page.php` (`renderErrorPage` / `renderErrorFragment` / `renderContentGatedFragment`) + standalone `error.php` (status whitelist 400/401/403/404/429/500/502/503). Page fragments (song/songbook/work/person/not-found) render in-theme 404s; bootstrap + maintenance failures render a themed 503; service-worker `OFFLINE_FALLBACK_HTML` is theme-aware so offline errors still match light/dark/HC. `.htaccess` `ErrorDocument` 403/500/503 → `/error.php`. Forward-looking **gated-lyrics** fragment wired into `song.php` behind the `content_gating_enabled` flag (copyrighted-lyrics gating is under consideration).

### Codebase hardening pass (the queued 7-task program)

1. **Lint/validity sweep** (`4acf696b`) — fixed an undefined-`$db` TypeError on successful VideoPsalm bulk-import in `editor/api.php`.
2. **Security audit** (`ca5aefb7`) — added postMessage origin+source validation to `storage-bridge.js` (CWE-346) and a same-site `_manageSafeRedirect()` guard on `manage/login.php` (CWE-601). Rest of the codebase verified clean (no SQLi/XSS/IDOR/committed-secrets).
3. **API/OpenAPI + help docs** (`26889b41`) — `api-docs.yaml` version 0.57→0.200, 6 newly-documented endpoints (song_detail/songs_list/song_links/catalogue_language_subtags/auth_verify_email/auth_update_avatar_service) + 5 new `app_status` fields; help-page Favourites cross-device-sync note.
4. **GitHub issues audit** — 325 open issues classified against the live codebase (17-agent workflow); ~178 verified-implemented/obsolete closed with per-issue evidence comments (excluding the unmerged #1010–#1021 epic and #4 never-built genre filter).
5–6. **`.claude/` + memory refresh** (this update) and **enhancement-issue backlog** creation.

### Deferred hardening (tracked as new enhancement issues)

CDN SRI on Bootstrap/Tone.js/pdf.js; SW message-handler validation; unguessable storage-bridge request IDs; editor-API OpenAPI coverage gap; content-gating auth-on-page-fetch + cache-exclusion follow-ups.

---

Last updated: 2026-06-05 (cont. 2) — **Adversarial security + WCAG 2.2 audits (fixed), W3C validity fixes, channel-gate lock-out fix, sheet-music design, and the standing-tasks governance convention.** Branch now **85 commits ahead of `origin/alpha`, never pushed**. _(Version note: the authoritative app version is **0.550.0** per `infoAppVer.php`; earlier mentions of "0.200.0" in this brief are stale.)_ Security audit → fixed a CRITICAL SQL-injection in the EasyWorship importer + `.mxl` path-traversal + 4 role→entitlement checks (`2e1f31ff`); legacy file-editor risk filed #1157. WCAG 2.2 audit (57 confirmed) → fixed SPA focus-management, `:focus-visible`, `aria-live`, and a 24px target-size floor (`94736c16`+`73806ae8`); full checklist on #1151. W3C → 5 `index.php` ARIA-on-div validity errors fixed (`71d00e6b`). **Global Admin now always bypasses the invite-only channel gate** so it can't lock itself out (`c9982cb4`). Sheet-music score-attach designed (#1155, FRBR tune→work→song→arrangement) + MIDI→MusicXML (#1156). **Governance:** the after-work consistency discipline is now policy — `.claude/standing-tasks.md` + CLAUDE.md § "Standing consistency tasks" (annotate code + update Issues/Milestones/Wiki/.md/.claude every time); new `SECURITY.md` + `LICENSING.md`; README refreshed; #1158 (annotation backfill) + #1159 (issue sweep) programs; 4 Milestones created. Full detail: `.claude/sessions/2026-06-05-HANDOFF.md` Continuation 2.

Last updated: 2026-06-05 (cont.) — **Home-UX rethink + import parsers + design rethinks (OpenLyrics, gating).** Branch now **78 commits ahead of `origin/alpha`, never pushed**. Six further local commits this session: the flat ISO-code language wall → a compact searchable dropdown picker (#1149, `resolveLanguageMeta()` added to `language_names.php`, fixes home + `/songbooks`); the Browse-by-Theme wall → a Top-8 "Popular Themes" strip with counts via a new `popular_tags` endpoint reusing `manage/tags.php`'s UseCount JOIN (#1148); the existing `'home'` card-layout surface wired into the public home via **client-side hydration** (`applyCardLayout()` in `card-layout.js` — the home fragment is shared-cache so the server can't emit a per-user order) (#448 reopened); a home heading-outline a11y fix; and a tested **MusicXML parser** (`includes/MusicXmlImporter.php` + `tests/php/test-musicxml-parser.php`, 25 assertions) for #1096 (parser only — editor wiring + DB write + real-export validation still TODO). Design rethinks logged as issues, not code: OpenLyrics (#1152 seed the 164-theme CCLI/OpenLyrics `themelist.txt`, #1153 dedupe-safe repo import, #1154 techniques-to-borrow; never-overwrite-`Verified` guard added to #881/#1052) and the **feature-gating system** (#946 expanded from a rename pass to a 4-axis structural model — Staff capability / Content access / Tenancy / Build channel — plus a per-user "Effective access" inspector; verified-dead `tblUserPermissions` + orphaned group channel flags flagged for removal; superseded #642). Full detail: `.claude/sessions/2026-06-05-HANDOFF.md` continuation section.

Last updated: 2026-06-05 — **Multi-format interchange + lyrics-ingest program: one-pass forward-looking DB schema shipped (two batches) + cross-repo issue map.** Branch `claude/db-direct-data-layer`, still local-only (owner gates the push). This continues the multi-format worship-software import/export + TTML lyrics-ingest program (the v0.550 lyrics-platform work spanning iHymns / iLyricsDB / MeedyaDL), with the **schema deliberately shipped in two additive, idempotent, CI-green batches so we never run multiple migration rounds as the features are built**. Both batches were produced by a design → adversarial-stress → implement → verify workflow (the same discipline #1010 used). Reads stay DB-direct (rule #17); all new tables are additive and dormant until their consuming feature lands.

### Schema batch A — #1066 one-pass (interchange fidelity · ingest hardening · identity)

Six migrations under `appWeb/.sql/` (each mirrored to `schema.sql` + one `migration-registry.php` entry with a **real** probe; both CI guards green; FK types/collation verified; adversarial verification verdict **SHIP**):

- `migrate-interchange-fidelity.php` — `tblSongComponents.ChordsJson` + `.NotesJson` (lossless chord + presenter-note round-trip), `tblSongArrangements` (named component reorderings the PP7 exporter reads via `IsDefault=1`; carries `Description`/`KeySignature`/`CapoFret`).
- `migrate-ingest-review-queue.php` — `tblLyricsConflicts` + `tblLyricsReviewQueue` (moderation gate between ingest and the read path; `AssignedTo` for multi-curator claim).
- `migrate-api-key-hardening.php` — `tblApiKeys.RateLimitPerMin/PerDay`, `tblApiKeyUsage` (rolling counters; `Scope` reserved in the unique key for per-endpoint limits), `tblApiKeyIdempotency` (safe-retry cache; `ExpiresAt` is **DATETIME**).
- `migrate-song-normalized-title.php` — `tblSongs.NormalizedTitle` + index + **backfill** via `ihymns_normalize_title()` (indexed dedup/match pre-filter; PHP still does the exact compare).
- `migrate-song-link-confidence.php` — `tblSongLinkSuggestions.Confidence` (ENUM high/medium/low) + `Signal` (VARCHAR).
- `migrate-song-identity-map.php` — `tblWorks.MusicBrainzWorkMBID` (composition identity on the work), `tblSongIdentityMap` (recording identity: ISRC/MusicBrainz/Spotify/Genius — **SongId is a non-unique index**, a song maps to many recordings; uniqueness on the external-id columns), `v_ChristianSongs` (slim **`SQL SECURITY INVOKER`** read fence, id/title/songbook/flags only). Change history → `tblActivityLog` (no dedicated table).

GATED (NOT shipped — blocked on the DB-merge decision, #1010 follow-on): the iLyricsDB bridge (`tblSongIdentityMap.ILyricsDBSongId` + bridge views). Issues #1085/#1086.

### Schema batch B — #1088 per-line lyric enrichment

`migrate-line-enrichment.php` (+ schema.sql + registry, both CI guards green, migration↔schema.sql drift-checked identical):

- `tblLyricLineTranslations` — per-line meaning **translation** + **romanization/transliteration**, modelling the Apple Music TTML `<translation>`/`<transliteration>` head tracks. Anchored on `tblLyricLines.Id` (BIGINT). Natural key `(LineId, TargetLanguage, Kind, Source)` admits Apple + human + machine and both kinds on one line/lang; `IsPrimary` picks the display row; `IsAutoGenerated` drives a machine-translation badge.
- `tblLyricLineAnnotations` — Genius-style **explanatory gloss** over a span. `StartLineId` (+ nullable `EndLineId`) + nullable `StartOffset`/`EndOffset` (0-based UTF-8 code-point, exclusive end) express sub-line phrase / whole-line / multi-line with no later ALTER. First-class indexed `IsVerified` badge. (Per-user auditable voting deferred to a future `tblLyricAnnotationVotes` table.)

Both anchor on the normalized `tblLyricLines` read path (distinct from `tblSongTranslations` = whole-song, `tblSongComponents.NotesJson` = presenter notes). All growable/moderation vocab is **VARCHAR not ENUM**; language tags are free-text `VARCHAR(35)` (no FK to `tblLanguages`) so TTML/LRC script subtags never RESTRICT-fail ingest; CASCADE to the line/lyrics, SET NULL to users; `(Source, SourceRef)` makes re-import idempotent.

### New schema objects (add to the DB summary)

Batch A tables/views: `tblSongArrangements`, `tblLyricsConflicts`, `tblLyricsReviewQueue`, `tblApiKeyUsage`, `tblApiKeyIdempotency`, `tblSongIdentityMap`, `v_ChristianSongs` (+ columns on `tblSongComponents`, `tblApiKeys`, `tblSongs`, `tblSongLinkSuggestions`, `tblWorks`). Batch B tables: `tblLyricLineTranslations`, `tblLyricLineAnnotations`.

### Cross-repo issue map (created this session)

- **iHymns:** epic **#1066** + schema #1067–#1072 + features #1073–#1084 + gated #1085/#1086 + G-Presenter **#1087**; line-enrichment schema **#1088** + feature **#1089**.
- **iLyricsDB:** **#147** (dual-mode offset/tempo `<10`=tempo float / `≥10`=percent + syllable-level timing, extends #13); **#148** (per-line translations + annotations parity for the shared core).
- **MeedyaDL:** **#908** (Idempotency-Key push) + **#909** (populate identity signals on push).
- **G-Presenter (#1087):** author has offered a **`.json`** format file — importer/exporter will target G-Presenter JSON once the sample lands (blocked-on-sample, like MediaShout was).

### Durable schema conventions reinforced this session

- **One-pass forward-looking schema:** for a feature family, design the *final* DDL up front (design → adversarial "what forces a second migration?" stress → implement), so additive tables ship once and sit dormant until consumed. No incremental ALTER rounds as the feature is tweaked.
- **VARCHAR not ENUM for any growable / moderation vocabulary** (`Status`, `Kind`, `AnnotationType`, `Signal`, conflict/queue/review vocab) — app-validated against a central map. An ENUM addition is an `ALTER` = the second migration we forbid.
- **Per-line lyric enrichment anchors on `tblLyricLines.Id`** (BIGINT), the normalized read path — not the index-fragile `LinesJson`/`NotesJson` parallel arrays (those are right only for presenter notes/chords).
- Idempotent external re-import via a `(Source, SourceRef)` UNIQUE (multiple NULLs allowed → manual rows coexist).

### Status / next

All 11 working-tree files (8 batch-A + `migrate-line-enrichment.php` + the two shared `schema.sql`/`migration-registry.php`) are **staged-to-commit but NOT yet committed** — awaiting owner go-ahead. Proposed: one atomic commit per batch (or one combined), explicit pathspecs, no push. On alpha apply: the new `/manage/setup-database` cards drive to zero-pending; the NormalizedTitle backfill recomputes all ~3,612 rows (re-runnable). Feature/app code (ingest wiring, curator UI, display, exporters) is tracked by the issues above — none shipped yet.

---

Last updated: 2026-06-10 — **Duplicate & Counterpart Review (epic #1215): the `/manage/duplicate-songs` page unified with the cross-book link-suggestions workflow.** Branch `claude/jolly-heisenberg-gb3hzn` (off alpha). Six commits, **no schema migration** — reuses the dormant `tblSongLinkSuggestions.Confidence`/`Signal` from #1066 + the existing `tblSongLinks` / `tblSongLinkSuggestionsDismissed`.

- **New shared scorer `includes/song_similarity.php` (#1216).** Extracted the builder's private `_bsls_*` maths into one module (`ihymns_sim_normalise` / `ihymns_sim_text` / `ihymns_sim_authors_jaccard` / `ihymns_sim_score` / `ihymns_sim_confidence_tier`). `ihymns_sim_score()` centralises the 0.50/0.35/0.15 title/first-line/authors blend + a hard-ID override (shared ISWC/CCLI/ISRC → certain match) and finally populates `Confidence`+`Signal`. **Two** normalisers remain, clearly named: `ihymns_normalize_title()` = exact dedup fold (grouping/NormalizedTitle/ingest), `ihymns_sim_normalise()` = fuzzy-compare fold (also strips leading articles). Unit test `tests/php/test-song-similarity.php` (22 assertions).
- **`/manage/duplicate-songs` rewritten (#1217/#1218/#1219).** Union-find clustering over exact-title + hard-ID + shared-URL + fuzzy-suggestion edges; heavy fields (first-line/authors/lyrics-count) fetched for **candidate members only** (rule #17). Three sections by book span (cross-book / same-official-songbook / same-collection); per-group probability % + signal chips. Same-official-songbook merges guarded (type-to-confirm + server `force` flag). Cross-book bulk **Link** writes `tblSongLinks`; per-group **Dismiss** writes `tblSongLinkSuggestionsDismissed` (a cluster is suppressed only when ALL its pairs are dismissed); **Rebuild** re-runs the builder in-process.
- **Per-action entitlements + nav unification (#1220).** Page view + Link + Dismiss = `edit_songs`; Merge = `manage_duplicate_songs`. `/manage/song-link-suggestions` → 302 redirect; nav relabelled "Duplicates & Links". Editor inline "Suggested counterparts" panel untouched (reads the API). CLAUDE.md gains checkpoint #22.
- **Deferred (#1221, for consideration):** `tblSongIdentityMap` signal, first-line fingerprint + incremental rebuild, dedicated review-dismissal table, merge-two-groups, cross-language detection.

NOT pushed beyond the feature branch; targets alpha. No `infoAppVer` bump (left to the beta auto-bump). **Pre-existing unrelated test drift** noted: `tests/php/test-opensong-parser.php` + `test-videopsalm-parser.php` fail because their `OPENSONG PARSER` marker no longer exists in `editor/api.php` (parser relocated) — predates this branch, untouched by it.

---

Last updated: 2026-06-10 (cont. 2) — **Standard theme vocabulary + canonicalisation + "Collections" rename merged to alpha (#1152 / #1222 / #1223, PR #1226, merge `ce0d392`); the unofficial-songbook badge — the last open #1223 item — in flight on branch `claude/unofficial-songbook-badge-gb3hzn` (off alpha).**

- **Theme vocabulary (#1152).** `appWeb/.sql/migrate-seed-theme-vocabulary.php` seeds the OpenLyrics `themelist.txt` / CCLI SongSelect taxonomy into `tblSongTags`. Additive, idempotent columns: `ParentId` (self-FK → 2-level Parent/Child hierarchy), `CcliThemeId` (dormant SongSelect number), `Source` (`'curator' | 'ccli-openlyrics'`). Leaf name in `Name`, path via `ParentId`; same-named curator tags promoted to the standard source, not duplicated. Surfaced with its hierarchy on `/manage/tags`. `schema.sql` + `migration-registry.php` updated; CI green.
- **Canonicalisation (#1222).** `/manage/tags` now suggests folding curator-added variant tags into their standard theme (shared `includes/song_similarity.php` scorer), reusing the existing irreversible Merge (variant = source/deleted → standard = target).
- **Collections rename (#1223).** "Catalogue" → "Collection" in user-facing admin copy + the nav entry **only**; the table (`tblCatalogues`/`tblCatalogueSongs`), the `/manage/catalogues` route, `admin.catalogues.*` log keys, the `'catalogue'` entity type, form fields and the `manage_songbooks` entitlement all stay `catalogue` **internally**. Vocabulary tracked in #945.
- **Unofficial-songbook badge (#1223 — this branch).** Server-rendered `.songbook-unofficial-badge` beside the abbreviation on the home tiles, the `/songbooks` list and the songbook header, shown when `tblSongbooks.IsOfficial = 0` (schema `DEFAULT 0` = "curated grouping / pseudo-songbook"). Presentation-only: `getSongbooks()` already returns official + unofficial books (no filter — only 0-song books are hidden); no storage merge (a song's home is `SongbookAbbr NOT NULL`, so a merge would orphan its songs). Bootstrap 5.3 `warning-subtle` tokens (theme-aware); "Unofficial" folded into each tile's accessible name; colour never the sole signal (WCAG 1.4.1). Four files: `includes/pages/{songbooks,home,songbook}.php` + `css/app.css`. **No schema change.** CLAUDE.md gains checkpoints #23 (theme vocabulary) + #24 (songbook official/unofficial + "Collection" naming) and three red-flag bullets. CHANGELOG + this brief updated in the same PR (the #1226 work had shipped code-only).

---

Last updated: 2026-06-10 (cont. 3) — **Version → 0.990.0; badge merged + `/manage/duplicate-songs` blank-page fixed; `.claude/` refreshed for cross-device dev.**

- **Version bump → `0.990.0`** (`includes/infoAppVer.php`; was 0.880.0). Owner-requested; the PWA service-worker cache version auto-syncs off this value (#81), so the bump alone forces clients to refresh.
- **Unofficial-songbook badge merged** — PR #1227 → `alpha` (squash `da3ab73`), auto-merged; content verified byte-for-byte on alpha. Issues #1152 / #1222 / #1223 closed (completed).
- **#1228 / PR #1229 — `/manage/duplicate-songs` rendered a fully BLANK page on alpha; fixed.** Root cause: the DB layer runs mysqli under `MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT` (`includes/db_mysql.php`), so a failing query **throws** `mysqli_sql_exception` — it does NOT return false. The GET detection path was written for *false-return* semantics (`if ($res) {…}`) with no try/catch, so any DB error white-screened it. Trigger: `$suggTableExists` probed `tblSongLinkSuggestions` but gated a read of the newer, **unmigrated-on-alpha** `tblSongLinkSuggestionsDismissed`. Fix: independent existence-probes per optional table + drop the `NOT EXISTS(dismissed)` pre-filter when that table is absent + a top-level try/catch → actionable error card, never blank. Squash `2e67f32`. **Ops follow-up:** apply the pending `tblSongLinkSuggestionsDismissed` migration on alpha via `/manage/setup-database` for full function.
- **`.claude/` cross-device refresh** — new `MEMORY.md` (portable working memory) + `sessions/2026-06-10-HANDOFF.md`; `CLAUDE.md` red-flag + `project-rules.md §9` capture the mysqli-STRICT-vs-`if($res)` anti-pattern; `README.md` lists `MEMORY.md`.
- **Tracked follow-up (unfiled):** ~6 other `/manage/*` pages share the `if ($res = $db->query(...))` false-return assumption — only risky where a query targets a possibly-missing object; worth a sweep (relates to #713).

---

Last updated: 2026-06-14 (session 4) — **Lyrics-normalisation #1235: P3 SHIPPED to alpha; the P4 cutover CODE IS COMPLETE (C1–C7 committed on `feat/lyrics-1235-p4`, draft PR #1262), each phase adversarially reviewed + its bugs fixed; only the operational soak + the gated drop RUN remain.** Full resume map: **`.claude/sessions/2026-06-12-HANDOFF-session4.md`**; plan of record: `.claude/lyrics-normalisation-strategy.md` §11 + §12; cutover runbooks: `.claude/lyrics-cutover-dress-rehearsal.md` + `.claude/lyrics-cutover-promotion-checklist.md`.

- **SHIPPED — PR #1259 (MERGED `7168606d`):** #1235 **P2b + P3** (Id-preserving line diff, per-line language + translation/annotation editor) **plus two data-loss fixes** found by the P4 planning pass: **PF1/R1** (#1257 CLOSED — stale-client save wiped per-line chords/languages; fixed with server-side carry-forward across `save_song`/`component_upsert`/`components_replace`) and **PF2/R3** (#1258 open — enrichment cascade snapshotted to `tblActivityLog` before the diff DELETE).
- **P4 cutover — `feat/lyrics-1235-p4`, DRAFT PR #1262 (kept draft so auto-merge can't land an incomplete cutover):** **C1** `eb3dd686` shared line-first assembler `includes/lyric_lines_read.php` (+9 tests); **C2** `303cf3d2` `PartTypeSlug` backfill + slug-at-write (closes the #1138 NULL gap); **C3** `10c2e407` verification tooling `appWeb/.sql/verify-lyrics-cutover.php` (G-gates; G2 = the byte-identity losslessness proof; writes the drop-gating sentinel) + `tools/export-fidelity-snapshot.php`; **C4** `241a30e5` **read switch** — `SongData` assembles components from `tblLyricLines` (byte-identical; orphaned `_mirror*` helpers removed). All zero-data-loss, revertable, CI-green.
- **P4 cutover — now CODE-COMPLETE (C4-cleanup→C6 committed since session 3):** **C4-cleanup** `97336426` (3 preview-line bypass readers off `LinesJson`); **C5** `91a77dec` **write inversion** — `tblLyricLines` is the write source of truth, the shared engine `lyricLinesWriteComponents()` (Id-stable thin upsert + gated shadow-JSON + the PURE `lyricLinesBuildDesiredFromComponents` + shared `lyricLinesApplyDesired` diff) replaces the LinesJson-sourced projector in all 5 funnels, v2 ed2 helpers drop-safe, CI guard `test-component-json-guard.php`; **load_song follow-up** `ae52f8bc`; **C6 the gated drop** `3a995e22` (`migrate-retire-component-lines-json.php` Stage-0-gated + `regenerate-lines-json-from-lines.php` recovery + schema.sql thin mirror + `@migration-drops` scanner + retired-era guards + `'manual'` registry flag); doc-fixes `ca3d8207`/`8bbdbaba`. **Adversarially reviewed:** C5 = 5 bugs fixed (the big one: read+write gates unified on `tblLyricLines.ChordsJson+Note+PartTypeSlug`), C6 = 4 bugs fixed (the destructive drop excluded from "Apply all" via the `'manual'` flag + `confirm=1`). Decisions: **C-variant**, **cutover-first**, **`tblSongChords` = chord home**, **`Source='ihymns'` reads**.
- **★ RESUME** = push/review PR #1262 → land C4/C5/C6 on alpha → ≥7-night alpha soak → **PROMOTE the full lyric stack to beta + production** (the 2026-06-13 audit found `origin/beta`+`origin/main` are PRE-P1, so the drop's prerequisite is C4/C5 live on ALL THREE shared-DB envs) → **run the drop ONCE on the SHARED DB** (`ihymns@mysql.MWBMpartners.ltd` — NOT per-env; one copy) inside a #1234 freeze with all three UIs paused + a tested backup. See the two cutover runbooks. Keep lyric editing alpha-only until C4/C5 is everywhere.
- **★ Strategic finding (§12):** the "23.8% duplicate part keys forecloses pure-C" figure is **94% scraped repeat-refrain garbage + ~1.3% mis-parses** — so on CLEAN data **pure-C is re-opened**. Logged as the data-cleanup **epic #1260** (`for consideration`): collapse repeats→arrangement, triage the 208 distinct cases, then re-decide pure-C. The owner provided a real production DB dump (gitignored gz; PII) that grounded the whole P4 plan.
- **Owner actions pending:** review/soak #1262 on alpha (apply `component-line-languages` + `lyric-lines-parttypeslug` migrations, run `verify-lyrics-cutover.php --phase=pre`); beta→main promotion (independent); the #1260 cleanup epic. Sibling **#1243** (musical metadata) must consume the C1 assembler, never re-grow a parallel JSON array.
