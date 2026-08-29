# Database & Migrations

> MySQL database, schema design, interactive installer, and data migration

---

## Overview

iHymns uses **MySQL** (v5.7+ / MariaDB 10.3+) as the primary data store for all application data:

- **Song data** — songbooks, songs, writers, composers, lyrics components
- **User accounts** — authentication, sessions, API tokens, password resets
- **User groups** — role-based access control with version channel gating
- **User data** — setlists, favorites (server-side sync)
- **Community** — song requests, activity log
- **Multilingual** — languages, song translations
- **Configuration** — runtime app settings

**All** queries — song data, admin panel auth, everything — use **MySQLi with prepared statements** via the single `getDbMysqli()` connection factory. **PDO was fully removed from the codebase** (#554/#555); there is no separate driver for the admin panel.

---

## Setup

### Interactive Installer

The recommended way to set up the database:

```bash
php appWeb/.sql/install.php
```

The installer will:
1. Prompt for MySQL host, port, database name, username, password, and optional table prefix
2. Test the connection
3. Write credentials to `appWeb/.auth/db_credentials.php` (permissions `0600`)
4. Create all tables from `appWeb/.sql/schema.sql`
5. Seed default user groups and languages

### Manual Configuration

If the interactive installer cannot be used (non-interactive shell, web server):

1. Copy `appWeb/.auth/db_credentials.example.php` to `appWeb/.auth/db_credentials.php`
2. Edit the credentials manually
3. Run `php appWeb/.sql/install.php` to create tables

### Data Migration

After table creation, `data/songs.json` can be imported as a **one-time seed** — this is the only role `songs.json` plays; every runtime read is live MySQL (epic #1010):

```bash
php appWeb/.sql/migrate-json.php --confirm
```

Without `--confirm` (CLI) / `?confirm=1` (web, via `/manage/setup-database`), the script only prints a pre-flight summary and does nothing — a bare unconfirmed request must never be able to truncate a live corpus.

---

## Database Schema

The full schema is defined in `appWeb/.sql/schema.sql`.

### Song Data Tables

| Table | Purpose |
|---|---|
| `tblSongbooks` | Songbook definitions (30+ songbooks, e.g. CP, JP, MP, SDAH, CH — see the live list at `/songbooks`) |
| `tblSongs` | Core song metadata + `LyricsText` for full-text search. Carries the soft-delete columns `IsDeleted` / `DeletedAt` / `DeletedBy` / `DeletedReason` / `DeleteNote` (#1694 — see below) |
| `tblSongWriters` | Song lyricist credits (many-to-one) |
| `tblSongComposers` | Song composer credits (many-to-one) |
| `tblSongComponents` | Thin per-section rows (Type / Number / SortOrder / Language / **`SourceWorkId`** / **`Label`**). Lyric lines live in the normalised `tblLyricLines` (rule #25); these columns are per-section METADATA. |
| `tblWorkComponents` | **Ordered medley composition** (#1907 / #1860 Phase 5): a Work "contains" constituent Works, M:N with `SortOrder` — distinct from `tblWorks.ParentWorkId` ("is-a-variant-of"). |

**Medley composition + custom component labels (#1907, #1860 Phase 5).** The dormant #1860 work-identity schema is now wired, plus one additive column. `tblSongComponents.Label VARCHAR(100)` is an optional custom **display** name for one section ("Kyrie", "isiZulu") overriding the derived "Verse 1 / Chorus" — DISPLAY-ONLY, so `Type` stays authoritative for CSS/chorus-highlight, arrangement resolution and every machine-export keyword (a label never reaches an exporter — CI-guarded). `tblSongComponents.SourceWorkId` links a section to the Work it excerpts (medley stitching); setting it additively records the medley composition into `tblWorkComponents` via the §3.6b.2 lockstep. Both are thin-row metadata carried on the `component_upsert` / `lyricLinesWriteComponents()` funnel — **never** the `tblLyricLines` line path (rule #25 untouched). The ONE shared write core is `workMedley*()` in `includes/work_admin.php` (M:N, idempotent keep-existing attach, bounded-depth cycle guard); consumed by the `/manage/works` "Constituent works (medley)" editor and the lockstep alike. The public song page + `/work/<slug>` show a read-only "Medley of: A, B, C" line. Per-section language was already live (#858/#1206).

**Accent/apostrophe-folded search (#1039).** Full-text search is backed by folded mirror columns so a query ignores diacritics and smart apostrophes — `tblSongs.NormalizedTitle` (app-maintained fold of `Title`) and `tblSongs.LyricsTextFolded` (diacritic-folded mirror of `LyricsText`) each carry their own `FULLTEXT` index (`ft_NormalizedTitle`, `ft_LyricsTextFolded`, plus the combined `ft_NormTitleLyricsFolded`). #1039 extends the same fold across the song/**songwriter/tune/place** search paths, so "Café" matches "cafe" and "don't" matches "dont" — online and in the offline cache alike.

### Song Deletion — Recoverable (#1694 / #1695, epic #1692)

Deleting a song is a **soft delete**, not a row removal — deliberately, because 38 of the 41 foreign keys elsewhere in the schema that reference `tblSongs(SongId)` are `ON DELETE CASCADE`, so a hard delete used to take the song's components, credits, media links and its *entire revision history* with it, recoverable only from a database backup.

`tblSongs.IsDeleted` hides the song from every filtered read; the row (and everything cascading from it) stays intact. `DeletedAt` / `DeletedBy` / `DeletedReason` / `DeleteNote` record when, who, and why — `DeletedReason` is `VARCHAR`, app-validated against `songDeleteReasons()` in `includes/song_soft_delete.php` rather than an `ENUM` (rule #20). `/manage/deleted-songs` lists deleted songs with **Restore** and **Purge** actions; visiting a soft-deleted song's URL now returns HTTP 410 Gone (not a generic 404) — see [[Architecture]].

Two separate entitlements gate the two actions, so the recoverable delete can be handed to curators without also handing them the irreversible one:

| Entitlement | Grants | Default roles |
|---|---|---|
| `delete_songs` | Soft-delete and restore | `editor`, `admin`, `global_admin` |
| `purge_songs` | The irreversible purge — the cascade delete, reachable only from the deleted state | `admin`, `global_admin` |

Every soft delete, restore, and purge notifies every `purge_songs` holder, fired from inside the write core so no future funnel can forget to. The one lifecycle module is `includes/song_soft_delete.php`. Because `saveEntitlementOverrides()` writes the whole entitlements map on every save, any install where `/manage/entitlements` has ever been saved keeps its own stored `delete_songs` value regardless of the code default — the data-only `migrate-delete-songs-rewiden.php` migration clears a stale admin-only override left over from before deletion was recoverable, without touching an operator's own deliberate choice.

### Catalogue Entity Tables (epic #1741 — Musicians / Works / Tunes / Song identifiers)

The MusicBrainz-shaped catalogue expansion models musicians, works and tunes as first-class entities with industry identifiers, disambiguation and profile pages. Most of the schema pre-existed; #1741 P1 extended it in one additive, dormant pass (rule #20 — never a second migration to add a value; every growable vocabulary is `VARCHAR`, app-validated, not `ENUM`).

> **Naming note (#1746 / #1741 P2):** the musician family was **renamed** from `tblCreditPeople*` to `tblMusician*`. The base tables are `tblMusician…`; the old `tblCreditPeople` / `tblCreditPerson*` names survive as **compat `VIEW`s** (`schema.sql` ~:2769-2799) so existing code/queries keep resolving. Always write the base-table name in new code.

| Table | Purpose |
|---|---|
| `tblMusicians` | Musicians (person / group / character / orchestra / …). `Type` is `VARCHAR`, app-validated. Carries `Biography`, `Disambiguation`, `Slug`. Profile at `/musician/<slug>` (`/person/<slug>` kept as alias). Compat view: `tblCreditPeople`. |
| `tblMusicianIdentifiers` | Musician industry IDs — IPI / ISNI / CAE / … (`IdentifierType` `VARCHAR`, new types need no `ALTER`; the 13-provider `CREDIT_IDENTIFIER_TYPES` registry). Compat view: `tblCreditPersonIdentifiers`. (Plus the dedicated `tblMusicianIPI`.) |
| `tblMusicianExternalLinks` | Per-musician external links (shared chip-list editor). Compat views: `tblCreditPersonExternalLinks` / `tblCreditPersonLinks` (+ `tblMusicianLinks`). |
| `tblMusicianRelations` | Musician↔musician relations (e.g. a character *Portrayed by* a person, with start/end dates) |
| `tblMusicianAliases` | Musician name variants (compat view: `tblCreditPersonAliases`) |
| `tblMusicianDuplicatesDismissed` | (#1785) A curator's "these two registry rows are NOT the same person" memory for `/manage/musician-duplicates` — pair-normalised (`MusicianIdA < MusicianIdB`), FK-`CASCADE`d so a merge auto-cleans its own dismissal. Scores are deliberately NOT stored (the duplicate scan is live-computed, never precomputed — a stored score would only go stale). |
| `tblWorks` | Works (the original piece). `ParentWorkId` self-FK (nesting), `Iswc`, `MusicBrainzWorkMBID`, `Disambiguation`, `Subtitle`. Page `/work/<slug>`. |
| `tblWorkSongs` / `tblWorkExternalLinks` | Work↔song membership (`IsCanonical`); per-work external links |
| `tblTunes` | Tunes, first-class. `Name`, `Slug`, `MeterCode`, `Subtitle`, `Disambiguation`, `MusicBrainzWorkMBID`, `HymnaryTuneId`. Page `/tune/<slug>`. `tblSongs.TuneId` FK links a song to its tune (written in lockstep with `TuneName`). |
| `tblTuneAliases` / `tblTuneCredits` / `tblTuneExternalLinks` | Tune spelling-variants (typeahead surfaces the canonical); per-tune composer/arranger/harmoniser/source credits; per-tune external links |
| `tblSongExternalIds` | Comprehensive per-song recording-ID key/value store — `IdType` ∈ MBID / Spotify / Genius / ISRC / … (`VARCHAR`, app-validated), `IdScope` server-derived, `(SongId, IdType, IdValue)` unique. `Source` distinguishes `manual` (curator) / `ihymns-backfill` (#1747 one-time) / `ihymns-mirror` (live ISRC dual-write, #1749). |

`tblSongs` also gained identity/publication columns in the same pass: `Isrc`, `Subtitle`, `Disambiguation`, `FirstPublishedYear`, `CopyrightYears`, `CopyrightHolder` (the last three split the single old `Copyright` field). Alias URLs `/isrc /iswc /ccli /ipi /isni /bowi` resolve an industry identifier to its entity via one shared normaliser + resolver (see [[Architecture]] and [[API Reference]]). All of the above is additive and byte-mirrored into `schema.sql`; each migration has a real completion probe.

**Bulk-import rights passthrough (#1673 / #1896).** Bulk song imports now persist the copyright line, CCLI number, ISWC and public-domain flags the source file provides, instead of writing blanks for every format. This un-breaks two downstream reads that depend on those columns: the CCLI usage report no longer undercounts imported songs, and imported songs auto-link to their Work by identifier (#1860). Writers/composers credits on import remain a follow-up (#1904).

### Publishers, Gating, Sharing & Print (branch `claude/issue-sweep-fixes-89`, epics #1765 / #1769 / #1767)

This batch landed several feature families as additive, dormant, forward-looking schema in one pass each (rule #20 — every growable vocabulary is `VARCHAR`, app-validated, never `ENUM`). All tables below are byte-mirrored into `schema.sql`, and each migration carries a real completion probe.

| Table | Purpose |
|---|---|
| `tblPublishers` | Songbook publisher registry — persons **and** companies (#93, part of epic #1765). `Kind` `VARCHAR` vocab; optional `MusicianId` FK (a musician-publisher isn't duplicated); `ParentId` self-FK for imprint / catalogue grouping; `Ipi` / `Isni` NULL-distinct UNIQUE; `CityName` + `CityId` place mirror; `IsActive`. Page `/publisher/<slug>`. |
| `tblSongbookPublishers` | Songbook↔publisher M:N (mirrors `tblSongbookCompilers`). `Role` `VARCHAR` vocab; `uq_book_pub_role` allows one publisher in several roles but never the same role twice. |
| `tblPublisherAliases` / `tblPublisherExternalLinks` | Publisher name variants + per-publisher external links (shared chip-list editor). |
| `tblLicenceTypes` | The licence vocabulary registry (#1769 P1) — CCLI / MRL / iHymns Basic / Pro / custom, what each covers, and any tier it confers. The one reader is `includes/licence_registry.php`; seeds are preserved on un-migrated installs. |
| `tblServiceDriverKeys` | Org-scoped external-driver credentials for the `service_drive` endpoint (#1770) — lets a presentation app drive the current song. |
| `tblPrintTemplateCustomLayout` | Uploadable full-page custom HTML print layouts (#1767 remainder P7), passed through the allowlist sanitiser on save and at render. Pairs with the new `tblPrintTemplates.OrgId` (org-scoped templates). |
| `tblIaFetchCache` / `tblIaImportCandidates` | IA-reconcile audit bookkeeping (#94 Phase 1) — cached archive.org OCR fetches + scored reconcile candidates. **Audit data, not song content**; the tool never writes a song. |
| `tblOrganisationLogos` | Per-organisation branding images for Print Templates (#1830) — one row per `(OrgId, Kind, Variant)`; `Kind` is a `VARCHAR` app-validated against the 10-entry `IHYMNS_ORG_LOGO_KINDS` registry (`includes/org_logo_helpers.php`: primary / full / horizontal / stacked / emblem / logotype / secondary / monochrome / reversed / favicon), `Variant` reserves a dormant light/dark axis (v1 only writes `default`). SVG rows store the hardened sanitiser's output in `ContentSanitised` (the only serve-readable column) plus the untouched upload in the dormant `ContentOriginal`; PNG/APNG rows store the validated original unchanged. See [[Security]] for the sanitiser detail. |

**Column families added on existing tables (all dormant until their feature is switched on):**

- **Set-list share scope (#1791):** `tblSharedSetlists` + `Scope` (`view` / `edit`), `EditAudience`, `ShowSharerName`, `Label`, `RevokedAt`, `ExpiresAt`, `LastUsedAt`, `EditCount` — an edit link is a 256-bit capability token whose power lives in the row (see [[Security]]).
- **Org policy (#1791 / #1770):** `tblOrganisations` + `SetlistEditAudience` / `EnforceSetlistEditAudience` (share-link clamp) and `LiveIdleTimeoutMins` / `EnforceIdleTimeout` (live-session idle policy).
- **Live-Follow session length (#1770 / #1798):** `tblLiveFollowSessions` + `IdleTimeoutMins` / `LastLeaderSeenAt` — resolved once at create via the app→org→user precedence ladder.
- **Gating rights facts (#1769 P1, all dormant behind the master switch):** `tblSongs.LyricsRightsLicenceKey` / `MusicRightsLicenceKey`, `tblSongbooks.DefaultLyricsRightsLicenceKey` / `DefaultMusicRightsLicenceKey`, `tblSongArrangements.MusicRightsLicenceKey` / `MusicRightsStatus`, `tblGatingCapabilities.EnforceJson`.
- **Publication metadata (#1765):** `tblSongbooks.IsDisabled` / `IsPublicDomain` / `OpenLibraryWorkId` / `OpenLibraryEditionId`; `tblSongbookSeries` + `tblSongbooks`-mirroring identifier columns (`Isbn` / `Issn` / `ArkId` / `OpenLibrary*`); `tblCatalogues.ArkId` / `OpenLibrary*`.

**Migration cards (all in `migration-registry.php` with real probes; operator-run via `/manage/setup-database`, not auto-applied on deploy):** `migrate-publication-metadata`, `migrate-publishers-entity` (idempotent by Name existence, not slug), `migrate-reconcile-credit-name-bytes`, `migrate-musician-duplicates-dismissed`, `migrate-add-gating-facts-and-licence-types`, `migrate-derive-rights-facts`, `migrate-consolidate-org-licences`, `migrate-live-follow-quick-capable`, `migrate-setlist-share-scope`, `migrate-print-template-layouts`, `migrate-ia-reconcile`, `migrate-organisation-logos` (#1830).

### Permanent internal ids + Editor2 metadata derivation (#1860 / #1862, branch `claude/ilyrics-identity-work-model`)

| Table / column | Purpose |
|---|---|
| `tblIlyricsIdSequence` | Per-entity-type allocator for the permanent `IL*` internal ids (#1860 §2.3) — one row per entity family (`song`/`work`/`musician`/`tune`/`publisher`/`catalogue`/`songbook`/`document`), `NextValue` is a SEED (`ilidAllocate()` claim-checks the entity table's `uq_IlId` before returning, so a restored/rolled-back counter can't mint a collision). Read/written only by `ilidAllocate()` in `includes/ilyrics_id.php`. |
| `IlId` (8 tables) | `tblSongs` / `tblWorks` / `tblMusicians` / `tblTunes` / `tblPublishers` / `tblCatalogues` / `tblSongbooks` / `tblSongMedia` each gain a nullable `VARCHAR(16)` `IlId` + `UNIQUE KEY uq_IlId` — the permanent, grammar-disjoint id (`IL<letter>` + 10 zero-padded digits, e.g. `ILS0000012345`; move/rename-stable, never re-keyed). NULL until minted; every dual-addressing read path (see [[API Reference]]) is column-existence-gated, so an un-migrated install is a verified no-op. |
| `tblSongs.LyricsPdFromYear` / `MusicPdFromYear` | (#1862 B1) `SMALLINT UNSIGNED NULL` — a denorm "public domain from this year" per part, derived from the oldest ratified contributor's death year + life-plus-70, recomputed live by `includes/pd_suggest.php`'s fold rather than a batch job. Suggestion-only; never auto-sets the `PublicDomain` checkboxes. |

**Migration cards:** `migrate-ilyrics-internal-ids` (creates + seeds `tblIlyricsIdSequence`, adds the 8 `IlId` columns — additive, dormant until Phase 2 mint-on-create + the go-live A/B/C commits wired every write funnel), `migrate-song-pd-from-year` (adds the two `PdFromYear` columns + a chunked backfill using the same live fold the write path uses), `migrate-reconcile-media-flags` (`'manual'` + `dryRunnable` — a one-time, docroot-sensitive `HasAudio`/`HasSheetMusic` reconcile; no new columns, those predate this batch).

### ProPresenter interop — media, chords, and dormant timeline groundwork (epic #1968)

Media referenced by an imported ProPresenter `.probundle`/`.proplaylist` (background videos, images) is ingested into `tblSongMedia` linked to the song, **admin-only until a curator publishes it** (owner decision D1, Phase 4). One additive, dormant column drives it:

- **`tblSongMedia.Visibility`** `VARCHAR(20) NOT NULL DEFAULT 'public'` — a per-row publish state (`public | admin`; `org`/`pending` reserved), app-validated via `IHYMNS_SONG_MEDIA_VISIBILITIES` (`includes/song_media_visibility.php`), VARCHAR-not-ENUM (a growable vocabulary). A **verified no-op** for all existing rows (each stamped `public`) and on un-migrated installs.

The serving gate (`includes/song_media_visibility.php`) is the ONE place that decides "may this be served publicly," at both grains — the list-emit SQL filter (every public `FROM tblSongMedia` read) and the `song-media.php` byte gate (404-no-body for an admin row to a non-curator). It is **always active** (an editorial publish state, not a tier cap — so NOT behind `content_gating_enabled`), a no-op for `public` rows, and fail-CLOSED on the serve axis for any unknown value. Ingest itself is **entirely dormant** behind `tblAppSettings.pp7_media_ingest_enabled` (default `'0'`), which the owner flips only after this reaches `main` (a mechanism against the shared-DB cross-channel leak). A single-song export can also **embed** a song's published background media into the `.probundle` it produces (#1979) — read-only against this same table, no additional schema.

**Migration card:** `migrate-song-media-visibility` (additive, idempotent, existence-guarded; no docroot include path, rule #41). Video/image media KINDS are app-level only (`Kind` has been VARCHAR since #1090 — no DDL).

**Chord round-trip (#1968 P6) — no new schema.** PP7 stores chords as positioned protobuf `CustomAttribute` rows over clean plain text, not inline `[G]` brackets, so the decoder/importer buckets them straight into the **existing** per-line positioned `chords` cells and rides the untouched `lyricLinesWriteComponents()` funnel (rule #25) — nothing new to add here.

**Presentation timeline groundwork (#1968 P6) — dormant, off by default.**

| Table | Purpose |
|---|---|
| `tblSongPresentationCues` | The auto-advance slide-cue schedule decoded from a `.pro`'s `Presentation.timeline` (`trigger_time` / `cue_id` / `name`), one row per cue. `Source` is `VARCHAR` (rule #20); `ArrangementName` is a multiplicity discriminator (a song can carry more than one arrangement's timeline); `ComponentId` is reserved `NULL` for later mapping work. Capture is gated behind `tblAppSettings.pp7_timeline_import_enabled` (seeded `'0'`) and wired into the shared import pipeline behind its own try/catch — a verified no-op at the toggle's shipped default. **No playback or auto-advance UI exists yet** — this is schema + capture only. |

**Migration card:** `migrate-pp7-timeline-groundwork` (additive, idempotent).

### User & Access Control Tables

| Table | Purpose |
|---|---|
| `tblUserGroups` | Groups with version channel access flags (Alpha/Beta/RC/RTW) |
| `tblUsers` | Accounts with role, group link, EmailVerified, LastLoginAt, LoginCount, AccessTier, CcliNumber, CcliVerified, and the `Status` / `StatusChangedAt` lifecycle pair (#1698 — `active` / `disabled` / `deleted`; see [[User Accounts & Roles]]) |
| `tblSessions` | Server-side admin panel sessions |
| `tblApiTokens` | Bearer tokens for PWA/native app auth (64-char hex, 30-day expiry). As of the 2026-08-28/29 API-coverage program, the same token also authenticates against the song-editor API (`manage/editor/api2.php` + the legacy `api.php` shim), `manage/places-api.php`, and `manage/print-pdf.php` — see [[Architecture]] § API coverage. |
| `tblPushTokens` | (API-coverage plan C1, 2026-08-28) Android/FireOS push registration tokens — `Provider` (`fcm` \| `adm`, `VARCHAR` not `ENUM`, rule #20) discriminates ordinary-Android Google FCM from Fire-OS-only Amazon ADM in one table rather than forking a near-identical second one. `UNIQUE(Provider, Token)`; `UserId` FK, `CASCADE` on delete. Distinct from the existing (undocumented-here) `tblApnsTokens` (Apple) and `tblPushSubscriptions` (Web Push/VAPID, keyed by browser endpoint URL). **Entirely dormant** until `includes/fcm.php` is keyed AND a live trigger calls its `fcmSend()` — neither is true yet; see [[Native Apps (Apple & Android)]] § Push notifications. Migration: `migrate-add-push-tokens.php`. |
| `tblPasswordResetTokens` | Single-use password reset tokens (48-char hex, 1-hour expiry) |
| `tblEmailLoginTokens` | Magic link tokens + 6-digit codes for passwordless email login (10-min expiry) |
| `tblUserGroupMembers` | Many-to-many user-to-group membership |
| `tblUserPermissions` | Fine-grained per-user permission overrides (NULL = inherit from role) |
| `tblLoginAttempts` | Brute force tracking (IP, username, success/failure, timestamp) |
| `tblAccessTiers` | Content access tier definitions (id, name, level, description) |
| `tblUserPurchases` | Purchase and subscription tracking (user, tier, payment reference, expiry) |

### User Data Tables

| Table | Purpose |
|---|---|
| `tblUserSetlists` | Server-side setlist storage for cross-device sync |
| `tblUserFavorites` | Server-side favorites sync (song IDs per user) |

### Language & Translation Tables

| Table | Purpose |
|---|---|
| `tblLanguages` | ISO 639-1 language reference (code, name, native name, text direction) |
| `tblSongTranslations` | Links source songs to translations in other languages |

### Community & Engagement Tables

| Table | Purpose |
|---|---|
| `tblSongRequests` | User-submitted song suggestions with status tracking |
| `tblSongHistory` | Recently viewed songs tracking per user |
| `tblSongTags` | Song categories/themes (Easter, Communion, etc.) |
| `tblSongTagMap` | Many-to-many song-to-tag mapping |
| `tblNotifications` | In-app notification system for users |

### System Tables

| Table | Purpose |
|---|---|
| `tblActivityLog` | Audit trail for admin actions (edits, logins, imports) |
| `tblAppSettings` | Key-value runtime configuration store |
| `tblMigrations` | Schema migration version tracking |
| `tblIntAppsSync` | MWBM-IntAppsAPI gateway local snapshot + refresh bookkeeping (Epic #1725) — one dormant table, keyed `(Scope, Channel, AppSlug)`; empty/unread until an admin enables the integration on `/manage/configuration`. See [[Architecture]] § External integrations. |
| `tblQrCache` | Server-side cache of CueRCode-generated QR images (#1920), keyed by a sha256 of the canonical payload+options JSON. Additive, dormant until the CueRCode key is configured; a 90-day TTL + 20,000-row belt bound growth (`appWeb/.sql/cleanup.php`). Read/written only via `includes/qr_cache.php`, composed behind `cuercodeGenerateCached()` in the ONE CueRCode client. See [[Architecture]] § QR. |

---

## Content Tiers

iHymns uses a tiered content access system to gate premium features such as audio playback, MIDI files, and PDF sheet music.

### The 5 Tiers

| Level | Tier Name | Access |
|---|---|---|
| 0 | **Free** | Lyrics only (browse, search, setlists) |
| 1 | **Basic** | Lyrics + song metadata extras |
| 2 | **Standard** | Basic + MIDI audio playback |
| 3 | **Premium** | Standard + PDF sheet music downloads |
| 4 | **Ultimate** | All content, including future premium features |

### Tier Resolution

Users can have a **personal tier** (set on `tblUsers.AccessTier`) and an **organisation-level tier** (inherited from their user group via `tblAccessTiers`). When both exist, the **highest tier wins** — ensuring that a user belonging to a Premium organisation still gets Premium access even if their personal tier is Free.

Resolution order:

1. Read the user's personal `AccessTier` level from `tblUsers`
2. Read the tier level associated with the user's group(s)
3. Take `MAX(personal_tier, org_tier)` as the effective tier
4. Gate feature access based on the effective tier level

### Related Tables

- `tblAccessTiers` — defines each tier (id, name, level, description)
- `tblUserPurchases` — records purchases/subscriptions that grant a user a specific tier (includes payment reference, start date, expiry date)
- `tblUsers.AccessTier` — the user's current personal tier (FK to `tblAccessTiers`)
- `tblUsers.CcliNumber` — the user's CCLI licence number (validated format)
- `tblUsers.CcliVerified` — whether the CCLI number has been verified (0/1)

---

## Version Access Control

User groups control access to release channels:

| Group | Alpha | Beta | RC | RTW |
|---|---|---|---|---|
| Developers | Yes | Yes | Yes | Yes |
| Beta Testers | No | Yes | Yes | Yes |
| RC Testers | No | No | Yes | Yes |
| Public | No | No | No | Yes |

Access is the **union** of all group memberships — if any group grants access to a channel, the user has it. Users have a primary `group_id` on the `users` table, with additional memberships via `user_group_members`.

---

## Connection Architecture

| Component | Driver | Used By |
|---|---|---|
| All queries — song data, admin panel, auth | **MySQLi** (prepared statements) via `getDbMysqli()` | `SongData.php`, `db_mysql.php`, `manage/includes/auth.php`, `manage/includes/db.php` (thin wrapper) |

`getDbMysqli()` is the **one** connection factory in the codebase; a `new PDO(...)` or a second `new mysqli(...)` outside it is a regression. All queries share credentials from `appWeb/.auth/db_credentials.php`, and mysqli runs under `MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT` — a failing statement throws rather than returning `false`.

---

## Table Prefix Support

The installer supports an optional table prefix for shared hosting environments. When configured, all table names are prefixed (e.g., `ih_songs`, `ih_users`). The prefix is stored in `DB_PREFIX` in the credentials file.

---

## File Structure

```text
appWeb/
├── .auth/
│   ├── .htaccess                      ← Blocks web access
│   ├── db_credentials.example.php     ← Template (tracked)
│   └── db_credentials.php             ← Credentials (NOT tracked)
├── .sql/
│   ├── schema.sql                     ← Full MySQL schema (canonical for a fresh install)
│   ├── install.php                    ← Interactive installer
│   └── migrate-json.php               ← One-time JSON-to-MySQL seed (confirm-gated)
└── public_html/
    ├── includes/
    │   ├── db_mysql.php               ← getDbMysqli() — the ONE MySQLi connection factory
    │   └── SongData.php                ← Song data handler (scoped live-read methods)
    └── manage/includes/
        ├── db.php                     ← Thin wrapper calling getDbMysqli() (admin panel)
        └── auth.php                   ← Authentication functions
```
