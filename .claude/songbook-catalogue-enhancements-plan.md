# Songbook / Catalogue Enhancements — GIRFT plan (2026-08-04)

Owner-green-lit epic on branch `claude/songbook-catalogue-enhancements` (off alpha). ONE eventual PR.
Fable-5 planned; file:line anchors are to be re-verified at implementation time (never trust on faith).

## Features (owner decisions locked)
1. **Disable a songbook** — hidden from public everywhere, visible+editable in /manage/*, reversible, nothing deleted.
2. **Songbook Public Domain** — ONE `IsPublicDomain` flag (informational, never a gate).
3. **ARK / OpenLibrary Work / OpenLibrary Edition** ids on publication entities only (songbooks/series/collections), single-value nullable columns (#672 pattern). NOT songs.
4. **Google Books** external-link provider for songbooks (registry seed only — table + editor already exist).
5. **MARCXML** import + export for the 3 publication entities (one pure DB-free module).
+ **Add/Edit form parity** (owner-reported): Add form omits fields Edit has → extract ONE shared form-fields partial per entity so they can't drift; add a parity CI guard. (Fold into the admin-forms commit — arrived after the Fable pass.)

## Current-state facts (verified)
- `tblSongbooks` already has `Isbn VARCHAR(20)` + `ArkId VARCHAR(80)` (#672); NO IsDisabled/IsPublicDomain/OpenLibrary.
- `tblSongbookSeries`, `tblCatalogues`: NO identifier columns. (`catalogue` = internal name for "Collection", rule #24.)
- `tblSongbookExternalLinks` EXISTS (#833) with a working card-list editor + auto-detect wiring in `manage/songbooks.php` → feature 4 is pure seeding. No `google-books` provider anywhere yet.
- Song soft-delete precedent to mirror: `includes/song_soft_delete.php` (`songSoftDeleteReady`/`songVisibleSqlFor`/`songVisibleSql`), `SongData::_visible()` chokepoint (SongData.php:317), guard `tests/php/test-song-visibility-guard.php`.
- `includes/media_identifiers.php` = the id vocab registry (WORK_IDENTIFIER_TYPES shape); `includes/identifier_normalize.php` = canonical folds (trichotomy: ''=empty, null=malformed, else canonical). Do NOT add to `IHYMNS_ID_SCHEMES` (drives test-identifier-routes.js route coverage; we're deferring /ark//openlibrary/ routes).
- `LyricsPublicDomain`/`MusicPublicDomain` are the metadata-not-gate precedent for feature 2.

## Architecture
- **New `includes/songbook_visibility.php`** (clone of song_soft_delete.php shape): `songbookDisableReady()`, `songbookVisibleSqlFor(ready,alias='b')` → `b.IsDisabled=0`|`1=1`, `songbookVisibleSql(db,alias)`, `songServableSqlFor(ready,songAlias='s')` → `NOT EXISTS(SELECT 1 FROM tblSongbooks _dsb WHERE _dsb.Abbreviation=s.SongbookAbbr AND _dsb.IsDisabled=1)`|`1=1` (songAlias '' → `tblSongs.SongbookAbbr` explicit), `songServableSql(db,songAlias)`. Alias validated via `ihymnsSqlIdentifierSafe()` in BOTH branches (throws).
- **`SongData` audience mode**: `private bool $publicVisibility=true` (default public = fail-safe hide-not-leak); `public static function forAdmin(): self`. `_visible()` composes `songVisibleSql AND songServableSql` in public mode → covers ~30 tblSongs reads at once. New `_bookVisible($alias='b')` for songbook-grain sites. Flip to `forAdmin()`: missing-numbers.php:44, editor/api.php ×5, editor/api2.php ×2. Leave gating-noop-verify.php PUBLIC (deliberate).

## The ONE migration — `appWeb/.sql/migrate-publication-metadata.php` (mirror song-soft-delete.php structure)
- tblSongbooks +4: `IsDisabled TINYINT(1) NOT NULL DEFAULT 0 AFTER IsOfficial`, `IsPublicDomain TINYINT(1) NOT NULL DEFAULT 0 AFTER IsDisabled`, `OpenLibraryWorkId VARCHAR(20) NULL AFTER ArkId`, `OpenLibraryEditionId VARCHAR(20) NULL AFTER OpenLibraryWorkId`.
- tblSongbookSeries +5 (AFTER Colour): `Isbn VARCHAR(20)`, `Issn VARCHAR(20)`, `ArkId VARCHAR(80)`, `OpenLibraryWorkId VARCHAR(20)`, `OpenLibraryEditionId VARCHAR(20)`.
- tblCatalogues +3 (AFTER Colour): `ArkId VARCHAR(80)`, `OpenLibraryWorkId VARCHAR(20)`, `OpenLibraryEditionId VARCHAR(20)`.
- Google Books seed: `tblExternalLinkTypes` row (slug `google-books`, Category `read`, AppliesTo `songbook`, AllowMultiple 1, icon `bi-google`) via `INSERT … ON DUPLICATE KEY UPDATE` (NOT updating IsActive/AppliesTo — curator-owned); `tblExternalLinkPatterns` rows (books.google.<tld> exact hosts pri 62; google.<tld>/books/edition/ matchSubdomains pri 63) via `INSERT … WHERE NOT EXISTS`. Both stages table-existence-gated.
- schema.sql byte-identical mirrors at the matching AFTER positions; 12 `@migration-adds` doctags (one per column).
- migration-registry.php: ONE `'publication-metadata'` entry, 14-clause OR-probe (12 columns + 2 seed rows, seed clauses table-conditioned). Never `=> true`.
- Everything dormant: defaults 0/NULL, verified byte-identical no-op until code phases land + admin acts.

## Feature 1 read-path census (see plan for exact sites)
- Chokepoints: `_visible()` (all tblSongs reads), `_bookVisible()` on getSongbooks/getSongbook/parentJoin/getMeta/getSongbookFamily/getSongbooksInSeries.
- Raw public sites append `AND songServableSql(...)`: api.php song_by_identifier/song_links/song_translations(×3)/catalogue_language_subtags(both sources)/popular_songs/song_history/songs_by_tag/related_songs(×5)/song_correction_submit/live_follow(×2)/service_broadcast; pages tune/tag/musician/song; songbook-language-filter; SongOfTheDay(×2); identifier_resolve (via new `$publicOnly` param).
- EXEMPT (`@disabled-visible:` marker): **`songs_exist` (api.php:9011)** — client prune-safety, disabled≠deleted, must NOT vaporise favourites; all admin raw sites; SongCount recomputes (disable-invariant); live_activity_push.
- Direct /song/<id> in a disabled book → getById null → existing 404/410 path. Optional `songbookDisabledHolds()` (fail-open) for a plain 404 (no distinguishing copy — don't invite probing).

## Feature 6 validators (`includes/identifier_normalize.php` + `media_identifiers.php`)
- `ihymns_canonical_ark(raw): ?string` (ark:/NAAN(5-9 digits)/name printable-ASCII; accept resolver URLs + %-encoded).
- `ihymns_canonical_openlibrary(raw, kind 'work'|'edition'): ?string` (bare OL…W/OL…M or URLs; cross-kind → null).
- New `PUBLICATION_IDENTIFIER_TYPES` registry (ark/openlibrary-work/openlibrary-edition/isbn/issn) + `mediaIdentifierPublicationValidate()`. Extend test-media-identifiers.php.

## MARCXML (`includes/marcxml.php`, pure/DB-free)
- `marcxmlParse` (DOMDocument, LIBXML_NONET, entity-subst OFF = XXE-safe, ns-tolerant, record|collection) → throws InvalidArgumentException on malformed (handler renders $error BEFORE DB — #1740).
- `marcxmlFieldMap(entityKind)` = mapping as DATA (245→Name/Subtitle, 250/260/264→pub city/publisher/year, 020→Isbn, 022→Issn, 010→Lccn, 035(OCoLC)→Oclc, 041/008→Language, 024 ind1=7 $2=ark & 856 ark:/→ArkId, 856 openlibrary works|books→OL, 856 archive/wikipedia→existing URL cols, other 856→tblSongbookExternalLinks via provider match, else→unmapped report).
- `marcxmlMapToEntity`/`marcxmlGenerate`/`marcxmlCollection` (DOMDocument build, structural escaping). Generic-856 provider match via NEW `externalLinkDetectProviderId(db,url)` in external_link_helpers.php (2nd consumer of tblExternalLinkPatterns, not a JS re-fork).

## CI guards (tree-derived + mutation-proven)
1. `test-songbook-visibility-guard.php` (clone of song visibility guard; vouch tokens or `@disabled-visible:`; `>=` floor).
2. `test-songbook-disable.php` (pure cores + source-asserts on _visible composition + forAdmin default + songs_exist-stays-exempt lock).
3. `test-marcxml.php` (fixture-driven, map-derived coverage, round-trip, XXE/malformed throw).
4. extend `test-media-identifiers.php`.

## Build sequence (7 commits, one PR)
1. Pure foundations (songbook_visibility.php, folds, media_identifiers registry, marcxml.php + tests 2/3/4) — green with no schema.
2. Migration batch + schema.sql mirrors + registry entry (verified no-op).
3. Feature-1 read sweep (SongData mode + _visible compose + _bookVisible sites + forAdmin flips + raw sites + markers + guard 1). Production behaviour unchanged (all IsDisabled=0 → tautologies).
4. Admin surfaces (toggle + PD checkbox + OL/ARK fields + folds; series/catalogues fields + validateCsrfRequest upgrade; payload+api-docs keys; songbook-page PD line) + **shared form-fields partial + add/edit parity guard**.
5. Google Books JS RULES fallback (DB seed already in commit 2).
6. MARCXML handlers on the 3 pages (fixture in commit 1).
7. Docs + wiki + .claude + CHANGELOG; alpha rehearsal checklist.

## Adversarial notes
- Don't fold songbook filter into songVisibleSql (breaks SongCount stability + admin raw sites + widen-guard).
- `/songbooks` + home tiles filter on denormalised SongCount which disable does NOT change — only the getSongbooks predicate hides them.
- `songs_exist` trap runs the OTHER way (filtering corrupts data) — guard locks the exemption.
- Un-migrated alpha (3 docroots, ONE MySQL, web-run migrations): every read behind Ready()/lockstep-probe/columnExists → degrades to today; writes REFUSE (not silent no-op).
- No second migration forced: series 260$b / catalogue ISBN deliberately absent (model as songbook, rule #24); disable-with-attribution rejected (tblActivityLog records who/when).
