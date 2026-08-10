# 📊 iHymns — Project Status

> **Quick reference for current project state**

---

## 🚦 Overall Status: 🟢 In Progress

| Area | Status | Notes |
| --- | --- | --- |
| 📋 Project Plan | ✅ Complete | See [Project_Plan.md](Project_Plan.md) |
| 🗂 Project Structure | ✅ Complete | Directories, .gitignore, deployment structure |
| 📖 Help Documentation | ✅ Complete | 8 guides in `help/` + in-app help (21 public topics, 39 admin sections) |
| 🎫 GitHub Issues | 🟢 Active | Highest issue now #1680+ — see GitHub for live open/closed counts |
| 🔧 Song Data | ✅ Active | ~14,000 songs across 30+ songbooks (live count in `tblSongs` — query the DB, don't trust this file); served **live from MySQL** (DB-direct #1010; the static cache was decommissioned #1020) |
| 🌐 Web PWA | ✅ Core + Enhanced | Search (Fuse.js), songbooks, lyrics, favourites, themes (Light/Dark/High-contrast/CVD/System #956), deep linking, WCAG 2.1 AA, offline support |
| 🛠 Song Editor | ✅ Complete | `appWeb/public_html/manage/editor/` — **v2 (granular, per-edit) is now the default** (#1601 scope item 2), 302-redirected from the legacy route; the legacy v1 editor is not retired and stays reachable via `?legacy=1`. v2 has a chords box, an Arrangement (running-order) editor, and per-line translation/annotation panels; bulk import (ZIP / VideoPsalm / OpenSong / FreeShow / EasyWorship / iHymns JSON #1633), media uploads, per-component language overrides |
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
- **Eight pre-gating security fixes** (#1388) — media-byte gating (`contentGatingMediaAllowed()`) for `/song-media/<id>` and the `bulk_audio` manifest; `songbook_export` now strips gated fields per song via `contentGatingApply()`; Service-Mode presence CCLI unlock requires a live heartbeat, not just `IsActive`; first-admin registration race closed with a transaction + row lock on both registration paths; logout clears per-user service-worker caches; `validateCsrfRequest()` no longer accepts `X-Requested-With` alone with no `Origin`/`Referer` at all. Everything is a verified no-op while `content_gating_enabled='0'`.
- **Shared API client, fetch monkey-patch deleted** (#1031) — new `js/utils/api-client.js` (`apiFetch`/`apiFetchJson`) replaces the site-wide `window.fetch` override that `songbook-language-filter.js` installed; fixes an anonymous user's saved language filter being silently ignored on a cold `/search` load.
- **Setlist playback mode** (#1533) — tap a song in an own or shared setlist to arm a floating prev/next nav bar with keyboard navigation and an aria-live announcement; fixes shared setlists being unnavigable. Alongside a Revisions Audit "Open in editor" link fix (#1623).
- **Dead code + doc-accuracy cleanup** (#1612, #1615, #1618) — removed the unused `js/utils/transpose.js` (and its stale service-worker precache entry); corrected the lyrics-cutover verifier's gate-count claim from "10/13" to the actual nine implemented gates, tracking the real gap as #1618.
- **Song Editor v2 becomes the default** (#1601 scope item 2) — `/manage/editor/` now 302-redirects to the granular, per-edit v2 editor; the legacy whole-song editor remains available via `?legacy=1` and is deliberately not yet retired. Shipped once every parity gap found along the way closed: a chords box, an Arrangement (running-order) editor, and per-line translation/annotation panels (#1627); `?tab=` / `?songbook=` / `#number=` / `?open=` deep links, the sidebar songbook filter + sort, `bulk_tag_detach`, and the export lines-per-slide setting (#1628, #1680); and a P0 fix (#1677) for a bug that had made every v2 write return 403 since the shell first shipped.
- **Setlist collaboration finished** (#1638) — invited collaborators are now notified, see shared setlists under "Shared with me," and their view/edit permission is actually enforced (it had shipped write-only and decorative).
- **Cross-device sync data-loss fixes** (#1649) — capped per-user syncs (set lists / favourites / custom tags) no longer silently delete rows that were only dropped by the cap; a new sync watermark stops an older device from deleting another device's newer, unseen writes.
- **Accessibility + security sweep** (#1643–#1648, #1665) — high-contrast/CVD modes restored across the whole `/manage` admin surface (they had never been styled there at all); Present mode is a real focus-trapping dialog; Service Mode announces section changes and no longer races the page render; sortable table headers keep their `columnheader` role; SPA navigation stopped reading whole pages aloud on every route change; the setlist Arrangement editor works by keyboard and touch; the SortableJS and Bootstrap CDN loads gained SRI + vendored fallbacks; eight admin pages' access gates now match what the nav actually advertises.
- **iHymns interchange JSON importer** (#1633) — a new additive/merge-only importer writes iHymns's own JSON export format straight to the database, following the same never-truncate contract as the ZIP importer.

### 2026-08 highlights ✅ (in progress — first entry)

- **Musician registry-vs-registry duplicate detection + easier merge UX** (#1785, follow-up to #1784, epic #1787) — a new live-computed scan (`includes/musician_duplicates.php`) finds registry rows that are probably the same person, blocked (not naive all-pairs) so it stays sub-second at thousands of rows; a new `/manage/musician-duplicates` review page (mirroring `/manage/duplicate-songs`, #1215) offers one-click merge, dismiss/undismiss, and a lifespan-conflict guard on the dangerous class of merge; every merge affordance across the app (the Merge modal, bulk-promote, the new page) now shows WHY two similar names look alike and WHICH registry row is which — closing the "which is merging into which?" confusion the #1784 fix surfaced. The merge core is now one shared function (`musicianMergeExecute()`), closing two data-loss bugs found during its extraction (a stranded sixth credit table; silently cascade-deleted aliases/relations on merge).

---

## 📌 Next Milestones

### Milestone 0 (blocking, not a feature): runtime verification of `claude/wave3-fixes`

~90 commits of correctness work sit on that branch, **none of it ever run against a database or a
browser** — the container has neither. Migrations applied, one v2 editor write, a real songbook
move, two-device setlist sync, and the CCLI gate flipped on in a controlled window. Ranked P0 with
the full sequence in `.claude/proposals-2026-07-31.md`; it outranks everything below because a
runtime surprise there invalidates assumptions the rest build on.


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
- **GitHub Issues**: highest issue now #1696+ — see GitHub for live open/closed counts
- **Phase**: ONE (v0.x.x — pre-release)
- **Version**: 0.4001.0 Alpha (authoritative: `includes/infoAppVer.php`)
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

Last updated: 2026-07-30
