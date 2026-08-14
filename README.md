# iHymns

> **A multiplatform Christian lyrics application for worship enhancement**

[![Version: 0.5200.0 Alpha](https://img.shields.io/badge/Version-0.5200.0%20Alpha-orange.svg)](#environments)
[![License: Proprietary](https://img.shields.io/badge/License-Proprietary-red.svg)](LICENSING.md)
[![Security Policy](https://img.shields.io/badge/Security-Policy-brightgreen.svg)](SECURITY.md)
[![Platform: Web](https://img.shields.io/badge/Platform-Web%20PWA-blue.svg)](#platforms)
[![Platform: Apple](https://img.shields.io/badge/Platform-iOS%20%7C%20iPadOS%20%7C%20macOS%20%7C%20tvOS%20%7C%20watchOS%20%7C%20visionOS-black.svg)](#platforms)
[![Platform: Android](https://img.shields.io/badge/Platform-Android-green.svg)](#platforms)

---

## About

**iHymns** provides searchable hymn and worship-song lyrics from a curated library of hymnals, designed to enhance Christian worship across all devices. Browse, search, and save your favourite hymns — online or offline — in any of the ~20 languages currently catalogued.

**Website**: [iHymns.app](https://ihymns.app) · **Alpha**: [dev.iHymns.app](https://dev.ihymns.app) · **Beta**: [beta.iHymns.app](https://beta.ihymns.app)

---

## Platforms

| Platform | Technology | Status |
| --- | --- | --- |
| Web PWA | HTML5, CSS3, Bootstrap 5.3, vanilla JS, PHP 8.1+, MySQL 5.7+ / MariaDB 10.3+ | **Alpha** (v0.5200.0) |
| Apple Universal (iOS / iPadOS / macOS / tvOS / watchOS / visionOS) | Swift 6.3, SwiftUI, one SwiftPM package (`iHymnsKit`) shared across four thin app shells | Phase 1 + Phase 2 code-complete (iHymnsKit SwiftPM package; watch relay, tvOS projector, Live Activities, App Intents); consolidated and CI-compiled but unreleased; device matrices and APNs provisioning owner-gated |
| Android / Fire OS | Kotlin, Jetpack Compose | Scaffold / in progress |

The Apple app is a single Universal purchase (bundle `app.ihymns`) spanning every Apple platform, with native-only extras — Sign in with Apple, an offline-first cache, Universal Links, Home Screen widgets — layered on top of web-app feature parity. It is in active development and not yet published to the App Store or TestFlight; see the [Native Apps](iHymns.wiki/Native-Apps-(Apple-&-Android).md) wiki page for current status.

---

## Features

### Song browsing & search

- **Full-text search** — title, lyrics, songbook, song number, writer, composer (Fuse.js client-side + MySQL FULLTEXT). Multi-language with primary-subtag filtering.
- **Scripture search** — `Ps 23`, `1 Cor 13`, `Rev 21` etc. via abbreviation expansion + curated tags (#397).
- **Alternative titles** (#832) — songs and songbooks carry "also known as …" entries that surface in search and the public page (search for *Faith's Review and Expectation* finds *Amazing Grace*).
- **Songbook browser** — alphabetical index, language filter, downloadable per book.
- **Number search** — numeric keypad with physical keyboard support; configurable live search.
- **Default songbook** — pre-selects in number search, keyboard quick-jump, and shuffle.
- **Formatted lyrics** — verse, chorus, refrain, bridge with optional numbering and chorus highlighting.
- **Multi-language medleys** (#858) — per-component language overrides apply correct screen-reader pronunciation, browser hyphenation, and JSON-LD `inLanguage` indexing.
- **Multi-level list sorting** (#1786) — a **Sort ▾** control on every catalogue list (songbooks, a songbook's songs, favourites, search results, theme / musician / tune / publisher / work / identifier pages) builds up to 3 sort levels; remembered per surface on the device and synced to the account when signed in.

### Worship tools

- **Favourites** — save songs with custom tags for quick access.
- **Setlists** — create, arrange, and share worship setlists with custom component arrangements. **Playback mode** (#1533) — tap any song in an own or shared setlist to arm a floating prev/next nav bar with keyboard navigation, working identically for shared lists.
- **Setlist scheduling & collaboration** — schedule setlists for a date / time with an "Up next" overview; invite collaborators by email with enforced view / edit permissions, who are notified and see the setlist under "Shared with me" (#398, #1638).
- **Set-list share-by-link** (#1790 / #1791) — a playlist-first read-only **view link** (no account) and a revocable **edit link** whose per-link audience (anyone with the link / signed-in only) an organisation can clamp; the server re-resolves the audience on every write.
- **Presentation mode** — fullscreen lyrics display with configurable auto-scroll.
- **Practice / memorisation mode** — Full / Dimmed / Hidden cycle with tap-to-reveal (#402).
- **Shuffle** — random song from any songbook; highlights your default.
- **Translation linking** — songs linked to equivalent translations in other languages.
- **Song media** (#853) — curators upload audio (MP3 / M4A / OGG / WAV / FLAC / ALAC), sheet music (PDF), MIDI, and MusicXML via the Song Editor; served behind a gated `/song-media/<id>` route with HTTP Range support for audio scrubbing.
- **Transpose** — shift song key up / down (persisted per song); where a curator has recorded a song's original key, tempo and time signature (#298), the song page shows it and Transpose names the key you've transposed *into*.
- **Setlist templates & service plans** (#301) — save a setlist's running order as a reusable template and apply it to start a new setlist with labelled rows (song and non-song) ready to fill in; templates are owner-editable only.
- **Export & Present** (#1565–#1570) — the Export ▾ menu on every song and songbook page downloads the song in 8 worship-software formats (OpenSong, OpenLyrics / OpenLP, ProPresenter 6, ProPresenter 7+, VideoPsalm, FreeShow, Proclaim, ChordPro); Present opens a full-screen one-stanza view.
- **Print templates & PDF** (#1767) — print a song or set list through a curator-designed template; signed-in users also get **Download PDF** (server-rendered — a whole set list becomes one file) and, where the org holds a CCLI licence, a copies-count prompt logged to the CCLI report with an enforced footer notice.
- **Organisation logos** (#1830) — a church uploads its logo (SVG or PNG, in any of ten brand-guide shapes — primary, combined, wide, stacked, symbol-only, name-only, alternative, single-colour, light-on-dark, app icon) from **Manage → Organisations**; a print template's new **Logo** block prints the best available shape automatically or a specific one a curator chooses. SVG uploads pass through a dedicated hardened sanitiser before storage.
- **Live Follow** (#1268 / #1798) — any signed-in user taps **Go Live** on a song and shares a six-character code; others follow along on their own devices, no account needed. A host declares a session length (30 min / 1 h / 2 h / until ended) and can **Extend** it live; an org admin can extend a member's session on their behalf. Distinct from Service Mode (below), which is venue / organisation-based.
- **Service Mode — congregation Live-Follow** (#1323 / #1335) — congregants join a live service via a venue-displayed rotating code and follow songs in sync (org venues + recurring schedules, anonymous presence tokens, two broadcaster UIs at `/manage/service-projection` and `/manage/service-lead`). Ships dormant behind `content_gating_enabled` with a CCLI-licence content gate.

### Catalogue

- **Compilers / editors** (#831) — songbooks credit the people who compiled / edited them, linking to the Credit-People registry.
- **External links** (#833) — MusicBrainz-style typed links registry across songs / songbooks / credit-people: Hymnary.org, IMSLP, Wikipedia, Wikidata, Internet Archive, MusicBrainz, VIAF, YouTube, Spotify, etc. Curator-driven categorisation; surfaces as JSON-LD `sameAs` for SEO.
- **External-link patterns** (#845) — curator-editable URL → link-type registry replaces hard-coded JS rules; new providers are a row insert.
- **Works** (#840) — composition grouping links the same hymn across translations, arrangements, and songbooks (mirrors MusicBrainz Work ↔ Recording).
- **IETF BCP 47** (#681 → #738) — all language tags through the IANA registry + CLDR native-name overlay; an in-app picker composes language / script / region / variant.
- **Parent songbooks** (#782) — express series and family relationships between songbooks ("Mission Praise" → "MP Combined" → "MP Combined Music Edition").
- **Official / unofficial songbooks + Collections** (#1223) — official and unofficial songbooks surface together as one "Songbooks" family (presentation only); unofficial books carry the shared "Unofficial" badge. Curated cross-songbook groupings are user-labelled **Collections** (internally `tblCatalogues`); managed at `/manage/catalogues`.
- **Publishers registry** (#93, epic #1765) — the songbook publisher promoted from free text to a first-class registry of persons and companies (`tblPublishers`, with imprint grouping, aliases, and a public `/publisher/<slug>` page); managed at `/manage/publishers`.
- **Songbook publication metadata + MARCXML** (#1765) — songbooks/series/collections carry a disable flag, a public-domain flag, and ARK / OpenLibrary / ISBN / ISSN identifiers (Google Books as an external-link provider); MARCXML import and export via the pure `includes/marcxml.php`.
- **Songbook display label** (#1332) — an optional free-text `DisplayAbbr` gives a richer user-facing abbreviation (e.g. "Psalty") while the real `Abbreviation` stays the SongId prefix.
- **Standard theme vocabulary** (#1152 / #1222) — the CCLI / SongSelect OpenLyrics theme taxonomy is seeded as a 2-level hierarchy; curator tags are canonicalised into standard themes from `/manage/tags`.
- **Duplicate & counterpart detection** (#1215 / #1216) — fuzzy cross-book matching via the shared `includes/song_similarity.php` scorer; the unified review UI at `/manage/duplicate-songs` links, unlinks, dismisses, and merges (the former `/manage/song-link-suggestions` is now a 302 redirect).
- **Musician registry duplicate detection** (#1785) — a sibling live scan for the Musicians registry (`includes/musician_duplicates.php`, extending the same shared NAME-similarity scorer): fold-equal byte variants, fuzzy near-misses, and curated-alias matches surface at `/manage/musician-duplicates` for one-click merge or dismiss, with a lifespan-conflict guard on the risky class of merge. Every merge affordance across the app now shows why two similar names look alike and which registry row is which.

### Discovery

- **Popular songs** — homepage shows trending songs (server-side view counts with client-side fallback).
- **Browse by theme** — filter songs by thematic tags.
- **Related songs** — content-based similarity matching using TF-IDF cosine similarity.
- **Song of the Day** (#108, language-aware in #855) — daily featured song respecting the user's active "Show languages" filter, with English fallback when the filter excludes English and we have no themed match.
- **Recently viewed** — quick access to your recent songs.

### Multilingual UX

- **"Show languages" filter** — pill-toggle group on the home page; per-user preference syncs across devices via `tblUsers.PreferredLanguagesJson` (#736).
- **Songbook visibility** (#857) — a songbook surfaces under any language filter that matches **any of its contained songs**, not just its own primary language.
- **Language-name tooltips** (#856) — every language pill / badge in the UI shows the full English language name on hover, resolved against `tblLanguages`.
- **Accept-Language honoured server-side** via the `X-Preferred-Languages` header injected into every fetch.

### Offline & PWA

- **Offline downloads** — individual songbooks or all at once.
- **Bulk download API** — optimised endpoint fetches entire songbooks in a handful of requests.
- **Rate-limited public reads** — the heaviest sessionless reads (`song_detail`, `search`, `songs_index`, `related_songs`, bulk) carry a fixed-window per-requester limit (per token where present, else per IP) that returns `429` + `Retry-After`; generous enough that real clients never trip it, fail-open and dormant until `migrate-add-read-rate-limit.php` runs (#1354).
- **Offline audio** — opt-in pre-cache so playback works without a connection (#401).
- **Per-songbook size readout + eviction** — Settings shows actual cached bytes per songbook with a remove-from-offline button (#401).
- **Background downloads** — continue when navigating away from Settings.
- **Auto-update** — optional automatic update of saved offline songs; service-worker update toast (#396).
- **Service worker** — precaches all app assets; cache version auto-derived from `infoAppVer.php` so every alpha build invalidates cleanly.
- **Offline indicator** — shows connection status in UI.
- **DB-direct reads, client-cache fallback** — song reads come live from MySQL (epic #1010 / WS-J; there is no server-side `songs.json` corpus cache and no JSON read fallback). When MySQL is unavailable the server returns a themed 503 (WS-K #1021); previously-downloaded songbooks remain available from the client offline cache.
- **What's New page** (#1583) — `/whats-new` shows what changed in recent releases, extracted from the changelog on every deploy; linked from the footer version number and the environment-badge dropdown.

### Appearance & accessibility

- **Themes** — light, dark, high contrast, and system-adaptive modes.
- **Colour vision deficiency** — accessible palette with pattern-based songbook indicators.
- **WCAG 2.1 AA** — screen-reader support, ARIA landmarks, keyboard shortcuts (toggleable via Accessibility settings, #406).
- **Responsive songbook names** — full name by default, abbreviation on narrow screens.
- **Adjustable font size** — lyrics scale from 14px to 28px.
- **Reduced motion** — respects `prefers-reduced-motion` and manual toggle.
- **Safe areas** — respects device notch, camera cutout, and home indicator on all screens.

### Authentication & access

- **Magic-link sign-in** — primary auth path (email + 6-digit code); password sign-in available as a fallback (#395).
- **Cross-subdomain cookie** — `HttpOnly`, `SameSite=Lax`, `Secure` auth cookie on `.ihymns.app` with 30-day sliding expiry survives iOS ITP (#390).
- **Roles** — Global Admin, Admin, Editor, User; capability-based entitlements editable at runtime by a global admin. Song deletion (`delete_songs`, Editor+) is now a recoverable soft delete (#1694/#1695, epic #1692) — deleted songs are hidden from every read surface but listed with **Restore** at `/manage/deleted-songs`; the old cascade delete survives only as the separate, irreversible **Purge** action, gated by its own `purge_songs` entitlement and reachable only from the deleted state.
- **Signed-in devices** (#1409 / #1511) — Settings → Account & Profile lists every device signed in to your account and lets you sign out any other one remotely.
- **Channel gating** — alpha / beta subdomains require the relevant access entitlement.
- **Content access tiers** — public, free, CCLI, premium, pro with organisation licensing (#640).
- **Extensible content gating** — server-side enforcement strips gated fields (lyric body, media) from the API by the requester's tier cap (#1353); the capability set is an extensible registry (`TIER_CAPS`, #1352) — a new gateable feature is **one line plus a migration card**, no schema change. Entirely dormant (a verified no-op) until `content_gating_enabled='1'`.
- **Songs and song-media respect `checkContentAccess()`** — the gated `/song-media/<id>` endpoint enforces the same restriction rules as the public song page, and (#1388) additionally applies a tier-cap gate to the media bytes themselves — not just the affordance — mirrored across `/song-media/<id>`, the offline `bulk_audio` manifest, and `songbook_export`. Still entirely dormant until `content_gating_enabled='1'`.

### Community

- **Song request form** — public, rate-limited, honeypot-protected (#403); admin triage queue at `/manage/requests`. Signed-in requesters can track their own submissions' status (Pending / Reviewed / Added / Declined) on the request page (#280).
- **Web Push notifications** (#311) — per-device opt-in from Settings, one checkbox per notification kind (`webPushKinds()` registry). VAPID (RFC 8292) + payload encryption (RFC 8291/8188) are implemented and RFC-vector-verified; entirely dormant until a site operator generates a keypair, and no push has yet been delivered to a real device.

### Administration

- **Song Editor** — the granular per-edit **v2 editor** (#1601) is the default at `/manage/editor/` (redirects there automatically; the previous whole-song editor remains available via `?legacy=1` while the migration completes); every change auto-saves as you make it. Multi-select bulk **verify** and **tag** (add or remove); bulk delete, move and export remain in the legacy editor for now (#1628, #1679). Eight tabs: Metadata, Structure (lyrics, a chords box, the Arrangement running-order editor, per-component language overrides #858, per-line translations/annotations #1088), Credits, Links, Tags, **Media** (#853), Preview, Revisions.
- **Revision history** — every save writes `tblSongRevisions`; a per-song Revisions tab (a History modal in the legacy editor) with per-revision Restore + global audit log at `/manage/revisions` (#400). The legacy editor's modal also shows a before/after JSON diff; the v2 tab does not yet (#1628). Restore semantics differ by editor version too: v2 restores the state a revision *left* the song in; the legacy editor restored the state *before* that edit.
- **Database setup** — web-accessible installer with backup restore upload, **pre-flight summary**, pre-restore auto-snapshot, transactional data-load, and live migration cards that auto-hide when fully applied (#820, #824, #405).
- **Activity logging** — audit trail for significant actions (logins, admin writes, backup restores, song-media uploads).
- **Analytics** — GA4, Plausible, Clarity, Matomo, Fathom with GDPR consent; admin dashboard with top songs / books / queries + zero-result queries + CSV export (#404).
- **Client error surfacing** (#1582) — uncaught browser errors show one generic toast and are beaconed (deduplicated, privacy-scrubbed) to the Activity Log (`Action=client.jserror`).

---

## Admin Portal

Accessible at **`/manage/`** (alias: `/admin/`) for users with the appropriate role. 46 destinations registered in the shared admin nav (`manage/includes/admin-links.php`), organised as Dashboard + 6 groups.

| Group | Surfaces |
| --- | --- |
| **Dashboard** | Library + activity snapshot, quick-links |
| **Songs** | Song Editor · Song Requests · Revisions Audit · Missing Numbers · Duplicates & Links (`/manage/duplicate-songs`) |
| **Catalogue** | Songbooks · Songbook Series · Works (`/manage/works`) · Collections (`/manage/catalogues`) · Publishers (`/manage/publishers`) · IA Reconcile (`/manage/ia-reconcile`) · External-Link Types (`/manage/external-link-types`) · Print templates · Musicians (`/manage/musicians`, incl. Add in Bulk + a registry-duplicate review companion at `/manage/musician-duplicates`, #1785) · Languages · Tags & Themes (`/manage/tags`) |
| **Access** | Content Restrictions · Access Tiers · Licence Types (`/manage/licence-types`) · Feature Gating · Gating Hub (`/manage/gating`) · Entitlements |
| **People** | Users · User Groups · Organisations · Venues (`/manage/venues`) · Service Projection (`/manage/service-projection`) · Lead a Service (`/manage/service-lead`) · My Organisations |
| **Operations** | Analytics · CCLI Usage Report · Data Health · Activity Log · Schema Audit · SQL Diagnostics · Database Setup · Configuration · Notifications · API Keys |
| **Help** | Help / Guides · API Docs (Swagger UI) |

Every write on these pages is CSRF-protected via `validateCsrfRequest()` — a robust same-origin check (requires `X-Requested-With`, validates any present `Origin`/`Referer` host) that also accepts a valid session token, so writes never fail on a stale baked token (#1352-family). DB error messages are never leaked to clients (see server error log).

---

## Quick Start

### Prerequisites

- **PHP 8.1+** with `mysqli` extension
- **MySQL 5.7+** or **MariaDB 10.3+** (InnoDB)
- **Node.js v22+** and **npm v10+** (for build tools)

### 1. Clone & install

```bash
git clone https://github.com/MWBMPartners/iHymns.git
cd iHymns
npm install
git config core.hooksPath tools/githooks
```

### 2. Generate song-import data

```bash
npm run parse-songs    # data/songs.json from .SourceSongData/
```

`songs.json` is a **migration input only** — it is parsed once to seed MySQL. At runtime the app reads songs **live from MySQL** (DB-direct, epic #1010); there is no server-side `songs.json` corpus cache.

### 3. Set up the database

```bash
# Interactive installer — prompts for MySQL credentials, creates tables
php appWeb/.sql/install.php

# Import song data from songs.json into MySQL (one-time seed)
php appWeb/.sql/migrate-json.php
```

Or use the **web-based installer** at `/manage/setup-database.php` (accessible during initial setup or as a global admin) and click each migration card in turn.

**One-shot alternative** (schema + all data in one command):

```bash
mysql -u user -p ihymns < appWeb/.sql/.fulldata/ihymns-full.sql
```

### 4. Create admin user

Visit `/manage/setup` in the browser to create the initial admin account. The first account becomes the **Global Admin**.

### 5. Start dev server

```bash
npm run dev    # PHP dev server at http://localhost:8000
```

---

## Database Setup

iHymns uses MySQL with a `tblCamelCase` schema spanning 152 tables (`CREATE TABLE` statements in `appWeb/.sql/schema.sql`). The full migration manifest lives in `appWeb/public_html/manage/setup-database.php` (`$friendlyTitles`); see the [Database & Migrations](iHymns.wiki/Database-&-Migrations.md) wiki page for an authoritative per-table reference.

### Database prerequisites

- MySQL 5.7+ or MariaDB 10.3+ with InnoDB support
- PHP 8.1+ with `mysqli` extension
- A database created for iHymns:

```sql
CREATE DATABASE ihymns CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### Step 1 — interactive installer

```bash
php appWeb/.sql/install.php
```

The wizard tests the connection, writes credentials to `appWeb/.auth/db_credentials.php` (mode `0600`), creates all base tables from `schema.sql`, and seeds default reference data (user groups, languages, access tiers, app settings).

### Step 2 — migrations

Migrations under `appWeb/.sql/migrate-*.php` add features incrementally (every one is idempotent — re-running a fully-applied migration is a no-op):

- IETF BCP 47 language tagging + IANA registry import + CLDR overlay (#681 / #738)
- Multi-language tables for songs and songbooks (#778)
- Parent songbooks (#782) · Songbook compilers (#831) · Alternative titles (#832)
- External-links registry + backfills (#833) · External-link URL patterns (#845)
- Works composition grouping (#840)
- Song media uploads (#853) · Song component language overrides (#858)
- Song arrangement persistence (#892) — `tblSongs.ArrangementJson` so the Song Editor's Structure-tab arrangement (e.g. "Verse 1, Verse 2, Verse 1, …" with refrain between verses) round-trips through save → reload.
- Bulk-import diagnostics (#906 / #907) — `tblBulkImportJobs.PerSongbookJson` carries per-songbook created/skipped/failed counts so the import summary surfaces a per-book breakdown instead of an opaque "X skipped"; `tblBulkImportJobs.PhaseLabel` lets the worker advertise its current phase ("walking-zip", "parsing-songs", "flushing-songbooks") so the progress UI never shows a silent 0%.
- Duplicate / counterpart detection + unified review (#1215 / #1216) · standard CCLI/OpenLyrics theme vocabulary (#1152)
- Org venues + recurring service schedules + live-follow sessions (Service Mode, #1323 / #1335) · songbook `DisplayAbbr` (#1332)
- Plus: bulk-import jobs (#676), song revisions, credit-people registry, organisation licences, activity log (#535), and many more.

Migrations are **not auto-applied on deploy**. On shared hosting the operator is web-only (no CLI/SSH), so run them via `/manage/setup-database` — the registry-driven dashboard with per-migration pending probes and an **"Apply all pending"** action. A `php appWeb/.sql/migrate-<name>.php` CLI route also exists where shell access is available.

### Step 3 — initial admin

Navigate to `/manage/setup` in the browser. The first account created becomes the Global Admin.

### Web-based setup (no CLI)

For shared hosting:

1. Copy `appWeb/.auth/db_credentials.example.php` to `db_credentials.php` and edit with your MySQL details.
2. Navigate to `/manage/setup-database.php`.
3. Run **Install** to create base tables.
4. Run each **migration** card in order (the dashboard shows pending migrations above the fold).
5. Run **Song Migration** to import song data from `songs.json`.
6. Visit `/manage/setup` to create the admin account.

---

## Environments

| Branch | Subdomain | Purpose |
| --- | --- | --- |
| `alpha` | `dev.ihymns.app` | Development / Alpha testing |
| `beta` | `beta.ihymns.app` | Beta testing |
| `main` | `ihymns.app` | Production |

Deployment is automated via GitHub Actions (SFTP). See `DEV_NOTES.md` for full deployment architecture.

---

## Project Structure

```text
iHymns/
├── .claude/              Claude AI context, ProjectBrief.md, project-rules.md
├── .github/workflows/    CI/CD: deploy, version bump, changelog
├── .SourceSongData/      Raw song text files (source of truth)
├── tools/                Build tools & song-data parser
├── data/                 Generated song data (songs.json, schema)
├── appWeb/               Web PWA application
│   ├── .auth/                Database credentials (NOT in git)
│   ├── .sql/                 Schema, installers, migrations
│   ├── uploads/songs/        Song media uploads (audio files; off the public docroot)
│   ├── public_html/          Web app source (deployed)
│   │   ├── includes/             Shared PHP (SongData, MediaStorage, language_names, …)
│   │   ├── js/modules/           ES modules (song-of-the-day, song-media-editor, …)
│   │   ├── manage/               Admin area + Song Editor
│   │   └── song-media.php        Gated streaming endpoint (#853)
│   └── .bulk_import_uploads/  Staging for bulk-import ZIPs
├── appApple/             Native Apple Universal app (Swift / SwiftUI, iHymnsKit package) — Phase 1 + Phase 2 code-complete, consolidated and CI-compiled, unreleased
├── appAndroid/           Android app (Kotlin / Compose) — scaffold / in progress
├── iHymns.wiki/          GitHub wiki (cloned alongside as a sibling — see below)
├── help/                 User documentation
├── Project_Plan.md       Detailed project plan
├── PROJECT_STATUS.md     Current status tracker
├── CHANGELOG.md          Change log
└── DEV_NOTES.md          Developer notes & deployment setup
```

The wiki sibling-clone pattern: clone the wiki repo alongside the main checkout so it stays in sync without polluting the app tree:

```bash
git clone https://github.com/MWBMPartners/iHymns.wiki.git
```

---

## Documentation

| Document | Description |
| --- | --- |
| [`.claude/ProjectBrief.md`](.claude/ProjectBrief.md) | **Current-state snapshot** — version, phase, schema summary, in-flight initiatives. Auto-loaded by Claude Code; the canonical "where are we" reference. |
| [`.claude/ProjectOverview.md`](.claude/ProjectOverview.md) | Original multi-platform scoping document. |
| [`.claude/project-rules.md`](.claude/project-rules.md) | Naming, data-access layers, error handling, i18n, test discipline. |
| [`Project_Plan.md`](Project_Plan.md) | Architecture, milestones, tech stack. |
| [`PROJECT_STATUS.md`](PROJECT_STATUS.md) | Current progress tracker. |
| [`CHANGELOG.md`](CHANGELOG.md) | All changes by version. |
| [`DEV_NOTES.md`](DEV_NOTES.md) | Deployment, secrets, architecture decisions. |
| `iHymns.wiki/` | **Comprehensive developer & user documentation** — API reference, database & migrations, deployment, security, PWA features, setlists & arrangements. Sibling-cloned (see Project Structure above). |

---

## License

Copyright © 2026 MWBM Partners Ltd. All rights reserved.

This software is proprietary. Unauthorized copying, modification, or distribution is strictly prohibited.

Third-party components retain their respective licences (MIT, Apache 2.0, BSD, etc.). See **[LICENSING.md](LICENSING.md)** for the full dependency-licence breakdown, song-content rights position, and trademark notes.

Security: to report a vulnerability privately, see **[SECURITY.md](SECURITY.md)**.

---

## Credits

- **MWBM Partners Ltd** — Development & maintenance
- Song data sourced from published hymnals and songbooks; respective publishers and rights-holders credited per song

---

Built with love for worship.
