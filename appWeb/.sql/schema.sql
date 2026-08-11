-- ============================================================================
-- iHymns — MySQL Database Schema
-- Copyright (c) 2026 iHymns. All rights reserved.
--
-- PURPOSE:
-- Defines the complete database structure for the iHymns application:
--   - Song data (songbooks, songs, writers, composers, components)
--   - User accounts and authentication (sessions, API tokens)
--   - User groups and permissions (role-based access control)
--   - Version access control (Alpha, Beta, RC, RTW channel gating)
--   - User setlists and favorites (server-side sync)
--   - Language and translation support
--   - Song requests (community submissions)
--   - Activity log and app settings
--
-- NAMING CONVENTION:
--   Tables:  tblCamelCase (e.g., tblSongs, tblUserGroups)
--   Columns: CamelCase    (e.g., SongId, CreatedAt, SongbookAbbr)
--
-- USAGE:
--   Run via the installer:  php appWeb/.sql/install.php
--   Or manually:            mysql -u user -p ihymns < appWeb/.sql/schema.sql
--
-- ENGINE:  InnoDB (transactional, foreign key support)
-- CHARSET: utf8mb4 (full Unicode — emoji, curly quotes, em dashes)
--
-- ----------------------------------------------------------------------------
-- SECTION MAP — the `-- ====` banners below, in file order. 136 tables is too
-- many to scroll blind; find your family here first.
--
--   SONG CATALOGUE CORE ............ places, songbooks, tblSongs, credit people
--   LYRICS STORAGE ................. components + line/word/syllable model
--   USER ACCOUNTS & AUTHENTICATION . users, groups, tokens, device codes
--   ACCESS TIERS & PURCHASES ....... tblAccessTiers (the TIER_CAPS backing store)
--   ORGANISATIONS & LICENSING ...... orgs, members, licences, restrictions
--   USER DATA ...................... setlists, favourites, custom tags
--   LANGUAGE & TRANSLATION SUPPORT . BCP 47 subtag registries + song translations
--   SONG REQUESTS & COMMUNITY ...... tblSongRequests (missing-song + correction)
--   AUDIT & ANALYTICS .............. activity log, IP reputation, app settings
--   FEATURE TABLES ................. keys, scheduling, revisions, push
--   DEFAULT DATA ................... the seed INSERTs a bare install needs
--   ENGAGEMENT & ANALYTICS ......... history, tags, bulk import, notifications
--   IN-PLACE MIGRATIONS ............ two idempotent ALTER/UPDATE statements
--   SONG RELATIONSHIPS & DEDUP ..... links, redirects, similarity suggestions
--   MIGRATION-ORIGIN FAMILIES ...... collections, external links, media, works…
--   INTERCHANGE + INGEST + IDENTITY  the one-pass #1066 batch
--   PER-LINE LYRIC ENRICHMENT ...... the one-pass #1088 batch
--   ENHANCEMENT FOUNDATION ......... the one-pass #1090 batch
--   SCHEMA-COMPLETENESS BATCH ...... vocal parts, part types, scripture, PROs
--   PRESENTATION THEMES ............ projection/casting styling groundwork
--   SERVICE MODE VENUES + EXTERNAL SYSTEMS
--   PRINT TEMPLATES / RATE LIMIT / ANALYTICS INGEST
--   AUTH PROVIDERS ................. Sign in with Apple
--   ADMIN-CONFIGURABLE FEATURE GATING
--   DEFERRED FOREIGN KEYS .......... the FKs that could not be declared inline
--
-- ----------------------------------------------------------------------------
-- THREE RULES GOVERN EDITS TO THIS FILE. All three were learned the hard way.
--
-- 1. DECLARATION ORDER IS LOAD-BEARING. InnoDB resolves an inline FOREIGN KEY
--    at CREATE time, so a table must appear BEFORE anything that inline-FKs
--    into it. That is why tblPlaces sits at the very top, and why the handful
--    of constraints that could not be re-ordered live in the DEFERRED FOREIGN
--    KEYS block at the very bottom (#1708 — before that fix a fresh install
--    died at errno 150 after creating 16 of 136 tables, and nobody noticed
--    for a long time because long-running installs are built incrementally by
--    migration cards and never execute this file end to end).
--
-- 2. DDL HERE MUST BE BYTE-IDENTICAL TO ITS MIGRATION MIRROR, COMMENT TEXT
--    INCLUDED (CLAUDE.md rule #19). A fresh install reads THIS file; a
--    long-running install is built by appWeb/.sql/migrate-*.php. If the two
--    drift, the Schema Audit page (#518) reports divergence nobody introduced
--    deliberately. Practical consequence when annotating:
--        * `--` prose comments (like this block) are stripped by the parser,
--          never reach the database, and may be edited freely.
--        * a `COMMENT '...'` clause on a column / index / table is DDL. Editing
--          one here alone de-syncs fresh installs from migrated ones — and
--          tests/php/test-schema-coverage.php compares PRESENCE only (which
--          tables and columns exist), never COMMENT text, so CI will not catch
--          it. Changing a COMMENT means changing schema.sql AND its migration
--          in the same commit.
--
-- 3. NEW GROWABLE VOCABULARY IS VARCHAR, NEVER ENUM (rule #20) — adding an
--    ENUM value is an ALTER, i.e. the second migration the one-pass batches
--    exist to avoid. The ENUMs still present in this file (tblLyrics.Status,
--    tblActivityLog.Result, tblCatalogues.Visibility, …) predate the rule and
--    are grandfathered. Do not read them as precedent. (tblExternalLinkTypes
--    .Category and tblMusicianAliases.Type were both widened to VARCHAR by
--    #1741 P1 — no longer examples of the exception.)
-- ============================================================================


-- ============================================================================
-- SONG CATALOGUE CORE — places, songbooks, songs, and the credit registry
--
-- Everything from here down to the LYRICS STORAGE banner is the catalogue
-- itself: the geographic registry other tables FK into, the songbooks, the
-- central tblSongs, the SIX free-text credit-role tables (Writers, Composers,
-- Arrangers, Adaptors, Translators, Artists — an earlier draft said five), and
-- the tblMusicians registry (renamed from tblCreditPeople, #1741 P2 — see the
-- back-compat VIEWs family below) that sits ALONGSIDE those six (not above
-- them — the role tables still store free-text names; see tblMusicians).
--
-- tblSongs is the hub of the whole schema: 41 foreign keys across this file
-- point at tblSongs.SongId — the human-readable string id, not the surrogate
-- Id — and 37 of them ON DELETE CASCADE. That fan-out is why song deletion is
-- a SOFT delete (#1694); see the note on tblSongs.IsDeleted. (Only four are
-- SET NULL: the two on tblSongRequests, tblSongRedirects.NewSongId, and
-- tblLiveFollowSessions.CurrentSongId.)
-- ============================================================================

-- ----------------------------------------------------------------------------
-- tblPlaces
-- Canonical registry of geographic places (suburb / city / state / country)
-- backed by a live geocoder lookup (Photon primary, Nominatim fallback —
-- both OpenStreetMap-derived). Other tables FK in here for consistent
-- place names across the catalogue. The geocoder identity (Provider +
-- OsmType + OsmId) is the natural key — a curator picking "Sydney" twice
-- in two different editors resolves to the same row. DisplayName is the
-- full hierarchical label as returned by the geocoder; Name is the short
-- label (city / village name). Latitude / Longitude are stored so future
-- map widgets don't need a re-fetch. Created via migrate-places.php.
--
-- Defined here at the top of the file (before every table that FKs
-- into it) so a fresh install can build all the inbound constraints
-- in declaration order without flipping foreign_key_checks.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblPlaces (
    Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    Provider        VARCHAR(20)     NOT NULL DEFAULT 'osm' COMMENT 'Geocoder family the OsmType/OsmId pair is namespaced under',
    OsmType         CHAR(1)         NULL COMMENT 'N=node, W=way, R=relation; NULL for manually-entered places',
    OsmId           BIGINT          NULL COMMENT 'OSM object id within OsmType; NULL for manually-entered places',
    DisplayName     VARCHAR(500)    NOT NULL COMMENT 'Full hierarchical label as returned by the geocoder',
    Name            VARCHAR(255)    NULL COMMENT 'Short label (city / village / suburb)',
    Suburb          VARCHAR(255)    NULL,
    City            VARCHAR(255)    NULL COMMENT 'City / town / village level (collapsed from OSM city/town/village/hamlet keys)',
    County          VARCHAR(255)    NULL,
    Region          VARCHAR(255)    NULL COMMENT 'State / province / region',
    Country         VARCHAR(255)    NULL,
    CountryCode     CHAR(2)         NULL COMMENT 'ISO-3166-1 alpha-2, lowercase',
    Latitude        DECIMAL(10,7)   NULL,
    Longitude       DECIMAL(10,7)   NULL,
    PlaceType       VARCHAR(50)     NULL COMMENT 'Geocoder-reported type: city, town, village, state, country, …',
    CreatedAt       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,

    /* Natural key — a Provider+OsmType+OsmId triple identifies a unique
       geocoder object. NULL OsmId rows (manual entries) are exempt from
       the uniqueness constraint because MySQL treats NULLs as distinct,
       which is the behaviour we want. */
    UNIQUE KEY uk_OsmRef (Provider, OsmType, OsmId),
    INDEX idx_DisplayName (DisplayName),
    INDEX idx_CountryCode (CountryCode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblSongbooks
-- Stores the songbook/collection definitions (CP, JP, MP, SDAH, CH, Misc).
--
-- `Abbreviation` is the load-bearing one and the reason this table is here so
-- early: it is not a label, it is the PREFIX of every SongId in the book
-- ("MP" in MP-1008), parsed as <letters>-<digits> by the PWA router, the
-- OG-image generator and several API validators, and it is the FK target that
-- tblSongs and tblSongbookEntries point at. Loosening its charset would re-key
-- ~14k songs and break every existing URL, bookmark and shared image. When a
-- richer user-facing label is wanted (punctuation, spaces), that is what the
-- optional `DisplayAbbr` is for — display only, never in a URL or an id.
--
-- `IsOfficial` = 0 does NOT mean second-class: unofficial books are curated
-- groupings that appear alongside published hymnals everywhere, just badged.
-- Filtering them out of a listing is a bug (rule #24). It drives ranking,
-- whether song numbers are shown, and the shared "Unofficial" badge.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongbooks (
    Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    Abbreviation    VARCHAR(10)     NOT NULL UNIQUE,
    DisplayAbbr     VARCHAR(30)     NULL DEFAULT NULL COMMENT 'Optional free-text display label shown in place of Abbreviation (any chars incl. - _ :); Abbreviation stays the alphanumeric SongId prefix. NULL = show Abbreviation (#1332).',
    Name            VARCHAR(255)    NOT NULL,
    SongCount       INT UNSIGNED    NOT NULL DEFAULT 0,
    DisplayOrder    INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'Explicit sort order for listings / filter dropdowns',
    Colour          VARCHAR(7)      NOT NULL DEFAULT '' COMMENT 'Badge colour hex #RRGGBB (empty = theme default)',
    IsOfficial      TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = published hymnal; 0 = curated grouping / pseudo-songbook (#502)',
    IsDisabled      TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'Disable-a-songbook flag (Feature 1, epic #1765): 1 = hidden from every public read, still visible and editable under /manage; reversible, nothing deleted. Read-path enforcement lands in commit 3 (songbookVisibleSql()); this column is dormant until then; DEFAULT 0 keeps every existing songbook visible',
    IsPublicDomain  TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'Public-domain flag for the songbook as a published work (Feature 2, epic #1765) - informational only, never a content gate; mirrors the tblSongs LyricsPublicDomain / MusicPublicDomain precedent. DEFAULT 0 so no existing songbook is retroactively marked PD',
    Publisher       VARCHAR(255)    NULL DEFAULT NULL COMMENT 'Publisher or originator (e.g. Praise Trust, Hope Publishing) (#502)',
    PublicationYear VARCHAR(50)     NULL DEFAULT NULL COMMENT 'Year / edition range (free-form: 1986, 1986-2003, 2nd edition 2011) (#502)',
    /* Publication city — VARCHAR mirror for JOIN-free reads + FK
       into tblPlaces for normalised country/region grouping. */
    PublicationCity   VARCHAR(255)  NULL DEFAULT NULL,
    PublicationCityId INT UNSIGNED  NULL DEFAULT NULL,
    Copyright       VARCHAR(500)    NULL DEFAULT NULL COMMENT 'Copyright notice for the collection as a whole (#502)',
    Affiliation     VARCHAR(120)    NULL DEFAULT NULL COMMENT 'Denominational / religious affiliation; backed by tblSongbookAffiliations registry (#670)',
    IsChristian     TINYINT(1)      NOT NULL DEFAULT 1 COMMENT 'Christian-corpus filter axis (#1045): iHymns surfaces only WHERE IsChristian=1; the shared iLyricsDB core applies no filter. Every iHymns songbook is Christian, hence default 1.',
    Language        VARCHAR(35)     NULL DEFAULT NULL COMMENT 'Optional IETF BCP 47 tag (language[-script][-region], e.g. en, pt-BR, zh-Hans-CN); NULL = not specified. Soft validation via the composite picker dropdowns; widened from VARCHAR(10) to fit script+region subtags (#681)',
    DefaultLyricsRightsLicenceKey VARCHAR(30) NULL DEFAULT NULL COMMENT 'EDITOR-DEFAULT only, NEVER resolver-read (#1769 P1): pre-fills the Rights panel LyricsRightsLicenceKey for new songs in this book and powers the P4 apply-to-all-songs bulk action. The resolver reads ONLY the per-song fact — no inheritance chase, no JOIN, and tblSongs NULL keeps its single meaning (no requirement). NO FK (degrade-safe). DORMANT until the P4 editor reads it',
    DefaultMusicRightsLicenceKey VARCHAR(30) NULL DEFAULT NULL COMMENT 'EDITOR-DEFAULT only, NEVER resolver-read (#1769 P1): pre-fills the Rights panel MusicRightsLicenceKey for new songs in this book; see DefaultLyricsRightsLicenceKey. NO FK (degrade-safe). DORMANT until the P4 editor reads it',

    /* Bibliographic + authority-control identifiers (#672). All
       optional, all VARCHAR — no FKs, no CHECK constraints. Curators
       fill these in by pasting the canonical form from a library
       catalogue / WikiData / WorldCat. */
    WebsiteUrl          VARCHAR(500)    NULL DEFAULT NULL COMMENT 'Publisher / official website URL for the songbook (#672)',
    InternetArchiveUrl  VARCHAR(500)    NULL DEFAULT NULL COMMENT 'Internet Archive page (e.g. https://archive.org/details/<id>) or bare IA identifier (#672)',
    WikipediaUrl        VARCHAR(500)    NULL DEFAULT NULL COMMENT 'Wikipedia article URL (#672)',
    WikidataId          VARCHAR(20)     NULL DEFAULT NULL COMMENT 'WikiData Q-number (e.g. Q12345) (#672)',
    OclcNumber          VARCHAR(30)     NULL DEFAULT NULL COMMENT 'OCLC WorldCat number (#672)',
    OcnNumber           VARCHAR(30)     NULL DEFAULT NULL COMMENT 'OCLC Control Number (often prefixed ocn/ocm/on); kept distinct from OclcNumber so catalogues that record both can carry both (#672)',
    LcpNumber           VARCHAR(30)     NULL DEFAULT NULL COMMENT 'Library of Congress permalink / project number (#672)',
    Isbn                VARCHAR(20)     NULL DEFAULT NULL COMMENT 'ISBN-10 or ISBN-13 (dashes optional) (#672)',
    ArkId               VARCHAR(80)     NULL DEFAULT NULL COMMENT 'Archival Resource Key (e.g. ark:/13960/t8jf3w89z) (#672)',
    OpenLibraryWorkId   VARCHAR(20)     NULL DEFAULT NULL COMMENT 'Open Library Work id, e.g. OL102749W (Feature 3, epic #1765; mirrors the #672 identifier-column pattern). Bare id form; validated by ihymns_canonical_openlibrary() in includes/identifier_normalize.php, kind=work',
    OpenLibraryEditionId VARCHAR(20)    NULL DEFAULT NULL COMMENT 'Open Library Edition id, e.g. OL7357422M (Feature 3, epic #1765; mirrors the #672 identifier-column pattern). Bare id form; validated by ihymns_canonical_openlibrary() in includes/identifier_normalize.php, kind=edition',
    IsniId              VARCHAR(25)     NULL DEFAULT NULL COMMENT 'International Standard Name Identifier (16 digits, optional spacing) (#672)',
    ViafId              VARCHAR(20)     NULL DEFAULT NULL COMMENT 'Virtual International Authority File ID (#672)',
    Lccn                VARCHAR(20)     NULL DEFAULT NULL COMMENT 'Library of Congress Control Number (#672)',
    LcClass             VARCHAR(50)     NULL DEFAULT NULL COMMENT 'Library of Congress Classification call number (#672)',

    /* Hierarchical relationships (#782): translations / editions /
       abridgements of another songbook. NULL = standalone. */
    ParentSongbookId    INT UNSIGNED    NULL DEFAULT NULL COMMENT 'Parent songbook for hierarchical relationships (translation / edition / abridgement of) (#782)',
    ParentRelationship  ENUM('translation','edition','abridgement','derivative','companion')
                                        NULL DEFAULT NULL COMMENT 'Type of relationship to ParentSongbookId (#782)',

    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_DisplayOrder (DisplayOrder),
    INDEX idx_ParentSongbook (ParentSongbookId),
    INDEX idx_PublicationCityId (PublicationCityId),
    INDEX idx_IsChristian (IsChristian),
    CONSTRAINT fk_Songbook_Parent
        FOREIGN KEY (ParentSongbookId) REFERENCES tblSongbooks(Id) ON DELETE SET NULL,
    CONSTRAINT fk_Songbooks_PublicationCity
        FOREIGN KEY (PublicationCityId) REFERENCES tblPlaces(Id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblSongbookAffiliations (#670)
-- Controlled vocabulary for the Affiliation column on tblSongbooks. Acts as a
-- parallel registry — Affiliation stays a denormalised VARCHAR on tblSongbooks
-- (no FK), but every non-empty value the songbook editor saves is also
-- INSERT IGNOREd here so the typeahead can prevent duplicate-creation drift
-- (e.g. "Seventh-day Adventist Church" vs "Seventh-Day Adventist Church"
-- vs "SDA Church"). Same shape as tblMusicians below.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongbookAffiliations (
    Id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    Name        VARCHAR(120)    NOT NULL,
    CreatedAt   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY ux_affiliation_name (Name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblSongs
-- Core song metadata. Each song belongs to one songbook.
-- The `SongId` column holds the canonical string ID (e.g., "CP-0001").
-- The `LyricsText` column holds concatenated plaintext lyrics for
-- full-text searching — populated during migration/save.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongs (
    Id                  INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    SongId              VARCHAR(20)     NOT NULL UNIQUE COMMENT 'Canonical ID, e.g. CP-0001',
    PublicId            VARCHAR(16)     NULL DEFAULT NULL COMMENT 'Opaque stable permalink id (IHUID, #1343-B); Crockford base32, uppercase, no I/L/O/U/0/1. Location-independent — survives songbook move/renumber. SongId stays the PK; this is additive.',
    Number              INT UNSIGNED    NULL DEFAULT NULL COMMENT 'Song number within its songbook; NULL for Misc (unstructured collection)',
    Title               VARCHAR(500)    NOT NULL,
    NormalizedTitle     VARCHAR(500)    NOT NULL DEFAULT '' COMMENT 'App-maintained fold of Title (iconv ASCII//TRANSLIT + mb_strtolower + unicode-property strip via ihymns_normalize_title()) for a fast indexed dedup/match pre-filter; the exact compare still runs in PHP. Plain column (not GENERATED) because MySQL 8 cannot reproduce the PHP normalizer. Backfilled on migrate; kept in sync on create/edit (#1066 Theme D)',
    Subtitle            VARCHAR(500)    NULL DEFAULT NULL COMMENT 'Optional song subtitle (#1741 P1)',
    Disambiguation      VARCHAR(255)    NOT NULL DEFAULT '' COMMENT 'Short parenthetical to distinguish same-named songs (#1741 P1)',
    SongbookAbbr        VARCHAR(10)     NOT NULL COMMENT 'FK to tblSongbooks.Abbreviation; the songbook NAME is read live via JOIN to tblSongbooks.Name (de-normalised SongbookName dropped in WS-E #1013 ph2)',
    Language            VARCHAR(35)     NOT NULL DEFAULT 'en' COMMENT 'IETF BCP 47 tag (language[-script][-region]); widened from VARCHAR(10) to fit script + region subtags (#681)',
    Copyright           VARCHAR(500)    NOT NULL DEFAULT '',
    CopyrightYears      VARCHAR(100)    NOT NULL DEFAULT '' COMMENT 'As-printed copyright year(s), free text e.g. "1978, 1987, 2011" (#1741 P1); Copyright stays as the legacy as-printed denorm string and is NOT auto-parsed into this + CopyrightHolder',
    CopyrightHolder     VARCHAR(255)    NOT NULL DEFAULT '' COMMENT 'Copyright holder name (#1741 P1); see CopyrightYears comment re: the legacy Copyright column',
    FirstPublishedYear  SMALLINT UNSIGNED NULL DEFAULT NULL COMMENT 'Year of first publication (#1741 P1); SMALLINT not MySQL YEAR — YEAR starts 1901 and hymns predate it. Same column added to tblWorks in the same P1 batch (Song AND Work editors both need it, rule #20)',
    /* Composition / first-performance origin (places sweep #2). The
       VARCHAR mirror keeps reads JOIN-free; the FK lets the future
       country/region report group across the catalogue. */
    OriginCity          VARCHAR(255)    NULL DEFAULT NULL,
    OriginCityId        INT UNSIGNED    NULL DEFAULT NULL,
    TuneName            VARCHAR(120)    NULL DEFAULT NULL COMMENT 'Traditional tune name, e.g. HYFRYDOL, OLD HUNDREDTH (#497). Denorm display mirror; the canonical entity is tblTunes via TuneId (#1090)',
    TuneId              INT UNSIGNED    NULL DEFAULT NULL COMMENT 'FK to tblTunes.Id (#1090 P4); the tune as a first-class entity (meter, MusicBrainz Work). TuneName kept as JOIN-free denorm mirror. FK added via trailing ALTER (tblTunes is defined later in this file)',
    Ccli                VARCHAR(50)     NOT NULL DEFAULT '' COMMENT 'CCLI Song Number',
    Iswc                VARCHAR(15)     NULL DEFAULT NULL COMMENT 'International Standard Musical Work Code, e.g. T-034.524.680-C (#497)',
    Isrc                VARCHAR(15)     NULL DEFAULT NULL COMMENT 'International Standard Recording Code (#1064); recording id from an ingest source (MeedyaDL/Apple Music)',
    Upc                 VARCHAR(20)     NULL DEFAULT NULL COMMENT 'Universal Product Code / barcode of the source release (#1064)',
    Verified            TINYINT(1)      NOT NULL DEFAULT 0,
    LyricsPublicDomain  TINYINT(1)      NOT NULL DEFAULT 0,
    MusicPublicDomain   TINYINT(1)      NOT NULL DEFAULT 0,
    LyricsRightsLicenceKey VARCHAR(30) NULL DEFAULT NULL COMMENT 'Per-song LYRICS-rights requirement FACT (#1769 P1, Model 2): tblLicenceTypes.LicenceKey a viewer must hold (via their org licence set or Service-Mode presence) for gated lyric actions on this song. NULL = no per-song requirement — plan caps + LyricsPublicDomain alone govern. NO FK, degrade-safe like tblUsers.AccessTier: an unknown or retired key degrades to no-requirement, never blocks a save or read. DORMANT until the P2 resolver reads it',
    MusicRightsLicenceKey VARCHAR(30) NULL DEFAULT NULL COMMENT 'Per-song MUSIC-rights requirement FACT (#1769 P1 / #1768): tblLicenceTypes.LicenceKey required for music-reproduction actions (chord display, sheet music, MusicXML, MIDI — plan Q3: MIDI counts). NULL = no per-song requirement — plan caps + MusicPublicDomain alone govern. NO FK (degrade-safe, see LyricsRightsLicenceKey). Song-grain first (#1768 Q2); the arrangement-grain override is the reserved pair on tblSongArrangements. DORMANT until the P2 resolver reads it',
    Availability        VARCHAR(20)     NOT NULL DEFAULT 'available' COMMENT 'available | paid_only | unavailable (#1090 audit). Owner-driven rights gate read by content_access.php — distinct from the blanket PD flags; VARCHAR not ENUM',
    IsExplicit          TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'Explicit-content flag (#1046); 0 for the Christian corpus, axis exists for generic/secular imports',
    Genre               VARCHAR(100)    NULL DEFAULT NULL COMMENT 'Free-text secondary genre (#1046): Hymn / Contemporary Worship / … (NOT the Christian-filter axis — that is tblSongbooks.IsChristian)',
    HasAudio            TINYINT(1)      NOT NULL DEFAULT 0,
    HasSheetMusic       TINYINT(1)      NOT NULL DEFAULT 0,
    LyricsText          MEDIUMTEXT      NOT NULL DEFAULT ('') COMMENT 'Concatenated lyrics for full-text search',
    LyricsTextFolded    MEDIUMTEXT      NULL DEFAULT NULL COMMENT 'Diacritic-folded mirror of LyricsText for accent-insensitive FULLTEXT (Noël↔Noel) (#1090 audit / #1039); app-maintained on write',
    ArrangementJson     JSON            NULL DEFAULT NULL COMMENT 'Optional int-array of indices into components[] that overrides the stored SortOrder (lets a refrain repeat between verses) (#892)',
    CreatedAt           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    /* Song soft delete (#1694, epic #1692). The row is retained and hidden —
       38 of the 41 FKs referencing tblSongs(SongId) CASCADE, so a hard delete
       destroys components, lyric lines, credits, media, tags and the entire
       revision history irrecoverably; restore must be prevention, not repair.
       Dormant until the read-path sweep + write cores consume them. The
       fk_Songs_DeletedBy FK is added via trailing ALTER (tblUsers is defined
       later in this file — same reason as fk_Songs_Tune). */
    IsDeleted           TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'Soft-delete flag (#1694): 1 = hidden from every filtered read, restorable via /manage/deleted-songs. The row is never hard-deleted on this path (38 of 41 inbound FKs CASCADE — restore must be prevention, not repair); purge is the separate, gated hard delete',
    DeletedAt           DATETIME        NULL DEFAULT NULL COMMENT 'UTC instant of the soft delete (#1694); DATETIME not TIMESTAMP so it is never re-read against a session zone (rule #20). NULL while the song is live',
    DeletedBy           INT UNSIGNED    NULL DEFAULT NULL COMMENT 'FK to tblUsers.Id — who soft-deleted it (#1694). ON DELETE SET NULL so attribution resolves to the account tombstone if the deleter is later erased (#1698)',
    DeletedReason       VARCHAR(50)     NULL DEFAULT NULL COMMENT 'Why it was deleted (#1694) — app-validated vocabulary from songDeleteReasons() in includes/song_soft_delete.php; VARCHAR not ENUM so the vocabulary grows without an ALTER (rule #20)',
    DeleteNote          VARCHAR(255)    NOT NULL DEFAULT '' COMMENT 'Free-text curator note accompanying the delete (#1694). Empty string when none — NOT NULL so reads never juggle NULL vs empty',

    INDEX idx_Songbook          (SongbookAbbr),
    INDEX idx_SongbookNumber    (SongbookAbbr, Number),
    INDEX idx_NormalizedTitle   (NormalizedTitle),
    INDEX idx_TuneName          (TuneName),
    INDEX idx_TuneId            (TuneId),
    INDEX idx_OriginCityId      (OriginCityId),
    INDEX idx_Genre             (Genre),
    INDEX idx_Isrc              (Isrc),
    INDEX idx_Iswc              (Iswc),
    INDEX idx_Ccli              (Ccli),
    INDEX idx_LyricsRightsLicence (LyricsRightsLicenceKey),
    INDEX idx_MusicRightsLicence  (MusicRightsLicenceKey),
    INDEX idx_IsDeleted         (IsDeleted, DeletedAt),
    UNIQUE KEY uniq_PublicId    (PublicId),
    FULLTEXT idx_TitleFt        (Title),
    FULLTEXT idx_LyricsFt       (LyricsText),
    FULLTEXT idx_TitleLyricsFt  (Title, LyricsText),
    FULLTEXT ft_LyricsTextFolded (LyricsTextFolded),

    /* Why the FK targets Abbreviation and not tblSongbooks.Id: SongbookAbbr IS
       the SongId prefix ("MP" in MP-1008), so the natural key is what every
       read path and URL already carries — a surrogate Id here would mean a
       JOIN on every song read for no gain.

       ON DELETE RESTRICT (not CASCADE) is deliberate and is the ONLY inbound
       delete rule on a songbook that blocks: deleting a songbook that still
       holds songs is refused outright rather than silently destroying its
       whole hymnal. Empty the book first.

       ON UPDATE CASCADE re-keys this column when an Abbreviation is renamed —
       but note it does NOT rewrite the abbreviation embedded in
       tblSongs.SongId, which is a plain string. A rename therefore leaves
       MP-1008 sitting in a book now called something else; re-keying songs is
       a deliberate application operation (includes/song_relocate.php, #1679),
       not a side effect of this constraint. */
    CONSTRAINT fk_Songs_Songbook
        FOREIGN KEY (SongbookAbbr) REFERENCES tblSongbooks(Abbreviation)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_Songs_OriginCity
        FOREIGN KEY (OriginCityId) REFERENCES tblPlaces(Id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblSongbookEntries (#1044 — iLyricsDB alignment)
-- N:N song↔songbook membership so a song can appear in several hymnals with
-- different numbers (cross-hymnal de-duplication) and a future non-Christian
-- song can have ZERO entries (owned via its artist instead). The existing
-- tblSongs.SongbookAbbr + Number are RETAINED as the song's "home" (IsHome=1);
-- this junction is strictly additive and not yet read by the app.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongbookEntries (
    Id            INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    SongbookAbbr  VARCHAR(10)     NOT NULL COMMENT 'FK to tblSongbooks.Abbreviation',
    SongId        VARCHAR(20)     NOT NULL COMMENT 'FK to tblSongs.SongId (the human id)',
    SongNumber    INT UNSIGNED    NULL COMMENT 'Number within THIS songbook; NULL for unstructured collections (Misc)',
    IsHome        TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = the song''s home/primary songbook (the one its SongId is prefixed from); kept in sync with tblSongs.SongbookAbbr',
    CreatedAt     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    /* uq_book_number enforces "one song per number per book" — but only for
       numbered books. MySQL treats every NULL as distinct inside a UNIQUE key,
       so the whole unnumbered Misc collection coexists here without collision.
       That NULL-permits-duplicates behaviour is used deliberately in several
       places in this file (uk_OsmRef on tblPlaces, the (Source, SourceRef)
       idempotency keys); it is a feature, not an oversight. */
    UNIQUE KEY uq_book_song   (SongbookAbbr, SongId),
    UNIQUE KEY uq_book_number (SongbookAbbr, SongNumber),
    INDEX idx_SongId   (SongId),
    INDEX idx_Songbook (SongbookAbbr),
    INDEX idx_Home     (SongId, IsHome),

    CONSTRAINT fk_SongbookEntries_Songbook
        FOREIGN KEY (SongbookAbbr) REFERENCES tblSongbooks(Abbreviation)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_SongbookEntries_Song
        FOREIGN KEY (SongId) REFERENCES tblSongs(SongId)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblSongWriters
-- Many-to-one: a song can have multiple writers (lyricists).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongWriters (
    Id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    SongId      VARCHAR(20)     NOT NULL,
    Name        VARCHAR(255)    NOT NULL,

    INDEX idx_SongId    (SongId),
    INDEX idx_Name      (Name),

    CONSTRAINT fk_Writers_Song
        FOREIGN KEY (SongId) REFERENCES tblSongs(SongId)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblSongComposers
-- Many-to-one: a song can have multiple composers.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongComposers (
    Id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    SongId      VARCHAR(20)     NOT NULL,
    Name        VARCHAR(255)    NOT NULL,

    INDEX idx_SongId    (SongId),
    INDEX idx_Name      (Name),

    CONSTRAINT fk_Composers_Song
        FOREIGN KEY (SongId) REFERENCES tblSongs(SongId)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblSongArrangers (#497)
-- Many-to-one: a song can have multiple arrangers.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongArrangers (
    Id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    SongId      VARCHAR(20)     NOT NULL,
    Name        VARCHAR(255)    NOT NULL,

    INDEX idx_SongId    (SongId),
    INDEX idx_Name      (Name),

    CONSTRAINT fk_Arrangers_Song
        FOREIGN KEY (SongId) REFERENCES tblSongs(SongId)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblSongAdaptors (#497)
-- Many-to-one: a song can have multiple adaptors.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongAdaptors (
    Id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    SongId      VARCHAR(20)     NOT NULL,
    Name        VARCHAR(255)    NOT NULL,

    INDEX idx_SongId    (SongId),
    INDEX idx_Name      (Name),

    CONSTRAINT fk_Adaptors_Song
        FOREIGN KEY (SongId) REFERENCES tblSongs(SongId)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblSongTranslators (#497)
-- Many-to-one: a song can have multiple translators. Distinct from the
-- tblSongTranslations link table (#352) which joins a source song to its
-- equivalent in another language — the Translators table credits the
-- people who produced those translations for *this* song.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongTranslators (
    Id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    SongId      VARCHAR(20)     NOT NULL,
    Name        VARCHAR(255)    NOT NULL,

    INDEX idx_SongId    (SongId),
    INDEX idx_Name      (Name),

    CONSTRAINT fk_Translators_Song
        FOREIGN KEY (SongId) REFERENCES tblSongs(SongId)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblSongArtists (#587)
-- Recording / release artist credits — distinct from the Writers /
-- Composers / Arrangers etc. roles. Captures the performing artist
-- (e.g. "Hillsong Worship" for "What a Beautiful Name") rather than
-- the songwriter. Feeds the future ProPresenter export which wants
-- the artist name on every slide. Created via migrate-song-artists.php.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongArtists (
    Id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    SongId      VARCHAR(20)     NOT NULL,
    Name        VARCHAR(255)    NOT NULL,
    SortOrder   INT             NOT NULL DEFAULT 0 COMMENT 'Display order when a song has multiple artists',
    CreatedAt   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_SongId    (SongId),
    INDEX idx_Name      (Name),

    CONSTRAINT fk_Artists_Song
        FOREIGN KEY (SongId) REFERENCES tblSongs(SongId)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblMusicians (#545; renamed from tblCreditPeople by #1741 P2 — see the
-- back-compat VIEWs family after the tblMusicianRelations block below, which
-- exposes this table's data under every pre-rename name/column for app code
-- that hasn't migrated to the new names yet, #1741 P2-B).
-- Registry of people credited on songs. Holds the canonical Name plus
-- optional biographical metadata. The five song-credit tables above
-- (tblSongWriters / tblSongComposers / tblSongArrangers / tblSongAdaptors
-- / tblSongTranslators) continue to store free-text Name strings — this
-- registry is additive, not a foreign-key on those five. Rename / merge
-- operations bulk-UPDATE the Name column across all five tables (and the
-- registry row) inside a single transaction, leaving the existing schema
-- intact.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblMusicians (
    Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    Name            VARCHAR(255)    NOT NULL,
    /* URL-safe slug (#588) — backfilled from Name with collision-safe
       numeric suffixes so two "John Smith" rows still map to two
       distinct slugs. Used for the public /people/<slug> page. */
    Slug            VARCHAR(255)    NULL UNIQUE,
    MusicBrainzArtistMBID VARCHAR(50) NULL DEFAULT NULL COMMENT 'MusicBrainz Artist MBID (#1090 P6) — typed home for artist dedup/enrichment, vs a parsed external-link URL',
    /* Special-case + Group flags (#584 / #585) — distinguish
       Anonymous / Traditional / Public Domain / Unknown ("special
       case") from real individuals, and Hillsong United / Bethel
       Music ("group / collective") from solo writers. Both flags
       feed UI rules in the Credit People editor (e.g. disable
       birth/death fields when special case; relabel dates as
       Founded/Disbanded when group). */
    IsSpecialCase   TINYINT(1)      NOT NULL DEFAULT 0,
    IsGroup         TINYINT(1)      NOT NULL DEFAULT 0,
    /* Entity-type vocabulary (#1741 P1) — wider than the two flags above.
       Authoritative once Phase 3 lands (creditPersonTypeApply() flips
       IsGroup/IsSpecialCase to derived mirrors); until then the flags stay
       the read/write source of truth and Type only carries the P1
       backfill from their current values. */
    Type            VARCHAR(20)     NOT NULL DEFAULT 'person' COMMENT 'person | group | character | orchestra | other (app-validated; VARCHAR not ENUM, rule #20). Authoritative once Phase 3 lands; IsGroup/IsSpecialCase stay the read/write source until then (#1741 P1)',
    Disambiguation  VARCHAR(255)    NOT NULL DEFAULT '' COMMENT 'Short parenthetical to distinguish same-named musicians, e.g. "(the elder)" (#1741 P1)',
    /* Structured-name parts (#934). Backfilled from Name on first run
       of migrate-credit-people-name-parts.php; new inserts populate
       these alongside the canonical Name string. Group / special-case
       rows leave these NULL — only real individuals get split. */
    FirstNames      VARCHAR(255)    NULL,
    Surname         VARCHAR(255)    NULL,
    MaidenSurname   VARCHAR(100)    NULL DEFAULT NULL COMMENT 'Optional birth surname, when different from the current Surname (#1501)',
    Suffix          VARCHAR(64)     NULL,
    Notes           TEXT            NULL,
    Biography       MEDIUMTEXT      NULL DEFAULT NULL COMMENT 'Musician biography (#1741 P1). Backfilled once from legacy Notes (copy, not move — Notes stays the live read path on person.php until Phase 4a switches over); new writes go here once that phase ships',
    BirthPlace      VARCHAR(255)    NULL,
    /* FK into tblPlaces; nullable for legacy / free-text rows where
       no canonical place was picked from the geocoder. BirthPlace
       stays alongside as a denormalised display string so reports
       and read paths don't need a JOIN. */
    BirthPlaceId    INT UNSIGNED    NULL,
    BirthDate       DATE            NULL,
    BirthDatePrecision VARCHAR(5)   NULL DEFAULT NULL COMMENT 'How much of BirthDate is real: year | month | day (partial historical dates)',
    DeathPlace      VARCHAR(255)    NULL,
    DeathPlaceId    INT UNSIGNED    NULL,
    DeathDate       DATE            NULL,
    DeathDatePrecision VARCHAR(5)   NULL DEFAULT NULL COMMENT 'How much of DeathDate is real: year | month | day (partial historical dates)',
    CreatedAt       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_Name (Name),
    UNIQUE KEY uq_MbArtist (MusicBrainzArtistMBID),
    INDEX idx_Name (Name),
    INDEX idx_Slug (Slug),
    INDEX idx_BirthPlaceId (BirthPlaceId),
    INDEX idx_DeathPlaceId (DeathPlaceId),

    CONSTRAINT fk_Musicians_BirthPlace
        FOREIGN KEY (BirthPlaceId) REFERENCES tblPlaces(Id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_Musicians_DeathPlace
        FOREIGN KEY (DeathPlaceId) REFERENCES tblPlaces(Id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblMusicianLinks (#545; renamed from tblCreditPersonLinks by #1741 P2)
-- Multiple external reference links per person (Wikipedia, official
-- website, MusicBrainz, Discogs, IMSLP, Hymnary, other). LinkType is a
-- short string key the UI maps to a friendly label and icon; storing as
-- VARCHAR rather than ENUM keeps new categories cheap to add. SortOrder
-- preserves admin-controlled display order. ON DELETE CASCADE removes
-- the links automatically when the parent registry row is deleted.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblMusicianLinks (
    Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    MusicianId      INT UNSIGNED    NOT NULL,
    LinkType        VARCHAR(64)     NOT NULL,
    Url             VARCHAR(2048)   NOT NULL,
    Label           VARCHAR(255)    NULL,
    SortOrder       SMALLINT        NOT NULL DEFAULT 0,
    CreatedAt       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_MusicianId (MusicianId),

    CONSTRAINT fk_MusicianLinks_Musician
        FOREIGN KEY (MusicianId) REFERENCES tblMusicians(Id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblMusicianIPI (#545; renamed from tblCreditPersonIPI by #1741 P2)
-- IPI (Interested Parties Information) Name Numbers per person. A single
-- individual can be registered under more than one IPI Name Number when
-- they use multiple performing names — hence one-to-many on the registry
-- row. UNIQUE on (MusicianId, IPINumber) prevents duplicate IPIs per
-- person while still allowing the same number to legitimately attach to
-- two different registry rows if the data demands it. NameUsed is the
-- spelling that IPI is registered under (often differs from the canonical
-- registry Name). ON DELETE CASCADE matches the links table.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblMusicianIPI (
    Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    MusicianId      INT UNSIGNED    NOT NULL,
    IPINumber       VARCHAR(32)     NOT NULL,
    NameUsed        VARCHAR(255)    NULL,
    Notes           VARCHAR(255)    NULL,
    CreatedAt       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_MusicianIPI (MusicianId, IPINumber),
    INDEX idx_IPINumber (IPINumber),

    CONSTRAINT fk_MusicianIPI_Musician
        FOREIGN KEY (MusicianId) REFERENCES tblMusicians(Id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblMusicianIdentifiers (renamed from tblCreditPersonIdentifiers by #1741 P2)
-- Unified MusicBrainz-style identifier table: holds IPI Name Numbers AND
-- ISNI (International Standard Name Identifier) rows side by side, with
-- IdentifierType discriminating. Replaces the per-kind tblMusicianIPI
-- table going forward; the legacy table stays as a one-release rollback
-- snapshot and is dropped in a follow-up migration.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblMusicianIdentifiers (
    Id              INT UNSIGNED       AUTO_INCREMENT PRIMARY KEY,
    MusicianId      INT UNSIGNED       NOT NULL,
    IdentifierType  VARCHAR(20)        NOT NULL COMMENT 'ipi | isni | cae | ipi-base | <pro-id> (app-validated; widened from ENUM #1090 P6 so new industry identifier types need no ALTER)',
    IdentifierValue VARCHAR(64)        NOT NULL,
    NameUsed        VARCHAR(255)       NULL,
    Notes           VARCHAR(255)       NULL,
    CreatedAt       DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP
                                       ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_MusicianIdValue (MusicianId, IdentifierType, IdentifierValue),
    INDEX idx_TypeValue (IdentifierType, IdentifierValue),

    CONSTRAINT fk_MusicianIdentifiers_Musician
        FOREIGN KEY (MusicianId) REFERENCES tblMusicians(Id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- LYRICS STORAGE — components + the normalised line / word / syllable model
--
-- Two layers that used to be one. tblSongComponents is now THIN metadata
-- (which verse, what order, what language); the actual words live in
--     tblLyrics -> tblLyricLines -> tblLyricWords -> tblLyricSyllables
-- one level per timing granularity, so an untimed hymn stops at tblLyricLines
-- and an Apple-Music TTML import fills all four.
--
-- WHY THIS MATTERS FOR EVERY LATER TABLE: tblLyricLines.Id (BIGINT) is the
-- anchor that per-line enrichment hangs off throughout the rest of this file —
-- tblLyricLineTranslations and tblLyricLineAnnotations (#1088), the vocal-part
-- assignment tables (#1137), tblSongScriptureRefs (#1112) and the presentation
-- slide overrides (#1168) all point here. Anchoring on an index into a JSON
-- array instead is the regression rule #21 exists to prevent: array positions
-- shift when a line is inserted, row ids do not.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- tblSongComponents (THIN metadata, #1235 P4/C6)
-- A component is now thin metadata only: Type / Number / SortOrder / Language.
-- Its lyric lines live in the AUTHORITATIVE tblLyricLines (grouped by ComponentId);
-- `SortOrder` preserves the display sequence. The JSON payload columns LinesJson /
-- ChordsJson / NotesJson / LanguagesJson were RETIRED (migrate-retire-component-lines-json.php)
-- once tblLyricLines became the single source of truth (read switch C4, write inversion C5).
-- This is the post-cutover canonical shape a fresh install lands directly; long-running
-- installs converge by running that drop migration. Rebuild the columns from lines via
-- regenerate-lines-json-from-lines.php if ever needed.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongComponents (
    Id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    SongId      VARCHAR(20)     NOT NULL,
    Type        VARCHAR(20)     NOT NULL COMMENT 'verse, chorus, refrain, bridge, etc.',
    Number      INT UNSIGNED    NOT NULL COMMENT 'Component number (e.g., verse 1, verse 2)',
    SortOrder   INT UNSIGNED    NOT NULL COMMENT 'Display order within the song',
    Language    VARCHAR(35)     NULL DEFAULT NULL COMMENT 'Optional per-component language override; NULL = inherit from parent tblSongs.Language. Used for multi-language medleys (#858)',

    INDEX idx_SongId        (SongId),
    INDEX idx_SongOrder     (SongId, SortOrder),

    CONSTRAINT fk_Components_Song
        FOREIGN KEY (SongId) REFERENCES tblSongs(SongId)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- Normalised lyrics model (#1047 — iLyricsDB alignment)
-- Generic, timing-capable lyrics: a song MAY have several tblLyrics (the iHymns
-- "approved" version, explicit vs clean, a timed Apple-Music import …); each is
-- a list of tblLyricLines (optional line timing + per-line language), each of
-- which MAY carry tblLyricWords for word/syllable timing (TTML / LRC-A).
-- #1235 P4: tblLyricLines is now the AUTHORITATIVE store (read switch C4, write
-- inversion C5); the legacy tblSongComponents JSON payload columns it was once
-- backfilled from were retired in C6 (see tblSongComponents above).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblLyrics (
    Id            INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    SongId        VARCHAR(20)     NOT NULL COMMENT 'FK to tblSongs.SongId',
    Source        VARCHAR(100)    NOT NULL DEFAULT 'ihymns' COMMENT 'Provenance: ihymns / applemusic-ttml / user-submission / …',
    SourceUrl     VARCHAR(1000)   NULL DEFAULT NULL,
    FormatVersion VARCHAR(20)     NOT NULL DEFAULT '1.0',
    IsPrimary     TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = the canonical lyrics shown for the song',
    IsExplicit    TINYINT(1)      NOT NULL DEFAULT 0,
    HasTiming     TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = line-level timing present',
    HasWordTiming TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = word-level timing present (TTML/LRC-A)',
    HasSyllableTiming TINYINT(1)  NOT NULL DEFAULT 0 COMMENT '1 = syllable-level timing present (TTML/karaoke) (#141)',
    /* Grandfathered ENUM — predates rule #20 and is explicitly exempted by it.
       Its later siblings tblLyricLineTranslations.Status and
       tblLyricLineAnnotations.Status carry the SAME five values as an
       app-validated VARCHAR; those are the shape to copy for anything new. */
    Status        ENUM('draft','pending_review','approved','rejected','archived') NOT NULL DEFAULT 'approved',
    SubmittedBy   INT UNSIGNED    NULL DEFAULT NULL,
    ApprovedBy    INT UNSIGNED    NULL DEFAULT NULL,
    ApprovedAt    DATETIME        NULL DEFAULT NULL,
    CreatedAt     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_song_source (SongId, Source),
    INDEX idx_SongId  (SongId),
    INDEX idx_Primary (SongId, IsPrimary),
    INDEX idx_Status  (Status),

    CONSTRAINT fk_Lyrics_Song
        FOREIGN KEY (SongId) REFERENCES tblSongs(SongId) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tblLyricLines (
    Id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    LyricsId      INT UNSIGNED    NOT NULL,
    ComponentId   INT UNSIGNED    NULL DEFAULT NULL COMMENT 'Source tblSongComponents.Id during transition (traceability)',
    PartType      VARCHAR(20)     NULL DEFAULT NULL COMMENT 'Denorm of the component type (verse/chorus/…) for standalone use',
    PartTypeSlug  VARCHAR(40)     NULL DEFAULT NULL COMMENT 'Typed key into tblSongPartTypes.Slug (decoded from itunes:song-part / component type) so reorder-by-part is a JOIN not a string match (#1090 audit)',
    PartNumber    INT UNSIGNED    NULL DEFAULT NULL,
    SortOrder     INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'Global line order within the lyrics',
    LineText      TEXT            NOT NULL,
    StartTimeMs   INT UNSIGNED    NULL DEFAULT NULL,
    EndTimeMs     INT UNSIGNED    NULL DEFAULT NULL,
    LanguageCode  VARCHAR(35)     NULL DEFAULT NULL COMMENT 'Per-line language override (IETF tag); NULL = song default',
    IsInstrumental TINYINT(1)     NOT NULL DEFAULT 0,
    MetaJson      JSON            NULL DEFAULT NULL COMMENT 'Lossless TTML line attrs (ttm:role, ttm:agent, itunes:song-part, background-vocal) (#141)',
    ChordsJson    JSON            NULL DEFAULT NULL COMMENT 'Per-line chords mirrored from tblSongComponents.ChordsJson[i] (null/string/array); #1235 P1 normalisation',
    Note          TEXT            NULL DEFAULT NULL COMMENT 'Per-line presenter/slide note mirrored from tblSongComponents.NotesJson[i]; #1235 P1 normalisation',
    CreatedAt     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    /* idx_Lyrics is (LyricsId, SortOrder) in that order because every read is
       "give me the lines of ONE lyrics version, in order" — the leading column
       narrows to the version, the trailing one lets InnoDB return them already
       sorted instead of filesorting a whole song's worth of rows.

       ComponentId is INDEXED BUT NOT FOREIGN-KEYED, unlike the ComponentId on
       the three presentation tables further down this file, which do CASCADE.
       Its column COMMENT frames it as transitional traceability — but the
       read path (includes/lyric_lines_read.php) now groups lines by it, so it
       is load-bearing, and nothing at the database level stops a component
       being deleted out from under the lines that name it. Reparenting and
       cleanup are the writer's job (lyricLinesWriteComponents(), rule #25). */
    INDEX idx_Lyrics    (LyricsId, SortOrder),
    INDEX idx_Component (ComponentId),

    CONSTRAINT fk_LyricLines_Lyrics
        FOREIGN KEY (LyricsId) REFERENCES tblLyrics(Id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tblLyricWords (
    Id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    LineId      BIGINT UNSIGNED NOT NULL,
    SortOrder   INT UNSIGNED    NOT NULL DEFAULT 0,
    WordText    VARCHAR(200)    NOT NULL,
    StartTimeMs INT UNSIGNED    NULL DEFAULT NULL,
    EndTimeMs   INT UNSIGNED    NULL DEFAULT NULL,
    MetaJson    JSON            NULL DEFAULT NULL COMMENT 'Lossless TTML word attrs (itunes:key, …) (#141)',
    CreatedAt   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_Line (LineId, SortOrder),

    CONSTRAINT fk_LyricWords_Line
        FOREIGN KEY (LineId) REFERENCES tblLyricLines(Id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- tblLyricSyllables (#141) — syllable-level timing within a word (Apple Music
-- syllable-synced TTML, karaoke). Empty until timed imports populate it.
CREATE TABLE IF NOT EXISTS tblLyricSyllables (
    Id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    WordId      BIGINT UNSIGNED NOT NULL,
    SortOrder   INT UNSIGNED    NOT NULL DEFAULT 0,
    SyllableText VARCHAR(100)   NOT NULL,
    StartTimeMs INT UNSIGNED    NULL DEFAULT NULL,
    EndTimeMs   INT UNSIGNED    NULL DEFAULT NULL,
    MetaJson    JSON            NULL DEFAULT NULL COMMENT 'Lossless TTML/format attrs (itunes:key, role, …)',
    CreatedAt   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_Word (WordId, SortOrder),

    CONSTRAINT fk_LyricSyllables_Word
        FOREIGN KEY (WordId) REFERENCES tblLyricWords(Id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblApiKeys (#1064) — machine-to-machine API keys for external services
-- (e.g. MeedyaDL #907 pushing TTML to the lyrics-ingest endpoint). The raw key
-- is shown once at creation and never stored; only its SHA-256 hash lives here.
-- Space-separated Scope authorises each endpoint (e.g. "lyrics:ingest").
--
-- Filed at the end of the catalogue section rather than under USER ACCOUNTS
-- because this is a MACHINE identity, not a person's — it authenticates the
-- ingest side of the lyrics pipeline above, and has no tblUsers row of its
-- own (CreatedBy only records which admin minted it). Its three satellite
-- tables — tblApiKeyUsage (rate-limit counters), tblApiKeyIdempotency (retry
-- cache) and tblApiKeyRequests (self-serve requests) — ship with the #1066
-- batch much further down, since they arrived later.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblApiKeys (
    Id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    Label       VARCHAR(120)    NOT NULL COMMENT 'Human label, e.g. MeedyaDL',
    KeyHash     CHAR(64)        NOT NULL COMMENT 'SHA-256 hex of the raw key (raw never stored)',
    KeyPrefix   VARCHAR(20)     NOT NULL DEFAULT '' COMMENT 'Non-secret leading chars for identification',
    Scope       VARCHAR(255)    NOT NULL DEFAULT '' COMMENT 'Space-separated scopes, e.g. lyrics:ingest',
    Active      TINYINT(1)      NOT NULL DEFAULT 1,
    LastUsedAt  DATETIME        NULL DEFAULT NULL,
    LastUsedIp  VARCHAR(45)     NULL DEFAULT NULL,
    RateLimitPerMin INT UNSIGNED NULL DEFAULT NULL COMMENT 'Max requests/minute; NULL = no limit (#1066 Theme B)',
    RateLimitPerDay INT UNSIGNED NULL DEFAULT NULL COMMENT 'Max requests/calendar day (UTC); NULL = no limit (#1066 Theme B)',
    CreatedBy   INT UNSIGNED    NULL DEFAULT NULL COMMENT 'tblUsers.Id of the admin who created it',
    CreatedAt   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_KeyHash (KeyHash),
    INDEX idx_Active (Active)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- USER ACCOUNTS & AUTHENTICATION
-- ============================================================================

-- ----------------------------------------------------------------------------
-- tblUserGroups
-- Defines organisational groups with version access control.
-- Each group determines which release channels its members can access:
--   - RTW (Release to Web) — production, everyone
--   - RC (Release Candidate) — pre-release testing
--   - Beta — beta testing
--   - Alpha — development/internal
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblUserGroups (
    Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    Name            VARCHAR(100)    NOT NULL UNIQUE,
    Description     TEXT            NOT NULL DEFAULT (''),
    AccessAlpha     TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'Can access Alpha (dev) builds',
    AccessBeta      TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'Can access Beta builds',
    AccessRc        TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'Can access RC (Release Candidate) builds',
    AccessRtw       TINYINT(1)      NOT NULL DEFAULT 1 COMMENT 'Can access RTW (Release to Web / production)',
    AllowCardReorder TINYINT(1)     NOT NULL DEFAULT 1 COMMENT 'Group members may customise dashboard / home card layout (#448)',
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblUsers
-- User accounts for both the admin panel (session-based) and the public
-- API (bearer token). The `Role` column defines the permission tier:
--   global_admin (4) > admin (3) > editor (2) > user (1)
-- The optional `GroupId` links to a user group for version access control.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblUsers (
    Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    Username        VARCHAR(100)    NOT NULL UNIQUE,
    Email           VARCHAR(255)    NOT NULL DEFAULT '',
    EmailVerified   TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = email address confirmed',
    PasswordHash    VARCHAR(255)    NOT NULL DEFAULT '' COMMENT 'Empty string = passwordless (email-only login)',
    DisplayName     VARCHAR(100)    NOT NULL DEFAULT '',
    Role            VARCHAR(20)     NOT NULL DEFAULT 'user' COMMENT 'global_admin, admin, editor, user',
    GroupId         INT UNSIGNED    NULL DEFAULT NULL COMMENT 'FK to tblUserGroups for version access',
    IsActive        TINYINT(1)      NOT NULL DEFAULT 1,
    -- Account lifecycle (#1698). IsActive stays THE lock (13+ enforcement points
    -- in manage/includes/auth.php plus getAuthenticatedUser()); Status only says
    -- WHICH kind of inactive, so disabled (reversible) and deleted (anonymised
    -- tombstone) are distinguishable. Invariant IsActive = (Status = 'active'),
    -- enforced in the ONE writer, setUserActive().
    Status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active | disabled | deleted — app-validated vocabulary, VARCHAR not ENUM (rule #20). deleted = anonymised tombstone (#1698)',
    StatusChangedAt DATETIME NULL DEFAULT NULL COMMENT 'UTC instant Status last changed; DATETIME not TIMESTAMP so it is not re-read against a session zone (#1698)',
    AccessTier      VARCHAR(20)     NOT NULL DEFAULT 'free' COMMENT 'public, free, ccli, premium, pro',
    CcliNumber      VARCHAR(20)     NOT NULL DEFAULT '' COMMENT 'CCLI licence number (6-7 digits)',
    CcliVerified    TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = CCLI number validated',
    LastLoginAt     TIMESTAMP       NULL DEFAULT NULL COMMENT 'Last successful login timestamp',
    LoginCount      INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'Total successful login count',
    Settings        JSON            NULL DEFAULT NULL COMMENT 'Synced per-user app preferences (theme, font, accessibility, etc.)',
    AvatarService   VARCHAR(20)     NULL DEFAULT NULL COMMENT 'Avatar resolver: gravatar, libravatar, dicebear, none. NULL = use site default. (#616)',
    PreferredLanguagesJson JSON     NULL DEFAULT NULL COMMENT 'Synced per-user language-filter choice — JSON array of IETF BCP 47 primary subtags (e.g. ["en","es"]). NULL / [] = show all languages (#736)',
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    /* Three separate axes live on this row and are routinely confused:
         Role ....... what you may DO in the admin panel (global_admin > admin
                      > editor > user). A plain string with no lookup table —
                      the ladder is a PHP constant, not data.
         AccessTier . what CONTENT you may see. Echoes tblAccessTiers.Name but
                      carries no foreign key, so a tier renamed there leaves
                      users pointing at a name that no longer resolves; the
                      resolver treats an unknown tier as the fallback matrix.
         GroupId .... which RELEASE CHANNEL builds you can reach (alpha/beta/
                      rc/rtw). The only one of the three that is a real FK, and
                      it is SET NULL so deleting a group demotes members to the
                      default rather than deleting the accounts.
       Email is indexed but NOT unique — Username carries the uniqueness. */
    INDEX idx_Role      (Role),
    INDEX idx_Email     (Email),
    INDEX idx_Group     (GroupId),
    INDEX idx_Status    (Status),

    CONSTRAINT fk_Users_Group
        FOREIGN KEY (GroupId) REFERENCES tblUserGroups(Id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- REMOVED (remediation X4, 2026-07-30, orphan inventory §3.1 B.1): tblSessions
-- (admin-panel session records), tblUserPurchases (a guessed 2019-vintage
-- purchases/monetisation shape), tblUserPermissions (per-user fine-grained
-- flags), tblMigrations (schema migration tracking). All four were schema.sql-
-- only — no migrate-*.php ever created them, no app code ever read or wrote
-- them (`/manage/` sessions are cookie/token-based via tblApiTokens +
-- PHP's own session store, not this table; migrations are tracked by
-- migration-registry.php + tblAppSettings sentinel rows, not tblMigrations).
-- A fresh install created four tables nothing has ever referenced. Owner
-- decision D2 (remediation-plan-2026-07-30.md §6): default A, drop all four —
-- a future purchases feature designs its own one-pass schema per rule #20
-- rather than resurrecting this guessed placeholder. The DROP itself needs no
-- new migration: the existing generic "Drop Legacy Tables" card
-- (appWeb/.sql/drop-legacy-tables.php, manage/setup-database.php) diffs the
-- live database against schema.sql at runtime and offers to drop anything no
-- longer declared here — manual, type-to-confirm-gated, and deliberately left
-- UN-RUN on production until the owner nods (D2's veto window).
-- ----------------------------------------------------------------------------


-- ----------------------------------------------------------------------------
-- tblApiTokens
-- Bearer tokens for public-facing user authentication.
-- 64-character hex string (32 random bytes), 30-day default expiry.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblApiTokens (
    Token           VARCHAR(64)     NOT NULL PRIMARY KEY,
    UserId          INT UNSIGNED    NOT NULL,
    DeviceName      VARCHAR(120)    NULL DEFAULT NULL COMMENT 'Client-reported device display name (#1409), e.g. an iPhone name — optional, never required for auth',
    Platform        VARCHAR(20)     NULL DEFAULT NULL COMMENT 'apple | android | web (#1409) — mirrors ANALYTICS_INGEST_ALLOWED_PLATFORMS vocabulary, VARCHAR not ENUM (rule #20)',
    AppVersion      VARCHAR(20)     NULL DEFAULT NULL COMMENT 'Client app version string at issue/last-seen time (#1409), e.g. 1.4.2',
    LastSeenAt      DATETIME        NULL DEFAULT NULL COMMENT 'Updated on each authenticated request bearing this token (#1409) — powers a future manage-devices last-active column',
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ExpiresAt       TIMESTAMP       NOT NULL,

    INDEX idx_User      (UserId),
    INDEX idx_Expires   (ExpiresAt),

    CONSTRAINT fk_Tokens_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblAuthDeviceCodes (#1407) — RFC 8628 OAuth Device Authorization Grant codes
-- for tvOS/limited-input pairing. A tvOS client requests a device_code +
-- shows a short UserCode; the account owner enters that UserCode on a
-- full-input device's browser at /link to approve. Channel-scoped (rule #26)
-- so an alpha-issued code can't be approved against production. Dormant until
-- the tvOS device-code client + /link page ship.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblAuthDeviceCodes (
    Id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    DeviceCodeHash CHAR(64)     NOT NULL COMMENT 'SHA-256 hex of the device_code the tvOS/limited-input client polls with (RFC 8628 section 3.2) — raw value never stored',
    UserCode       VARCHAR(12)  NOT NULL COMMENT 'Short human code shown on-screen for the user to enter at /link (RFC 8628 section 3.1)',
    Status         VARCHAR(20)  NOT NULL DEFAULT 'pending' COMMENT 'pending | approved | denied | expired — app-validated, VARCHAR not ENUM (rule #20)',
    UserId         INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK tblUsers — set once the user approves at /link; NULL while pending',
    Channel        VARCHAR(16)  NULL DEFAULT NULL COMMENT '3-docroot env discriminator (rule #26) — filter in every poll/approve/prune query',
    PollCount      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Poll attempts so far — enforces RFC 8628 section 3.5 slow_down backoff',
    LastPolledAt   DATETIME     NULL DEFAULT NULL,
    ExpiresAt      DATETIME     NOT NULL COMMENT 'Device/user code TTL (short-lived, RFC 8628 section 3.2)',
    CreatedAt      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_DeviceCodeHash (DeviceCodeHash),
    UNIQUE KEY uq_UserCode (UserCode),
    INDEX      idx_Status_Expiry (Status, ExpiresAt),
    INDEX      idx_User (UserId),

    CONSTRAINT fk_AuthDeviceCodes_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='RFC 8628 OAuth Device Authorization Grant codes for tvOS/limited-input pairing (#1407). Dormant until the tvOS device-code client + /link page ship.';


-- ----------------------------------------------------------------------------
-- tblPasswordResetTokens
-- Single-use tokens for the "forgot password" flow.
-- 48-character hex string (24 random bytes), 1-hour default expiry.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblPasswordResetTokens (
    -- CHAR(64) holds the full sha256 hex of the raw token. Pre-#898
    -- this column was VARCHAR(48), which silently truncated the 64-
    -- char hash to 48. Lookups still worked (insert + lookup
    -- truncated identically) but the on-disk hash was effectively
    -- 192 bits instead of 256. The follow-up migration widens
    -- existing installs in place.
    Token           CHAR(64)        NOT NULL PRIMARY KEY,
    UserId          INT UNSIGNED    NOT NULL,
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ExpiresAt       TIMESTAMP       NOT NULL,
    Used            TINYINT(1)      NOT NULL DEFAULT 0,

    INDEX idx_User      (UserId),

    CONSTRAINT fk_ResetTokens_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblEmailLoginTokens
-- Time-limited tokens for passwordless email login (magic link / code).
-- Two modes:
--   1. Magic link: user clicks a URL containing the Token (48-char hex)
--   2. Code entry: user enters a 6-digit numeric Code on the login page
-- Both expire after 10 minutes and are single-use.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblEmailLoginTokens (
    Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    Email           VARCHAR(255)    NOT NULL COMMENT 'Email address the token was sent to',
    UserId          INT UNSIGNED    NULL COMMENT 'FK to tblUsers if email matches existing account',
    -- Stores sha256(raw token) hex (64 chars). The raw 48-char hex
    -- token only ever lives in the outbound email body. Pre-#898
    -- this column held the raw token; the follow-up migration drops
    -- any unused rows and flips the storage discipline.
    Token           VARCHAR(64)     NOT NULL UNIQUE COMMENT 'sha256 hex of raw 48-char hex magic-link token',
    -- Code stays plaintext: 6-digit numeric is ~20 bits of entropy,
    -- below the threshold where hashing provides meaningful defence
    -- against an attacker with the table contents. The defence-in-
    -- depth here is single-use + 10-minute expiry + email-scoped
    -- lookup, all enforced by tblEmailLoginTokens itself.
    Code            VARCHAR(6)      NOT NULL COMMENT '6-digit numeric code for manual entry (plaintext)',
    Used            TINYINT(1)      NOT NULL DEFAULT 0,
    ExpiresAt       TIMESTAMP       NOT NULL,
    IpAddress       VARCHAR(45)     NOT NULL DEFAULT '' COMMENT 'IP that requested the token',
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_Email     (Email),
    INDEX idx_Token     (Token),
    INDEX idx_Code      (Email, Code),
    INDEX idx_Expires   (ExpiresAt),

    CONSTRAINT fk_EmailLogin_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblEmailVerificationTokens (#898)
-- Single-use tokens for confirming a user's email address after password
-- registration. Stores the SHA-256 hash of a 48-char hex token (raw token
-- only ever lives in the email body); 24-hour expiry. On consumption,
-- tblUsers.EmailVerified flips 0 -> 1 and the row is marked Used.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblEmailVerificationTokens (
    TokenHash       CHAR(64)        NOT NULL PRIMARY KEY COMMENT 'sha256 of raw token',
    UserId          INT UNSIGNED    NOT NULL,
    Email           VARCHAR(255)    NOT NULL COMMENT 'Email at the moment the token was issued',
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ExpiresAt       TIMESTAMP       NOT NULL,
    Used            TINYINT(1)      NOT NULL DEFAULT 0,

    INDEX idx_User      (UserId),
    INDEX idx_Expires   (ExpiresAt),

    CONSTRAINT fk_VerifyTokens_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- ACCESS TIERS
-- (Historically "ACCESS TIERS & PURCHASES" — the purchases half was
-- tblUserPurchases, a guessed 2019-vintage monetisation shape that no code
-- ever touched, removed in the 2026-07-30 orphan remediation. See the note by
-- tblUsers. A real purchases feature designs its own one-pass schema.)
-- ============================================================================

-- ----------------------------------------------------------------------------
-- tblAccessTiers
-- Defines available content access tiers. Each tier unlocks specific
-- content types. Higher tiers include all lower tier access.
--
-- This is the storage behind the TIER_CAPS registry in
-- includes/access_tier_validation.php, and the split between the columns
-- below is the thing to understand (rule #28):
--
--   * The seven Can*/Requires* TINYINT columns are FROZEN. The camelCase keys
--     they emit (canViewLyrics, canPlayAudio, …) are the published native-app
--     API contract, so they keep dedicated columns and are never re-homed
--     into JSON.
--   * EVERY new capability goes inside the single `Capabilities` JSON column
--     instead. Adding a gateable feature is ONE line in TIER_CAPS plus running
--     its migration card — never a new column here, and never a hardcoded
--     tier->capability matrix in PHP. A column per feature is precisely the
--     ALTER-per-tweak pattern rule #20 forbids.
--
-- The rows are seeded further down (under DEFAULT DATA) and are editable at
-- /manage/tiers, so the LIVE row is the source of truth at runtime — the
-- matrix that still exists in checkTierAccess() survives only as the fallback
-- for an un-migrated install or an unknown tier name.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblAccessTiers (
    Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    Name            VARCHAR(30)     NOT NULL UNIQUE COMMENT 'public, free, ccli, premium, pro',
    DisplayName     VARCHAR(50)     NOT NULL,
    Level           INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'Higher = more access',
    Description     TEXT            NOT NULL DEFAULT (''),
    CanViewLyrics   TINYINT(1)      NOT NULL DEFAULT 1,
    CanViewCopyrighted TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Access copyrighted songs',
    CanPlayAudio    TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'MIDI/audio playback',
    CanDownloadMidi TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'Download MIDI files',
    CanDownloadPdf  TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'Download sheet music PDFs',
    CanOfflineSave  TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'Save songs for offline use',
    RequiresCcli    TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'Requires valid CCLI licence',
    Capabilities    JSON            NULL COMMENT 'json-backed extensible tier caps (rule 20); future gated capabilities live here as named keys, no per-feature ALTER',
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- ORGANISATIONS & LICENSING
-- ============================================================================

-- ----------------------------------------------------------------------------
-- tblOrganisations
-- Multi-tenancy: churches, worship teams, denominations.
-- Supports nested hierarchy via ParentOrgId (self-referencing FK).
-- Holds licence information for content access control.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblOrganisations (
    Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    Name            VARCHAR(255)    NOT NULL,
    Slug            VARCHAR(100)    NOT NULL UNIQUE COMMENT 'URL-safe identifier',
    ParentOrgId     INT UNSIGNED    NULL DEFAULT NULL COMMENT 'Self-ref FK for nested orgs',
    Description     TEXT            NOT NULL DEFAULT (''),
    /* Physical address — VARCHAR mirror for JOIN-free reads + FK
       into tblPlaces for country grouping / regional filters. */
    PhysicalCity     VARCHAR(255)   NULL DEFAULT NULL,
    PhysicalCityId   INT UNSIGNED   NULL DEFAULT NULL,
    LicenceType     VARCHAR(30)     NOT NULL DEFAULT 'none' COMMENT 'Licence vocabulary token -- registry: tblLicenceTypes (includes/licence_registry.php, #459/#1769 P2). Values: none | ccli | mrl | ihymns_basic | ihymns_pro | custom. Legacy primary-licence column; multi-licence rows live in tblOrganisationLicences',
    LicenceNumber   VARCHAR(100)    NOT NULL DEFAULT '' COMMENT 'CCLI licence number or iHymns key',
    LicenceExpiresAt TIMESTAMP      NULL DEFAULT NULL,
    IsActive        TINYINT(1)      NOT NULL DEFAULT 1,
    LiveIdleTimeoutMins SMALLINT UNSIGNED NULL DEFAULT NULL COMMENT 'Org override for the Quick-session leader-idle timeout (minutes). NULL = no override — the app default applies (#1770 req 5)',
    EnforceIdleTimeout TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = the org LOCKS LiveIdleTimeoutMins for its members (their personal value is ignored). Only meaningful when LiveIdleTimeoutMins is non-NULL (#1770 req 5)',
    SetlistEditAudience VARCHAR(20) NULL DEFAULT NULL COMMENT 'G4-org #1791: this org''s preference for members'' set-list EDIT links — anyone | authenticated | NULL (no org opinion). App-validated VARCHAR vocab.',
    EnforceSetlistEditAudience TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'G4-org #1791: 0 = SetlistEditAudience is an advisory default a member may loosen; 1 = mandatory cap (a member''s edit links can never be more open than SetlistEditAudience). Mirrors EnforceIdleTimeout (#1770).',
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_Parent    (ParentOrgId),
    INDEX idx_Slug      (Slug),
    INDEX idx_Licence   (LicenceType),
    INDEX idx_PhysicalCityId (PhysicalCityId),

    CONSTRAINT fk_Org_Parent
        FOREIGN KEY (ParentOrgId) REFERENCES tblOrganisations(Id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_Organisations_PhysicalCity
        FOREIGN KEY (PhysicalCityId) REFERENCES tblPlaces(Id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblOrganisationMembers
-- Many-to-many: users belong to organisations with a role within each.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblOrganisationMembers (
    UserId          INT UNSIGNED    NOT NULL,
    OrgId           INT UNSIGNED    NOT NULL,
    Role            VARCHAR(20)     NOT NULL DEFAULT 'member' COMMENT 'owner, admin, member',
    JoinedAt        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (UserId, OrgId),

    CONSTRAINT fk_OrgMember_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_OrgMember_Org
        FOREIGN KEY (OrgId) REFERENCES tblOrganisations(Id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblOrganisationLicences
-- Multi-licence-per-org join table (#640). An organisation can hold
-- several licence types in parallel — e.g. CCLI for the lyrics + MRL
-- for the print rights — each with its own number, expiry, and
-- active flag. The original tblOrganisations.LicenceType /
-- LicenceNumber columns remain as the "primary" licence and are
-- mirrored into a row in this table; additional licences live only
-- here. Created via migrate-organisation-licences.php.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblOrganisationLicences (
    Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    OrganisationId  INT UNSIGNED    NOT NULL,
    LicenceType     VARCHAR(30)     NOT NULL COMMENT 'Licence vocabulary token -- registry: tblLicenceTypes (includes/licence_registry.php, #459/#1769 P2). e.g. ccli | mrl | ihymns_basic | ihymns_pro | custom',
    LicenceNumber   VARCHAR(100)    NOT NULL DEFAULT '',
    IsActive        TINYINT(1)      NOT NULL DEFAULT 1,
    ExpiresAt       TIMESTAMP       NULL DEFAULT NULL,
    Notes           TEXT            NULL DEFAULT NULL,
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_OrgLicence (OrganisationId, LicenceType),
    INDEX idx_LicenceType (LicenceType),
    INDEX idx_IsActive    (IsActive),

    CONSTRAINT fk_OrgLicence_Org
        FOREIGN KEY (OrganisationId) REFERENCES tblOrganisations(Id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblContentLicences
-- Licences that grant access to specific songbooks/features.
-- Can be attached to an org OR a user (or both).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblContentLicences (
    Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    OrgId           INT UNSIGNED    NULL DEFAULT NULL,
    UserId          INT UNSIGNED    NULL DEFAULT NULL,
    LicenceType     VARCHAR(30)     NOT NULL COMMENT 'Licence vocabulary token -- registry: tblLicenceTypes (includes/licence_registry.php, #459/#1769 P2). e.g. ihymns_basic | ihymns_pro | ccli | mrl | custom',
    LicenceKey      VARCHAR(100)    NOT NULL DEFAULT '',
    ExpiresAt       TIMESTAMP       NULL DEFAULT NULL,
    IsActive        TINYINT(1)      NOT NULL DEFAULT 1,
    SongbooksAllowed JSON           NULL COMMENT 'JSON array of songbook abbrevs, NULL = all',
    FeaturesAllowed JSON            NULL COMMENT 'JSON array of feature flags, NULL = all',
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_Org   (OrgId),
    INDEX idx_User  (UserId),

    CONSTRAINT fk_Licence_Org
        FOREIGN KEY (OrgId) REFERENCES tblOrganisations(Id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_Licence_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblContentRestrictions
-- Rule-based content lockout system. Combines org, user, platform, licence,
-- songbook, song, and feature restrictions with priority-based evaluation.
-- Higher Priority values override lower ones. Deny beats allow at same priority.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblContentRestrictions (
    Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    EntityType      VARCHAR(20)     NOT NULL COMMENT 'song, songbook, feature',
    EntityId        VARCHAR(50)     NOT NULL COMMENT 'Song ID, songbook abbr, or feature name',
    RestrictionType VARCHAR(30)     NOT NULL COMMENT 'require_licence, require_org, block_platform, block_user, block_org, require_lyrics_pd, require_music_pd (app allow-list; reads the INDEPENDENT PD flags — never AND them) (#1090 audit)',
    AppliesToAction VARCHAR(20)     NOT NULL DEFAULT 'all' COMMENT 'all | display | print | export | translate | reproduce — per-action policy (CCLI separates display from reproduction) (#1090 audit); VARCHAR not ENUM',
    TargetType      VARCHAR(20)     NOT NULL DEFAULT '' COMMENT 'platform, org, user, licence_type',
    TargetId        VARCHAR(50)     NOT NULL DEFAULT '' COMMENT 'PWA/Apple/Android, org ID, user ID, licence type',
    Effect          VARCHAR(5)      NOT NULL DEFAULT 'deny' COMMENT 'allow or deny',
    Priority        INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'Higher = overrides lower',
    Reason          VARCHAR(255)    NOT NULL DEFAULT '' COMMENT 'Human-readable reason for restriction',
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    /* This table has NO foreign keys at all, which is unusual here and
       intentional: both (EntityType, EntityId) and (TargetType, TargetId) are
       POLYMORPHIC pairs. EntityId holds a SongId, a songbook abbreviation or
       a bare feature name depending on EntityType, so there is no single
       table to point at. The cost is that nothing cascades — a restriction
       row survives the song or songbook it names, and evaluation has to
       tolerate rows whose entity no longer exists.

       The index pairs mirror the two ways the evaluator reads: "what applies
       to THIS entity" and "what applies to THIS audience". Never query this
       table directly from a page or endpoint — go through
       includes/content_access.php::checkContentAccess() (rule #8), which also
       applies the Priority / deny-beats-allow resolution the columns imply
       but the schema cannot enforce. */
    INDEX idx_Entity    (EntityType, EntityId),
    INDEX idx_Target    (TargetType, TargetId),
    INDEX idx_Priority  (Priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- REMOVED (remediation X5, 2026-07-30, orphan inventory §2.2/§3.2 #1670):
-- tblUserGroupMembers. Group membership actually lives on tblUsers.GroupId
-- (a single FK, not a many-to-many join) — `admin_group_member_add` UPDATEs
-- that column directly. This table's only reader was the dormant `user_access`
-- action's UNION arm (api.php), which the fix removed; the action itself
-- STAYS (content-gating family, dormant by design per rule #28 — fixing its
-- 500 is not the same as giving it a caller, and it keeps its orphan-guard
-- allowlist entry).
--
-- REMOVED (remediation X4, 2026-07-30, orphan inventory §3.1 B.1):
-- tblUserPermissions — schema.sql-only, no migration, no app code ever read
-- or wrote it (per-user overrides never shipped; role-based gating via
-- tblUsers.Role + the entitlements system is the live mechanism). See the
-- longer note by tblUsers above for the drop mechanism (existing generic
-- "Drop Legacy Tables" card; manual, confirm-gated, left UN-RUN pending
-- owner decision D2).
-- ----------------------------------------------------------------------------


-- ============================================================================
-- USER DATA
-- ============================================================================

-- ----------------------------------------------------------------------------
-- tblUserSetlists
-- Server-side setlist storage linked to user accounts for cross-device sync.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblUserSetlists (
    Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    UserId          INT UNSIGNED    NOT NULL,
    SetlistId       VARCHAR(100)    NOT NULL COMMENT 'Client-generated unique ID',
    Name            VARCHAR(200)    NOT NULL,
    SongsJson       MEDIUMTEXT      NOT NULL DEFAULT ('[]') COMMENT 'JSON array of song objects',
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    ExpiresAt DATETIME NULL DEFAULT NULL COMMENT 'Optional expiry (#1661), UTC; NULL = never expires. DATETIME not TIMESTAMP (rule #20 TTL convention)',
    -- Mirror of appWeb/.sql/migrate-setlist-slots.php — keep byte-identical (rule #19).
    -- ONE envelope column rather than SlotsJson + a TemplateId FK: templateName is a
    -- SNAPSHOT so provenance survives the template being deleted or renamed, and the
    -- sync loop needs no per-row existence check. Growth (durations, flags) goes
    -- inside the JSON, never into a second ALTER (rule #20).
    SlotsJson JSON NULL DEFAULT NULL COMMENT 'Optional service plan (#301/#1671 F4): {templateId, templateName, slots[]}. NULL = no plan',

    UNIQUE KEY uq_UserSetlist (UserId, SetlistId),
    INDEX idx_User (UserId),

    CONSTRAINT fk_Setlists_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblUserSetlistTombstones (#1661)
-- An EXPLICIT, permanent record that a setlist was deleted, so the sync
-- protocol never has to infer deletion from a setlist being absent from a
-- payload — the inference that let a truncated payload read as "the user
-- deleted the tail of their collection" (#1649). A tombstoned id is dead
-- forever; re-creating mints a fresh client id. An expiry that passes is
-- converted into a tombstone with Reason='expired', so expiry and deletion
-- share ONE propagation mechanism.
--
-- Mirror of appWeb/.sql/migrate-setlist-tombstones.php — keep byte-identical.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblUserSetlistTombstones (
    UserId     INT UNSIGNED  NOT NULL,
    SetlistId  VARCHAR(100)  NOT NULL COMMENT 'The client-generated id of the deleted setlist',
    DeletedAt  DATETIME      NOT NULL COMMENT 'DB clock (userSyncNow frame) at deletion',
    Reason     VARCHAR(20)   NOT NULL DEFAULT 'user' COMMENT 'user | expired | admin — VARCHAR not ENUM (rule #20)',

    PRIMARY KEY (UserId, SetlistId),

    CONSTRAINT fk_SetlistTomb_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Explicitly deleted user setlists — anti-resurrection + cross-device prune (#1661).';


-- ----------------------------------------------------------------------------
-- tblSharedSetlists
-- Public, link-shared setlists (anyone with the URL can view). Replaces the
-- legacy file-based store under APP_SETLIST_SHARE_DIR. ShareId stays the
-- 8-char hex (bin2hex(random_bytes(4))) so existing share URLs keep
-- working when historical JSON files are imported by the migration.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSharedSetlists (
    ShareId         VARCHAR(64)     NOT NULL PRIMARY KEY COMMENT '8 hex chars (legacy view links, 32-bit) or 22/43-char base64url capability token (128/256-bit). Widened #1791.',
    Data            JSON            NOT NULL COMMENT 'Full setlist payload as written by the share API',
    Scope           VARCHAR(10)     NOT NULL DEFAULT 'view' COMMENT 'view | edit — app-validated VARCHAR vocab, never ENUM (rule #20). edit implies view.',
    Label           VARCHAR(100)    NULL DEFAULT NULL COMMENT 'Owner-facing name for this link ("worship team"). Display only.',
    RevokedAt       DATETIME        NULL DEFAULT NULL COMMENT 'Hard revocation instant (UTC). Non-NULL = link refuses at every scope.',
    ExpiresAt       DATETIME        NULL DEFAULT NULL COMMENT 'Optional expiry (UTC), NULL = never. DATETIME not TIMESTAMP (rule #20 TTL convention).',
    LastUsedAt      DATETIME        NULL DEFAULT NULL COMMENT 'Last successful token READ or WRITE (edit links) — owner-facing "in use?" signal.',
    EditCount       INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'Successful token WRITES through this link (mirrors ViewCount).',
    ShowSharerName  TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'G3 #1791: 1 = shared page shows "Shared by <owner display name>"; 0 (default) = anonymous, current posture. Owner-set per link at mint.',
    EditAudience    VARCHAR(20)     NOT NULL DEFAULT 'anyone' COMMENT 'G4 #1791: who may edit via an edit-scope link — anyone | authenticated. Owner-set per link; DEFAULT anyone (no account needed). App-validated VARCHAR vocab, never ENUM (rule #20). Ignored for view-scope links.',
    OwnerUserId     INT UNSIGNED    NULL DEFAULT NULL COMMENT 'Live-share link: FK to tblUsers.Id of the authenticated owner. NULL = legacy/anonymous snapshot-only share (#1380).',
    SourceSetlistId VARCHAR(100)    NULL DEFAULT NULL COMMENT 'Live-share link: the owner''s tblUserSetlists.SetlistId. (OwnerUserId, SourceSetlistId) resolves the CURRENT setlist at read time. NULL = snapshot-only (#1380).',
    CreatedBy       INT UNSIGNED    NULL DEFAULT NULL COMMENT 'FK to tblUsers (NULL for guest creates)',
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    ViewCount       INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'Incremented on retrieval for share-link analytics',

    /* Both user FKs are ON DELETE SET NULL rather than CASCADE, and that is
       the whole design: a share URL handed to a congregation must not 404
       because the person who created it later deleted their account. Nulling
       OwnerUserId degrades a LIVE share (which re-resolves the owner's
       current setlist through idx_LiveSource) into the frozen `Data`
       snapshot it already carries — the link keeps working, it just stops
       tracking. SourceSetlistId is a client-generated string with no FK of
       its own, so the pair is resolved by lookup, not by constraint. */
    INDEX idx_CreatedBy (CreatedBy),
    INDEX idx_CreatedAt (CreatedAt),
    KEY idx_LiveSource (OwnerUserId, SourceSetlistId),
    KEY idx_Expiry (ExpiresAt),

    CONSTRAINT fk_SharedSetlists_User FOREIGN KEY (CreatedBy) REFERENCES tblUsers(Id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_SharedSetlists_OwnerUser FOREIGN KEY (OwnerUserId) REFERENCES tblUsers(Id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblUserFavorites
-- Server-side favorites sync (song IDs per user).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblUserFavorites (
    UserId          INT UNSIGNED    NOT NULL,
    SongId          VARCHAR(20)     NOT NULL,
    Tags            JSON            NULL COMMENT 'Per-favourite user tags (#122) — JSON array of strings; NULL = untagged (WS-G #1019)',
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (UserId, SongId),

    CONSTRAINT fk_Favorites_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_Favorites_Song
        FOREIGN KEY (SongId) REFERENCES tblSongs(SongId)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblUserCustomTags (WS-G #1019)
-- Per-user pool of custom favourite-tag names. The DB-first counterpart of
-- the localStorage `ihymns_custom_tags` string array. Distinct from the
-- curator-managed global tblSongTags / tblSongTagMap — these are private,
-- per-account labels the user invents for organising their own favourites.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblUserCustomTags (
    UserId          INT UNSIGNED    NOT NULL,
    Tag             VARCHAR(50)     NOT NULL,
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (UserId, Tag),
    INDEX idx_User (UserId),

    CONSTRAINT fk_CustomTags_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- LANGUAGE & TRANSLATION SUPPORT
-- ============================================================================

-- ----------------------------------------------------------------------------
-- tblLanguages
-- Reference table for supported languages. Uses ISO 639-1 codes.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblLanguages (
    Code            VARCHAR(35)     NOT NULL PRIMARY KEY COMMENT 'IANA language subtag (ISO 639-1/2/3 + extensions; widened from 10 → 35 in #738)',
    Name            VARCHAR(250)    NOT NULL COMMENT 'English name (CLDR-polished form preferred over raw IANA Description)',
    NativeName      VARCHAR(250)    NOT NULL DEFAULT '' COMMENT 'Native name (e.g. Français)',
    TextDirection   VARCHAR(3)      NOT NULL DEFAULT 'ltr' COMMENT 'ltr or rtl',
    Scope           ENUM('individual','macrolanguage','collection','private-use','special') NOT NULL DEFAULT 'individual' COMMENT 'IANA Scope; macrolanguages (zh, ar, fa) outrank narrower variants in the picker (#738)',
    IsActive        TINYINT(1)      NOT NULL DEFAULT 1,
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblLanguageScripts (#681 / renamed in #738 from tblScripts)
-- Reference table for ISO 15924 four-letter script codes (e.g. Latn, Cyrl,
-- Hans, Hant, Arab). Used as the optional second subtag in an IETF BCP 47
-- language tag (e.g. zh-Hans, sr-Latn). Curators pick from this list via the
-- songbook + song editors' composite IETF language picker. Renamed for
-- clarity — "Scripts" alone reads as mini-programs/processors.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblLanguageScripts (
    Code            VARCHAR(4)      NOT NULL PRIMARY KEY COMMENT 'ISO 15924 four-letter code (Title Case: Latn, Cyrl, Hans, …)',
    Name            VARCHAR(150)    NOT NULL COMMENT 'English name (CLDR-polished where available)',
    NativeName      VARCHAR(150)    NOT NULL DEFAULT '' COMMENT 'Native or contextual name where useful (e.g. 简体)',
    IsActive        TINYINT(1)      NOT NULL DEFAULT 1,
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblRegions (#681)
-- Reference table for ISO 3166-1 alpha-2 region codes (e.g. GB, US, BR, PT).
-- Used as the optional third subtag in an IETF BCP 47 language tag (e.g.
-- pt-BR, en-GB). VARCHAR(3) leaves room for the M.49 numeric area codes
-- (e.g. 419 for Latin America) that BCP 47 also accepts.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblRegions (
    Code            VARCHAR(3)      NOT NULL PRIMARY KEY COMMENT 'ISO 3166-1 alpha-2 (uppercase) or M.49 numeric area code',
    Name            VARCHAR(150)    NOT NULL COMMENT 'English name (CLDR-polished where available)',
    IsActive        TINYINT(1)      NOT NULL DEFAULT 1,
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblLanguageVariants (#738)
-- Reference table for IANA variant subtags (5-8 chars, e.g. 1996 for German
-- post-1996 orthography, fonipa for IPA phonetics, valencia for Valencian).
-- Used as the optional fourth subtag in an IETF BCP 47 language tag.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblLanguageVariants (
    Code            VARCHAR(8)      NOT NULL PRIMARY KEY COMMENT 'IANA variant subtag (5-8 chars)',
    Name            VARCHAR(250)    NOT NULL COMMENT 'English name (CLDR-polished where available; raw IANA Description otherwise)',
    IsActive        TINYINT(1)      NOT NULL DEFAULT 1,
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblSongTranslations
-- Links a song to its translation in another language.
-- Each translation is itself a song record in tblSongs.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongTranslations (
    Id                  INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    SourceSongId        VARCHAR(20)     NOT NULL COMMENT 'Original song ID',
    TranslatedSongId    VARCHAR(20)     NOT NULL COMMENT 'Translated song ID',
    TargetLanguage      VARCHAR(35)     NOT NULL COMMENT 'IETF BCP 47 tag of translation; widened from VARCHAR(10) to align with tblSongs.Language (#681)',
    Translator          VARCHAR(255)    NOT NULL DEFAULT '' COMMENT 'Translator name(s)',
    Verified            TINYINT(1)      NOT NULL DEFAULT 0,
    CreatedAt           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_Translation (SourceSongId, TargetLanguage),
    INDEX idx_Source     (SourceSongId),
    INDEX idx_Target     (TranslatedSongId),

    CONSTRAINT fk_Trans_Source
        FOREIGN KEY (SourceSongId) REFERENCES tblSongs(SongId)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_Trans_Target
        FOREIGN KEY (TranslatedSongId) REFERENCES tblSongs(SongId)
        ON DELETE CASCADE ON UPDATE CASCADE,
    /* uq_Translation is (SourceSongId, TargetLanguage): ONE translation per
       language per source song. A second Spanish rendering of the same hymn
       is not an additional row here — it is a separate tblSongs record, and
       which one is "the" Spanish counterpart is a curatorial choice.

       fk_Trans_Lang is the ONLY hard foreign key to tblLanguages in this
       file, and it is the outlier: every other language column in the schema
       (tblSongs.Language, tblLyricLines.LanguageCode, tblSongLanguages
       .Language, the whole #1088 pair) is deliberately free-text with NO FK,
       precisely so a BCP 47 tag carrying a script or region subtag can never
       RESTRICT-fail an import (rule #21). tblLanguages is seeded from the
       IANA registry's `Type: language` records only — bare primary subtags —
       so a translation tagged zh-Hans or pt-BR cannot be recorded through
       this constraint even though the column is VARCHAR(35) and shaped for
       exactly those tags. Do not "fix" that by widening the seed — it would
       turn tblLanguages from a subtag registry into a tag table. Dropping
       this one constraint to match the rest of the schema is the change that
       would need discussing. */
    CONSTRAINT fk_Trans_Lang
        FOREIGN KEY (TargetLanguage) REFERENCES tblLanguages(Code)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- SONG REQUESTS & COMMUNITY FEATURES
-- ============================================================================

-- ----------------------------------------------------------------------------
-- tblSongRequests
-- User-submitted suggestions for missing songs. Available to all users.
-- Status tracks the lifecycle: pending → reviewed → added/declined.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongRequests (
    Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    Title           VARCHAR(500)    NOT NULL COMMENT 'Requested song title',
    Songbook        VARCHAR(100)    NOT NULL DEFAULT '' COMMENT 'Songbook name or abbreviation (if known)',
    SongNumber      VARCHAR(20)     NOT NULL DEFAULT '' COMMENT 'Song number (if known)',
    Language        VARCHAR(35)     NOT NULL DEFAULT 'en' COMMENT 'IETF BCP 47 tag of requested song; widened from VARCHAR(10) for consistency (#681)',
    Details         TEXT            NOT NULL DEFAULT ('') COMMENT 'Additional info (first line of lyrics, etc.)',
    ContactEmail    VARCHAR(255)    NOT NULL DEFAULT '' COMMENT 'Optional email for follow-up',
    UserId          INT UNSIGNED    NULL DEFAULT NULL COMMENT 'FK to tblUsers (NULL for anonymous)',
    IpAddress       VARCHAR(45)     NOT NULL DEFAULT '' COMMENT 'Submitter IP for rate limiting',
    Status          VARCHAR(20)     NOT NULL DEFAULT 'pending' COMMENT 'pending, reviewed, added, declined',
    AdminNotes      TEXT            NOT NULL DEFAULT ('') COMMENT 'Internal notes from reviewers',
    ResolvedSongId  VARCHAR(20)     NULL DEFAULT NULL COMMENT 'Song ID if request was fulfilled',
    RequestType     VARCHAR(20)     NOT NULL DEFAULT 'missing_song' COMMENT 'missing_song | correction (app-validated). Corrections (#1090 N2) carry SongId + FieldName + Original/Proposed; missing-song rows leave them empty',
    SongId          VARCHAR(20)     NULL DEFAULT NULL COMMENT 'For RequestType=correction: the existing song being corrected (FK to tblSongs.SongId)',
    FieldName       VARCHAR(50)     NOT NULL DEFAULT '' COMMENT 'For corrections: which field (title|lyrics|author|copyright|tune|…); server-side allow-list validated, never free interpolation',
    OriginalValue   TEXT            NULL DEFAULT NULL COMMENT 'For corrections: the current value (server-prefilled from the live record) — the before side of the diff',
    ProposedValue   TEXT            NULL DEFAULT NULL COMMENT 'For corrections: the submitter''s proposed value — the after side of the diff',
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_Status      (Status),
    INDEX idx_User        (UserId),
    INDEX idx_Created     (CreatedAt),
    INDEX idx_RequestType (RequestType),
    INDEX idx_SongId      (SongId),

    CONSTRAINT fk_Requests_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    /* #1064 — ResolvedSongId is FK-enforced; nulled when its song is removed. */
    CONSTRAINT fk_Requests_ResolvedSong
        FOREIGN KEY (ResolvedSongId) REFERENCES tblSongs(SongId)
        ON DELETE SET NULL ON UPDATE CASCADE,
    /* #1090 N2 — the corrected song; nulled when its song is removed. */
    CONSTRAINT fk_Requests_TargetSong
        FOREIGN KEY (SongId) REFERENCES tblSongs(SongId)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- AUDIT & ANALYTICS
-- ============================================================================

-- ----------------------------------------------------------------------------
-- tblActivityLog
-- Comprehensive activity audit trail (#535).
--
-- Every meaningful action — auth, admin CRUD, user activity, API
-- request, system event — writes one row here. Used for:
--   - Analytics (most-used features, peak hours, songbook popularity)
--   - Debugging (replay a user's request sequence to reproduce a bug)
--   - Support (look up exactly what the user did)
--   - Edit history (who changed what on songs, songbooks, users, orgs)
--   - Forensics (suspicious patterns, post-incident timelines)
--
-- See includes/activity_log.php for the canonical write API.
-- See manage/activity-log.php for the admin viewer + filters.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblActivityLog (
    Id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    UserId          INT UNSIGNED    NULL COMMENT 'User who performed the action (NULL for system / unauthenticated)',
    Action          VARCHAR(50)     NOT NULL COMMENT 'Dotted lowercase verb, e.g. song.edit, auth.login, setlist.share',
    EntityType      VARCHAR(50)     NOT NULL DEFAULT '' COMMENT 'e.g. song, user, songbook, setlist, organisation',
    EntityId        VARCHAR(50)     NOT NULL DEFAULT '' COMMENT 'Primary key of the affected entity (string for cross-table use)',
    Result          ENUM('success','failure','error') NOT NULL DEFAULT 'success'
                    COMMENT 'success = OK; failure = user-side reject; error = server-side exception (#535)',
    Details         JSON            NULL COMMENT 'Additional context (before/after diff, error message, request body)',
    IpAddress       VARCHAR(45)     NOT NULL DEFAULT '' COMMENT 'Real client IP after proxy resolution (CF-Connecting-IP / X-Forwarded-For / REMOTE_ADDR), IPv6-capable',
    IpProxyChain    VARCHAR(255)    NULL COMMENT 'Comma-separated proxy chain from X-Forwarded-For; last hop = proxy that delivered to PHP',
    ProxyVpnIndicator VARCHAR(50)   NULL COMMENT 'Heuristic/external classification: none | cloudflare | xff | datacentre | vpn | tor | proxy',
    ProxyVpnDetail  JSON            NULL COMMENT 'Provider / score / source headers — populated by heuristic resolver + (future) external lookup',
    UserAgent       VARCHAR(500)    NOT NULL DEFAULT '' COMMENT 'Truncated UA — useful for "mobile vs desktop" debugging (#535)',
    RequestId       CHAR(16)        NOT NULL DEFAULT '' COMMENT 'Per-HTTP-request correlation ID; groups every row from one request (#535)',
    Method          VARCHAR(10)     NOT NULL DEFAULT '' COMMENT 'HTTP method (GET/POST/etc) for HTTP-driven events; blank for cron/system (#535)',
    DurationMs      INT UNSIGNED    NULL COMMENT 'Wall-clock duration of the logged operation in milliseconds (#535)',
    Environment     VARCHAR(16)     NULL DEFAULT NULL COMMENT 'Deploy environment at log time: alpha | beta | production (the DB is shared across all three) (#1207)',
    RequestPath     VARCHAR(512)    NULL DEFAULT NULL COMMENT 'Requested path (REQUEST_URI minus query) — which file/route was hit (#1207)',
    Referrer        VARCHAR(2048)   NULL DEFAULT NULL COMMENT 'HTTP Referer header — where the request came from (#1207)',
    Country         CHAR(2)         NULL DEFAULT NULL COMMENT 'ISO-3166-1 alpha-2 country resolved from IpAddress AT log time (snapshot; geo resolver #1208 populates it)',
    CreatedAt       TIMESTAMP(6)    NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT 'Microsecond precision (#1287) — logActivity writes NOW(6); the Id PK is the tiebreaker for same-instant rows (#1285)',

    /* (EntityType, EntityId) is polymorphic and un-FK'd on purpose — and here
       that is a FEATURE, not the compromise it is on tblContentRestrictions.
       An audit trail that CASCADE-deleted with the thing it audits would
       erase exactly the evidence you need after a deletion. Contrast
       tblSongRevisions, which DOES cascade with its song: revisions are the
       song's own history and go with it (part of why deleting a song had to
       become soft, #1694), whereas this table is the record of who did it.
       The only FK here is UserId, and it is SET NULL so an erased account
       leaves the actions behind, unattributed.

       RequestPath is indexed on a 191-character PREFIX (idx_RequestPath).
       (An earlier draft said "RequestPath and Query" — tblActivityLog has NO
       Query column; idx_Query(191) belongs to tblSearchQueries, ~700 lines
       below. The reasoning that follows is right, the column list was not.)
       191 is not a guess at a useful length: an InnoDB index column is capped at 3072
       bytes and utf8mb4 costs up to 4 bytes per character, so 191*4 = 764
       fits the old 767-byte limit that legacy row formats still impose.
       Anything wider simply refuses to build on some installs. */
    INDEX idx_User              (UserId),
    INDEX idx_Action            (Action),
    INDEX idx_Entity            (EntityType, EntityId),
    INDEX idx_Created           (CreatedAt),
    INDEX idx_Result            (Result),
    INDEX idx_RequestId         (RequestId),
    INDEX idx_ProxyVpnIndicator (ProxyVpnIndicator),
    INDEX idx_Environment       (Environment),
    INDEX idx_RequestPath       (RequestPath(191)),
    INDEX idx_Country           (Country),

    CONSTRAINT fk_Log_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- tblIpReputation
--
-- Cache table for proxy/VPN classification of client IPs. Keyed by
-- IpAddress; one row per unique client. The activity-log resolver
-- reads through this table on every request, only paying the
-- external-lookup latency on the first request from a new IP within
-- the configured TTL window.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblIpReputation (
    IpAddress      VARCHAR(45)  NOT NULL PRIMARY KEY,
    Indicator      VARCHAR(50)  NULL
                   COMMENT 'cloudflare | xff | datacentre | vpn | tor | proxy | none',
    Provider       VARCHAR(100) NULL
                   COMMENT 'Provider name from external lookup (e.g. NordVPN, AWS, Cloudflare WARP)',
    Score          SMALLINT     NULL
                   COMMENT 'Confidence score 0-100 if the lookup source provides one',
    Detail         JSON         NULL
                   COMMENT 'Raw lookup response, kept for forensic + audit',
    Source         VARCHAR(50)  NOT NULL DEFAULT ''
                   COMMENT 'header | ipqs | maxmind | ipinfo | manual',
    LookedUpAt     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ExpiresAt      TIMESTAMP    NULL,
    CountryCode    CHAR(2)      NULL DEFAULT NULL COMMENT 'ISO-3166-1 alpha-2 from the geo lookup (#1208)',
    CountryName    VARCHAR(100) NULL DEFAULT NULL COMMENT 'Country display name from the geo lookup (#1208)',
    GeoLookedUpAt  DATETIME     NULL DEFAULT NULL COMMENT 'When geo was last resolved for this IP — drives the cache TTL (#1208)',

    INDEX idx_Indicator (Indicator),
    INDEX idx_Expires   (ExpiresAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblAppSettings
-- Key-value configuration store for runtime settings.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblAppSettings (
    SettingKey      VARCHAR(100)    NOT NULL PRIMARY KEY,
    SettingValue    TEXT            NOT NULL DEFAULT (''),
    Description     VARCHAR(255)    NOT NULL DEFAULT '',
    UpdatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- REMOVED (remediation X4, 2026-07-30, orphan inventory §3.1 B.1):
-- tblMigrations. Schema.sql-only, no migrate-*.php ever created it, no app
-- code ever read or wrote it — this codebase tracks applied migrations via
-- manage/includes/migration-registry.php's derived probes (each checking the
-- live schema/data directly) plus sentinel rows in tblAppSettings, not a
-- migrations-applied ledger table. See the longer note by tblUsers above for
-- the drop mechanism.
-- ----------------------------------------------------------------------------


-- ============================================================================
-- FEATURE TABLES (#298–#313) — song keys, scheduling, templates,
-- collaboration, revision history, push subscriptions.
--
-- A batch of small per-feature tables added together. Two of the original
-- members are gone and their headstones are left in place below rather than
-- deleted, because a reader who finds them referenced in an old commit,
-- migration or issue needs to know where they went: tblSongChords (#299,
-- dropped #1613 — chords are now per-line on tblLyricLines.ChordsJson) and
-- tblUserPreferences (#310, dropped #1671 — it was an un-namespaced duplicate
-- of tblUsers.Settings). Both drops are separate, self-guarded,
-- confirm-gated migrations; neither runs automatically.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- tblSongKeys (#298)
-- Musical key and tempo per song.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongKeys (
    Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    SongId          VARCHAR(20)     NOT NULL UNIQUE,
    OriginalKey     VARCHAR(5)      NOT NULL DEFAULT '' COMMENT 'e.g., C, G, Bb, F#m',
    Tempo           INT UNSIGNED    NULL COMMENT 'BPM',
    TimeSignature   VARCHAR(10)     NOT NULL DEFAULT '4/4',
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_SongKeys_Song
        FOREIGN KEY (SongId) REFERENCES tblSongs(SongId)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblSongChords (#299) — DROPPED (#1613). Zero PHP/JS references; chord notation now
-- lives per-line on tblLyricLines.ChordsJson (rule #21/#25 of .claude/CLAUDE.md). See
-- appWeb/.sql/migrate-drop-song-chords.php for the (self-guarded, confirm-gated) drop.
-- ----------------------------------------------------------------------------


-- ----------------------------------------------------------------------------
-- tblSetlistSchedule (#300)
-- Calendar scheduling for setlists.
--
-- NOTE ON `SetlistId` — here, on tblSetlistCollaborators, on tblSongUsageEvents
-- and on tblLiveFollowSessions it is a VARCHAR(100) SOFT link with no foreign
-- key, matching tblUserSetlists.SetlistId. That column is a CLIENT-generated
-- id (the app mints setlists offline and syncs later), so it is unique only
-- per user — (UserId, SetlistId) is the real key, which is why tblUserSetlists
-- puts its UNIQUE there. A single-column FK could not express that, so nothing
-- cascades: a deleted setlist leaves its schedule rows behind, and deletion is
-- propagated explicitly through tblUserSetlistTombstones instead (#1661).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSetlistSchedule (
    Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    SetlistId       VARCHAR(100)    NOT NULL,
    UserId          INT UNSIGNED    NOT NULL,
    OrgId           INT UNSIGNED    NULL COMMENT 'Organisation this schedule belongs to',
    ScheduledDate   DATE            NOT NULL,
    Notes           TEXT            NOT NULL DEFAULT (''),
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_Date      (ScheduledDate),
    INDEX idx_User      (UserId),
    INDEX idx_Org       (OrgId),

    CONSTRAINT fk_Schedule_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_Schedule_Org
        FOREIGN KEY (OrgId) REFERENCES tblOrganisations(Id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblSetlistTemplates (#301)
-- Service order templates.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSetlistTemplates (
    Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    Name            VARCHAR(200)    NOT NULL,
    Description     TEXT            NOT NULL DEFAULT (''),
    SlotsJson       JSON            NOT NULL COMMENT 'Array of {label, type} slot definitions',
    CreatedBy       INT UNSIGNED    NULL,
    OrgId           INT UNSIGNED    NULL,
    IsPublic        TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'Visible to all users',
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_Org       (OrgId),

    CONSTRAINT fk_Template_User
        FOREIGN KEY (CreatedBy) REFERENCES tblUsers(Id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_Template_Org
        FOREIGN KEY (OrgId) REFERENCES tblOrganisations(Id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblSetlistCollaborators (#312)
-- Collaborative editing permissions for shared setlists.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSetlistCollaborators (
    Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    SetlistOwnerId  INT UNSIGNED    NOT NULL COMMENT 'FK to tblUsers — the setlist owner',
    SetlistId       VARCHAR(100)    NOT NULL COMMENT 'Matches tblUserSetlists.SetlistId',
    CollaboratorId  INT UNSIGNED    NOT NULL COMMENT 'FK to tblUsers — the collaborator',
    Permission      VARCHAR(10)     NOT NULL DEFAULT 'edit' COMMENT 'view, edit',
    InvitedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_Collab (SetlistOwnerId, SetlistId, CollaboratorId),

    CONSTRAINT fk_Collab_Owner
        FOREIGN KEY (SetlistOwnerId) REFERENCES tblUsers(Id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_Collab_User
        FOREIGN KEY (CollaboratorId) REFERENCES tblUsers(Id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblSongRevisions (#313)
-- Edit history for songs.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongRevisions (
    Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    SongId          VARCHAR(20)     NOT NULL,
    UserId          INT UNSIGNED    NULL,
    Action          VARCHAR(20)     NOT NULL COMMENT 'create, edit, delete',
    PreviousData    JSON            NULL COMMENT 'Song state before change',
    NewData         JSON            NULL COMMENT 'Song state after change',
    Status          VARCHAR(20)     NOT NULL DEFAULT 'approved' COMMENT 'pending, approved, rejected',
    ReviewedBy      INT UNSIGNED    NULL,
    ReviewNote      TEXT            NOT NULL DEFAULT (''),
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_Song      (SongId),
    INDEX idx_User      (UserId),
    INDEX idx_Status    (Status),

    CONSTRAINT fk_Revision_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_Revision_Reviewer
        FOREIGN KEY (ReviewedBy) REFERENCES tblUsers(Id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    /* #1064 — revisions belong to their song; cascade-deleted with it. */
    CONSTRAINT fk_Revisions_Song
        FOREIGN KEY (SongId) REFERENCES tblSongs(SongId)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblUserPreferences (#310) — DROPPED (#1671 F5). It was an un-namespaced duplicate of
-- tblUsers.Settings: same concept, same payload shape, a parallel table, and no caller in
-- the first-party tree. Server-side preference sync now lives ONLY on tblUsers.Settings
-- (see the `Settings` column above and appWeb/public_html/includes/user_settings.php),
-- whose write contract gained a namespace so a second product can share the row. The
-- (self-guarded, confirm-gated) drop is appWeb/.sql/migrate-drop-user-preferences.php.
-- ----------------------------------------------------------------------------


-- ----------------------------------------------------------------------------
-- tblPushSubscriptions (#311)
-- Web Push API subscriptions.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblPushSubscriptions (
    Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    UserId          INT UNSIGNED    NOT NULL,
    Endpoint        TEXT            NOT NULL,
    P256dhKey       TEXT            NOT NULL,
    AuthKey         TEXT            NOT NULL,
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_User      (UserId),

    CONSTRAINT fk_Push_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- DEFAULT DATA — Seed user groups, languages, and app settings
-- ============================================================================

INSERT IGNORE INTO tblUserGroups (Name, Description, AccessAlpha, AccessBeta, AccessRc, AccessRtw) VALUES
    ('Developers',    'Full access to all release channels including Alpha builds',     1, 1, 1, 1),
    ('Beta Testers',  'Access to Beta, Release Candidate, and production builds',       0, 1, 1, 1),
    ('RC Testers',    'Access to Release Candidate and production builds only',         0, 0, 1, 1),
    ('Public',        'Access to production (RTW) builds only',                         0, 0, 0, 1);

INSERT IGNORE INTO tblLanguages (Code, Name, NativeName, TextDirection) VALUES
    ('en', 'English',    'English',    'ltr'),
    ('fr', 'French',     'Français',   'ltr'),
    ('es', 'Spanish',    'Español',    'ltr'),
    ('de', 'German',     'Deutsch',    'ltr'),
    ('pt', 'Portuguese', 'Português',  'ltr'),
    ('it', 'Italian',    'Italiano',   'ltr'),
    ('nl', 'Dutch',      'Nederlands', 'ltr'),
    ('sw', 'Swahili',    'Kiswahili',  'ltr'),
    ('ko', 'Korean',     '한국어',      'ltr'),
    ('zh', 'Chinese',    '中文',        'ltr'),
    ('ja', 'Japanese',   '日本語',      'ltr'),
    ('ar', 'Arabic',     'العربية',     'rtl'),
    ('he', 'Hebrew',     'עברית',       'rtl'),
    ('la', 'Latin',      'Latina',     'ltr');

INSERT IGNORE INTO tblAppSettings (SettingKey, SettingValue, Description) VALUES
    ('maintenance_mode',    '0',    'Enable maintenance mode (0=off, 1=on)'),
    ('maintenance_message', '',     'Custom message on the maintenance landing page (empty = default text)'),
    ('song_requests_enabled', '1',  'Allow users to submit song requests (0=off, 1=on)'),
    ('max_song_requests_per_day', '5', 'Maximum song requests per IP per day'),
    ('registration_mode', 'open', 'User registration mode: open, invite, admin_only'),
    ('motd', '', 'Message of the day shown on home page (empty = disabled)'),
    ('email_service', 'none', 'Email service: none, sendmail, ms365, google_workspace, signula'),
    ('email_from', '', 'Sender email address for system emails'),
    ('captcha_provider', 'none', 'RESERVED — not wired yet (#1685). No captcha code exists in this codebase; changing this alters no behaviour. Intended providers once built: recaptcha_v2/v3, turnstile, hcaptcha, friendly, altcha, mtcaptcha.'),
    ('captcha_site_key', '', 'RESERVED — not wired yet (#1685), see captcha_provider. Intended CAPTCHA provider public site key once built'),
    ('captcha_secret_key', '', 'RESERVED — not wired yet (#1685), see captcha_provider. Intended CAPTCHA provider server-side secret key once built'),
    ('ads_enabled', '0', 'RESERVED — not wired yet (#1685). No ad code exists anywhere in this codebase today; setting this to 1 changes no behaviour. Intended toggle for advertisement display once built (0=off, 1=on)'),
    ('ads_provider', 'none', 'RESERVED — not wired yet (#1685), see ads_enabled. Intended ad provider once built: none, adsense, ezoic, mediavine, custom'),
    ('ads_publisher_id', '', 'RESERVED — not wired yet (#1685), see ads_enabled. Intended ad provider publisher/client ID once built'),
    ('content_gating_enabled', '0', 'Enable content tier gating (0=off, 1=on — all content open when off)'),
    -- NOTE (#1668): `ccli_validation_enabled` used to be seeded here. It was NEVER
    -- read by any code, yet its description called it the CCLI enforcement switch —
    -- so an operator setting it to 1 would believe copyright enforcement was on when
    -- nothing whatsoever had changed. A dead flag that lies about what it does is
    -- worse than no flag. The real controls are `content_gating_enabled` = 1 PLUS
    -- `require_licence:ccli` rows in tblContentRestrictions (rule #28), and the
    -- licence itself is now resolved by userHasValidCcli() in includes/licences.php.
    -- Existing installs have the row deleted by migrate-remove-ccli-validation-setting.php.
    -- Do NOT re-add it.
    ('audio_signing_enabled', '0', 'Sign /audio MP3 URLs so gated audio streams via the gated route (#1358); 0=off (serve static /data/audio literal), 1=on (mint signed /audio URLs). Requires content_gating_enabled=1 AND the AUDIO_SIGNING_KEY constant.'),
    -- NOTE: Description is VARCHAR(255) and MySQL runs STRICT, so an over-long
    -- description here is not a truncation — it aborts the whole seed INSERT on
    -- a fresh install. This row was 269 characters until #1685; the guard that
    -- now measures it is tests/php/test-seed-column-widths.php.
    ('apple_team_id', '', 'Apple Developer Team ID for the app.ihymns bundle (#1401). Embedded into /.well-known/apple-app-site-association for Universal Links; empty = AASA serves a placeholder TEAMID appID. Set on manage/configuration.php — never hard-code it in source.'),
    -- Sentinel, not a setting: nothing reads this value. It exists so the
    -- setup-database card for migrate-entitlement-truthup.php can tell whether
    -- the one-off prune has run. Seeded here because a FRESH install has no
    -- saved entitlement overrides to prune — the migration would be a no-op, so
    -- the card should show applied from the start rather than sitting pending
    -- forever on a database that was never affected.
    ('entitlement_truthup_applied', '1', 'Sentinel (#1590): the entitlement truth-up cleared the stale saved overrides for delete_songs / bulk_edit_songs / run_db_backup. Nothing reads this value; it exists so the setup-database card can tell that the one-off prune has run.'),
    -- Same reasoning as the row above: a FRESH install has never saved
    -- /manage/entitlements, so there is no stored delete_songs override to
    -- shadow the shipped editor+ default and nothing for the #1695 prune to do.
    -- Seeding the sentinel means the card shows applied from the start instead
    -- of sitting pending forever on a database that was never affected.
    ('delete_songs_rewiden_applied', '1', 'Sentinel (#1695): the stage-3 re-widen considered the saved delete_songs override. Nothing reads this value; it exists so the setup-database card can tell the one-off prune has run.');


-- Default access tiers (#346)
INSERT IGNORE INTO tblAccessTiers (Name, DisplayName, Level, Description, CanViewLyrics, CanViewCopyrighted, CanPlayAudio, CanDownloadMidi, CanDownloadPdf, CanOfflineSave, RequiresCcli) VALUES
    ('public',  'Public',         0, 'Public domain songs only. No login required.',                    1, 0, 0, 0, 0, 0, 0),
    ('free',    'Free',          10, 'All song lyrics viewable. Login required.',                       1, 1, 0, 0, 0, 0, 0),
    ('ccli',    'CCLI Licensed', 20, 'Full lyrics access with valid CCLI licence.',                     1, 1, 1, 0, 0, 0, 1),
    ('premium', 'Premium',       30, 'Audio playback, MIDI and PDF downloads.',                         1, 1, 1, 1, 1, 1, 0),
    ('pro',     'Professional',  40, 'All features including API access and bulk export.',              1, 1, 1, 1, 1, 1, 0);


-- The canonical generic "Miscellaneous" songbook. EVERY song must belong to a
-- songbook (tblSongs.SongbookAbbr is NOT NULL with an FK to tblSongbooks), and
-- the editor defaults a song saved with no songbook to 'Misc' — so it MUST
-- exist even on a bare install, not just after a data import. Language is
-- intentionally left NULL: Misc is an unstructured, multi-language catch-all
-- collection with no single language. (Number is likewise nullable for Misc.)
INSERT IGNORE INTO tblSongbooks (Abbreviation, Name, SongCount) VALUES
    ('Misc', 'Miscellaneous', 0);


-- ============================================================================
-- ENGAGEMENT & ANALYTICS TABLES
-- ============================================================================

-- ----------------------------------------------------------------------------
-- tblSongHistory
-- Tracks recently viewed songs per user for "Recently Viewed" and
-- "Most Popular" features. Lightweight — only stores song ID + timestamp.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongHistory (
    Id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    UserId          INT UNSIGNED    NULL COMMENT 'NULL for anonymous views',
    SongId          VARCHAR(20)     NOT NULL,
    ViewedAt        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_User      (UserId),
    INDEX idx_Song      (SongId),
    INDEX idx_ViewedAt  (ViewedAt),

    CONSTRAINT fk_History_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_History_Song
        FOREIGN KEY (SongId) REFERENCES tblSongs(SongId)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblSongTags
-- Curator-managed tags/categories for songs (e.g., "Easter", "Communion",
-- "Wedding", "Funeral"). Shared across all users — NOT the same thing as
-- tblUserCustomTags, which is a private per-account pool. Songs can have
-- multiple tags and tags can apply to multiple songs, via tblSongTagMap.
--
-- Since #1152 this table also holds the STANDARD theme vocabulary — the
-- CCLI / SongSelect taxonomy shipped with OpenLyrics — seeded by
-- migrate-seed-theme-vocabulary.php, which is what ParentId, CcliThemeId and
-- Source are for. Two consequences worth knowing before writing to it:
--
--   * ParentId gives a TWO-LEVEL hierarchy, and `Name` holds the LEAF only.
--     A theme filed as "Jesus Christ/Second Coming" is stored as the leaf
--     "Second Coming" with ParentId pointing at "Jesus Christ" — never as the
--     slash-joined path. Name is VARCHAR(50) and UNIQUE, so writing the path
--     would both collide and truncate.
--   * Source distinguishes 'curator' from 'ccli-openlyrics'. Curator variants
--     are folded into a standard theme by the canonicalisation merge on
--     /manage/tags, which is irreversible (the variant row is deleted). Grow
--     the vocabulary through the migration's source data, never ad hoc.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongTags (
    Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    Name            VARCHAR(50)     NOT NULL UNIQUE,
    Slug            VARCHAR(50)     NOT NULL UNIQUE COMMENT 'URL-safe lowercase version',
    Description     VARCHAR(255)    NOT NULL DEFAULT '',
    ParentId        INT UNSIGNED    NULL DEFAULT NULL COMMENT 'Self-FK to tblSongTags.Id for the 2-level CCLI/OpenLyrics theme hierarchy (#1152); NULL = top-level',
    CcliThemeId     INT UNSIGNED    NULL DEFAULT NULL COMMENT 'CCLI SongSelect theme number for import id-match (#1152); NULL until known',
    Source          VARCHAR(50)     NOT NULL DEFAULT 'curator' COMMENT 'Provenance: curator | ccli-openlyrics (seeded standard vocab) (#1152)',
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_Slug  (Slug),
    INDEX idx_ParentId (ParentId),
    INDEX idx_Source (Source),
    CONSTRAINT fk_SongTags_Parent
        FOREIGN KEY (ParentId) REFERENCES tblSongTags(Id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblSongTagMap
-- Many-to-many mapping between songs and tags.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongTagMap (
    SongId          VARCHAR(20)     NOT NULL,
    TagId           INT UNSIGNED    NOT NULL,
    TaggedBy        INT UNSIGNED    NULL COMMENT 'User who added the tag',
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (SongId, TagId),

    CONSTRAINT fk_TagMap_Song
        FOREIGN KEY (SongId) REFERENCES tblSongs(SongId)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_TagMap_Tag
        FOREIGN KEY (TagId) REFERENCES tblSongTags(Id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_TagMap_User
        FOREIGN KEY (TaggedBy) REFERENCES tblUsers(Id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblBulkImportJobs (#676)
-- Tracks long-running bulk_import_zip jobs so the browser can poll for
-- progress and the persistent progress widget on every iHymns page can
-- survive navigation. Created when an editor uploads a zip via
-- /manage/editor/api.php?action=bulk_import_zip; the action saves the
-- tmp file path here, returns {job_id} immediately, calls
-- fastcgi_finish_request() to release the HTTP connection, then
-- continues processing in the freed worker, updating ProcessedEntries
-- + counts every N entries. The bulk_import_status endpoint reads
-- this row.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblBulkImportJobs (
    Id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    UserId                   INT UNSIGNED NULL COMMENT 'editor who started the import; NULL if global_admin used a CLI invocation',
    Filename                 VARCHAR(255) NOT NULL COMMENT 'Original upload filename (display only)',
    TempPath                 VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'Server-side path to the moved temp file; cleared on completion',
    SizeBytes                BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Original upload size in bytes (display only)',
    Status                   ENUM('queued','running','completed','failed') NOT NULL DEFAULT 'queued',
    TotalEntries             INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Real .txt entries the worker has classified for processing',
    ProcessedEntries         INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Counter the worker bumps every ~50 rows so the polling endpoint can render a percentage',
    SongbooksCreatedJson     JSON NULL COMMENT 'Result summary — list of abbrevs created in this run',
    SongbooksExistingJson    JSON NULL COMMENT 'Result summary — list of abbrevs that already existed',
    SongsCreated             INT UNSIGNED NOT NULL DEFAULT 0,
    SongsSkippedExisting     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'INSERT-only contract: existing SongIds are left untouched',
    SongsFailed              INT UNSIGNED NOT NULL DEFAULT 0,
    ErrorsJson               JSON NULL COMMENT 'Per-entry [{entry, error}, …] from the parser / save path',
    SkippedSongIdsJson       JSON NULL COMMENT 'JSON array of SongIds the worker skipped because the row already existed. Powers the bulk-import completion notification\'s "Download skipped SongIds" CSV button.',
    PerSongbookJson          JSON NULL COMMENT 'Per-songbook breakdown of created / skipped / failed counts so the import-summary notification can render a per-book table instead of a single aggregate (#906)',
    PhaseLabel               VARCHAR(64) NULL DEFAULT NULL COMMENT 'Human-readable phase the worker is currently in (walking-zip, parsing-songs, flushing-songbooks, …). Lets the polling frontend show progress text before the percentage starts moving (#907)',
    StartedAt                TIMESTAMP NULL DEFAULT NULL COMMENT 'When the worker began processing (post-fastcgi_finish_request)',
    CompletedAt              TIMESTAMP NULL DEFAULT NULL,
    CreatedAt                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    /* Per-user lookup for the polling endpoint (always WHERE
       UserId = ? AND Status IN (...)). */
    INDEX idx_user_status (UserId, Status),
    /* Ops-side audit: "show me jobs that have been running > 1h" */
    INDEX idx_status_updated (Status, UpdatedAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblNotifications
-- In-app notification system for users (new songs, request status changes,
-- system announcements, etc.).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblNotifications (
    Id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    UserId          INT UNSIGNED    NOT NULL,
    Type            VARCHAR(50)     NOT NULL COMMENT 'e.g., song_added, request_update, announcement',
    Title           VARCHAR(255)    NOT NULL,
    Body            TEXT            NOT NULL DEFAULT (''),
    ActionUrl       VARCHAR(500)    NOT NULL DEFAULT '' COMMENT 'Deep link (e.g., /song/CP-0001)',
    IsRead          TINYINT(1)      NOT NULL DEFAULT 0,
    Environment     VARCHAR(16)     NULL DEFAULT NULL COMMENT 'Target environment (#1238): NULL = all; alpha / beta / production = that env only',
    ExpiresAt       DATETIME        NULL DEFAULT NULL COMMENT 'Optional expiry (#1238): NULL = never; the client hides the notification after this moment',
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_User          (UserId),
    INDEX idx_UserUnread    (UserId, IsRead),
    INDEX idx_Created       (CreatedAt),

    CONSTRAINT fk_Notif_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblLoginAttempts
-- Rate limiting for authentication attempts. Tracks failed logins per IP
-- to prevent brute force attacks.
--
-- The name undersells it: this is the GENERIC rate-limit counter for the whole
-- app (includes/rate_limit.php::checkRateLimit()), and callers that have
-- nothing to do with logging in — the analytics ingest endpoint among them —
-- reuse it by putting an ACTION NAME in the `Username` column. So a row here
-- does not necessarily describe a login, and Username is a free string with no
-- FK to tblUsers (account_lifecycle.php deletes by that string on erasure).
-- Keep that in mind before reading COUNT(*) on this table as a login figure.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblLoginAttempts (
    Id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    IpAddress       VARCHAR(45)     NOT NULL,
    Username        VARCHAR(100)    NOT NULL DEFAULT '',
    Success         TINYINT(1)      NOT NULL DEFAULT 0,
    AttemptedAt     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_Ip        (IpAddress),
    INDEX idx_IpTime    (IpAddress, AttemptedAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- IN-PLACE MIGRATIONS (safe to re-run)
-- These run after the CREATE TABLE IF NOT EXISTS statements so existing
-- deployments upgrade without manual DB work. Each statement is idempotent:
-- MySQL treats a no-op ALTER (column already matches) as a successful
-- metadata-only operation.
-- ============================================================================

-- Make tblSongs.Number nullable so the Misc ("unsorted") songbook can hold
-- songs without a songbook number (#392).
ALTER TABLE tblSongs MODIFY Number INT UNSIGNED NULL DEFAULT NULL;

-- Zero out any existing Misc song numbers (historic placeholders).
UPDATE tblSongs SET Number = NULL WHERE SongbookAbbr = 'Misc' AND Number IS NOT NULL;

-- ============================================================================
-- SONG RELATIONSHIPS, PERMALINK CONTINUITY & DUPLICATE DETECTION
--
-- Four different answers to "these two rows are about the same hymn", kept
-- deliberately apart because they mean different things:
--
--   tblSongLinks ............ same hymn, DIFFERENT SONGBOOK (Amazing Grace as
--                             MP-031 / CH-376 / SDAH-108). A curator assertion.
--   tblSongTranslations ..... same hymn, DIFFERENT LANGUAGE (declared earlier,
--                             under LANGUAGE & TRANSLATION SUPPORT).
--   tblSongRedirects ........ a DEAD SongId whose shared permalink must still
--                             resolve. Not a relationship — a tombstone.
--   tblSongLinkSuggestions .. a MACHINE GUESS awaiting a curator's yes/no,
--                             with tblSongLinkSuggestionsDismissed as the "no".
--
-- Only the first two assert anything about the catalogue. Promoting a
-- suggestion writes a tblSongLinks row; it never edits the suggestion into
-- truth. Scoring lives in includes/song_similarity.php — the ONE scorer
-- (rule #22), never re-forked.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- tblSongLinks (#807) — cross-book counterparts.
-- All rows sharing a GroupId represent the same hymn in different songbooks
-- (Amazing Grace as MP-031 / CH-376 / SDAH-108 / SoF-29 / JP-006). Distinct
-- from tblSongTranslations (different-language same hymn) and from
-- tblSongbooks.ParentSongbookId (which only handles translated / edition
-- derivatives at the songbook level, requiring matching hymn numbers).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongLinks (
    Id           INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    GroupId      INT UNSIGNED  NOT NULL
                 COMMENT 'All songs sharing a GroupId are the same hymn (cross-book counterparts).',
    SongId       VARCHAR(20)   NOT NULL,
    Note         VARCHAR(255)  NOT NULL DEFAULT ''
                 COMMENT 'Optional curator-set annotation, e.g. "uses 1990 Wesley revision text"',
    Verified     TINYINT(1)    NOT NULL DEFAULT 0,
    Origin       VARCHAR(20)   NOT NULL DEFAULT 'manual'
                 COMMENT 'How this link was created: manual (curator click) or auto-iswc/auto-ccli/auto-isrc (#1125 hard-key auto-promotion). VARCHAR not ENUM (rule #20).',
    CreatedBy    INT UNSIGNED  NULL DEFAULT NULL
                 COMMENT 'tblUsers.Id of the curator who linked this row, if signed in',
    CreatedAt    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    /* uk_song makes SongId UNIQUE, so a song belongs to AT MOST ONE link
       group. That is the load-bearing constraint of this table: groups are
       equivalence classes, not tags, and merging two groups means re-stamping
       GroupId on every member rather than adding a second row. GroupId itself
       is a bare INT with no parent table — there is no tblSongLinkGroups; the
       group exists only as the set of rows sharing the value. */
    UNIQUE KEY uk_song (SongId),
    KEY idx_GroupId (GroupId),
    CONSTRAINT fk_SongLinks_Song
        FOREIGN KEY (SongId) REFERENCES tblSongs(SongId)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblSongRedirects (#1343) — keep a shared permalink (/song/<SongId>) alive
-- after a song is merged, deleted, renamed or MOVED to another songbook,
-- instead of a dead 404 (the "Here To Stay" problem). OldSongId is the dead id
-- (PK, NOT an FK — the row is gone); NewSongId is the 301 target
-- (FK->tblSongs ON DELETE SET NULL) or NULL for a tombstone ("removed").
-- Merge auto-writes duplicate->survivor; delete offers relink-or-tombstone; a
-- songbook move (#1679, includes/song_relocate.php) writes Reason='move'.
-- Resolution is transitive + cycle-guarded.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongRedirects (
    OldSongId  VARCHAR(20)  NOT NULL
               COMMENT 'The dead/merged/renamed SongId whose permalink must keep resolving (#1343). PK; NOT an FK — the old song row is gone.',
    NewSongId  VARCHAR(20)  NULL DEFAULT NULL
               COMMENT 'Resolve target (FK->tblSongs); NULL = tombstone (removed, no replacement).',
    Reason     VARCHAR(20)  NOT NULL DEFAULT 'merge'
               COMMENT 'merge | delete | rename | move — VARCHAR not ENUM (rule #20); move = songbook re-key (#1679).',
    Note       VARCHAR(255) NOT NULL DEFAULT '',
    CreatedBy  INT UNSIGNED NULL DEFAULT NULL
               COMMENT 'tblUsers.Id of the curator who created the redirect, if signed in',
    CreatedAt  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (OldSongId),
    KEY idx_NewSongId (NewSongId),
    CONSTRAINT fk_SongRedirect_New
        FOREIGN KEY (NewSongId) REFERENCES tblSongs(SongId)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblSongLinkSuggestions (#808) — pre-computed pairwise similarity scores.
-- Populated by appWeb/public_html/includes/tools/build-song-link-suggestions.php.
-- Reviewed at /manage/duplicate-songs — the standalone /manage/song-link-
-- suggestions page this comment used to name was ABSORBED into that unified
-- review UI in #1215 and is now only a 302 redirect (rule #22).
--
-- Pairs are stored canonically with SongIdA < SongIdB so each unordered pair
-- has at most one row: without that normalisation (A,B) and (B,A) would both
-- insert past uk_pair and the same duplicate would be offered to the curator
-- twice. The builder is responsible for the ordering — the database cannot
-- express "sorted pair" as a constraint.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongLinkSuggestions (
    Id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    SongIdA         VARCHAR(20)  NOT NULL COMMENT 'Always lexicographically <= SongIdB',
    SongIdB         VARCHAR(20)  NOT NULL,
    Score           DECIMAL(4,3) NOT NULL COMMENT 'Composite similarity, 0.000-1.000',
    /* The next two columns look like they contradict each other — one ENUM,
       one VARCHAR, both added by the same #1066 change, with the VARCHAR one
       explaining itself as "not ENUM so a new value needs no ALTER". They do
       not. Confidence is a CLOSED triage ladder: high / medium / low is the
       whole idea, and a fourth rung would be a redesign, not an addition.
       Signal names the DETECTION METHOD, and every new matching technique
       adds one — which is precisely the growable case rule #20 is about.
       Judge a vocabulary by whether it grows, not by how many values it has
       today. */
    Confidence      ENUM('high','medium','low') NOT NULL DEFAULT 'low' COMMENT 'Triage tier so curators sort by confidence, not raw blend: strong-key match (ISRC/MBID) => high; fuzzy+author => medium; title-only => low (#1066 Theme D)',
    `Signal`        VARCHAR(50)  NOT NULL DEFAULT 'fuzzy' COMMENT 'Detection method: fuzzy | shared-isrc | shared-musicbrainz | shared-spotify | shared-genius. VARCHAR (not ENUM) so a new signal type needs no ALTER (#1066 Theme D). Backtick-quoted — SIGNAL is a reserved word in MySQL 8',
    TitleScore      DECIMAL(4,3) NOT NULL DEFAULT 0.000,
    LyricsScore     DECIMAL(4,3) NOT NULL DEFAULT 0.000,
    AuthorsScore    DECIMAL(4,3) NOT NULL DEFAULT 0.000,
    ComputedAt      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_pair (SongIdA, SongIdB),
    KEY idx_Score (Score),
    KEY idx_Confidence (Confidence),
    KEY idx_Signal (`Signal`),
    KEY idx_SongA (SongIdA),
    KEY idx_SongB (SongIdB),
    CONSTRAINT fk_SongLinkSugg_A FOREIGN KEY (SongIdA)
        REFERENCES tblSongs(SongId) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_SongLinkSugg_B FOREIGN KEY (SongIdB)
        REFERENCES tblSongs(SongId) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblSongLinkSuggestionsDismissed (#808) — curator-rejected pairs.
-- The build job consults this table and skips dismissed pairs so the
-- suggestion list doesn't keep proposing pairs the curator has already
-- said no to.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongLinkSuggestionsDismissed (
    Id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    SongIdA         VARCHAR(20)  NOT NULL COMMENT 'Always lexicographically <= SongIdB',
    SongIdB         VARCHAR(20)  NOT NULL,
    DismissedBy     INT UNSIGNED NULL DEFAULT NULL,
    DismissedAt     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    Reason          VARCHAR(255) NOT NULL DEFAULT '',
    UNIQUE KEY uk_pair (SongIdA, SongIdB),
    KEY idx_SongA (SongIdA),
    KEY idx_SongB (SongIdB),
    /* #1064 — dismissed pairs are FK-enforced; cascade-deleted with either song. */
    CONSTRAINT fk_DismissedSugg_A
        FOREIGN KEY (SongIdA) REFERENCES tblSongs(SongId)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_DismissedSugg_B
        FOREIGN KEY (SongIdB) REFERENCES tblSongs(SongId)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Search-query log for analytics (#404). Captures every search so we can
-- surface top queries + zero-result queries in the admin dashboard.
CREATE TABLE IF NOT EXISTS tblSearchQueries (
    Id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    Query           VARCHAR(500)    NOT NULL,
    ResultCount     INT UNSIGNED    NOT NULL DEFAULT 0,
    UserId          INT UNSIGNED    NULL,
    SearchedAt      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_SearchedAt (SearchedAt),
    INDEX idx_Query      (Query(191)),
    CONSTRAINT fk_Search_User FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- MIGRATION-ORIGIN FAMILIES (sync target for the schema-audit page)
--
-- This is a PROVENANCE boundary, not a subject boundary: everything from here
-- to the #1066 banner was originally introduced by an appWeb/.sql/migrate-*.php
-- script and mirrored back here so schema.sql stays the canonical description
-- of what a live database should hold. Adding a new table that ships via a
-- migration? Append its CREATE TABLE block here so the schema-audit page
-- (#518) and tests/php/test-schema-coverage.php both stay clean.
--
-- Because the ordering is historical, unrelated subjects sit next to each
-- other. The `-- ==== FAMILY:` banners below re-impose the subject grouping:
--   FAMILY: COLLECTIONS · EXTERNAL LINKS · CREDIT-PEOPLE SATELLITES ·
--   ALTERNATIVE TITLES & PER-ENTITY LANGUAGES · SONGBOOK SERIES & COMPILERS ·
--   SONG MEDIA · WORKS
-- ============================================================================


-- ============================================================================
-- FAMILY: COLLECTIONS (#941)
-- Curatorial groupings ORTHOGONAL to the songbook hierarchy — a song lives in
-- exactly one songbook but can appear in any number of collections. Note the
-- vocabulary split (rule #24): these are called "Collections" in the UI and
-- "Catalogues" everywhere internal — table names, the /manage/catalogues
-- route, the admin.catalogues.* log keys and the 'catalogue' entity type all
-- stay as they are. Renaming them is an explicitly rejected change.
-- ============================================================================


-- ----------------------------------------------------------------------------
-- tblCatalogues (#941) — many-to-many curatorial groupings orthogonal to the
-- songbook hierarchy. CRUD at /manage/catalogues.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblCatalogues (
    Id           INT UNSIGNED      AUTO_INCREMENT PRIMARY KEY,
    Slug         VARCHAR(255)      NOT NULL,
    Title        VARCHAR(255)      NOT NULL,
    Description  TEXT              NULL,
    SortOrder    SMALLINT          NOT NULL DEFAULT 0,
    Visibility   ENUM('public','curated','admin_only')
                                    NOT NULL DEFAULT 'public',
    CreatedAt    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,
    Colour       VARCHAR(7)        NOT NULL DEFAULT '' COMMENT 'Badge colour hex #RRGGBB (empty = theme default) (#1181)',
    ArkId                 VARCHAR(80) NULL DEFAULT NULL COMMENT 'Archival Resource Key for the collection (Feature 3, epic #1765); mirrors tblSongbooks.ArkId (#672). No Isbn/Issn on this table - a curated collection (rule #24: catalogue internally, Collection in UI) is not itself a catalogued serial publication, see the plan adversarial notes section',
    OpenLibraryWorkId     VARCHAR(20) NULL DEFAULT NULL COMMENT 'Open Library Work id for the collection, e.g. OL102749W (Feature 3, epic #1765); mirrors tblSongbooks.OpenLibraryWorkId',
    OpenLibraryEditionId  VARCHAR(20) NULL DEFAULT NULL COMMENT 'Open Library Edition id for the collection, e.g. OL7357422M (Feature 3, epic #1765); mirrors tblSongbooks.OpenLibraryEditionId',
    UNIQUE KEY uk_Slug (Slug),
    INDEX idx_Visibility (Visibility),
    INDEX idx_SortOrder  (SortOrder)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblCatalogueSongs (#941) — join table for the Catalogues many-to-many.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblCatalogueSongs (
    CatalogueId  INT UNSIGNED      NOT NULL,
    SongId       VARCHAR(20)       NOT NULL,
    SortOrder    INT               NOT NULL DEFAULT 0,
    AddedBy      INT UNSIGNED      NULL,
    AddedAt      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (CatalogueId, SongId),
    INDEX idx_SongId (SongId),
    INDEX idx_SortOrder (CatalogueId, SortOrder),
    CONSTRAINT fk_CatalogueSongs_Catalogue
        FOREIGN KEY (CatalogueId) REFERENCES tblCatalogues(Id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_CatalogueSongs_Song
        FOREIGN KEY (SongId) REFERENCES tblSongs(SongId)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- FAMILY: EXTERNAL LINKS (#833 / #845)
--
-- One provider registry plus ONE LINK TABLE PER ENTITY —
-- tblSong/tblSongbook/tblMusician/tblWork ExternalLinks — rather than a
-- single polymorphic table with an EntityType column. That looks like
-- duplication and is the deliberate choice (rule #15): each link table gets a
-- REAL foreign key to its own parent, so links cascade away with the thing
-- they describe. tblContentRestrictions above shows the alternative, where a
-- polymorphic key means nothing can cascade at all.
--
-- The provider FK runs the other way: every link table declares its
-- LinkTypeId ON DELETE RESTRICT, so a provider that is still in use cannot be
-- deleted out from under the links pointing at it. Deactivate it (IsActive=0)
-- instead. tblExternalLinkPatterns holds the URL -> provider match rules that
-- js/modules/external-link-detect.js reads, which is why no provider list is
-- ever hard-coded in PHP or JS (rule #11/#12).
-- ============================================================================

-- ----------------------------------------------------------------------------
-- tblExternalLinkTypes (#833) — controlled vocabulary of link providers
-- (Hymnary, CCLI Songselect, IMSLP, YouTube, Spotify, Internet Archive,
-- Wikipedia, MusicBrainz, …). Seeded by migrate-external-links.php.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblExternalLinkTypes (
    Id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    Slug          VARCHAR(60)  NOT NULL,
    Name          VARCHAR(120) NOT NULL,
    Category      VARCHAR(20)  NOT NULL DEFAULT 'other'
                  COMMENT 'information | listen | watch | read | sheet-music | purchase | authority | official | social | other (app-validated; widened from ENUM, rule #20, #1741 P1)',
    UrlPattern    VARCHAR(255) NULL,
    IconClass     VARCHAR(60)  NULL,
    AppliesTo     VARCHAR(255) NOT NULL DEFAULT 'song,songbook,person,musician'
                  COMMENT 'CSV of applicable entity types: song | songbook | musician | work | tune (legacy alias "person" also recognised for pre-#1741-P2-B app-code back-compat — see migrate-musicians-rename.php; retired once the app-code rename lands) (app-validated via FIND_IN_SET; widened from SET so a new entity type never needs an ALTER, rule #20, #1741 P1/P2)',
    AllowMultiple TINYINT(1)   NOT NULL DEFAULT 1,
    IsActive      TINYINT(1)   NOT NULL DEFAULT 1,
    DisplayOrder  INT UNSIGNED NOT NULL DEFAULT 0,
    CreatedAt     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_slug   (Slug),
    INDEX     idx_active   (IsActive),
    INDEX     idx_category (Category, DisplayOrder)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblExternalLinkPatterns (#845) — curator-editable host/path patterns that
-- map a pasted URL to its tblExternalLinkTypes entry (replaces the legacy
-- JS-hardcoded provider regex list).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblExternalLinkPatterns (
    Id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    LinkTypeId      INT UNSIGNED NOT NULL,
    Host            VARCHAR(255) NOT NULL,
    PathPrefix      VARCHAR(255) NULL,
    MatchSubdomains TINYINT(1)   NOT NULL DEFAULT 1,
    Priority        INT UNSIGNED NOT NULL DEFAULT 100,
    IsActive        TINYINT(1)   NOT NULL DEFAULT 1,
    Note            VARCHAR(255) NULL,
    CreatedAt       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_type     (LinkTypeId),
    INDEX idx_host     (Host),
    INDEX idx_priority (Priority),

    CONSTRAINT fk_linkpat_type
        FOREIGN KEY (LinkTypeId) REFERENCES tblExternalLinkTypes(Id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblSongbookExternalLinks (#833) — per-songbook external-link rows.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongbookExternalLinks (
    Id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    SongbookId  INT UNSIGNED NOT NULL,
    LinkTypeId  INT UNSIGNED NOT NULL,
    Url         VARCHAR(2048) NOT NULL,
    Note        VARCHAR(255) NULL,
    SortOrder   INT UNSIGNED NOT NULL DEFAULT 0,
    Verified    TINYINT(1)   NOT NULL DEFAULT 0,
    CreatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_book (SongbookId),
    INDEX idx_type (LinkTypeId),

    CONSTRAINT fk_link_book
        FOREIGN KEY (SongbookId) REFERENCES tblSongbooks(Id) ON DELETE CASCADE,
    CONSTRAINT fk_link_type_book
        FOREIGN KEY (LinkTypeId) REFERENCES tblExternalLinkTypes(Id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblSongExternalLinks (#833) — per-song external-link rows.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongExternalLinks (
    Id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    SongId      VARCHAR(20)  NOT NULL,
    LinkTypeId  INT UNSIGNED NOT NULL,
    Url         VARCHAR(2048) NOT NULL,
    Note        VARCHAR(255) NULL,
    SortOrder   INT UNSIGNED NOT NULL DEFAULT 0,
    Verified    TINYINT(1)   NOT NULL DEFAULT 0,
    CreatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_song (SongId),
    INDEX idx_type (LinkTypeId),

    CONSTRAINT fk_link_song
        FOREIGN KEY (SongId)     REFERENCES tblSongs(SongId)         ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_link_type_song
        FOREIGN KEY (LinkTypeId) REFERENCES tblExternalLinkTypes(Id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblMusicianExternalLinks (#833; renamed from tblCreditPersonExternalLinks
-- by #1741 P2) — per-musician external links. Replaces the legacy free-text
-- tblMusicianLinks (kept as read-fallback).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblMusicianExternalLinks (
    Id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    MusicianId      INT UNSIGNED NOT NULL,
    LinkTypeId      INT UNSIGNED NOT NULL,
    Url             VARCHAR(2048) NOT NULL,
    Note            VARCHAR(255) NULL,
    SortOrder       INT UNSIGNED NOT NULL DEFAULT 0,
    Verified        TINYINT(1)   NOT NULL DEFAULT 0,
    CreatedAt       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_musician (MusicianId),
    INDEX idx_type     (LinkTypeId),

    CONSTRAINT fk_link_musician
        FOREIGN KEY (MusicianId)     REFERENCES tblMusicians(Id)           ON DELETE CASCADE,
    CONSTRAINT fk_link_type_musician
        FOREIGN KEY (LinkTypeId)     REFERENCES tblExternalLinkTypes(Id)   ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- FAMILY: MUSICIAN SATELLITES (renamed from "CREDIT-PEOPLE SATELLITES" by
-- #1741 P2)
-- Everything that hangs off tblMusicians and arrived after it: alternative
-- names, and group -> member composition. The identifier tables
-- (tblMusicianIPI / tblMusicianIdentifiers) and the musician link
-- tables live earlier in the file, with the registry itself.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- tblMusicianAliases (renamed from tblCreditPersonAliases by #1741 P2) — AKA /
-- alternative names for searchability. MusicBrainz-style alias model: one
-- row per (musician, name) with a Type classification, optional Locale tag
-- for transliterations, and an IsPrimary flag for the preferred display
-- form within a locale. Searched alongside Name in site search + admin
-- filter + editor typeahead. /people/<slug> renders aliases under the bio
-- header and emits JSON-LD alternateName.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblMusicianAliases (
    Id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    MusicianId      INT UNSIGNED NOT NULL,
    Name            VARCHAR(255) NOT NULL COMMENT 'Display form of the alias',
    SortName        VARCHAR(255) NULL COMMENT 'Surname-first sortable form; NULL = derive from Name',
    Type            VARCHAR(20)  NOT NULL DEFAULT 'other'
                                 COMMENT 'legal | artist | pseudonym | nickname | maiden | search-hint | misspelling | other (app-validated; widened from ENUM, rule #20, #1741 P1)',
    Locale          VARCHAR(35)  NULL COMMENT 'Optional IETF BCP 47 tag for transliterations (ja, ru-Latn, zh-Hans, …)',
    IsPrimary       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = preferred display form in this Locale',
    SortOrder       INT UNSIGNED NOT NULL DEFAULT 0,
    Note            VARCHAR(255) NULL,
    CreatedAt       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_musician_name (MusicianId, Name),
    INDEX idx_musician (MusicianId),
    INDEX idx_name     (Name),
    INDEX idx_type     (Type),

    CONSTRAINT fk_alias_musician
        FOREIGN KEY (MusicianId) REFERENCES tblMusicians(Id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblMusicianRelations (#1502; renamed from tblCreditPersonMembers by
-- #1741 P2 — the P1 M3 relation generalisation below made "Members" too
-- narrow a name) — links a SUBJECT musician to an OBJECT musician via a
-- typed, dated relation ('member' today, 'portrays' for a person credited
-- as portraying a historical figure in a dramatisation). Originally a thin
-- group/member join table; both FKs point at tblMusicians(Id) ON DELETE
-- CASCADE so deleting either side cleans up the link automatically. UNIQUE
-- guards duplicate membership; "a group can't list itself as a member" is
-- an application-layer check (addCreditPersonGroupMember() in
-- credit_people_helpers.php), not a schema CHECK constraint. SortOrder is
-- append-order only for v1 (no drag-reorder UI yet).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblMusicianRelations (
    Id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    SubjectMusicianId INT UNSIGNED NOT NULL COMMENT 'FK to tblMusicians.Id — the Group/band/collective musician (subject side of the relation) (#1741 P2)',
    ObjectMusicianId  INT UNSIGNED NOT NULL COMMENT 'FK to tblMusicians.Id — an individual member musician (object side of the relation) (#1741 P2)',
    /* Relation generalisation (#1741 P1 M3) — 'member' today, 'portrays'
       for a person credited as portraying a historical figure in a
       dramatisation. Dated so the same pair can legitimately repeat under
       a different relation or date range (e.g. left and later rejoined). */
    RelationType   VARCHAR(30)  NOT NULL DEFAULT 'member' COMMENT 'member | portrays (app-validated; VARCHAR not ENUM, rule #20) — portrays models e.g. a person credited as portraying a historical figure in a dramatisation (#1741 P1)',
    DateFrom            DATE         NULL DEFAULT NULL COMMENT 'Start of this relation (member-since / portrayed-from); partial-date precision in DateFromPrecision (#1741 P1)',
    DateFromPrecision   VARCHAR(5)   NULL DEFAULT NULL COMMENT 'How much of DateFrom is real: year | month | day (partial historical dates) (#1741 P1)',
    DateTo              DATE         NULL DEFAULT NULL COMMENT 'End of this relation (member-until / portrayed-until); NULL = ongoing/current (#1741 P1)',
    DateToPrecision     VARCHAR(5)   NULL DEFAULT NULL COMMENT 'How much of DateTo is real: year | month | day (partial historical dates) (#1741 P1)',
    Note                VARCHAR(255) NULL DEFAULT NULL COMMENT 'Free-text curator note about this relation (#1741 P1)',
    SortOrder      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Admin-controlled display order within the group; append-order by default',
    CreatedAt      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    /* uq_subject_object_rel (renamed from uq_group_member_rel by #1741 P2,
       which itself superseded the original uq_group_member in #1741 P1) —
       DateFrom sits inside the key and is nullable; MySQL/MariaDB treat
       every NULL as distinct inside a UNIQUE key (the same pattern as
       tblSongbookEntries.uq_book_number), so undated relations still
       collide on (Subject,Object,RelationType) alone while multiple DATED
       relations for the same pair+type coexist. */
    UNIQUE KEY uq_subject_object_rel (SubjectMusicianId, ObjectMusicianId, RelationType, DateFrom),
    INDEX      idx_subject (SubjectMusicianId),
    INDEX      idx_object  (ObjectMusicianId),

    CONSTRAINT fk_musicianrelations_subject
        FOREIGN KEY (SubjectMusicianId) REFERENCES tblMusicians(Id) ON DELETE CASCADE,
    CONSTRAINT fk_musicianrelations_object
        FOREIGN KEY (ObjectMusicianId)  REFERENCES tblMusicians(Id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Group/band/collective -> individual member musicians (#1502).';


-- ----------------------------------------------------------------------------
-- tblMusicianDuplicatesDismissed (#1785) — a curator's "these two registry
-- rows are NOT the same person" memory for /manage/musician-duplicates.
-- Mirrors tblSongLinkSuggestionsDismissed: pair normalised (IdA < IdB,
-- numeric), UNIQUE per pair, FK-cascaded so merging/deleting either person
-- auto-cleans the dismissal. Scores are deliberately NOT stored — the scan
-- recomputes live (#1785 §3.4), so a stored score could only go stale.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblMusicianDuplicatesDismissed (
    Id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    MusicianIdA  INT UNSIGNED NOT NULL COMMENT 'Always numerically < MusicianIdB (#1785)',
    MusicianIdB  INT UNSIGNED NOT NULL,
    DismissedBy  INT UNSIGNED NULL DEFAULT NULL COMMENT 'tblUsers.Id; NULL = user since deleted',
    DismissedAt  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    Reason       VARCHAR(255) NOT NULL DEFAULT '',
    UNIQUE KEY uk_pair (MusicianIdA, MusicianIdB),
    KEY idx_B (MusicianIdB),
    CONSTRAINT fk_MusDupDism_A FOREIGN KEY (MusicianIdA) REFERENCES tblMusicians(Id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_MusDupDism_B FOREIGN KEY (MusicianIdB) REFERENCES tblMusicians(Id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- FAMILY: MUSICIANS BACK-COMPAT VIEWS (#1741 P2)
--
-- One UPDATABLE view per pre-rename table name, over the tblMusician* tables
-- above, with every renamed column aliased back to its pre-rename name via
-- `NewCol AS OldCol`. This is the shield that lets every app-code call site
-- that still says tblCreditPeople/tblCreditPerson* (the whole codebase except
-- this file's migration counterpart and includes/schema_audit.php) keep
-- reading AND WRITING through the old names unchanged — the app-code rename
-- itself is #1741 P2-B, a deliberately separate, later step.
--
-- Every column list below is explicit (never `SELECT *`) and every column is
-- a direct, unmodified reference to its underlying column (a straight column
-- reference, optionally aliased) — no expression, aggregate, JOIN, DISTINCT
-- or GROUP BY — so MySQL/MariaDB's view-updatability rules are satisfied by
-- construction and INSERT/UPDATE/DELETE against any of these seven names
-- keep working exactly as they did against the base table.
--
-- These views are PERMANENT until a dedicated drop card (P2b, tracked
-- separately, gated 'manual'+confirm=1 like the #1235 LinesJson retirement)
-- runs once every docroot is confirmed running P2-B's renamed code. Do not
-- hand-drop them; do not reintroduce a real table under any of these seven
-- names.
-- ============================================================================

CREATE OR REPLACE VIEW tblCreditPeople AS
    SELECT Id, Name, Slug, MusicBrainzArtistMBID, IsSpecialCase, IsGroup, Type, Disambiguation,
           FirstNames, Surname, MaidenSurname, Suffix, Notes, Biography, BirthPlace, BirthPlaceId,
           BirthDate, BirthDatePrecision, DeathPlace, DeathPlaceId, DeathDate, DeathDatePrecision,
           CreatedAt, UpdatedAt
      FROM tblMusicians;

CREATE OR REPLACE VIEW tblCreditPersonLinks AS
    SELECT Id, MusicianId AS CreditPersonId, LinkType, Url, Label, SortOrder, CreatedAt, UpdatedAt
      FROM tblMusicianLinks;

CREATE OR REPLACE VIEW tblCreditPersonIPI AS
    SELECT Id, MusicianId AS CreditPersonId, IPINumber, NameUsed, Notes, CreatedAt, UpdatedAt
      FROM tblMusicianIPI;

CREATE OR REPLACE VIEW tblCreditPersonIdentifiers AS
    SELECT Id, MusicianId AS CreditPersonId, IdentifierType, IdentifierValue, NameUsed, Notes,
           CreatedAt, UpdatedAt
      FROM tblMusicianIdentifiers;

CREATE OR REPLACE VIEW tblCreditPersonExternalLinks AS
    SELECT Id, MusicianId AS CreditPersonId, LinkTypeId, Url, Note, SortOrder, Verified,
           CreatedAt, UpdatedAt
      FROM tblMusicianExternalLinks;

CREATE OR REPLACE VIEW tblCreditPersonAliases AS
    SELECT Id, MusicianId AS CreditPersonId, Name, SortName, Type, Locale, IsPrimary, SortOrder,
           Note, CreatedAt, UpdatedAt
      FROM tblMusicianAliases;

CREATE OR REPLACE VIEW tblCreditPersonMembers AS
    SELECT Id, SubjectMusicianId AS GroupPersonId, ObjectMusicianId AS MemberPersonId, RelationType,
           DateFrom, DateFromPrecision, DateTo, DateToPrecision, Note, SortOrder, CreatedAt, UpdatedAt
      FROM tblMusicianRelations;


-- ============================================================================
-- FAMILY: ALTERNATIVE TITLES & PER-ENTITY LANGUAGES (#832 / #778)
--
-- Two pairs of parallel song/songbook tables. Both exist for the same reason:
-- the singular column that came first (Title, Language) could hold exactly one
-- value, and real hymnals need several — an "also known as" title per source,
-- a language per section of a bilingual book. The singular columns are kept as
-- the display default rather than dropped, with IsPrimary=1 in the chip-list
-- mirroring them, so no read path had to change when these landed.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- tblSongAlternativeTitles (#832) — multiple "also known as" titles per song.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongAlternativeTitles (
    Id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    SongId       VARCHAR(20)  NOT NULL,
    Title        VARCHAR(255) NOT NULL,
    Language     VARCHAR(35)  NULL,
    SortOrder    INT UNSIGNED NOT NULL DEFAULT 0,
    Note         VARCHAR(255) NULL,
    CreatedAt    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_song   (SongId),
    INDEX idx_title  (Title),
    UNIQUE KEY uq_song_title (SongId, Title),

    CONSTRAINT fk_alt_song
        FOREIGN KEY (SongId) REFERENCES tblSongs(SongId) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblSongbookAlternativeTitles (#832) — multiple "also known as" titles per
-- songbook (e.g. "Adventist Hymnal" → The Church Hymnal).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongbookAlternativeTitles (
    Id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    SongbookId   INT UNSIGNED NOT NULL,
    Title        VARCHAR(255) NOT NULL,
    SortOrder    INT UNSIGNED NOT NULL DEFAULT 0,
    Note         VARCHAR(255) NULL,
    CreatedAt    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_book   (SongbookId),
    INDEX idx_title  (Title),
    UNIQUE KEY uq_book_title (SongbookId, Title),

    CONSTRAINT fk_alt_book
        FOREIGN KEY (SongbookId) REFERENCES tblSongbooks(Id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblSongbookLanguages (#778) — multi-language songbook chip-list. Mirrors
-- the legacy tblSongbooks.Language column as IsPrimary=1 on first backfill.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongbookLanguages (
    SongbookId  INT UNSIGNED NOT NULL,
    Language    VARCHAR(35)  NOT NULL COMMENT 'IETF BCP 47 tag — same shape as tblSongbooks.Language',
    IsPrimary   TINYINT(1)   NOT NULL DEFAULT 0
                COMMENT 'Display-default language for this songbook; exactly one row per songbook should carry IsPrimary=1',
    SortOrder   SMALLINT     NOT NULL DEFAULT 0
                COMMENT 'Render order in chip-list editor; lower comes first',
    CreatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (SongbookId, Language),
    KEY idx_Language (Language),
    KEY idx_Primary  (SongbookId, IsPrimary),
    CONSTRAINT fk_sblang_songbook FOREIGN KEY (SongbookId)
        REFERENCES tblSongbooks(Id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblSongLanguages (#778) — multi-language song chip-list.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongLanguages (
    SongId      VARCHAR(20) NOT NULL,
    Language    VARCHAR(35) NOT NULL,
    IsPrimary   TINYINT(1)  NOT NULL DEFAULT 0,
    SortOrder   SMALLINT    NOT NULL DEFAULT 0,
    CreatedAt   TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (SongId, Language),
    KEY idx_Language (Language),
    KEY idx_Primary  (SongId, IsPrimary),
    CONSTRAINT fk_slang_song FOREIGN KEY (SongId)
        REFERENCES tblSongs(SongId)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- FAMILY: SONGBOOK SERIES & COMPILERS (#782 / #831)
--
-- Two songbook-level relationships that are easy to confuse with each other
-- and with tblSongbooks.ParentSongbookId declared at the top of this file:
--   ParentSongbookId ..... VERTICAL. This book is a translation / edition /
--                          abridgement OF that one. Self-FK, SET NULL.
--   tblSongbookSeries .... HORIZONTAL. These books are peers in a set
--                          (Songs of Fellowship 1, 2, 3). Many-to-many.
--   tblSongbookCompilers . credits the PEOPLE who assembled a hymnal, as
--                          opposed to the per-song credit tables.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- tblSongbookSeries (#782) — peer-to-peer songbook collections (Songs of
-- Fellowship volumes, themed compilations).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongbookSeries (
    Id           INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    Name         VARCHAR(120)  NOT NULL,
    Description  VARCHAR(255)  NOT NULL DEFAULT '',
    Slug         VARCHAR(120)  NOT NULL UNIQUE
                 COMMENT 'URL-safe lowercase form for /series/<slug> public listing pages',
    CreatedAt    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    Colour       VARCHAR(7)    NOT NULL DEFAULT '' COMMENT 'Badge colour hex #RRGGBB inherited by all member songbooks; empty = theme default (#1181)',
    Isbn                 VARCHAR(20) NULL DEFAULT NULL COMMENT 'ISBN for the series as a whole (Feature 3, epic #1765); mirrors tblSongbooks.Isbn (#672). Deliberately absent from tblCatalogues - see the plan adversarial notes section',
    Issn                 VARCHAR(20) NULL DEFAULT NULL COMMENT 'ISSN for the series as a whole (Feature 3, epic #1765); a series is the one publication entity of the three that commonly carries a serial number. Deliberately absent from tblSongbooks and tblCatalogues',
    ArkId                 VARCHAR(80) NULL DEFAULT NULL COMMENT 'Archival Resource Key for the series (Feature 3, epic #1765); mirrors tblSongbooks.ArkId (#672)',
    OpenLibraryWorkId     VARCHAR(20) NULL DEFAULT NULL COMMENT 'Open Library Work id for the series, e.g. OL102749W (Feature 3, epic #1765); mirrors tblSongbooks.OpenLibraryWorkId',
    OpenLibraryEditionId  VARCHAR(20) NULL DEFAULT NULL COMMENT 'Open Library Edition id for the series, e.g. OL7357422M (Feature 3, epic #1765); mirrors tblSongbooks.OpenLibraryEditionId',
    KEY idx_Name (Name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblSongbookSeriesMembership (#782) — songbook ↔ series many-to-many.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongbookSeriesMembership (
    SeriesId     INT UNSIGNED NOT NULL,
    SongbookId   INT UNSIGNED NOT NULL,
    SortOrder    SMALLINT     NOT NULL DEFAULT 0
                 COMMENT 'Display order within the series (e.g. volume 1 → 10, volume 2 → 20, …)',
    Note         VARCHAR(120) NOT NULL DEFAULT ''
                 COMMENT 'Optional free-text annotation, e.g. "published 1998" or "combined edition"',
    CreatedAt    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (SeriesId, SongbookId),
    KEY idx_member (SongbookId),
    CONSTRAINT fk_sbsm_series   FOREIGN KEY (SeriesId)
        REFERENCES tblSongbookSeries(Id) ON DELETE CASCADE,
    CONSTRAINT fk_sbsm_songbook FOREIGN KEY (SongbookId)
        REFERENCES tblSongbooks(Id)      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblSongbookCompilers (#831) — many-to-many credit at the songbook level
-- (compilers / editors of a hymnal, e.g. Mission Praise → Horrobin & Leavers).
-- MusicianId (renamed from CreditPersonId by #1741 P2) FKs to tblMusicians.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongbookCompilers (
    Id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    SongbookId      INT UNSIGNED NOT NULL,
    MusicianId      INT UNSIGNED NOT NULL,
    SortOrder       INT UNSIGNED NOT NULL DEFAULT 0,
    Note            VARCHAR(255) NULL,
    CreatedAt       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_book_musician (SongbookId, MusicianId),
    INDEX idx_book     (SongbookId),
    INDEX idx_musician (MusicianId),

    CONSTRAINT fk_compiler_book
        FOREIGN KEY (SongbookId) REFERENCES tblSongbooks(Id) ON DELETE CASCADE,
    CONSTRAINT fk_compiler_musician
        FOREIGN KEY (MusicianId) REFERENCES tblMusicians(Id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- FAMILY: SONG MEDIA (#853)
-- The one table in this schema that stores FILE BYTES. Note the hybrid split
-- below and the reason for it: small, cacheable, rights-sensitive files (PDF,
-- MIDI, MusicXML) go in the MEDIUMBLOB so they inherit the database's backup
-- and transaction story, while audio — large, streamed, range-requested —
-- stays on disk with only its path here. StorageBackend records which, and a
-- row is meaningless without it. Sha256 is indexed so a re-upload of the same
-- file is detectable rather than silently duplicated.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- tblSongMedia (#853) — per-song accompanying files (audio / sheet-music /
-- midi / musicxml). Hybrid storage: PDF / MIDI / MusicXML → MEDIUMBLOB,
-- audio → filesystem under appWeb/uploads/songs/<hash>.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongMedia (
    Id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    SongId          VARCHAR(20)  NOT NULL,
    Kind            VARCHAR(20)  NOT NULL COMMENT 'audio | sheet-music | midi | musicxml | notation-source | pdf (app-validated via SongMediaStorage::allKinds(); widened from ENUM #1090 so new media kinds — e.g. Forte .fnf notation-source — need no ALTER)',
    StorageBackend  ENUM('filesystem','database') NOT NULL,
    FileName        VARCHAR(255) NOT NULL,
    MimeType        VARCHAR(127) NOT NULL,
    SizeBytes       BIGINT UNSIGNED NOT NULL,
    Sha256          CHAR(64)     NOT NULL,
    Content         MEDIUMBLOB   NULL,
    StoragePath     VARCHAR(255) NULL,
    Annotation      VARCHAR(255) NULL,
    SortOrder       INT UNSIGNED NOT NULL DEFAULT 0,
    UploadedBy      INT UNSIGNED NULL,
    UploadedAt      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_song_kind (SongId, Kind, SortOrder),
    INDEX idx_kind      (Kind),
    INDEX idx_sha256    (Sha256),

    CONSTRAINT fk_media_song
        FOREIGN KEY (SongId) REFERENCES tblSongs(SongId) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- FAMILY: WORKS (#840) — the composition above the songs
--
-- The third grouping axis, and the one people mix up with the other two.
--   tblSongLinks .. "these ROWS are the same hymn in different books" — flat,
--                   curator-asserted, one group per song.
--   tblWorks ...... "these songs are renderings of one COMPOSITION" — a real
--                   entity with its own identity (ISWC, MusicBrainz Work MBID),
--                   its own page at /work/<slug>, and self-nesting via
--                   ParentWorkId for suites and movements.
--   tblTunes ...... (further down, #1090) the MELODY, which is a different
--                   composition again — one tune carries many texts.
-- A song can sit in all three at once and that is not a contradiction.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- tblWorks (#840) — composition grouping. Mirrors MusicBrainz Work ↔
-- Recording: one Work can span multiple tblSongs (different songbooks /
-- arrangements / translations of the same underlying composition).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblWorks (
    Id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ParentWorkId  INT UNSIGNED NULL,
    Iswc          CHAR(15)     NULL,
    MusicBrainzWorkMBID VARCHAR(50) NULL DEFAULT NULL COMMENT 'MusicBrainz Work MBID (composition identity). Lives on the work, NOT the recording-level identity map, so work-dedup has one home (#1066 Theme D / stress-C2)',
    Title         VARCHAR(255) NOT NULL,
    Slug          VARCHAR(80)  NOT NULL,
    /* Identity + descriptive fields (#1741 P1 §2.2). Ccli is NULL (not '')
       so absent values coexist under uq_ccli — every NULL is distinct.
       TuneName/TuneId mirror the tblSongs pair; fk_Works_Tune is a
       trailing ALTER below (tblTunes is declared later in this file). */
    Ccli          VARCHAR(50)  NULL DEFAULT NULL COMMENT 'CCLI Work Number (#1741 P1) — the future /ccli/ resolver''s Work-first lookup key. NULL rather than empty string so absent values coexist under uq_ccli (every NULL is distinct)',
    Bowi          VARCHAR(30)  NULL DEFAULT NULL COMMENT 'Best Open Work Identifier — Luminate Data''s open ISWC alternative (media-identifiers-spec.md §4b/§4c, #1741 D5); the future /bowi/ resolver''s lookup key. NULL rather than empty string so absent values coexist under uq_bowi (every NULL is distinct), mirrors uq_iswc/uq_ccli.',
    Subtitle      VARCHAR(255) NULL DEFAULT NULL COMMENT 'Optional work subtitle (#1741 P1)',
    Disambiguation VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Short parenthetical to distinguish same-named works (#1741 P1)',
    TuneName      VARCHAR(120) NULL DEFAULT NULL COMMENT 'Traditional tune name mirror (#1741 P1); denorm display string, same pattern as tblSongs.TuneName. Canonical entity is tblTunes via TuneId',
    TuneId        INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK to tblTunes.Id (#1741 P1); mirrors tblSongs.TuneId. FK added via trailing ALTER — tblTunes is defined later in this file (same reason as fk_Songs_Tune)',
    FirstPublishedYear SMALLINT UNSIGNED NULL DEFAULT NULL COMMENT 'Year of first publication (#1741 P1); SMALLINT not MySQL YEAR — YEAR starts 1901 and hymn works predate it. Same column added to tblSongs in the same P1 batch (Song AND Work editors both need it, rule #20)',
    CopyrightYears VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'As-printed copyright year(s), free text e.g. "1978, 1987, 2011" (#1741 P1)',
    CopyrightHolder VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Copyright holder name (#1741 P1)',
    Notes         TEXT         NULL,
    /* Composition origin — VARCHAR mirror + FK into tblPlaces. */
    OriginCity    VARCHAR(255) NULL,
    OriginCityId  INT UNSIGNED NULL,
    CreatedAt     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_slug   (Slug),
    UNIQUE KEY uq_iswc   (Iswc),
    UNIQUE KEY uq_mbwork (MusicBrainzWorkMBID),
    UNIQUE KEY uq_ccli   (Ccli),
    UNIQUE KEY uq_bowi   (Bowi),
    INDEX      idx_title (Title),
    INDEX      idx_parent (ParentWorkId),
    INDEX      idx_OriginCityId (OriginCityId),
    INDEX      idx_TuneId (TuneId),

    CONSTRAINT fk_work_parent
        FOREIGN KEY (ParentWorkId) REFERENCES tblWorks(Id) ON DELETE SET NULL,
    CONSTRAINT fk_Works_OriginCity
        FOREIGN KEY (OriginCityId) REFERENCES tblPlaces(Id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblWorkSongs (#840) — tblWorks ↔ tblSongs membership.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblWorkSongs (
    WorkId       INT UNSIGNED NOT NULL,
    SongId       VARCHAR(20)  NOT NULL,
    IsCanonical  TINYINT(1)   NOT NULL DEFAULT 0,
    SortOrder    INT UNSIGNED NOT NULL DEFAULT 0,
    Note         VARCHAR(255) NULL,
    CreatedAt    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (WorkId, SongId),
    INDEX idx_song (SongId),
    INDEX idx_work_canonical (WorkId, IsCanonical),

    CONSTRAINT fk_work_song_work
        FOREIGN KEY (WorkId) REFERENCES tblWorks(Id)        ON DELETE CASCADE,
    CONSTRAINT fk_work_song_song
        FOREIGN KEY (SongId) REFERENCES tblSongs(SongId)    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblWorkExternalLinks (#840) — per-work external-link rows.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblWorkExternalLinks (
    Id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    WorkId      INT UNSIGNED NOT NULL,
    LinkTypeId  INT UNSIGNED NOT NULL,
    Url         VARCHAR(2048) NOT NULL,
    Note        VARCHAR(255) NULL,
    SortOrder   INT UNSIGNED NOT NULL DEFAULT 0,
    Verified    TINYINT(1)   NOT NULL DEFAULT 0,
    CreatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_work (WorkId),
    INDEX idx_type (LinkTypeId),

    CONSTRAINT fk_link_work
        FOREIGN KEY (WorkId)     REFERENCES tblWorks(Id)             ON DELETE CASCADE,
    CONSTRAINT fk_link_type_work
        FOREIGN KEY (LinkTypeId) REFERENCES tblExternalLinkTypes(Id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- INTERCHANGE FIDELITY + INGEST HARDENING + IDENTITY MAP (#1066)
-- One-pass forward-looking schema for the multi-format interchange, the
-- timed-lyrics ingest pipeline, API-key hardening, and cross-system identity.
-- Tables are additive + dormant until the consuming features land; shipping
-- them together avoids a second migration round as those features are built.
--
-- ----------------------------------------------------------------------------
-- READ THIS BEFORE DELETING ANYTHING IN THIS BATCH — or in the #1088, #1090,
-- presentation-theme (#1168) or gating (#1481) batches that follow.
--
-- These tables were shipped AHEAD of the code that uses them. That is not
-- rot; it is the design (CLAUDE.md rule #20).
--
-- ⚠️ READ THE NEXT SENTENCE BEFORE TRUSTING THIS BLOCK. An earlier draft said
-- these batches have "no rows, no reader and no writer" FULL STOP, and that is
-- FALSE for four of the five named above — #1088 has full CRUD in
-- includes/line_enrichment.php plus a shipped editor panel, #1066's
-- tblApiKeyUsage is read and written by includes/api_keys.php, #1090's
-- tblTunes is JOINed in SongData.php, and #1481's tblGatingRules is read by
-- includes/gating_rules.php behind /manage/feature-gating.
--
-- That wrong sentence was worse than no comment, because this block's whole
-- argument is "grep for callers, find none, delete — that test misleads you
-- here". A reader who greps WILL find callers, and a comment insisting they
-- will not simply destroys its own credibility at the moment it matters.
--
-- The honest form: a batch is dormant UNTIL its consuming feature lands, and
-- several of these have since landed. Check the specific table before
-- concluding anything about it. When a feature FAMILY needs schema, the
-- final shape is worked out up front, stress-tested against "what would force
-- a second migration?", and shipped as ONE additive, idempotent, dormant
-- batch — instead of dribbling out an ALTER every time the feature is tweaked.
-- An empty table is the cheap half of that trade; the expensive half was
-- already paid in design.
--
-- So the honest state of a table here is "waiting", not "abandoned", and the
-- usual dead-code test — grep for callers, find none, delete — gives exactly
-- the wrong answer. Each block below says which feature it is waiting for.
-- If that feature is genuinely cancelled, removing the table is a decision
-- with an issue attached, not a tidy-up.
--
-- The same reasoning explains the shapes you will see repeatedly:
--   * every growable vocabulary is VARCHAR with an app-level allow-list, never
--     ENUM, because adding an ENUM value IS the second migration;
--   * a discriminator column sits inside a UNIQUE key before anything needs it
--     (tblApiKeyUsage.Scope, tblGatingRules.Scope), so per-scope limits later
--     cost nothing;
--   * a (Source, SourceRef) UNIQUE makes external re-import idempotent, and
--     because MySQL treats NULLs as distinct, manually-created rows coexist;
--   * TTLs are DATETIME, never TIMESTAMP, so they are never reinterpreted
--     against a session time zone.
-- ----------------------------------------------------------------------------
-- ============================================================================

-- ----------------------------------------------------------------------------
-- tblSongArrangements (#1066 Theme E) — named reorderings + repetitions of a
-- song's components (the PP7 "arrangement" concept). Coexists with the simpler
-- tblSongs.ArrangementJson: when an IsDefault=1 row exists it wins, else code
-- falls back to ArrangementJson (soft-deprecation, no backfill, no drop).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongArrangements (
    Id                 INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    SongId             VARCHAR(20)   NOT NULL COMMENT 'FK to tblSongs.SongId',
    Name               VARCHAR(255)  NOT NULL COMMENT 'Arrangement name, e.g. Default, Verse-only, Key of G',
    IsDefault          TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1 = canonical arrangement for PP7 export / presentation view',
    ComponentOrderJson JSON          NOT NULL COMMENT 'Array of component indices defining playback sequence, e.g. [0,1,1,2,3]',
    Description        TEXT          NULL DEFAULT NULL COMMENT 'Free-text notes about this arrangement',
    KeySignature       VARCHAR(10)   NULL DEFAULT NULL COMMENT 'Structured key, e.g. G, Bb, F#m — home for the future transpose feature',
    CapoFret           TINYINT       NULL DEFAULT NULL COMMENT 'Capo fret position for chord display (0-12); NULL = none',
    MusicRightsStatus  VARCHAR(20)   NULL DEFAULT NULL COMMENT 'RESERVED DORMANT (#1769 P1 / #1768 Q2): arrangement-grain music-rights status — pd | licensed | restricted (app-validated growable vocabulary, VARCHAR never ENUM, rule #20). NULL = inherit the song-grain facts (tblSongs.MusicPublicDomain + MusicRightsLicenceKey). Nothing reads this yet; the arrangement-grain gate flips on later with zero migration',
    MusicRightsLicenceKey VARCHAR(30) NULL DEFAULT NULL COMMENT 'RESERVED DORMANT (#1769 P1 / #1768 Q2): arrangement-grain requirement override — tblLicenceTypes.LicenceKey needed for music-reproduction actions on THIS arrangement. NULL = inherit song-grain. NO FK (degrade-safe, matches tblSongs.MusicRightsLicenceKey)',
    CreatedAt          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_SongName     (SongId, Name),
    INDEX      idx_SongDefault (SongId, IsDefault),

    CONSTRAINT fk_SongArrangements_Song
        FOREIGN KEY (SongId) REFERENCES tblSongs(SongId)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Named song arrangements (#1066 Theme E). PP7 exporter reads IsDefault=1.';


-- ----------------------------------------------------------------------------
-- tblLyricsConflicts (#1066 Theme B) — detected conflicts between an existing
-- curated version and an incoming ingest (ISRC matches but lyrics/title differ,
-- etc.), captured for moderator review instead of a silent UPSERT clobber.
-- Moderation-vocabulary columns are VARCHAR, not ENUM, so new resolution /
-- conflict kinds (escalate, split, defer…) need no ALTER (stress-B3).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblLyricsConflicts (
    Id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    GroupId          INT UNSIGNED NOT NULL COMMENT 'Conflict group; rows sharing GroupId form one detected conflict',
    SongId           VARCHAR(20)  NOT NULL COMMENT 'FK to tblSongs.SongId — the existing/curator version',
    IncomingLyricsId INT UNSIGNED NULL DEFAULT NULL COMMENT 'tblLyrics.Id of the ingest source; NULL if curator-curator',
    IncomingSource   VARCHAR(100) NOT NULL COMMENT 'Source of incoming data, e.g. applemusic-ttml, user-submission',
    ConflictType     VARCHAR(30)  NOT NULL COMMENT 'lyrics_mismatch | isrc_mismatch | title_mismatch | artist_mismatch | partial_overlap (app-validated; VARCHAR so new kinds need no ALTER)',
    DescriptionText  TEXT         NOT NULL COMMENT 'Human-readable conflict summary',
    ExistingData     JSON         NOT NULL COMMENT 'Snapshot of current tblLyrics/tblSongs data for the diff UI',
    IncomingData     JSON         NOT NULL COMMENT 'Snapshot of the incoming ingest data',
    ResolutionAction VARCHAR(30)  NOT NULL DEFAULT 'unresolved' COMMENT 'unresolved | accept_incoming | keep_existing | manual_merge | deduplicate | escalate | split | defer (app-validated)',
    ResolvedBy       INT UNSIGNED NULL DEFAULT NULL COMMENT 'tblUsers.Id of the resolver',
    ResolvedAt       DATETIME     NULL DEFAULT NULL,
    ResolveNote      TEXT         NULL DEFAULT NULL,
    CreatedAt        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_GroupId          (GroupId),
    INDEX idx_SongId           (SongId),
    INDEX idx_IncomingLyricsId (IncomingLyricsId),
    INDEX idx_ConflictType     (ConflictType),
    INDEX idx_ResolutionAction (ResolutionAction),

    CONSTRAINT fk_Conflict_Song
        FOREIGN KEY (SongId) REFERENCES tblSongs(SongId) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_Conflict_IncomingLyrics
        FOREIGN KEY (IncomingLyricsId) REFERENCES tblLyrics(Id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_Conflict_Resolver
        FOREIGN KEY (ResolvedBy) REFERENCES tblUsers(Id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Ingest/curation conflicts queued for moderator resolution (#1066 Theme B).';


-- ----------------------------------------------------------------------------
-- tblLyricsReviewQueue (#1066 Theme B) — moderation gate between ingest and the
-- public read path. One row per lyrics record awaiting review; AssignedTo lets
-- a multi-curator team claim rows (stress-B2). ConflictGroupId is a SOFT link
-- to tblLyricsConflicts.GroupId (GroupId is non-unique → cannot be a hard FK).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblLyricsReviewQueue (
    Id              INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    LyricsId        INT UNSIGNED  NOT NULL COMMENT 'FK to tblLyrics; cascade-deleted with the lyrics',
    SongId          VARCHAR(20)   NOT NULL COMMENT 'Denorm of tblLyrics.SongId for direct queue filtering',
    Source          VARCHAR(100)  NOT NULL COMMENT 'Denorm of tblLyrics.Source',
    SourceUrl       VARCHAR(1000) NULL DEFAULT NULL,
    Priority        INT           NOT NULL DEFAULT 0 COMMENT '-1 low / 0 normal / +1 high; queue sorts Priority DESC, CreatedAt ASC',
    ModerationNote  TEXT          NULL DEFAULT NULL,
    QueuedReason    VARCHAR(30)   NOT NULL DEFAULT 'curator_submitted' COMMENT 'curator_submitted | conflict_detected | data_quality_flag | manual_review (app-validated)',
    ConflictGroupId INT UNSIGNED  NULL DEFAULT NULL COMMENT 'Soft link to tblLyricsConflicts.GroupId (not a hard FK — GroupId is non-unique)',
    AssignedTo      INT UNSIGNED  NULL DEFAULT NULL COMMENT 'tblUsers.Id who claimed this row (multi-curator concurrency)',
    AssignedAt      DATETIME      NULL DEFAULT NULL,
    ReviewedBy      INT UNSIGNED  NULL DEFAULT NULL COMMENT 'tblUsers.Id of reviewer',
    ReviewedAt      DATETIME      NULL DEFAULT NULL,
    ReviewDecision  VARCHAR(20)   NULL DEFAULT NULL COMMENT 'approved | rejected | needs_edits | deferred (app-validated)',
    ReviewNote      TEXT          NULL DEFAULT NULL,
    CreatedAt       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_LyricsId      (LyricsId),
    INDEX idx_SongId           (SongId),
    INDEX idx_ReviewDecision   (ReviewDecision),
    INDEX idx_Priority         (Priority, CreatedAt),
    INDEX idx_QueuedReason     (QueuedReason),
    INDEX idx_ConflictGroupId  (ConflictGroupId),
    INDEX idx_AssignedTo       (AssignedTo),

    CONSTRAINT fk_LyricsQueue_Lyrics
        FOREIGN KEY (LyricsId) REFERENCES tblLyrics(Id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_LyricsQueue_Song
        FOREIGN KEY (SongId) REFERENCES tblSongs(SongId) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_LyricsQueue_Assignee
        FOREIGN KEY (AssignedTo) REFERENCES tblUsers(Id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_LyricsQueue_Reviewer
        FOREIGN KEY (ReviewedBy) REFERENCES tblUsers(Id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Moderation queue for ingested/submitted lyrics (#1066 Theme B).';


-- ----------------------------------------------------------------------------
-- tblApiKeyUsage (#1066 Theme B) — rolling rate-limit counters per API key.
-- The Scope column in the unique key reserves per-endpoint limiting without a
-- future migration (default '' = global) (stress-B4).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblApiKeyUsage (
    Id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ApiKeyId     INT UNSIGNED    NOT NULL COMMENT 'FK to tblApiKeys.Id',
    Scope        VARCHAR(64)     NOT NULL DEFAULT '' COMMENT 'Per-scope window key; "" = global. Reserves per-endpoint limits without a migration',
    WindowType   VARCHAR(10)     NOT NULL COMMENT 'minute | day — rolling-window granularity',
    WindowStart  DATETIME        NOT NULL COMMENT 'Window start (minute-truncated, or UTC day)',
    RequestCount INT UNSIGNED    NOT NULL DEFAULT 1,
    UpdatedAt    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_KeyWindow (ApiKeyId, Scope, WindowType, WindowStart),
    INDEX      idx_Window   (WindowStart),

    CONSTRAINT fk_Usage_ApiKey
        FOREIGN KEY (ApiKeyId) REFERENCES tblApiKeys(Id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-key rolling rate-limit counters (#1066 Theme B).';


-- ----------------------------------------------------------------------------
-- tblApiKeyIdempotency (#1066 Theme B) — cached responses keyed by a client
-- Idempotency-Key so retried POSTs (a MeedyaDL re-push) are safe. ExpiresAt is
-- DATETIME (not TIMESTAMP) to dodge MySQL 8 implicit-default magic (stress-A3).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblApiKeyIdempotency (
    Id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ApiKeyId       INT UNSIGNED    NOT NULL COMMENT 'FK to tblApiKeys.Id',
    IdempotencyKey VARCHAR(255)    NOT NULL COMMENT 'Client-provided idempotency key',
    RequestHash    CHAR(64)        NOT NULL COMMENT 'SHA-256 of the request body',
    ResponseData   MEDIUMTEXT      NOT NULL COMMENT 'Cached response payload (JSON)',
    HttpStatus     INT UNSIGNED    NOT NULL DEFAULT 200,
    CreatedAt      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ExpiresAt      DATETIME        NOT NULL COMMENT 'TTL expiry (fixed 24h from creation); rows past this are cleanup-eligible',

    UNIQUE KEY uq_KeyHashCombo   (ApiKeyId, IdempotencyKey, RequestHash),
    INDEX      idx_Expires       (ExpiresAt),
    INDEX      idx_ApiKeyCreated (ApiKeyId, CreatedAt),

    CONSTRAINT fk_Idempotency_ApiKey
        FOREIGN KEY (ApiKeyId) REFERENCES tblApiKeys(Id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Idempotency-key response cache for safe ingest retries (#1066 Theme B).';


-- ----------------------------------------------------------------------------
-- tblApiKeyRequests (#1064 / API platform Phase D) — self-serve key requests:
-- an admin requests a key (label + justification, safe scope), a global admin
-- approves (mints + links ApiKeyId) or rejects. Status is VARCHAR (rule #20).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblApiKeyRequests (
    Id            INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    RequesterId   INT UNSIGNED  NOT NULL COMMENT 'tblUsers.Id of the admin who requested the key',
    Label         VARCHAR(120)  NOT NULL COMMENT 'Human label for the requested key',
    Scope         VARCHAR(255)  NOT NULL DEFAULT 'catalogue:read' COMMENT 'Requested scope (self-serve allow-list, app-validated)',
    Justification VARCHAR(1000) NOT NULL DEFAULT '' COMMENT 'Why the requester needs the key',
    Status        VARCHAR(20)   NOT NULL DEFAULT 'pending' COMMENT 'pending | approved | rejected (VARCHAR, rule #20)',
    ReviewedBy    INT UNSIGNED  NULL DEFAULT NULL COMMENT 'tblUsers.Id of the global admin who reviewed',
    ReviewedAt    DATETIME      NULL DEFAULT NULL COMMENT 'When it was reviewed (UTC)',
    ReviewNote    VARCHAR(500)  NOT NULL DEFAULT '' COMMENT 'Optional reviewer note (esp. on rejection)',
    ApiKeyId      INT UNSIGNED  NULL DEFAULT NULL COMMENT 'tblApiKeys.Id minted on approval',
    CreatedAt     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_Requester (RequesterId),
    INDEX idx_Status    (Status),
    CONSTRAINT fk_ApiKeyReq_Requester FOREIGN KEY (RequesterId) REFERENCES tblUsers(Id)    ON DELETE CASCADE,
    CONSTRAINT fk_ApiKeyReq_Reviewer  FOREIGN KEY (ReviewedBy)  REFERENCES tblUsers(Id)    ON DELETE SET NULL,
    CONSTRAINT fk_ApiKeyReq_ApiKey    FOREIGN KEY (ApiKeyId)    REFERENCES tblApiKeys(Id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Self-serve API-key requests + their review/approval state (Phase D).';


-- ----------------------------------------------------------------------------
-- tblSongIdentityMap (#1066 Theme D) — cross-system recording identity:
-- iHymns SongId <-> MusicBrainz recording / Spotify track / Genius / ISRC.
-- SongId is a NON-unique index on purpose: one song legitimately maps to
-- several recordings (explicit vs clean ISRC, live vs studio Spotify) — the
-- same N-recordings-per-song world tblLyrics already models. Uniqueness lives
-- on the external-id columns (stress-A1). Composition identity (Work MBID)
-- lives on tblWorks, not here (stress-C2). Change history goes to the existing
-- tblActivityLog, not a dedicated table (stress-C1). The iLyricsDB link column
-- + bridge views are GATED on the DB-merge decision (see issue #1066-gated).
--
-- #1749 FULL UNIFICATION (D-3, decided default, non-blocking) — this table's
-- four provider columns are now FROZEN LEGACY, not actively synced denorms:
-- the #1747 D5 backfill absorbed them one-way into tblSongExternalIds (the
-- new recording-ID authority), nothing reads or writes these four columns
-- live today (0.7 in the build spec — no SELECT/INSERT/UPDATE anywhere
-- outside migrations/probes), and the table itself stays gated on the #1010
-- iLyricsDB DB-merge decision above. Do NOT build a live store->identity-map
-- sync — see .claude/catalogue-1741-1749-unification-plan.md §2.3 for the
-- full reasoning (shape incompatibility: this table's provider columns carry
-- a TABLE-WIDE UNIQUE key, the store's uq_Song_Type_Value is PER-SONG, so a
-- faithful sync needs cross-song collision handling this table's dormancy
-- doesn't justify building yet). Removal is gated on #1010, same as always.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongIdentityMap (
    Id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    SongId                   VARCHAR(20)  NOT NULL COMMENT 'FK to tblSongs.SongId (the iHymns song); NON-unique — a song may map to several recordings',
    MusicBrainzRecordingMBID VARCHAR(50)  NULL DEFAULT NULL COMMENT 'MusicBrainz recording MBID',
    SpotifyTrackId           VARCHAR(50)  NULL DEFAULT NULL COMMENT 'Spotify track id/URI',
    GeniusTrackId            VARCHAR(50)  NULL DEFAULT NULL COMMENT 'Genius track id',
    IsrcCode                 VARCHAR(15)  NULL DEFAULT NULL COMMENT 'Denorm of tblSongs.Isrc for join-free lookups (app keeps both in sync)',
    /* ⚠ These two are ENUMs inside the very batch that codified "growable
       vocabulary is VARCHAR, never ENUM" — the exception is not explained
       anywhere, and SourceOfTruth in particular clearly grows: adding the
       next external system (Apple Music, Hymnary, Discogs) means an ALTER,
       which is exactly the second migration rule #20 exists to avoid.
       Compare tblSongLinkSuggestions.Signal a few hundred lines up, which
       enumerates the SAME kind of thing as a VARCHAR and says why. Treat this
       as a known inconsistency to converge, not as precedent — and note that
       converging it is a paired schema.sql + migration change, since the
       column definitions here must stay byte-identical to their mirror. */
    SourceOfTruth            ENUM('ihymns','ilyricsdb','musicbrainz','spotify','genius','manual') NOT NULL DEFAULT 'ihymns',
    MappingStatus            ENUM('pending','verified','conflict','deprecated') NOT NULL DEFAULT 'pending',
    VerifiedAt               DATETIME     NULL DEFAULT NULL,
    VerifiedBy               INT UNSIGNED NULL DEFAULT NULL COMMENT 'tblUsers.Id; NULL = auto-verified',
    Notes                    TEXT         NULL DEFAULT NULL,
    CreatedAt                TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt                TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX      idx_SongId        (SongId),
    UNIQUE KEY uk_MBRecording    (MusicBrainzRecordingMBID),
    UNIQUE KEY uk_Spotify        (SpotifyTrackId),
    UNIQUE KEY uk_Genius         (GeniusTrackId),
    UNIQUE KEY uk_Isrc           (IsrcCode),
    INDEX      idx_SourceOfTruth (SourceOfTruth),
    INDEX      idx_StatusVerified (MappingStatus, VerifiedAt),

    CONSTRAINT fk_IdentityMap_Song
        FOREIGN KEY (SongId) REFERENCES tblSongs(SongId) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_IdentityMap_VerifiedBy
        FOREIGN KEY (VerifiedBy) REFERENCES tblUsers(Id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cross-system recording identity map (#1066 Theme D). iLyricsDB link column gated on the DB-merge decision.';


-- ----------------------------------------------------------------------------
-- tblSongExternalIds (#1741 D5) — key/value recording/release/product ID
-- store. Luminate's own dashboard models Song -> many Recordings -> many
-- provider IDs (~13 DSP + ~10 database + legacy codes per
-- .claude/media-identifiers-spec.md §4b) — a one-column-per-provider table
-- (tblSongIdentityMap's shape, immediately above) cannot absorb that
-- (rule #28); this key/value table can, at the cost of one additional row
-- per identifier instead of an ALTER per provider. IdScope/IdType are
-- VARCHAR + app-validated against includes/media_identifiers.php's central
-- map (rule #20 — never ENUM). Decision A=c (media-identifiers-spec.md §4c):
-- release/product IDs (ICPN/GRid/UPC/EAN/catalog-number/MC_RELEASE/
-- MC_RELEASE_GROUP/MC_PRODUCT) are stored on THIS same recording-grain row as
-- ingest provenance (IdScope='release'|'product'), not a separate tblReleases
-- entity — iHymns is a hymn-lyrics catalogue, not a commercial release
-- database (owner-accepted scoping, spec §4c). The 4 existing
-- tblSongIdentityMap columns (MusicBrainzRecordingMBID/SpotifyTrackId/
-- GeniusTrackId/IsrcCode) are GRANDFATHERED READS — untouched, not migrated
-- here; a later backfill MAY populate this table from them (out of scope for
-- this additive/dormant batch). Nothing reads or writes this table yet — the
-- P3 alias-URL resolver is what starts consuming it (#1741 D5).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongExternalIds (
    Id          INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    SongId      VARCHAR(20)   NOT NULL COMMENT 'FK to tblSongs.SongId — the recording-grain row this identifier belongs to (or its release/product context under decision A=c, media-identifiers-spec.md §4c)',
    IdScope     VARCHAR(20)   NOT NULL COMMENT 'recording | song | work | release | product | party (app-validated against includes/media_identifiers.php SONG_EXTERNAL_ID_SCOPES; VARCHAR not ENUM, rule #20). work/party are reserved — not populated by this phase',
    IdType      VARCHAR(40)   NOT NULL COMMENT 'Provider/database/legacy identifier key, e.g. isrc | spotify | musicbrainz-recording | icpn | mc-release | … — app-validated against includes/media_identifiers.php RECORDING_EXTERNAL_ID_TYPES (VARCHAR not ENUM, rule #20; #1741 D5)',
    IdValue     VARCHAR(191)  NOT NULL COMMENT 'The identifier value as issued by the provider/authority. VARCHAR(191) keeps a utf8mb4 UNIQUE index under the legacy 767-byte-per-column InnoDB limit',
    Source      VARCHAR(40)   NULL DEFAULT NULL COMMENT 'Provenance system that supplied this row, e.g. ihymns | musicbrainz | luminate | manual (free text, mirrors tblLyrics.Source style)',
    SourceRef   VARCHAR(191)  NULL DEFAULT NULL COMMENT 'External primary id from Source for idempotent re-import / dedup; NULL for manual entry',
    CreatedAt   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_Song_Type_Value (SongId, IdType, IdValue),
    INDEX      idx_Type_Value     (IdType, IdValue),
    INDEX      idx_SongId         (SongId),

    CONSTRAINT fk_SongExternalIds_Song
        FOREIGN KEY (SongId) REFERENCES tblSongs(SongId) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Key/value recording/release/product external-ID store (#1741 D5) — the Q5 successor absorbing DSP/database/legacy IDs without a per-provider ALTER.';


-- ----------------------------------------------------------------------------
-- v_ChristianSongs (#1066 Theme C) — lightweight read-layer fence so iHymns
-- surfaces only Christian songs (sb.IsChristian=1) while a shared core could
-- read unfiltered. Deliberately id/title/songbook/flags ONLY — no LyricsText /
-- ArrangementJson (a fence, not a corpus materialiser; CLAUDE.md §17) and no
-- NormalizedTitle (keeps the view independent of migration ordering)
-- (stress-A4/A5/D4). SQL SECURITY INVOKER so the view runs with the app user's
-- privileges, not the migration-running admin's.
-- NOTE: schema_audit.php does not parse views, so this object is NOT covered by
-- test-schema-coverage.php — keep it in sync with the migration by hand.
-- ----------------------------------------------------------------------------
CREATE OR REPLACE
    SQL SECURITY INVOKER
    VIEW v_ChristianSongs AS
SELECT
    s.Id, s.SongId, s.Number, s.Title, s.SongbookAbbr,
    sb.Id AS SongbookId, sb.Name AS SongbookName, sb.Abbreviation AS SongbookAbbreviation, sb.IsChristian,
    s.Language, s.Copyright, s.OriginCity, s.OriginCityId, s.TuneName,
    s.Ccli, s.Iswc, s.Isrc, s.Upc, s.Verified,
    s.LyricsPublicDomain, s.MusicPublicDomain, s.IsExplicit, s.Genre,
    s.HasAudio, s.HasSheetMusic,
    s.CreatedAt, s.UpdatedAt
FROM tblSongs s
JOIN tblSongbooks sb ON s.SongbookAbbr = sb.Abbreviation
WHERE sb.IsChristian = 1;


-- ============================================================================
-- PER-LINE LYRIC ENRICHMENT (#1088) — translations + Genius-style annotations
-- Two line-grain tables anchored on tblLyricLines.Id (BIGINT), siblings to
-- tblLyricWords/tblLyricSyllables. Additive + dormant until the consuming
-- feature lands. Distinct from tblSongTranslations (whole-song -> separate
-- song), tblSongComponents.NotesJson (presenter notes) and tblLyricLines.MetaJson
-- (lossless TTML attrs). All growable vocab is VARCHAR not ENUM (#1066 policy);
-- language tags are free-text VARCHAR(35) (mirror tblLyricLines.LanguageCode,
-- not FK to tblLanguages) so TTML/LRC script subtags never RESTRICT-fail ingest.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- tblLyricLineTranslations (#1088) — per-line meaning TRANSLATION or
-- TRANSLITERATION (romanization/pronunciation) of one lyric line. Models the
-- Apple Music TTML <translation>/<transliteration> head tracks: a line may carry
-- BOTH kinds, into MANY target languages, from SEVERAL providers — so Kind +
-- TargetLanguage + Source are all in the natural key; IsPrimary picks the one
-- preferred row per (line, language, kind). MetaJson is the loss-free TTML escape
-- hatch; the moderation quartet mirrors tblLyrics for curated translations.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblLyricLineTranslations (
    Id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    LineId          BIGINT UNSIGNED NOT NULL COMMENT 'FK to tblLyricLines.Id — the line being translated / romanized',
    LyricsId        INT UNSIGNED    NOT NULL COMMENT 'Denorm of tblLyricLines.LyricsId — fetch all aux text for a lyrics version in one indexed query. App MUST derive it from the line, never trust the caller.',
    Kind            VARCHAR(20)     NOT NULL DEFAULT 'translation' COMMENT 'translation (meaning, ttm:role=x-translation) | transliteration (romanization, ttm:role=x-roman). VARCHAR not ENUM — vocab may grow (furigana, ipa); app-validate against a central map',
    TargetLanguage  VARCHAR(35)     NOT NULL COMMENT 'IETF BCP 47 tag of THIS aux text (en, ja, ko, ja-Latn, ko-Latn, zh-Hans-CN). Free text, mirrors tblLyricLines.LanguageCode — NOT a FK (script subtags absent from tblLanguages)',
    TranslationType VARCHAR(20)     NULL DEFAULT NULL COMMENT 'Apple per-track type for Kind=translation: subtitle (normal) | replacement (Simplified<->Traditional). NULL for transliterations. VARCHAR not ENUM',
    Text            TEXT            NOT NULL COMMENT 'The translated / romanized line text',
    SortOrder       INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'Display order when a line carries several aux rows (multiple languages / both kinds)',
    Source          VARCHAR(100)    NOT NULL DEFAULT 'ihymns' COMMENT 'Provenance: applemusic-ttml / human / machine-<engine> / ihymns / … (mirrors tblLyrics.Source). Part of the natural key',
    SourceUrl       VARCHAR(1000)   NULL DEFAULT NULL COMMENT 'Origin URL of the translation track, if any',
    SourceRef       VARCHAR(190)    NULL DEFAULT NULL COMMENT 'External primary id from the Source system for idempotent re-import / dedup. NULL for manual',
    IsPrimary       TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = preferred row to display for this (LineId, TargetLanguage, Kind). App demotes the prior primary on insert (no DB constraint, mirrors tblLyrics.IsPrimary)',
    IsAutoGenerated TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = machine-generated (vs human-curated / publisher-supplied) — drives a machine-translation badge',
    Status          VARCHAR(20)     NOT NULL DEFAULT 'approved' COMMENT 'draft|pending_review|approved|rejected|archived (app-validated). VARCHAR not ENUM (#1066). Same column order/names as tblLyrics',
    SubmittedBy     INT UNSIGNED    NULL DEFAULT NULL COMMENT 'FK to tblUsers.Id — who submitted (NULL for imported / system)',
    ApprovedBy      INT UNSIGNED    NULL DEFAULT NULL COMMENT 'FK to tblUsers.Id — who approved (NULL until approved)',
    ApprovedAt      DATETIME        NULL DEFAULT NULL,
    MetaJson        JSON            NULL DEFAULT NULL COMMENT 'Lossless TTML attrs: original itunes:key / for= linkage, ttm:role, xml:lang as authored, sub-span timing — loss-free re-export',
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_Line_Lang_Kind_Source (LineId, TargetLanguage, Kind, Source),
    UNIQUE KEY uq_SourceRef             (Source, SourceRef),
    INDEX idx_Lyrics    (LyricsId),
    INDEX idx_Line      (LineId, SortOrder),
    INDEX idx_Line_Kind (LineId, Kind),
    INDEX idx_Primary   (LineId, TargetLanguage, Kind, IsPrimary),
    INDEX idx_Status    (Status),

    CONSTRAINT fk_LineTrans_Line
        FOREIGN KEY (LineId)   REFERENCES tblLyricLines(Id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_LineTrans_Lyrics
        FOREIGN KEY (LyricsId) REFERENCES tblLyrics(Id)     ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_LineTrans_SubmittedBy
        FOREIGN KEY (SubmittedBy) REFERENCES tblUsers(Id)   ON DELETE SET NULL,
    CONSTRAINT fk_LineTrans_ApprovedBy
        FOREIGN KEY (ApprovedBy)  REFERENCES tblUsers(Id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- tblLyricLineAnnotations (#1088) — per-span explanatory gloss/footnote (Genius
-- referent+annotation model, collapsed to one row per span). The referent span
-- is StartLineId (+ optional EndLineId for multi-line) with optional character
-- offsets (0-based UTF-8 code-point, EndOffset exclusive) for sub-line phrase
-- highlighting — covering phrase / whole-line / multi-line with no later ALTER.
-- CASCADE on BOTH endpoints (a span is undefined if either boundary line dies;
-- SET NULL would silently corrupt a multi-line span). IsVerified is a first-class
-- indexed Genius-verified badge (distinct from Status=approved). Community vote
-- tallies are deliberately NOT columns — if voting ships it is a separate
-- auditable tblLyricAnnotationVotes table; interim caches live in MetaJson.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblLyricLineAnnotations (
    Id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    StartLineId     BIGINT UNSIGNED NOT NULL COMMENT 'Referent span START line. FK -> tblLyricLines.Id. Always set',
    EndLineId       BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Referent span END line. FK -> tblLyricLines.Id. NULL = single-line span (ends on StartLineId); set only for multi-line spans',
    StartOffset     INT UNSIGNED    NULL DEFAULT NULL COMMENT '0-based UTF-8 code-point index into StartLineId LineText where the highlighted phrase BEGINS. NULL = start of the start line',
    EndOffset       INT UNSIGNED    NULL DEFAULT NULL COMMENT '0-based EXCLUSIVE code-point index into the end line (EndLineId if set, else StartLineId) where the phrase ENDS. NULL = end of that line',
    LyricsId        INT UNSIGNED    NOT NULL COMMENT 'Denorm of tblLyricLines.LyricsId for StartLineId — one indexed fetch of all annotations for a lyrics version; scopes the cascade. App MUST derive it from the start line',
    AnnotationType  VARCHAR(40)     NOT NULL DEFAULT 'explanation' COMMENT 'explanation|reference|scripture|history|translation|trivia|… VARCHAR not ENUM (#1066); app-validate against a central map -> icon/colour',
    LanguageCode    VARCHAR(35)     NULL DEFAULT NULL COMMENT 'IETF BCP 47 language the GLOSS is written in (may differ from the line). Free text, mirrors tblLyricLines.LanguageCode — NOT a FK. NULL = site default',
    Body            MEDIUMTEXT      NOT NULL COMMENT 'Annotation body (Genius annotation body). MEDIUMTEXT: prose + scripture quotes can exceed TEXT comfort, never near 16MB',
    BodyFormat      VARCHAR(20)     NOT NULL DEFAULT 'markdown' COMMENT 'markdown|html|plain. VARCHAR for future formats',
    SortOrder       INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'Order when a line carries several annotations',
    Source          VARCHAR(100)    NOT NULL DEFAULT 'manual' COMMENT 'manual|curator|genius|… Mirrors tblLyrics.Source vocab; VARCHAR not ENUM. Part of the dedup key',
    SourceUrl       VARCHAR(1000)   NULL DEFAULT NULL COMMENT 'Canonical URL when imported (e.g. the genius.com annotation permalink)',
    SourceRef       VARCHAR(190)    NULL DEFAULT NULL COMMENT 'External primary id from Source (e.g. Genius annotation/referent id) for idempotent re-import + dedup. NULL for manual',
    Status          VARCHAR(20)     NOT NULL DEFAULT 'approved' COMMENT 'draft|pending_review|approved|rejected|archived (app-validated). VARCHAR not ENUM',
    SubmittedBy     INT UNSIGNED    NULL DEFAULT NULL COMMENT 'FK -> tblUsers.Id; author/submitter. NULL after user deletion or for system imports',
    ApprovedBy      INT UNSIGNED    NULL DEFAULT NULL COMMENT 'FK -> tblUsers.Id; moderator who approved. NULL until approved',
    ApprovedAt      DATETIME        NULL DEFAULT NULL COMMENT 'When approved',
    IsVerified      TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = first-class verified (staff/artist-cosigned) badge, distinct from Status=approved. Filterable via idx_Verified',
    VerifiedBy      INT UNSIGNED    NULL DEFAULT NULL COMMENT 'FK -> tblUsers.Id; who verified. NULL until verified',
    VerifiedAt      DATETIME        NULL DEFAULT NULL COMMENT 'When verified',
    MetaJson        JSON            NULL DEFAULT NULL COMMENT 'Lossless extra Source attrs (Genius community/author block, cosigners, custom_preview) + interim vote tallies until a real votes table lands + future per-annotation flags',
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_SourceRef (Source, SourceRef),
    INDEX idx_Lyrics    (LyricsId),
    INDEX idx_StartLine (StartLineId, SortOrder),
    INDEX idx_EndLine   (EndLineId),
    INDEX idx_Status    (Status),
    INDEX idx_Type      (AnnotationType),
    INDEX idx_Verified  (IsVerified),

    CONSTRAINT fk_LineAnnot_StartLine
        FOREIGN KEY (StartLineId) REFERENCES tblLyricLines(Id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_LineAnnot_EndLine
        FOREIGN KEY (EndLineId)   REFERENCES tblLyricLines(Id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_LineAnnot_Lyrics
        FOREIGN KEY (LyricsId)    REFERENCES tblLyrics(Id)     ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_LineAnnot_SubmittedBy
        FOREIGN KEY (SubmittedBy) REFERENCES tblUsers(Id)      ON DELETE SET NULL,
    CONSTRAINT fk_LineAnnot_ApprovedBy
        FOREIGN KEY (ApprovedBy)  REFERENCES tblUsers(Id)      ON DELETE SET NULL,
    CONSTRAINT fk_LineAnnot_VerifiedBy
        FOREIGN KEY (VerifiedBy)  REFERENCES tblUsers(Id)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- ENHANCEMENT FOUNDATION (#1090) — forward-looking, additive, dormant tables
-- for the next program phase: tune/meter entity, CCLI usage-event spine,
-- corpus-quality linter, annotation voting (sort-only), semantic-search
-- embeddings, and live-follow sessions. Shipped one-pass so they can deploy
-- ahead of the features that consume them. VARCHAR-not-ENUM for growable vocab;
-- FK to SongId VARCHAR(20); InnoDB utf8mb4_unicode_ci throughout.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- tblTunes (#1090 P4) — the hymn TUNE as a first-class entity (HYFRYDOL,
-- OLD HUNDREDTH …), promoting tblSongs.TuneName from free text. A tune carries
-- a METER (87.87 D, CM, LM — common-metre interchange) and may be a MusicBrainz
-- Work. tblSongs.TuneName stays as a JOIN-free denorm display mirror.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblTunes (
    Id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    Name                VARCHAR(120) NOT NULL COMMENT 'Canonical tune name, e.g. HYFRYDOL',
    Slug                VARCHAR(140) NOT NULL COMMENT 'URL-safe handle',
    Subtitle            VARCHAR(255) NULL DEFAULT NULL COMMENT 'Optional tune subtitle (#1741 P1)',
    Disambiguation      VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Short parenthetical to distinguish same-named tunes (#1741 P1)',
    MeterCode           VARCHAR(60)  NULL DEFAULT NULL COMMENT 'Hymn metre, e.g. 87.87 D | CM | LM | 86.86 (VARCHAR not ENUM)',
    MusicBrainzWorkMBID VARCHAR(50)  NULL DEFAULT NULL COMMENT 'MusicBrainz Work MBID — a tune is a composition (mirrors tblWorks)',
    HymnaryTuneId       VARCHAR(64)  NULL DEFAULT NULL COMMENT 'Hymnary.org tune identifier for enrichment cross-link',
    Notes               TEXT         NULL DEFAULT NULL,
    CreatedAt           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_Name   (Name),
    UNIQUE KEY uq_Slug   (Slug),
    UNIQUE KEY uq_MbWork (MusicBrainzWorkMBID),
    INDEX      idx_Meter (MeterCode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Hymn tunes as first-class entities (#1090 P4).';

-- ----------------------------------------------------------------------------
-- tblTuneAliases (#1090 P4) — alternate names a tune is known by (modelled on
-- tblMusicianAliases; indexed rows, not JSON).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblTuneAliases (
    Id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    TuneId    INT UNSIGNED NOT NULL,
    Name      VARCHAR(120) NOT NULL,
    CreatedAt TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_TuneName (TuneId, Name),
    INDEX      idx_Tune    (TuneId),
    INDEX      idx_Name    (Name),

    CONSTRAINT fk_TuneAlias_Tune
        FOREIGN KEY (TuneId) REFERENCES tblTunes(Id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- tblTuneCredits (#1741 P1) — composer/arranger/harmoniser/source credits.
-- ONE Role-discriminated table, NOT six per-role clones like the legacy
-- tblSongWriters/tblSongComposers/… family. Name is a plain name-string
-- (matching every other credit table in this schema); MusicianId (renamed
-- from CreditPersonId by #1741 P2) is a RESERVED, nullable, dormant FK to
-- tblMusicians so the "credits: name-strings vs FK-ify" open question
-- costs zero ALTER either way.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblTuneCredits (
    Id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    TuneId          INT UNSIGNED NOT NULL,
    Role            VARCHAR(20)  NOT NULL COMMENT 'composer | arranger | harmoniser | source (app-validated; VARCHAR not ENUM, rule #20)',
    Name            VARCHAR(255) NOT NULL COMMENT 'Credited name-string, matching the tblSong*/tblWork credit-table pattern (no FK to tblMusicians by default)',
    MusicianId      INT UNSIGNED NULL DEFAULT NULL COMMENT 'Reserved FK to tblMusicians.Id (#1741 P1/P2) — nullable/dormant so the credits name-string-vs-FK decision costs zero ALTER either way; unused until that decision lands',
    SortOrder       INT UNSIGNED NOT NULL DEFAULT 0,
    CreatedAt       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_tune         (TuneId),
    INDEX idx_role         (Role),
    INDEX idx_MusicianId   (MusicianId),

    CONSTRAINT fk_TuneCredits_Tune
        FOREIGN KEY (TuneId)     REFERENCES tblTunes(Id)     ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_TuneCredits_Musician
        FOREIGN KEY (MusicianId) REFERENCES tblMusicians(Id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Tune composer/arranger/harmoniser/source credits (#1741 P1), Role-discriminated per plan §2.3.';


-- ----------------------------------------------------------------------------
-- tblTuneExternalLinks (#1741 P1) — per-tune external-link rows. Mirrors
-- tblWorkExternalLinks exactly (rule #15: a new external-links entity gets
-- its own tbl<Entity>ExternalLinks table, never a generic FK column).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblTuneExternalLinks (
    Id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    TuneId      INT UNSIGNED NOT NULL,
    LinkTypeId  INT UNSIGNED NOT NULL,
    Url         VARCHAR(2048) NOT NULL,
    Note        VARCHAR(255) NULL,
    SortOrder   INT UNSIGNED NOT NULL DEFAULT 0,
    Verified    TINYINT(1)   NOT NULL DEFAULT 0,
    CreatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_tune (TuneId),
    INDEX idx_type (LinkTypeId),

    CONSTRAINT fk_link_tune
        FOREIGN KEY (TuneId)     REFERENCES tblTunes(Id)             ON DELETE CASCADE,
    CONSTRAINT fk_link_type_tune
        FOREIGN KEY (LinkTypeId) REFERENCES tblExternalLinkTypes(Id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Back-reference FK from tblSongs.TuneId -> tblTunes (added here, after tblTunes
-- exists; the column + index are declared inline in the tblSongs block above).
ALTER TABLE tblSongs
    ADD CONSTRAINT fk_Songs_Tune
        FOREIGN KEY (TuneId) REFERENCES tblTunes(Id) ON DELETE SET NULL ON UPDATE CASCADE;

-- Back-reference FK from tblWorks.TuneId -> tblTunes (#1741 P1; added here for
-- the same reason as fk_Songs_Tune immediately above: tblWorks is declared
-- before tblTunes in this file, so the FK cannot be inline. The column +
-- index are declared inline in the tblWorks block above).
ALTER TABLE tblWorks
    ADD CONSTRAINT fk_Works_Tune
        FOREIGN KEY (TuneId) REFERENCES tblTunes(Id) ON DELETE SET NULL ON UPDATE CASCADE;

-- Back-reference FK from tblSongs.DeletedBy -> tblUsers (added here rather than
-- inline for the same reason as fk_Songs_Tune: tblSongs is created before
-- tblUsers in this file; the columns + index are declared inline in the
-- tblSongs block above). ON DELETE SET NULL so erasing the deleting curator's
-- account (#1698) anonymises the attribution without touching the song. (#1694)
ALTER TABLE tblSongs
    ADD CONSTRAINT fk_Songs_DeletedBy
        FOREIGN KEY (DeletedBy) REFERENCES tblUsers(Id) ON DELETE SET NULL ON UPDATE CASCADE;

-- ============================================================================
-- FAMILY: PUBLISHERS (#93 / epic #1765) — the songbook Publisher promoted from
-- the free-text tblSongbooks.Publisher scalar to a first-class registry of
-- persons AND companies, with multi-publisher copyright (M:N) and imprint /
-- catalogue grouping (self-FK). Mirrors the tblTunes family idiom (registry +
-- aliases + external links) and the tblSongbookCompilers M:N idiom.
-- Forward-looking ONE-PASS batch (rule #20): the aliases + external-links
-- tables ship now so the family never needs a second migration, even though
-- their editor panels land alongside. The free-text tblSongbooks.Publisher
-- column STAYS as a JOIN-free denorm display mirror (same pattern as
-- TuneName<->TuneId), so every existing reader keeps working with no JOIN;
-- migrate-publishers-entity.php backfills the registry from DISTINCT non-empty
-- Publisher strings. ENTIRELY additive + idempotent.
-- ----------------------------------------------------------------------------
-- tblPublishers — the registry. A publisher is a company OR a person (Kind),
-- optionally linked to tblMusicians (a musician who is also a publisher is not
-- duplicated). ParentId self-FK models imprint / catalogue grouping (mirrors
-- tblWorks.ParentWorkId / tblSongTags.ParentId).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblPublishers (
    Id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ParentId       INT UNSIGNED NULL DEFAULT NULL COMMENT 'Self-FK: imprint / catalogue grouping — an imprint points to its parent publisher (mirrors tblWorks.ParentWorkId / tblSongTags.ParentId). NULL = top-level. (#93)',
    Name           VARCHAR(255) NOT NULL COMMENT 'Publisher display name (company name, or the person''s name for a person-publisher).',
    Slug           VARCHAR(120) NOT NULL COMMENT 'URL-safe handle, unique.',
    Kind           VARCHAR(20)  NOT NULL DEFAULT 'company' COMMENT 'Publisher kind — app-validated vocabulary, VARCHAR not ENUM (rule #20): company | person | imprint | society | other.',
    MusicianId     INT UNSIGNED NULL DEFAULT NULL COMMENT 'Optional FK to tblMusicians for a person-publisher, so a musician who also publishes is not duplicated. NULL for a pure company. (#93)',
    Subtitle       VARCHAR(255) NULL DEFAULT NULL COMMENT 'Optional publisher subtitle / tagline.',
    Disambiguation VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Short parenthetical to distinguish same-named publishers.',
    Ipi            VARCHAR(20)  NULL DEFAULT NULL COMMENT 'Interested-Parties Information number (publishers hold these too). NULL (not empty string) so absent values coexist under uq_Ipi.',
    Isni           VARCHAR(20)  NULL DEFAULT NULL COMMENT 'ISNI. NULL (not empty string) so absent values coexist under uq_Isni.',
    CityName       VARCHAR(255) NULL DEFAULT NULL COMMENT 'Publisher city display string (denorm mirror of CityId, same pattern as tblWorks.OriginCity).',
    CityId         INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK to tblPlaces for the publisher city (mirrors tblWorks.OriginCityId).',
    Notes          TEXT         NULL DEFAULT NULL,
    IsActive       TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'Soft-hide a defunct publisher from pickers without deleting its songbook links.',
    CreatedAt      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_Slug     (Slug),
    UNIQUE KEY uq_Ipi      (Ipi),
    UNIQUE KEY uq_Isni     (Isni),
    INDEX      idx_Parent   (ParentId),
    INDEX      idx_Kind     (Kind),
    INDEX      idx_Musician (MusicianId),
    INDEX      idx_Name     (Name),
    INDEX      idx_Active   (IsActive),
    INDEX      idx_City     (CityId),

    CONSTRAINT fk_Publisher_Parent
        FOREIGN KEY (ParentId)   REFERENCES tblPublishers(Id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_Publisher_Musician
        FOREIGN KEY (MusicianId) REFERENCES tblMusicians(Id)  ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_Publisher_City
        FOREIGN KEY (CityId)     REFERENCES tblPlaces(Id)     ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Songbook publishers as first-class entities — persons + companies (#93 / epic #1765).';

-- ----------------------------------------------------------------------------
-- tblSongbookPublishers (#93) — the many-to-many between songbooks and
-- publishers (multi-publisher copyright). Mirrors tblSongbookCompilers exactly;
-- Role is an app-validated VARCHAR vocabulary (not ENUM, rule #20). The nullable
-- ValidFrom/ValidTo reserve rights-window tracking so a future "publisher held
-- rights 1990-2005" need costs no ALTER (rule #20 forward-looking).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongbookPublishers (
    Id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    SongbookId   INT UNSIGNED NOT NULL,
    PublisherId  INT UNSIGNED NOT NULL,
    Role         VARCHAR(30)  NOT NULL DEFAULT 'publisher' COMMENT 'Role in this songbook — app-validated, VARCHAR not ENUM: publisher | co-publisher | distributor | imprint | administrator | printer.',
    SortOrder    INT UNSIGNED NOT NULL DEFAULT 0,
    Note         VARCHAR(255) NULL DEFAULT NULL,
    ValidFrom    DATE         NULL DEFAULT NULL COMMENT 'Reserved (#93): start of this publisher''s rights window for this book. Dormant until a rights-window feature lands.',
    ValidTo      DATE         NULL DEFAULT NULL COMMENT 'Reserved (#93): end of this publisher''s rights window for this book. Dormant until a rights-window feature lands.',
    CreatedAt    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_book_pub_role (SongbookId, PublisherId, Role),
    INDEX      idx_book (SongbookId),
    INDEX      idx_pub  (PublisherId),

    CONSTRAINT fk_sbpub_book
        FOREIGN KEY (SongbookId)  REFERENCES tblSongbooks(Id)  ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_sbpub_pub
        FOREIGN KEY (PublisherId) REFERENCES tblPublishers(Id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Songbook<->publisher many-to-many for multi-publisher copyright (#93).';

-- ----------------------------------------------------------------------------
-- tblPublisherAliases (#93) — alternate names a publisher is known by (former
-- names, imprints-as-strings). Mirrors tblTuneAliases / tblMusicianAliases;
-- indexed rows, not JSON. Feeds publisher typeahead matching.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblPublisherAliases (
    Id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    PublisherId INT UNSIGNED NOT NULL,
    Name        VARCHAR(255) NOT NULL,
    CreatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_PubName (PublisherId, Name),
    INDEX      idx_Pub    (PublisherId),
    INDEX      idx_Name   (Name),

    CONSTRAINT fk_PubAlias_Pub
        FOREIGN KEY (PublisherId) REFERENCES tblPublishers(Id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Alternate / former names for a publisher (#93).';

-- ----------------------------------------------------------------------------
-- tblPublisherExternalLinks (#93) — per-publisher external-link rows. Mirrors
-- tblTuneExternalLinks / tblWorkExternalLinks exactly (rule #15: a new
-- external-links entity gets its own tbl<Entity>ExternalLinks table, never a
-- generic FK column).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblPublisherExternalLinks (
    Id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    PublisherId INT UNSIGNED NOT NULL,
    LinkTypeId  INT UNSIGNED NOT NULL,
    Url         VARCHAR(2048) NOT NULL,
    Note        VARCHAR(255) NULL,
    SortOrder   INT UNSIGNED NOT NULL DEFAULT 0,
    Verified    TINYINT(1)   NOT NULL DEFAULT 0,
    CreatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_pub  (PublisherId),
    INDEX idx_type (LinkTypeId),

    CONSTRAINT fk_link_pub
        FOREIGN KEY (PublisherId) REFERENCES tblPublishers(Id)      ON DELETE CASCADE,
    CONSTRAINT fk_link_type_pub
        FOREIGN KEY (LinkTypeId)  REFERENCES tblExternalLinkTypes(Id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-publisher external-link rows (#93), mirroring tblTuneExternalLinks.';

-- ----------------------------------------------------------------------------
-- tblSongUsageEvents (#1090 P5) — the reportable USE spine: "song X used on
-- date Y in context Z by org O". The substrate for CCLI / CCS / OneLicense
-- usage reports (GROUP BY tblSongs.Ccli over an org+date window). BIGINT PK
-- (high volume). Dormant until projection/print/schedule write-hooks land.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongUsageEvents (
    Id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    SongId       VARCHAR(20)  NOT NULL COMMENT 'FK to tblSongs.SongId',
    OrgId        INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK to tblOrganisations — the reporting church/org',
    UserId       INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK to tblUsers — who logged/triggered it',
    SetlistId    VARCHAR(100) NULL DEFAULT NULL COMMENT 'Soft link to a setlist (no hard FK — setlists are client-synced)',
    ScheduleId   INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK to tblSetlistSchedule (a scheduled service), if any',
    UsedAt       DATETIME     NOT NULL COMMENT 'When the song was used',
    UsageContext VARCHAR(20)  NOT NULL DEFAULT 'projected' COMMENT 'projected | printed | streamed | recorded | rehearsed (app-validated)',
    LicenceId    INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK to tblOrganisationLicences — the licence the use is reported under',
    Quantity     INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Copies/prints/attendance count where the licensor needs it',
    Source       VARCHAR(40)  NOT NULL DEFAULT 'app' COMMENT 'app | import | api | manual',
    MetaJson     JSON         NULL DEFAULT NULL,
    CreatedAt    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    /* idx_OrgDate is (OrgId, UsedAt) in that order because the only query that
       matters is "everything this ORG used between these two DATES" — org
       first narrows to one tenant, the date range then scans a contiguous
       slice of the index rather than filtering a whole-corpus date sweep.

       Note the delete semantics carefully before this table goes live: the
       song FK is ON DELETE CASCADE, so removing a song erases the record that
       it was ever used — the substrate of a licence report. Every other FK
       here (Org, User, Schedule, Licence) is SET NULL precisely so the EVENT
       survives its context. Song deletion being soft (#1694) is what keeps
       that from mattering in practice; a hard purge still takes the
       reporting history with it, which is a property to weigh rather than
       discover. */
    INDEX idx_Song     (SongId),
    INDEX idx_OrgDate  (OrgId, UsedAt),
    INDEX idx_Context  (UsageContext),
    INDEX idx_Date     (UsedAt),
    INDEX idx_Schedule (ScheduleId),
    INDEX idx_Licence  (LicenceId),

    CONSTRAINT fk_Usage_Song
        FOREIGN KEY (SongId) REFERENCES tblSongs(SongId) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_Usage_Org
        FOREIGN KEY (OrgId) REFERENCES tblOrganisations(Id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_Usage_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_Usage_Schedule
        FOREIGN KEY (ScheduleId) REFERENCES tblSetlistSchedule(Id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_Usage_Licence
        FOREIGN KEY (LicenceId) REFERENCES tblOrganisationLicences(Id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Reportable song-usage events for CCLI/CCS/OneLicense reporting (#1090 P5).';

-- ----------------------------------------------------------------------------
-- tblSongQualityFindings (#1090 P8) — the corpus-quality LINTER worklist: one
-- row per (song, rule) defect, UPSERTed on each lint run, triaged like the
-- review queue. Turns "somewhere there are bad records" into a finite queue.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongQualityFindings (
    Id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    SongId      VARCHAR(20)  NOT NULL COMMENT 'FK to tblSongs.SongId',
    RuleKey     VARCHAR(60)  NOT NULL COMMENT 'Rule that fired, e.g. missing_ccli | orphan_tune | unbalanced_chords (app-validated vs a central rule map)',
    Severity    VARCHAR(10)  NOT NULL DEFAULT 'warning' COMMENT 'info | warning | error',
    Status      VARCHAR(20)  NOT NULL DEFAULT 'open' COMMENT 'open | acknowledged | fixed | wontfix | false_positive',
    AssignedTo  INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK to tblUsers — claimed by',
    DetailText  TEXT         NULL DEFAULT NULL,
    ContextJson JSON         NULL DEFAULT NULL COMMENT 'Structured evidence for the finding',
    FirstSeenAt DATETIME     NOT NULL COMMENT 'When the rule first fired for this (song,rule)',
    LastSeenAt  DATETIME     NOT NULL COMMENT 'Most recent lint run that still saw it',
    ResolvedBy  INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK to tblUsers',
    ResolvedAt  DATETIME     NULL DEFAULT NULL,
    CreatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_SongRule  (SongId, RuleKey),
    INDEX      idx_Status   (Status),
    INDEX      idx_Severity (Severity),
    INDEX      idx_Rule     (RuleKey),
    INDEX      idx_Assignee (AssignedTo),

    CONSTRAINT fk_QualityFinding_Song
        FOREIGN KEY (SongId) REFERENCES tblSongs(SongId) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_QualityFinding_Assignee
        FOREIGN KEY (AssignedTo) REFERENCES tblUsers(Id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_QualityFinding_Resolver
        FOREIGN KEY (ResolvedBy) REFERENCES tblUsers(Id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Corpus-quality linter findings worklist (#1090 P8).';

-- ----------------------------------------------------------------------------
-- tblLyricAnnotationVotes (#1090 P10) — community votes on per-line annotations.
-- One vote per user per annotation (UNIQUE). The tally (SUM(Vote)) drives
-- SORT ORDER in the reviewer queue ONLY — it NEVER writes Status/IsVerified and
-- NEVER auto-publishes to the read path. Publishing stays moderator-driven
-- (doctrinal/abuse safety — see the iLyricsDB #25 auto-promote anti-pattern).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblLyricAnnotationVotes (
    Id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    AnnotationId BIGINT UNSIGNED  NOT NULL COMMENT 'FK to tblLyricLineAnnotations.Id',
    UserId       INT UNSIGNED     NOT NULL COMMENT 'FK to tblUsers.Id',
    Vote         TINYINT          NOT NULL COMMENT '+1 or -1 (app-validated to {-1,1})',
    CreatedAt    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_AnnotationUser (AnnotationId, UserId),
    INDEX      idx_Annotation    (AnnotationId),

    CONSTRAINT fk_AnnotVote_Annotation
        FOREIGN KEY (AnnotationId) REFERENCES tblLyricLineAnnotations(Id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_AnnotVote_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Annotation votes — sort-only, never auto-publish (#1090 P10).';

-- ----------------------------------------------------------------------------
-- tblSongEmbeddings (#1090 P11) — semantic-search vector cache. ContentHash
-- (sha256 of the embedded text) lets an edit invalidate exactly the affected
-- rows (re-embed on mismatch only — never a whole-corpus re-embed). MySQL 8 has
-- no native ANN at this version, so similarity is app-side cosine over a
-- FULLTEXT-prefiltered candidate set; this table is the durable cache.
-- Prepped now, dormant until semantic search ships.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongEmbeddings (
    Id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    SongId      VARCHAR(20)     NOT NULL COMMENT 'FK to tblSongs.SongId',
    ModelKey    VARCHAR(80)     NOT NULL COMMENT 'Embedding model, e.g. openai-text-embedding-3-small | voyage-3 (multiple models coexist)',
    Dims        SMALLINT UNSIGNED NOT NULL COMMENT 'Vector dimensionality',
    `Vector`    MEDIUMBLOB      NOT NULL COMMENT 'Packed float32 vector',
    ContentHash CHAR(64)        NOT NULL COMMENT 'sha256 of the embedded text — drives re-index-on-edit (re-embed only on mismatch)',
    SourceField VARCHAR(20)     NOT NULL DEFAULT 'lyrics' COMMENT 'lyrics | title | combined',
    GeneratedAt DATETIME        NOT NULL,
    CreatedAt   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_SongModelField (SongId, ModelKey, SourceField),
    INDEX      idx_Model         (ModelKey),
    INDEX      idx_Hash          (ContentHash),

    CONSTRAINT fk_Embedding_Song
        FOREIGN KEY (SongId) REFERENCES tblSongs(SongId) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Semantic-search embedding cache (#1090 P11, prep-now/dormant).';

-- ----------------------------------------------------------------------------
-- tblLiveFollowSessions (#1090 P7) — ephemeral broadcast state for the native
-- "Live Follow" feature: a leader's current song/slide that congregants mirror
-- on their own devices (short-code join). A cleanup job prunes past ExpiresAt.
--
-- ⚠ ONE TABLE, TWO DIFFERENT FEATURES — confusing them is what once made Live
-- Follow look permanently broken (rule #26). SessionKind is the discriminator:
--   'host'    = Live Follow (#1268). Any authenticated user starts one. No
--               venue, no schedule, no organisation — OrgId is left NULL.
--   'service' = Service Mode (#1335). Requires a venue, an occurrence date and
--               org-admin rights, and is joined through the rotating codes in
--               tblLiveFollowJoinCodes rather than the SessionCode column.
-- The nullable HostUserId / VenueId / ScheduleId / OccurrenceDate columns are
-- not optional extras; they are how the two shapes coexist in one table.
--
-- ⚠ `Channel` is the 3-docroot environment discriminator, and it MUST appear
-- in the WHERE clause of EVERY join / poll / broadcast / gate / prune query.
-- An unfiltered query is a cross-environment leak. The visible consequence of
-- it working correctly is that a session hosted on one docroot can never be
-- joined from another — so the natural "desktop on dev, phone on www" test
-- always fails with a generic wrong-code message and looks like a bug.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblLiveFollowSessions (
    Id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    SessionCode          VARCHAR(12)  NOT NULL COMMENT 'Short human code / QR payload congregants enter to join',
    HostUserId           INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK to tblUsers — leader hosting; NULL for an org/venue-anchored service session (#1335)',
    OrgId                INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK to tblOrganisations',
    VenueId              INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK tblOrgVenues — venue of this service session (#1335)',
    ScheduleId           INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK tblOrgServiceSchedules — the recurring schedule (#1335)',
    OccurrenceDate       DATE         NULL DEFAULT NULL COMMENT 'Date of this occurrence (scheduleId + date = occurrence identity) (#1335)',
    SessionKind          VARCHAR(20)  NOT NULL DEFAULT 'host' COMMENT 'host = #1268 leader follow | service = #1335 venue/org session — app-validated, VARCHAR not ENUM',
    Channel              VARCHAR(16)  NULL DEFAULT NULL COMMENT '3-docroot env discriminator (HTTP_HOST-derived at create); filter in every join/poll/gate/prune query (#1335)',
    SetlistId            VARCHAR(100) NULL DEFAULT NULL COMMENT 'Soft link to the setlist being followed',
    CurrentSongId        VARCHAR(20)  NULL DEFAULT NULL COMMENT 'FK to tblSongs — the song currently displayed',
    CurrentComponentIndex INT        NULL DEFAULT NULL COMMENT 'Index into the arrangement/component order being shown',
    StateJson            JSON         NULL DEFAULT NULL COMMENT 'Extra broadcast state (blank, theme, font-size hints)',
    StateRevision        INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Monotonic broadcast revision; host bumps on each state write so followers poll cheaply with ?since=',
    IsActive             TINYINT(1)   NOT NULL DEFAULT 1,
    StartedAt            DATETIME     NOT NULL,
    LastHeartbeatAt      DATETIME     NOT NULL,
    ExpiresAt            DATETIME     NULL DEFAULT NULL COMMENT 'Cleanup horizon for the prune job',
    LastLeaderSeenAt DATETIME NULL DEFAULT NULL COMMENT 'UTC instant of the last GENUINE leader interaction (broadcast/create/console action, or a leaderActive heartbeat) — NOT bumped by the automated 30s keepalive alone. NULL = pre-#1770 row or not yet stamped; idle enforcement skips it (#1770)',
    IdleTimeoutMins SMALLINT UNSIGNED NULL DEFAULT NULL COMMENT 'Resolved idle-timeout (minutes) stamped at session create from the app→org→user precedence chain (serviceMode_resolveIdleTimeoutMins). NULL = no idle enforcement (service sessions, legacy rows, un-migrated writers) (#1770)',
    CreatedAt            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_Code   (SessionCode),
    INDEX      idx_Host  (HostUserId),
    INDEX      idx_Active (IsActive, LastHeartbeatAt),
    INDEX      idx_Service (VenueId, ScheduleId, OccurrenceDate, IsActive),
    INDEX      idx_OrgActive (OrgId, IsActive),
    INDEX      idx_Idle (SessionKind, IsActive, LastLeaderSeenAt),

    CONSTRAINT fk_LiveFollow_Host
        FOREIGN KEY (HostUserId) REFERENCES tblUsers(Id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_LiveFollow_Org
        FOREIGN KEY (OrgId) REFERENCES tblOrganisations(Id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_LiveFollow_Song
        FOREIGN KEY (CurrentSongId) REFERENCES tblSongs(SongId) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Live-follow broadcast sessions for native present+follow (#1090 P7); extended for Service Mode org/venue/service sessions (#1335).';

-- ----------------------------------------------------------------------------
-- Service Mode (#1335) — rotating venue join codes + anonymous congregant
-- presence + per-session poll budget. Dormant until Phase 2b/2c/3. Mirror of
-- migrate-service-mode-sessions.php.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblLiveFollowJoinCodes (
    Id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    SessionId   INT UNSIGNED  NOT NULL COMMENT 'FK tblLiveFollowSessions',
    Code        VARCHAR(12)   NOT NULL COMMENT 'Crockford base32 rotating join code (current/previous live)',
    Generation  INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT 'Monotonic per-session rotation counter',
    Status      VARCHAR(20)   NOT NULL DEFAULT 'current' COMMENT 'current | previous | superseded | expired — app-validated, VARCHAR not ENUM (rule #20). superseded = rotated out by the next code; expired = the session ended or ExpiresAt passed. Retired rows are KEPT, never deleted (#1621)',
    ActiveCode  VARCHAR(12)   GENERATED ALWAYS AS (CASE WHEN Status IN ('current','previous') THEN Code ELSE NULL END) STORED COMMENT 'Partial-unique idiom (#1621): mirrors Code while the row is LIVE, else NULL, so uq_ActiveCode makes live codes GLOBALLY unique while retired rows coexist (multiple NULLs are distinct). MySQL has no filtered index, and a generated column must be deterministic (UTC_TIMESTAMP is rejected), so liveness is expressed by Status alone. POSITIVE status list, so any future retired state is excluded automatically',
    IssuedAt    DATETIME      NOT NULL,
    ExpiresAt   DATETIME      NOT NULL COMMENT 'Rotation horizon + grace (UTC); join rejects past this',
    CreatedAt   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_Session_Code (SessionId, Code),
    UNIQUE KEY uq_ActiveCode (ActiveCode),
    INDEX idx_Session_Status (SessionId, Status),
    INDEX idx_Live_Expiry (Status, ExpiresAt),
    INDEX idx_Expiry (ExpiresAt),
    CONSTRAINT fk_JoinCode_Session FOREIGN KEY (SessionId) REFERENCES tblLiveFollowSessions(Id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Rotating venue join codes for Service Mode (#1335). LIVE codes are GLOBALLY unique via the ActiveCode generated column (#1621); current+previous window so a just-before-rotation scan still validates. Retired rows are kept for audit, never deleted.';

CREATE TABLE IF NOT EXISTS tblServicePresence (
    Id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    SessionId        INT UNSIGNED  NOT NULL COMMENT 'FK tblLiveFollowSessions',
    OrgId            INT UNSIGNED  NULL DEFAULT NULL COMMENT 'Denorm of the session org for the Phase-3 gate read',
    VenueId          INT UNSIGNED  NULL DEFAULT NULL,
    ScheduleId       INT UNSIGNED  NULL DEFAULT NULL,
    OccurrenceDate   DATE          NULL DEFAULT NULL,
    Channel          VARCHAR(16)   NULL DEFAULT NULL COMMENT '3-docroot env discriminator (copied from the session); filter in every query',
    PresenceDeviceId VARCHAR(64)   NOT NULL COMMENT 'Client-minted anonymous device id (localStorage UUID) — advisory cooldown key, NOT a security control',
    PresenceToken    CHAR(43)      NOT NULL COMMENT 'Opaque base64url 32-byte nonce — the gate key; hard-revocable (not a signed token)',
    Role             VARCHAR(20)   NOT NULL DEFAULT 'congregant' COMMENT 'congregant | projector — app-validated poll-budget role (#1406), VARCHAR not ENUM (rule #20)',
    JoinedAt         DATETIME      NOT NULL,
    LastSeenAt       DATETIME      NOT NULL,
    ExpiresAt        DATETIME      NOT NULL COMMENT 'Resolved UTC = min(service-occurrence end via venue IANA tz, hard ceiling); gate + prune compare against this',
    IsActive         TINYINT(1)    NOT NULL DEFAULT 1 COMMENT '0 = left/revoked → immediate gate revocation',
    CreatedAt        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_Token (PresenceToken),
    UNIQUE KEY uq_DeviceSession (SessionId, PresenceDeviceId),
    INDEX idx_Session (SessionId, IsActive),
    INDEX idx_Gate (PresenceToken, IsActive, ExpiresAt),
    CONSTRAINT fk_Presence_Session FOREIGN KEY (SessionId) REFERENCES tblLiveFollowSessions(Id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Anonymous congregant presence for Service Mode (#1335). PresenceToken = the Phase-3 gate key; ExpiresAt = resolved-UTC service end; one row per device per session (re-join reactivates).';

CREATE TABLE IF NOT EXISTS tblServicePollCounters (
    Id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    SessionId   INT UNSIGNED NOT NULL COMMENT 'FK tblLiveFollowSessions',
    WindowStart DATETIME     NOT NULL COMMENT 'Start of the fixed counting window (UTC)',
    PollCount   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Polls served to the WHOLE session in this window — NAT-safe per-session budget (not per-IP, which would throttle a congregation behind one church-wifi IP)',
    CreatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_Session_Window (SessionId, WindowStart),
    INDEX idx_Window (WindowStart),
    CONSTRAINT fk_PollCtr_Session FOREIGN KEY (SessionId) REFERENCES tblLiveFollowSessions(Id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-session poll budget for Service Mode (#1335) — the NAT-safe replacement for the #1268 per-IP poll cap.';

-- ----------------------------------------------------------------------------
-- tblSessionControlTokens (#1408) — scoped, revocable tokens that grant
-- "control THIS ONE live/service session" without handing out the operator's
-- own login (e.g. a leader hands their phone to a co-leader for the second
-- half of a service). Dormant until session-control-token issuance/
-- validation ships.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSessionControlTokens (
    Id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    TokenHash  CHAR(64)     NOT NULL COMMENT 'SHA-256 hex of the scoped control token (raw value never stored)',
    SessionId  INT UNSIGNED    NOT NULL COMMENT 'FK tblLiveFollowSessions(Id) — INT UNSIGNED, matching that PK exactly; a BIGINT here is errno 150 and the table cannot be created (#1708)',
    Channel    VARCHAR(16)     NULL DEFAULT NULL COMMENT '3-docroot env discriminator (rule #26) — filter in every issue/validate/revoke query',
    Scope      VARCHAR(40)      NOT NULL DEFAULT 'broadcast' COMMENT 'granted capability, e.g. broadcast | view — app-validated, VARCHAR not ENUM (rule #20)',
    IssuedAt   DATETIME     NOT NULL,
    ExpiresAt  DATETIME     NOT NULL COMMENT 'Revocable, short-lived control-token TTL',
    RevokedAt  DATETIME     NULL DEFAULT NULL COMMENT 'Explicit revoke timestamp; NULL = still live (subject to ExpiresAt)',
    CreatedAt  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_TokenHash (TokenHash),
    INDEX      idx_Session (SessionId),
    INDEX      idx_Expiry (ExpiresAt),

    CONSTRAINT fk_SessionControlTokens_Session
        FOREIGN KEY (SessionId) REFERENCES tblLiveFollowSessions(Id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Scoped, revocable session control tokens for remote-control delegation (#1408). Dormant until session-control-token issuance/validation ships.';

-- ----------------------------------------------------------------------------
-- tblApnsTokens (#1410) — APNs push tokens for ordinary device notifications
-- AND (separately) Live Activities / Dynamic Island updates. `Kind` +
-- nullable `SessionId` distinguish the two: an ordinary device token has no
-- SessionId; a Live Activity token is scoped to the one live/service session
-- it mirrors and churns independently of the device token. Dormant until the
-- APNs bridge ships.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblApnsTokens (
    Id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    Kind       VARCHAR(20)    NOT NULL DEFAULT 'device' COMMENT 'device | liveActivity — app-validated, VARCHAR not ENUM (rule #20); Live Activity tokens churn per-activity so Kind + nullable SessionId hedge without a 2nd migration',
    UserId     INT UNSIGNED   NULL DEFAULT NULL COMMENT 'FK tblUsers — owning user; NULL for an anonymous/presence-scoped token',
    SessionId  INT UNSIGNED    NULL DEFAULT NULL COMMENT 'FK tblLiveFollowSessions(Id) — INT UNSIGNED, matching that PK exactly (#1708); set only for Kind=liveActivity tokens tied to one live/service session',
    PushToken  VARBINARY(255) NOT NULL COMMENT 'Raw APNs device/activity push token bytes',
    ApnsEnv    VARCHAR(20)    NOT NULL DEFAULT 'production' COMMENT 'sandbox | production — which APNs gateway this token is valid against',
    ExpiresAt  DATETIME       NULL DEFAULT NULL COMMENT 'Optional TTL — NULL = no expiry (ordinary device tokens); Live Activity tokens set this to the activity end',
    CreatedAt  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_PushToken (PushToken),
    INDEX      idx_User (UserId),
    INDEX      idx_Session (SessionId),

    CONSTRAINT fk_ApnsTokens_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_ApnsTokens_Session
        FOREIGN KEY (SessionId) REFERENCES tblLiveFollowSessions(Id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='APNs push tokens for device notifications and Live Activities (#1410). Dormant until the APNs bridge ships.';


-- ============================================================================
-- SCHEMA-COMPLETENESS BATCH (#1090 audit) — gaps the cross-repo audit found
-- iHymns missing that a converged iHymns+iLyricsDB backend needs. Additive +
-- dormant; anchored on shipped tables; VARCHAR-not-ENUM vocab throughout.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- VOCAL / SINGING PARTS (#1137) — first-class queryable projection of the
-- lossless TTML ttm:agent/ttm:role/background-vocal signal trapped in
-- tblLyricLines.MetaJson. Registry + MANY-to-MANY line/word assignment (true
-- duet/unison). Named singer reuses tblMusicians (no new tblArtists). Gender
-- is an orthogonal axis. Additive + dormant (MetaJson stays the source of truth).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblVocalParts (
    Id             INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    LyricsId       INT UNSIGNED    NOT NULL COMMENT 'FK to tblLyrics.Id — parts are per lyrics version',
    PartKind       VARCHAR(30)     NOT NULL DEFAULT 'lead' COMMENT 'lead|main|backing|soloist|male|female|duet|group|unison|choir|congregation|cantor|descant|narrator|spoken|named-singer (app-validated vs a central map -> badge). VARCHAR not ENUM',
    Label          VARCHAR(120)    NULL DEFAULT NULL COMMENT 'Editor display override (Soprano, Worship Leader, …)',
    MusicianId     INT UNSIGNED    NULL DEFAULT NULL COMMENT 'FK to tblMusicians.Id — typed named-singer link (reuses the musician registry, NOT a new tblArtists)',
    SingerName     VARCHAR(255)    NULL DEFAULT NULL COMMENT 'Free-text named singer when no registry row',
    Gender         VARCHAR(16)     NULL DEFAULT NULL COMMENT 'male|female|neutral — orthogonal axis (a named soloist may also be female)',
    TtmlAgentId    VARCHAR(64)     NULL DEFAULT NULL COMMENT 'Source <ttm:agent> handle (v1,v2) — loss-free re-export + idempotent back-fill key',
    Source         VARCHAR(100)    NOT NULL DEFAULT 'ihymns' COMMENT 'applemusic-ttml | manual | … (mirrors tblLyrics.Source)',
    SortOrder      INT UNSIGNED    NOT NULL DEFAULT 0,
    MetaJson       JSON            NULL DEFAULT NULL COMMENT 'Lossless TTML <head> agent def attrs (ttm:agent type, ttm:name)',
    CreatedAt      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_Lyrics_Agent (LyricsId, TtmlAgentId),
    INDEX idx_Lyrics   (LyricsId),
    INDEX idx_Kind     (PartKind),
    INDEX idx_Musician (MusicianId),

    CONSTRAINT fk_VocalParts_Lyrics
        FOREIGN KEY (LyricsId)   REFERENCES tblLyrics(Id)     ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT fk_VocalParts_Musician
        FOREIGN KEY (MusicianId) REFERENCES tblMusicians(Id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-version singing-part registry — first-class vocal parts (#1137).';

CREATE TABLE IF NOT EXISTS tblLyricLineVocalParts (
    Id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    LineId       BIGINT UNSIGNED NOT NULL,
    VocalPartId  INT UNSIGNED    NOT NULL,
    LyricsId     INT UNSIGNED    NOT NULL COMMENT 'Denorm of tblLyricLines.LyricsId; app derives from the line, never the caller',
    IsBackground TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'TTML background-vocal / ttm:role=x-bg — lead+backing on one line = two rows split by this bit',
    SortOrder    INT UNSIGNED    NOT NULL DEFAULT 0,
    CreatedAt    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_Line_Part (LineId, VocalPartId),
    INDEX idx_Lyrics (LyricsId),
    INDEX idx_Line   (LineId, SortOrder),
    INDEX idx_Part   (VocalPartId),

    CONSTRAINT fk_LineVP_Line
        FOREIGN KEY (LineId)      REFERENCES tblLyricLines(Id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_LineVP_Part
        FOREIGN KEY (VocalPartId) REFERENCES tblVocalParts(Id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_LineVP_Lyrics
        FOREIGN KEY (LyricsId)    REFERENCES tblLyrics(Id)     ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-line vocal-part assignment, many-to-many for duet/unison (#1137).';

CREATE TABLE IF NOT EXISTS tblLyricWordVocalParts (
    Id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    WordId       BIGINT UNSIGNED NOT NULL,
    VocalPartId  INT UNSIGNED    NOT NULL,
    LyricsId     INT UNSIGNED    NOT NULL COMMENT 'Denorm via word->line->lyrics; app-derived. App rule: a word with rows overrides its line parts; a word with none inherits the line',
    IsBackground TINYINT(1)      NOT NULL DEFAULT 0,
    SortOrder    INT UNSIGNED    NOT NULL DEFAULT 0,
    CreatedAt    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_Word_Part (WordId, VocalPartId),
    INDEX idx_Lyrics (LyricsId),
    INDEX idx_Word   (WordId, SortOrder),
    INDEX idx_Part   (VocalPartId),

    CONSTRAINT fk_WordVP_Word
        FOREIGN KEY (WordId)      REFERENCES tblLyricWords(Id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_WordVP_Part
        FOREIGN KEY (VocalPartId) REFERENCES tblVocalParts(Id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_WordVP_Lyrics
        FOREIGN KEY (LyricsId)    REFERENCES tblLyrics(Id)     ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-word vocal-part assignment (word-level overlap; overrides line) (#1137).';

-- ----------------------------------------------------------------------------
-- tblSongPartTypes (#1138) — controlled song-section vocabulary (the typed key
-- tblLyricLines.PartTypeSlug references; tblSongComponents.Type stays free-text).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongPartTypes (
    Id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    Slug       VARCHAR(40)  NOT NULL COMMENT 'intro|verse|pre-chorus|chorus|post-chorus|bridge|refrain|interlude|instrumental|solo|ad-lib|outro|tag|vamp|coda',
    Name       VARCHAR(60)  NOT NULL,
    IsNumbered TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Verse 1 / Chorus 2 vs Intro/Outro',
    SortOrder  INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_Slug (Slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Controlled song-part type vocabulary (#1138).';

-- ----------------------------------------------------------------------------
-- tblBibleBooks + tblSongScriptureRefs (#1112) — scripture cross-reference index
-- (OSIS canonical key, browse-by-passage). Owner-confirmed. Unblocks lectionary.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblBibleBooks (
    Id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    Code           VARCHAR(12)  NOT NULL COMMENT 'OSIS book code (Gen, Ps, Matt, …)',
    Name           VARCHAR(60)  NOT NULL,
    Testament      VARCHAR(12)  NOT NULL COMMENT 'old | new | apocrypha',
    CanonicalOrder INT UNSIGNED NOT NULL,
    UNIQUE KEY uq_Code (Code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='OSIS Bible-book reference list (#1112).';

CREATE TABLE IF NOT EXISTS tblSongScriptureRefs (
    Id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    SongId      VARCHAR(20)  NOT NULL,
    StartLineId BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Optional anchor to tblLyricLines.Id (NULL = whole-song ref)',
    Book        VARCHAR(12)  NOT NULL COMMENT 'OSIS book code (FK-by-code to tblBibleBooks.Code)',
    Chapter     INT UNSIGNED NULL DEFAULT NULL,
    VerseStart  INT UNSIGNED NULL DEFAULT NULL,
    VerseEnd    INT UNSIGNED NULL DEFAULT NULL,
    OsisRef     VARCHAR(60)  NULL DEFAULT NULL COMMENT 'Versification-neutral OSIS ref e.g. Ps.23.1-Ps.23.6',
    Source      VARCHAR(40)  NOT NULL DEFAULT 'manual' COMMENT 'manual | hymnary | parsed (VARCHAR not ENUM)',
    SortOrder   INT UNSIGNED NOT NULL DEFAULT 0,
    CreatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_Song (SongId),
    INDEX idx_Book (Book, Chapter, VerseStart),
    INDEX idx_Line (StartLineId),

    CONSTRAINT fk_ScriptureRefs_Song
        FOREIGN KEY (SongId)      REFERENCES tblSongs(SongId)  ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT fk_ScriptureRefs_Line
        FOREIGN KEY (StartLineId) REFERENCES tblLyricLines(Id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Scripture cross-reference index — browse-by-passage (#1112).';

-- ----------------------------------------------------------------------------
-- tblSongRoyaltyIds (#1140) — per-song PRO / royalty-authority registration.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongRoyaltyIds (
    Id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    SongId      VARCHAR(20)  NOT NULL,
    Authority   VARCHAR(40)  NOT NULL COMMENT 'ASCAP|BMI|PRS|SESAC|GEMA|… VARCHAR not ENUM (growable)',
    AuthorityId VARCHAR(100) NOT NULL COMMENT 'Society-assigned work/agreement id',
    Note        VARCHAR(255) NULL DEFAULT NULL,
    SortOrder   INT UNSIGNED NOT NULL DEFAULT 0,
    CreatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_Song_Authority (SongId, Authority),
    INDEX idx_Song (SongId),

    CONSTRAINT fk_RoyaltyIds_Song
        FOREIGN KEY (SongId) REFERENCES tblSongs(SongId) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-song PRO / royalty-authority IDs (#1140).';

-- ----------------------------------------------------------------------------
-- tblSearchSynonyms (#1142) — synonym expansion for live FULLTEXT search.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSearchSynonyms (
    Id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    PrimaryTerm VARCHAR(120) NOT NULL,
    Synonym     VARCHAR(120) NOT NULL,
    Language    VARCHAR(35)  NULL DEFAULT NULL,
    SortOrder   INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_Term_Syn (PrimaryTerm, Synonym, Language)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Search synonym expansion (#1142).';

-- ----------------------------------------------------------------------------
-- tblLyricsSourceDocuments (#1143) — verbatim ingested carrier docs for lossless
-- whole-document round-trip (LyricsFile YAML, .ilyrics, raw TTML/LRC/ASS).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblLyricsSourceDocuments (
    Id          INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    LyricsId    INT UNSIGNED  NOT NULL COMMENT 'FK to tblLyrics.Id — the version this document produced',
    Format      VARCHAR(40)   NOT NULL COMMENT 'lyricsfile|ilyrics|ttml|lrc|ass|… (VARCHAR not ENUM)',
    MimeType    VARCHAR(120)  NULL DEFAULT NULL,
    SpecVersion VARCHAR(40)   NULL DEFAULT NULL COMMENT 'Carrier spec version (LyricsFile 2.0.0, …)',
    Source      VARCHAR(100)  NOT NULL DEFAULT 'ihymns',
    SourceUrl   VARCHAR(1000) NULL DEFAULT NULL,
    RawPayload  LONGTEXT      NOT NULL COMMENT 'Verbatim source document — round-trips losslessly',
    Sha256      CHAR(64)      NULL DEFAULT NULL COMMENT 'Dedup / idempotent re-import',
    CreatedAt   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_Lyrics_Sha (LyricsId, Sha256),
    INDEX idx_Lyrics (LyricsId),

    CONSTRAINT fk_SourceDocs_Lyrics
        FOREIGN KEY (LyricsId) REFERENCES tblLyrics(Id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Verbatim ingested carrier documents for lossless round-trip (#1143).';


-- =====================================================================
-- Presentation themes / styling groundwork for casting & projection
-- (#1168 / #1170). Eight additive, DORMANT tables — nothing reads them
-- until the casting/editor feature consumes them. Mirror of
-- migrate-presentation-themes.php (rule #19). Content↔presentation
-- separation is absolute: no table here stores a lyric string.
-- =====================================================================

CREATE TABLE IF NOT EXISTS tblPresentationThemes (
    Id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    OrgId       INT UNSIGNED NULL DEFAULT NULL COMMENT 'Owner org; NULL = built-in/system theme shared across orgs',
    Name        VARCHAR(100) NOT NULL,
    Description TEXT NULL DEFAULT NULL,
    IsBuiltIn   TINYINT(1) NOT NULL DEFAULT 0,
    SortOrder   INT UNSIGNED NOT NULL DEFAULT 0,
    CreatedAt   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_Org (OrgId, IsBuiltIn, SortOrder),
    CONSTRAINT fk_PresTheme_Org FOREIGN KEY (OrgId) REFERENCES tblOrganisations(Id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Reusable presentation/projection themes, org-scoped; org-NULL = built-in. Dormant until casting/editor consumes (#1168).';

CREATE TABLE IF NOT EXISTS tblPresentationThemeVariants (
    Id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ThemeId         INT UNSIGNED NOT NULL,
    DisplayRole     VARCHAR(30) NOT NULL DEFAULT 'audience' COMMENT 'audience|stage|lower_third|mirror|print — VARCHAR not ENUM (rule #20)',
    FontFamily      VARCHAR(100) NULL DEFAULT NULL,
    FontSizeScale   DECIMAL(5,3) NOT NULL DEFAULT 1.000 COMMENT '1.000 = 100% baseline',
    FontWeight      VARCHAR(20) NULL DEFAULT NULL COMMENT 'normal|bold|light|heavy — VARCHAR (rule #20)',
    TextColour      VARCHAR(32) NULL DEFAULT NULL COMMENT '#RRGGBB(AA) or wide-gamut/Display-P3 float string (PP7)',
    TextAlign       VARCHAR(20) NULL DEFAULT NULL COMMENT 'left|center|right|justified|natural',
    TextVAlign      VARCHAR(20) NULL DEFAULT NULL COMMENT 'top|middle|bottom',
    Uppercase       TINYINT(1) NOT NULL DEFAULT 0,
    LineHeight      DECIMAL(5,3) NULL DEFAULT NULL,
    LetterSpacing   DECIMAL(6,3) NULL DEFAULT NULL,
    OutlineColour   VARCHAR(32) NULL DEFAULT NULL,
    OutlineWidth    DECIMAL(6,3) NULL DEFAULT NULL,
    OutlineStyle    VARCHAR(20) NULL DEFAULT NULL COMMENT 'solid|dash|dot — VARCHAR (rule #20)',
    ShadowStyle     VARCHAR(20) NULL DEFAULT NULL COMMENT 'drop|inner|glow — VARCHAR (rule #20)',
    ShadowColour    VARCHAR(32) NULL DEFAULT NULL,
    ShadowOffset    DECIMAL(6,3) NULL DEFAULT NULL,
    ShadowRadius    DECIMAL(6,3) NULL DEFAULT NULL,
    ShadowOpacity   DECIMAL(4,3) NULL DEFAULT NULL,
    TextBgColour    VARCHAR(32) NULL DEFAULT NULL,
    TransitionType  VARCHAR(50) NULL DEFAULT NULL COMMENT 'fade|push|wipe|dissolve|morph|custom — VARCHAR (rule #20)',
    TransitionDurationMs INT UNSIGNED NULL DEFAULT NULL,
    TransitionDirection  VARCHAR(20) NULL DEFAULT NULL,
    TextAnimationType    VARCHAR(50) NULL DEFAULT NULL COMMENT 'appear|typewriter|fly|… — VARCHAR (rule #20)',
    TextAnimationDelayMs INT UNSIGNED NULL DEFAULT NULL,
    TextAnimationStart   VARCHAR(20) NULL DEFAULT NULL COMMENT 'on_click|with_previous|after_previous|with_slide',
    LayoutMode      VARCHAR(40) NOT NULL DEFAULT 'standard' COMMENT 'standard|bilingual_side_by_side|bilingual_stacked|lower_third — VARCHAR (rule #20)',
    PrimaryLanguageCode VARCHAR(35) NULL DEFAULT NULL COMMENT 'IETF BCP 47; NULL = song lang (free-text, rule #21 — no FK)',
    AltLanguageCode     VARCHAR(35) NULL DEFAULT NULL,
    MaxLinesPerSlide INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = no limit (#1065)',
    ScaleMode       VARCHAR(30) NULL DEFAULT NULL COMMENT 'fit_container|scale_up|scale_down|scale_up_down',
    SafeAreaInsetJson JSON NULL DEFAULT NULL COMMENT '{top,bottom,left,right} points',
    HighlightMode   VARCHAR(20) NOT NULL DEFAULT 'line' COMMENT 'word|line|syllable — VARCHAR (rule #20); timing reads tblLyricWords/tblLyricSyllables',
    HighlightColour VARCHAR(32) NULL DEFAULT NULL,
    HighlightDurationMs INT UNSIGNED NULL DEFAULT NULL,
    HighlightFadeMs INT UNSIGNED NULL DEFAULT NULL,
    CreatedAt       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ThemeRole (ThemeId, DisplayRole),
    CONSTRAINT fk_PresVariant_Theme FOREIGN KEY (ThemeId) REFERENCES tblPresentationThemes(Id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-display-role (audience vs stage) style surface of a theme: text/colour/align/layout/transition/karaoke/bilingual (#1168).';

CREATE TABLE IF NOT EXISTS tblPresentationBackgroundLayers (
    Id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    VariantId   INT UNSIGNED NOT NULL,
    ZIndex      INT NOT NULL DEFAULT 0,
    BackgroundType VARCHAR(20) NOT NULL DEFAULT 'solid' COMMENT 'solid|gradient|image|video|blur|invert — VARCHAR (rule #20)',
    Colour      VARCHAR(32) NULL DEFAULT NULL,
    GradientType VARCHAR(20) NULL DEFAULT NULL COMMENT 'linear|radial|angle',
    GradientAngle FLOAT NULL DEFAULT NULL,
    GradientStopsJson JSON NULL DEFAULT NULL COMMENT '[{colour,position,blendPoint}]',
    MediaUrl    VARCHAR(1000) NULL DEFAULT NULL,
    MediaId     VARCHAR(200) NULL DEFAULT NULL COMMENT 'Non-portable host-library handle; round-trip only, never auto-fetched',
    Opacity     DECIMAL(4,3) NULL DEFAULT NULL,
    BlurAmount  FLOAT NULL DEFAULT NULL,
    LoopMs      INT UNSIGNED NULL DEFAULT NULL,
    IsForeground TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = foreground media layered OVER text',
    CreatedAt   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_VariantZ (VariantId, ZIndex),
    CONSTRAINT fk_PresBgLayer_Variant FOREIGN KEY (VariantId) REFERENCES tblPresentationThemeVariants(Id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Z-ordered background/foreground media layers per variant (#1168).';

CREATE TABLE IF NOT EXISTS tblPresentationFooterOverlays (
    Id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    VariantId   INT UNSIGNED NOT NULL,
    Template    VARCHAR(500) NOT NULL COMMENT 'Token string, e.g. "{ccli} © {copyright}" — tokens resolved at render from tblSongs, NOT stored',
    Position    VARCHAR(30) NOT NULL DEFAULT 'bottom' COMMENT 'bottom|bottom_left|top_right|… — VARCHAR (rule #20)',
    FontFamily  VARCHAR(100) NULL DEFAULT NULL,
    FontSizeScale DECIMAL(5,3) NULL DEFAULT NULL,
    Colour      VARCHAR(32) NULL DEFAULT NULL,
    Opacity     DECIMAL(4,3) NULL DEFAULT NULL,
    SortOrder   INT UNSIGNED NOT NULL DEFAULT 0,
    CreatedAt   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_Variant (VariantId, SortOrder),
    CONSTRAINT fk_PresFooter_Variant FOREIGN KEY (VariantId) REFERENCES tblPresentationThemeVariants(Id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='CCLI/copyright/ref footer overlays per variant; >1 allowed (#1168).';

CREATE TABLE IF NOT EXISTS tblPresentationThemeAssignments (
    Id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ThemeId     INT UNSIGNED NOT NULL,
    AssignmentScope VARCHAR(20) NOT NULL COMMENT 'org_default|user_default|songbook|song|arrangement|component — VARCHAR (rule #20); a future setlist scope needs no ALTER',
    OrgId         INT UNSIGNED NULL DEFAULT NULL COMMENT 'Tenant: org_default anchor, or the org whose entity-override this is',
    UserId        INT UNSIGNED NULL DEFAULT NULL COMMENT 'Tenant: user_default anchor, or the user whose personal entity-override this is',
    SongbookId    INT UNSIGNED NULL DEFAULT NULL,
    SongId        VARCHAR(20)  NULL DEFAULT NULL,
    ArrangementId INT UNSIGNED NULL DEFAULT NULL,
    ComponentId   INT UNSIGNED NULL DEFAULT NULL,
    DisplayRole   VARCHAR(30)  NULL DEFAULT NULL COMMENT 'NULL = applies to all display roles; else binds only that variant',
    Priority      INT NOT NULL DEFAULT 0 COMMENT 'Tie-break within a scope level',
    CreatedAt   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_Theme (ThemeId),
    INDEX idx_Scope (AssignmentScope),
    INDEX idx_Org (OrgId),
    INDEX idx_Song (SongId),
    INDEX idx_Songbook (SongbookId),
    CONSTRAINT fk_PresAssign_Theme     FOREIGN KEY (ThemeId) REFERENCES tblPresentationThemes(Id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_PresAssign_Org       FOREIGN KEY (OrgId) REFERENCES tblOrganisations(Id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_PresAssign_User      FOREIGN KEY (UserId) REFERENCES tblUsers(Id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_PresAssign_Songbook  FOREIGN KEY (SongbookId) REFERENCES tblSongbooks(Id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_PresAssign_Song      FOREIGN KEY (SongId) REFERENCES tblSongs(SongId) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_PresAssign_Arr       FOREIGN KEY (ArrangementId) REFERENCES tblSongArrangements(Id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_PresAssign_Component FOREIGN KEY (ComponentId) REFERENCES tblSongComponents(Id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Theme→scope cascade spine (org→songbook→song→arrangement→component) with org/user tenancy; per-anchor typed FKs. Uniqueness + anchor-exclusivity are APP-enforced — a STORED generated key / CHECK cannot coexist with the FK CASCADE actions we need for cleanup (MySQL errors 3823/1215) (#1168).';

CREATE TABLE IF NOT EXISTS tblPresentationSlideOverrides (
    Id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ComponentId INT UNSIGNED NOT NULL COMMENT 'The component/slide-host (tblSongComponents.Id) this override patches',
    LyricsId    INT UNSIGNED NULL DEFAULT NULL COMMENT 'Scopes the patch to one lyrics version (tblLyrics.Id); NULL = version-agnostic',
    LyricLineId BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Optional per-LINE anchor → tblLyricLines.Id (rule #21)',
    LyricWordId BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Optional per-WORD anchor → tblLyricWords.Id for per-word karaoke styling (rule #21)',
    LineIndex   INT UNSIGNED NULL DEFAULT NULL COMMENT 'Transitional fallback index into LinesJson when no tblLyricLines row exists yet',
    DisplayRole VARCHAR(30) NULL DEFAULT NULL COMMENT 'NULL = all roles',
    StylePatchJson JSON NOT NULL COMMENT 'Sparse style patch {fontSizeScale,textColour,slideBreakAfter,highlightColour,foregroundMediaUrl,…} — STYLE ONLY, never lyric text',
    CreatedAt   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_Component (ComponentId),
    INDEX idx_Line (LyricLineId),
    INDEX idx_Word (LyricWordId),
    CONSTRAINT fk_PresOverride_Component FOREIGN KEY (ComponentId) REFERENCES tblSongComponents(Id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_PresOverride_Lyrics    FOREIGN KEY (LyricsId) REFERENCES tblLyrics(Id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_PresOverride_Line      FOREIGN KEY (LyricLineId) REFERENCES tblLyricLines(Id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_PresOverride_Word      FOREIGN KEY (LyricWordId) REFERENCES tblLyricWords(Id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-slide / per-line / per-word STYLE patch (reference-only, no lyric text). Anchors on tblLyricLines.Id / tblLyricWords.Id (rule #21) (#1168).';

CREATE TABLE IF NOT EXISTS tblPresentationThemeTags (
    Id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ThemeId   INT UNSIGNED NOT NULL,
    Tag       VARCHAR(60) NOT NULL,
    CreatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ThemeTag (ThemeId, Tag),
    CONSTRAINT fk_PresTag_Theme FOREIGN KEY (ThemeId) REFERENCES tblPresentationThemes(Id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Browse-by-theme tags, e.g. Advent / Good Friday / dark (#1148/#1168).';

CREATE TABLE IF NOT EXISTS tblPresentationFormatFidelity (
    Id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ThemeId   INT UNSIGNED NULL DEFAULT NULL,
    VariantId INT UNSIGNED NULL DEFAULT NULL,
    ComponentId INT UNSIGNED NULL DEFAULT NULL COMMENT 'Per-slide anchor (PP7 build orders are per-slide)',
    SourceFormat VARCHAR(40) NOT NULL COMMENT 'propresenter6|propresenter7|freeshow|videopsalm|openlp|gpresenter|easyworship|mediashout|proclaim — VARCHAR (rule #20)',
    SourceRef VARCHAR(200) NOT NULL DEFAULT '' COMMENT 'Element UUID / item key in the source file',
    RawJson   JSON NOT NULL COMMENT 'Lossless carry of source styling with no first-class column yet',
    CreatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_Fidelity (ThemeId, VariantId, ComponentId, SourceFormat, SourceRef),
    INDEX idx_Component (ComponentId),
    CONSTRAINT fk_PresFidelity_Theme     FOREIGN KEY (ThemeId) REFERENCES tblPresentationThemes(Id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_PresFidelity_Variant   FOREIGN KEY (VariantId) REFERENCES tblPresentationThemeVariants(Id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_PresFidelity_Component FOREIGN KEY (ComponentId) REFERENCES tblSongComponents(Id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Round-trip fidelity carrier for source styling with no first-class column; (Source,SourceRef) UNIQUE = idempotent re-import (rule #20) (#1168).';

-- ============================================================================
-- SERVICE MODE VENUES & SCHEDULES + EXTERNAL-SYSTEM INTEGRATION (#1325/#1327)
--
-- Service Mode's tables are spread across this file by arrival order, which
-- makes the feature hard to see whole. The full set, in file order:
--   tblLiveFollowSessions ..... the session spine (SessionKind='service')
--   tblLiveFollowJoinCodes .... the rotating venue join codes
--   tblServicePresence ........ anonymous congregant presence + gate token
--   tblServicePollCounters .... per-session poll budget
--   tblOrgVenues .............. (here) the physical places
--   tblOrgServiceSchedules .... (here) the recurring service times
--
-- The two below are Phase 1: they describe WHERE and WHEN, and nothing else
-- depends on them being populated. A service OCCURRENCE is not stored — it is
-- computed at read time as (ScheduleId, date), which is why the sessions table
-- carries those two columns rather than an occurrence FK.
--
-- Note what lat/lng/radius are NOT: they are a map pin and a convenience
-- geofence, never the presence gate. Geolocation is spoofable; the proof that
-- somebody is in the room is that they can read the rotating code off the
-- screen in front of them.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- tblOrgVenues (#1325) — org physical venues for "Service Mode" (#1323). lat/lng
-- + radius are a CONVENIENCE geofence + map pin, NOT the presence gate (Service
-- Mode gates on a venue rotating code). Mirror of migrate-org-venues.php.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblOrgVenues (
    Id           INT UNSIGNED      AUTO_INCREMENT PRIMARY KEY,
    OrgId        INT UNSIGNED      NOT NULL COMMENT 'FK to tblOrganisations.Id — the org this venue belongs to',
    Name         VARCHAR(150)      NOT NULL COMMENT 'Venue display name (e.g. Main Sanctuary, Church Hall)',
    AddressLine  VARCHAR(255)      NULL DEFAULT NULL COMMENT 'Street address (free text)',
    City         VARCHAR(120)      NULL DEFAULT NULL,
    Postcode     VARCHAR(20)       NULL DEFAULT NULL,
    CountryCode  CHAR(2)           NULL DEFAULT NULL COMMENT 'ISO 3166-1 alpha-2',
    PlaceId      INT UNSIGNED      NULL DEFAULT NULL COMMENT 'FK to tblPlaces.Id — geocoder-resolved place (optional)',
    Latitude     DECIMAL(10,7)     NULL DEFAULT NULL COMMENT 'Venue centroid latitude — convenience geofence + map pin, NOT the presence gate',
    Longitude    DECIMAL(10,7)     NULL DEFAULT NULL COMMENT 'Venue centroid longitude',
    RadiusMetres SMALLINT UNSIGNED NULL DEFAULT NULL COMMENT 'Convenience geofence radius; presence gate is the venue rotating code (Phase 2)',
    TimeZone     VARCHAR(40)       NOT NULL DEFAULT 'UTC' COMMENT 'IANA tz the schedules are interpreted in (e.g. Europe/London)',
    IsActive     TINYINT(1)        NOT NULL DEFAULT 1,
    SortOrder    INT UNSIGNED      NOT NULL DEFAULT 0,
    CreatedAt    TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt    TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_Org   (OrgId, IsActive),
    INDEX idx_Place (PlaceId),
    CONSTRAINT fk_OrgVenues_Org   FOREIGN KEY (OrgId)   REFERENCES tblOrganisations(Id) ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT fk_OrgVenues_Place FOREIGN KEY (PlaceId) REFERENCES tblPlaces(Id)        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Org physical venues — Service Mode Phase 1 (#1325). lat/lng/radius = convenience geofence + map pin; presence gate is the venue rotating code.';

-- ---------------------------------------------------------------------------
-- tblServiceDriverKeys (#1770 req 4/7) — durable org-scoped credentials for
-- external presentation-app drivers (ProPresenter/OpenLP shims) of Service-Mode
-- sessions. Placed AFTER tblOrgVenues (its FK target) so a fresh sequential
-- install satisfies the FK. Deliberately NO Channel column — the rule-#26 wall
-- is enforced at session-resolution time, never on the durable credential.
-- Dormant until C4.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblServiceDriverKeys (
    Id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    OrgId       INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK tblOrganisations — the org whose service sessions this key may drive. v1 mint path always sets it; app rule: exactly one of OrgId / OwnerUserId is non-NULL (#1770 req 4)',
    OwnerUserId INT UNSIGNED NULL DEFAULT NULL COMMENT 'RESERVED-DORMANT (rule #20): a future personal driver key for Quick sessions. No v1 code writes it (#1770 §10 S4)',
    VenueId     INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK tblOrgVenues — optional narrowing to one venue; NULL = any venue of the org',
    KeyHash     CHAR(64)     NOT NULL COMMENT 'SHA-256 hex of the raw key — raw value never stored (tblSessionControlTokens / tblApiKeys discipline)',
    KeyPrefix   VARCHAR(20)  NOT NULL DEFAULT '' COMMENT 'Non-secret leading chars for admin identification (mirrors tblApiKeys.KeyPrefix)',
    Label       VARCHAR(120) NOT NULL COMMENT 'Operator-facing name, e.g. "Sanctuary ProPresenter"',
    Scope       VARCHAR(40)  NOT NULL DEFAULT 'broadcast' COMMENT 'Granted capability — app-validated VARCHAR vocab, never ENUM (rule #20)',
    Protocol    VARCHAR(30)  NOT NULL DEFAULT 'generic' COMMENT 'Which shim family minted/uses it: generic | propresenter | openlp | … — display + diagnostics vocab, app-validated VARCHAR',
    IsActive    TINYINT(1)   NOT NULL DEFAULT 1,
    ExpiresAt   DATETIME     NULL DEFAULT NULL COMMENT 'Optional expiry (UTC), NULL = never. DATETIME not TIMESTAMP (rule #20 TTL convention)',
    RevokedAt   DATETIME     NULL DEFAULT NULL COMMENT 'Hard revocation instant (UTC); non-NULL = refused',
    LastUsedAt  DATETIME     NULL DEFAULT NULL,
    LastUsedIp  VARCHAR(45)  NULL DEFAULT NULL,
    CreatedBy   INT UNSIGNED NULL DEFAULT NULL COMMENT 'tblUsers.Id of the minting org-admin',
    CreatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_KeyHash (KeyHash),
    INDEX idx_Org (OrgId, IsActive),
    CONSTRAINT fk_DriverKey_Org   FOREIGN KEY (OrgId)       REFERENCES tblOrganisations(Id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_DriverKey_Venue FOREIGN KEY (VenueId)     REFERENCES tblOrgVenues(Id)     ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_DriverKey_User  FOREIGN KEY (OwnerUserId) REFERENCES tblUsers(Id)         ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_DriverKey_Creator FOREIGN KEY (CreatedBy) REFERENCES tblUsers(Id)         ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Durable org-scoped credentials for external presentation-app drivers (ProPresenter/OpenLP shims) of Service-Mode sessions (#1770 req 4/7). Deliberately NO Channel column — the wall is enforced at session-resolution time (rule #26): every resolve filters the serving docroot''s channel.';

-- ----------------------------------------------------------------------------
-- tblOrgServiceSchedules (#1325) — recurring service times per venue. The
-- service-OCCURRENCE (scheduleId,date) is computed at read time; Phase-2
-- sessions + Phase-3 CCLI unlock bind to it. RecurrenceKind is VARCHAR
-- (app-validated) not ENUM (rule #20). Mirror of migrate-org-venues.php.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblOrgServiceSchedules (
    Id             INT UNSIGNED      AUTO_INCREMENT PRIMARY KEY,
    VenueId        INT UNSIGNED      NOT NULL COMMENT 'FK to tblOrgVenues.Id',
    OrgId          INT UNSIGNED      NOT NULL COMMENT 'Denorm of the venue org (app-derived) for cheap org-scoped queries',
    Title          VARCHAR(150)      NOT NULL DEFAULT 'Service' COMMENT 'e.g. Sunday Morning, Evening Service',
    DayOfWeek      TINYINT UNSIGNED  NULL DEFAULT NULL COMMENT 'ISO-8601 1=Mon..7=Sun for recurring kinds; NULL for one_off (date in RecurrenceData)',
    StartTime      TIME              NOT NULL COMMENT 'Local service start in the effective TimeZone',
    DurationMins   SMALLINT UNSIGNED NOT NULL DEFAULT 90 COMMENT 'Service window length (drives the active-now check)',
    RecurrenceKind VARCHAR(20)       NOT NULL DEFAULT 'weekly' COMMENT 'weekly|fortnightly|monthly_nth|one_off|custom — app-validated, VARCHAR not ENUM (rule #20)',
    RecurrenceData JSON              NULL DEFAULT NULL COMMENT 'Kind-specific: {interval},{nth,dayOfWeek},{date} for one_off,{until},{exceptions:[dates]}',
    TimeZone       VARCHAR(40)       NULL DEFAULT NULL COMMENT 'Override the venue tz for this schedule; NULL = inherit the venue tz',
    IsActive       TINYINT(1)        NOT NULL DEFAULT 1,
    SortOrder      INT UNSIGNED      NOT NULL DEFAULT 0,
    CreatedAt      TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt      TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_Venue (VenueId, IsActive),
    INDEX idx_Org   (OrgId, IsActive),
    INDEX idx_Day   (DayOfWeek, StartTime),
    CONSTRAINT fk_OrgSchedules_Venue FOREIGN KEY (VenueId) REFERENCES tblOrgVenues(Id)     ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_OrgSchedules_Org   FOREIGN KEY (OrgId)   REFERENCES tblOrganisations(Id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Recurring service times per venue — Service Mode Phase 1 (#1325). The service-occurrence (scheduleId,date) is computed at read time; Phase-2 sessions + Phase-3 unlock bind to it.';

-- ----------------------------------------------------------------------------
-- External-system integration hook (#1327) — DORMANT. Lets iHymns entities be
-- linked to / synced with an external system (first: WebMS-Intra), system-
-- agnostic so a 2nd system needs no ALTER (rule #20). Per-entity dedicated ref
-- tables (rule #15, NOT a generic polymorphic FK) + a registry. Mirror of
-- migrate-external-systems.php. See .claude/live-congregant-strategy.md.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblExternalSystems (
    Id            INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    SystemKey     VARCHAR(60)   NOT NULL COMMENT 'Stable machine key (e.g. webms-intra). App resolves systems by this — NEVER hard-code the key list in PHP (rule #15)',
    Name          VARCHAR(120)  NOT NULL COMMENT 'Curator-facing display name (e.g. WebMS Intranet)',
    Description   VARCHAR(255)  NULL DEFAULT NULL COMMENT 'What this system is / what the mapping means',
    BaseUrl       VARCHAR(255)  NULL DEFAULT NULL COMMENT 'Base URL to build a deep link from a stored ExternalId; {id} placeholder substituted app-side',
    Kind          VARCHAR(30)   NOT NULL DEFAULT 'sync' COMMENT 'sync | directory | finance | rota | identity | other — app-validated, VARCHAR not ENUM (rule #20)',
    AuthScope     VARCHAR(40)   NULL DEFAULT NULL COMMENT 'Credential/realm hint the sync layer maps to a secret NAME — never the secret itself',
    IsActive      TINYINT(1)    NOT NULL DEFAULT 1 COMMENT '0 = registered but paused; mappings persist, sync skips',
    DisplayOrder  INT UNSIGNED  NOT NULL DEFAULT 0,
    CreatedAt     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_SystemKey (SystemKey),
    INDEX      idx_Active   (IsActive),
    INDEX      idx_Kind     (Kind, DisplayOrder)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Registry of external SYSTEMS an iHymns entity can be mapped/synced into (WebMS-Intra, …). SystemKey UNIQUE so keys are never hard-coded (rule #15); sibling of tblExternalLinkTypes (#833).';

CREATE TABLE IF NOT EXISTS tblOrganisationExternalRefs (
    Id            INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    OrgId         INT UNSIGNED  NOT NULL COMMENT 'FK to tblOrganisations.Id — the iHymns org',
    SystemId      INT UNSIGNED  NOT NULL COMMENT 'FK to tblExternalSystems.Id — which external system this id lives in',
    ExternalId    VARCHAR(190)  NOT NULL COMMENT 'Primary identifier of the org WITHIN the external system. 190 = utf8mb4 index-safe',
    ExternalSlug  VARCHAR(190)  NULL DEFAULT NULL COMMENT 'Optional human/slug handle in the external system',
    SyncStatus    VARCHAR(20)   NOT NULL DEFAULT 'linked' COMMENT 'linked | pending | synced | conflict | error | deprecated — app-validated, VARCHAR not ENUM (rule #20)',
    SyncDirection VARCHAR(20)   NOT NULL DEFAULT 'inbound' COMMENT 'none | inbound | outbound | bidirectional — VARCHAR not ENUM; inbound-first until a DPA exists',
    Source        VARCHAR(100)  NOT NULL DEFAULT 'webms-intra' COMMENT 'Provenance of the mapping row; part of the idempotency key',
    SourceRef     VARCHAR(190)  NULL DEFAULT NULL COMMENT 'External primary id from the Source push for idempotent re-import; NULL for a manual link (multiple NULLs coexist, rule #20)',
    LocalHash     VARCHAR(64)   NULL DEFAULT NULL COMMENT 'Fingerprint of the iHymns row at last sync — optimistic-concurrency conflict detection',
    ExternalEtag  VARCHAR(190)  NULL DEFAULT NULL COMMENT 'External side version/etag at last sync — conflict detection',
    LastSyncedAt  DATETIME      NULL DEFAULT NULL COMMENT 'Last successful sync (DATETIME not TIMESTAMP — rule #20)',
    LastError     VARCHAR(500)  NULL DEFAULT NULL COMMENT 'Last sync failure detail for operator triage (no secrets/PII)',
    LastErrorAt   DATETIME      NULL DEFAULT NULL,
    DeletedAt     DATETIME      NULL DEFAULT NULL COMMENT 'Soft-unlink (operator removed the link) — distinct from FK-CASCADE hard delete',
    MetaJson      JSON          NULL DEFAULT NULL COMMENT 'Lossless extra attrs from the external system for round-trip re-export',
    CreatedBy     INT UNSIGNED  NULL DEFAULT NULL COMMENT 'FK to tblUsers.Id — who created the link (NULL for system import)',
    CreatedAt     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_Org_System_Ext (OrgId, SystemId, ExternalId),
    UNIQUE KEY uq_System_Ext     (SystemId, ExternalId),
    UNIQUE KEY uq_SourceRef      (Source, SourceRef),
    INDEX      idx_Org           (OrgId),
    INDEX      idx_System        (SystemId),
    INDEX      idx_Status        (SyncStatus),
    CONSTRAINT fk_OrgExtRef_Org       FOREIGN KEY (OrgId)     REFERENCES tblOrganisations(Id)  ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT fk_OrgExtRef_System    FOREIGN KEY (SystemId)  REFERENCES tblExternalSystems(Id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_OrgExtRef_CreatedBy FOREIGN KEY (CreatedBy) REFERENCES tblUsers(Id)          ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-org external-system identity map (rule #15 dedicated ref table). (Source,SourceRef) UNIQUE = idempotent re-import (rule #20); SystemId in the keys = multi-system without an ALTER.';

CREATE TABLE IF NOT EXISTS tblOrgVenueExternalRefs (
    Id            INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    VenueId       INT UNSIGNED  NOT NULL COMMENT 'FK to tblOrgVenues.Id — the iHymns venue',
    OrgId         INT UNSIGNED  NOT NULL COMMENT 'Denorm of the venue org (app-derived, never client-trusted) for cheap org-scoped queries',
    SystemId      INT UNSIGNED  NOT NULL COMMENT 'FK to tblExternalSystems.Id',
    ExternalId    VARCHAR(190)  NOT NULL COMMENT 'Primary identifier of the venue WITHIN the external system',
    ExternalSlug  VARCHAR(190)  NULL DEFAULT NULL COMMENT 'Optional human/slug handle in the external system',
    SyncStatus    VARCHAR(20)   NOT NULL DEFAULT 'linked' COMMENT 'linked | pending | synced | conflict | error | deprecated — app-validated, VARCHAR not ENUM (rule #20)',
    SyncDirection VARCHAR(20)   NOT NULL DEFAULT 'inbound' COMMENT 'none | inbound | outbound | bidirectional — VARCHAR not ENUM',
    Source        VARCHAR(100)  NOT NULL DEFAULT 'webms-intra' COMMENT 'Provenance of the mapping row; part of the idempotency key',
    SourceRef     VARCHAR(190)  NULL DEFAULT NULL COMMENT 'External primary id from the Source push for idempotent re-import; NULL for a manual link',
    LocalHash     VARCHAR(64)   NULL DEFAULT NULL COMMENT 'Fingerprint of the iHymns row at last sync — conflict detection',
    ExternalEtag  VARCHAR(190)  NULL DEFAULT NULL COMMENT 'External side version/etag at last sync — conflict detection',
    LastSyncedAt  DATETIME      NULL DEFAULT NULL COMMENT 'Last successful sync (DATETIME not TIMESTAMP — rule #20)',
    LastError     VARCHAR(500)  NULL DEFAULT NULL COMMENT 'Last sync failure detail for operator triage (no secrets/PII)',
    LastErrorAt   DATETIME      NULL DEFAULT NULL,
    DeletedAt     DATETIME      NULL DEFAULT NULL COMMENT 'Soft-unlink — distinct from FK-CASCADE hard delete',
    MetaJson      JSON          NULL DEFAULT NULL COMMENT 'Lossless extra attrs for round-trip re-export',
    CreatedBy     INT UNSIGNED  NULL DEFAULT NULL COMMENT 'FK to tblUsers.Id — who created the link (NULL for system import)',
    CreatedAt     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_Venue_System_Ext (VenueId, SystemId, ExternalId),
    UNIQUE KEY uq_System_Ext       (SystemId, ExternalId),
    UNIQUE KEY uq_SourceRef        (Source, SourceRef),
    INDEX      idx_Venue           (VenueId),
    INDEX      idx_Org             (OrgId),
    INDEX      idx_System          (SystemId),
    INDEX      idx_Status          (SyncStatus),
    CONSTRAINT fk_VenueExtRef_Venue     FOREIGN KEY (VenueId)   REFERENCES tblOrgVenues(Id)      ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT fk_VenueExtRef_Org       FOREIGN KEY (OrgId)     REFERENCES tblOrganisations(Id)  ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT fk_VenueExtRef_System    FOREIGN KEY (SystemId)  REFERENCES tblExternalSystems(Id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_VenueExtRef_CreatedBy FOREIGN KEY (CreatedBy) REFERENCES tblUsers(Id)          ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-venue external-system identity map (rule #15 dedicated ref table). (Source,SourceRef) UNIQUE = idempotent re-import (rule #20); OrgId denorm mirrors tblOrgServiceSchedules.';

CREATE TABLE IF NOT EXISTS tblOrgServiceScheduleExternalRefs (
    Id            INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    ScheduleId    INT UNSIGNED  NOT NULL COMMENT 'FK to tblOrgServiceSchedules.Id — the iHymns service time',
    OrgId         INT UNSIGNED  NOT NULL COMMENT 'Denorm of the schedule org (app-derived, never client-trusted) for cheap org-scoped queries',
    SystemId      INT UNSIGNED  NOT NULL COMMENT 'FK to tblExternalSystems.Id',
    ExternalId    VARCHAR(190)  NOT NULL COMMENT 'Primary identifier of the service/event WITHIN the external system',
    ExternalSlug  VARCHAR(190)  NULL DEFAULT NULL COMMENT 'Optional human/slug handle in the external system',
    SyncStatus    VARCHAR(20)   NOT NULL DEFAULT 'linked' COMMENT 'linked | pending | synced | conflict | error | deprecated — app-validated, VARCHAR not ENUM (rule #20)',
    SyncDirection VARCHAR(20)   NOT NULL DEFAULT 'inbound' COMMENT 'none | inbound | outbound | bidirectional — VARCHAR not ENUM',
    Source        VARCHAR(100)  NOT NULL DEFAULT 'webms-intra' COMMENT 'Provenance of the mapping row; part of the idempotency key',
    SourceRef     VARCHAR(190)  NULL DEFAULT NULL COMMENT 'External primary id from the Source push for idempotent re-import; NULL for a manual link',
    LocalHash     VARCHAR(64)   NULL DEFAULT NULL COMMENT 'Fingerprint of the iHymns row at last sync — conflict detection',
    ExternalEtag  VARCHAR(190)  NULL DEFAULT NULL COMMENT 'External side version/etag at last sync — conflict detection',
    LastSyncedAt  DATETIME      NULL DEFAULT NULL COMMENT 'Last successful sync (DATETIME not TIMESTAMP — rule #20)',
    LastError     VARCHAR(500)  NULL DEFAULT NULL COMMENT 'Last sync failure detail for operator triage (no secrets/PII)',
    LastErrorAt   DATETIME      NULL DEFAULT NULL,
    DeletedAt     DATETIME      NULL DEFAULT NULL COMMENT 'Soft-unlink — distinct from FK-CASCADE hard delete',
    MetaJson      JSON          NULL DEFAULT NULL COMMENT 'Lossless extra attrs for round-trip re-export',
    CreatedBy     INT UNSIGNED  NULL DEFAULT NULL COMMENT 'FK to tblUsers.Id — who created the link (NULL for system import)',
    CreatedAt     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_Sched_System_Ext (ScheduleId, SystemId, ExternalId),
    UNIQUE KEY uq_System_Ext       (SystemId, ExternalId),
    UNIQUE KEY uq_SourceRef        (Source, SourceRef),
    INDEX      idx_Sched           (ScheduleId),
    INDEX      idx_Org             (OrgId),
    INDEX      idx_System          (SystemId),
    INDEX      idx_Status          (SyncStatus),
    CONSTRAINT fk_SchedExtRef_Sched     FOREIGN KEY (ScheduleId) REFERENCES tblOrgServiceSchedules(Id) ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT fk_SchedExtRef_Org       FOREIGN KEY (OrgId)      REFERENCES tblOrganisations(Id)     ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT fk_SchedExtRef_System    FOREIGN KEY (SystemId)   REFERENCES tblExternalSystems(Id)   ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_SchedExtRef_CreatedBy FOREIGN KEY (CreatedBy)  REFERENCES tblUsers(Id)             ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-service-schedule external-system identity map (rule #15 dedicated ref table). (Source,SourceRef) UNIQUE = idempotent re-import (rule #20); OrgId denorm mirrors tblOrgServiceSchedules.';

-- ============================================================================
-- Print templates (#1350 Phase 2) — curator-authored block-based print layouts
-- for the clean song-print path. Layout is a JSON ordered block list so adding a
-- block type / option never needs an ALTER (rule #20); Scope is VARCHAR not ENUM.
-- The 3 built-ins live in js/modules/print.js (offline); this holds custom ones.
-- Mirrors appWeb/.sql/migrate-print-templates.php (rule #19).
-- ============================================================================
CREATE TABLE IF NOT EXISTS tblPrintTemplates (
    Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    Name            VARCHAR(120)    NOT NULL COMMENT 'Curator-visible template name',
    Scope           VARCHAR(20)     NOT NULL DEFAULT 'song' COMMENT 'song | setlist | … (VARCHAR not ENUM, rule #20 — new scopes need no ALTER)',
    OwnerId         INT UNSIGNED    NULL DEFAULT NULL COMMENT 'FK tblUsers.Id — NULL = global/curated template (reserves per-user templates without a second migration)',
    BlocksJson      JSON            NOT NULL COMMENT 'Ordered block list: [{type, …options}] — title/subtitle/credits/lyrics/copyright/identifiers/spacer/pagebreak/text. Adding a block type needs no ALTER.',
    PageOptionsJson JSON            NULL DEFAULT NULL COMMENT 'Page-level options (base font pt, columns, …)',
    IsActive        TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '0 = hidden from the picker without deleting',
    IsDefault       TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = pre-selected in the picker for its scope',
    SortOrder       INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'Picker order',
    CreatedBy       INT UNSIGNED    NULL DEFAULT NULL COMMENT 'FK tblUsers.Id — who created it',
    CreatedAt       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_ScopeActive (Scope, IsActive, SortOrder),
    INDEX idx_Owner (OwnerId),

    CONSTRAINT fk_PrintTemplate_Owner
        FOREIGN KEY (OwnerId) REFERENCES tblUsers(Id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblReadRateLimit (#1354) — public-read fixed-window rate-limit counters,
-- keyed by token-or-IP (not an API key id — that's tblApiKeyUsage). Backs the
-- lightweight limiter on the heaviest sessionless reads in api.php. The Scope
-- column reserves per-endpoint limits without a future migration (rule #20);
-- the limiter (includes/read_rate_limit.php) is FAIL-OPEN, so an un-migrated
-- install is a clean no-op (#1228 white-screen lesson).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblReadRateLimit (
    Id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    RateKey      VARCHAR(72)     NOT NULL COMMENT 'sha256 hex of the bearer token, or ip:<addr>',
    Scope        VARCHAR(40)     NOT NULL DEFAULT '' COMMENT 'endpoint group, so different reads can have different limits without a 2nd migration',
    WindowType   VARCHAR(10)     NOT NULL COMMENT 'minute | day',
    WindowStart  DATETIME        NOT NULL COMMENT 'fixed-window start (UTC, minute- or day-truncated)',
    RequestCount INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'requests counted in this (key, scope, window) bucket',

    UNIQUE KEY uq_read_rl (RateKey, Scope, WindowType, WindowStart),
    INDEX      idx_WindowStart (WindowStart)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Public-read fixed-window rate-limit counters, keyed by token-or-IP (#1354).';


-- ----------------------------------------------------------------------------
-- tblAppAnalyticsEvents (#1448) — first-party, anonymous analytics events
-- ingested from native app clients (`?action=analytics_ingest` in api.php).
-- Deliberately carries NO user/device identifier and NO IP address column —
-- see includes/analytics_ingest.php's header for the full PII/retention
-- stance. EventName is an app-level allow-listed VARCHAR (rule #20, growable
-- vocabulary — never an ENUM).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblAppAnalyticsEvents (
    Id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    EventName       VARCHAR(64)     NOT NULL COMMENT 'app-level allow-listed event name, e.g. song_opened -- VARCHAR not ENUM per CLAUDE.md rule #20 (growable vocabulary)',
    ParametersJson  JSON            NULL COMMENT 'small, non-identifying event context -- flat string map, no PII, validated + length-capped before insert',
    ClientTimestamp DATETIME        NULL COMMENT 'client-reported event time (UTC), best-effort only -- never trusted for anything except display/ordering',
    Platform        VARCHAR(20)     NOT NULL DEFAULT 'unknown' COMMENT 'reporting client platform, e.g. apple | android | web',
    ReceivedAt      DATETIME        NOT NULL COMMENT 'server receipt time (UTC, set via UTC_TIMESTAMP() at insert) -- the trustworthy timestamp for querying/retention',

    INDEX idx_EventName_ReceivedAt (EventName, ReceivedAt),
    INDEX idx_ReceivedAt (ReceivedAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='First-party anonymous analytics events ingested from native app clients (#1448). Deliberately carries NO user/device identifier and NO IP address -- abuse mitigation is via per-IP request rate limiting on the ingest endpoint (tblLoginAttempts reuse via checkRateLimit()), not by retaining IP alongside the event.';

-- ============================================================================
-- AUTH PROVIDERS (Sign in with Apple) — #1402
-- External identity-provider links + a single-use anti-replay nonce ledger.
-- Mirrors appWeb/.sql/migrate-user-auth-providers.php (rule #19 — DDL here
-- must stay byte-identical, COMMENT text included).
-- ============================================================================

-- ----------------------------------------------------------------------------
-- tblUserAuthProviders (#1402)
-- External identity-provider links (Sign in with Apple first; forward-looking
-- for any future provider — Provider is VARCHAR, app-validated, never ENUM,
-- rule #20). Identity is ALWAYS (Provider, Subject) — NEVER email (Apple
-- private-relay addresses are per-app aliases the user can disable/rotate).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblUserAuthProviders (
    Id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    UserId          INT UNSIGNED    NOT NULL COMMENT 'FK to tblUsers — the iHymns account this provider identity signs in as',
    Provider        VARCHAR(30)     NOT NULL COMMENT 'apple | (future: google, …) — app-validated vocabulary, VARCHAR not ENUM (rule #20)',
    Subject         VARCHAR(128)    NOT NULL COMMENT 'Provider-stable subject claim (Apple JWT `sub`, e.g. 001234.<32hex>.5678) — the ONE durable identity key',
    Email           VARCHAR(255)    NOT NULL DEFAULT '' COMMENT 'Provider-asserted email at link time (may be @privaterelay.appleid.com); INFORMATIONAL ONLY — never an identity key',
    EmailIsPrivateRelay TINYINT(1)  NOT NULL DEFAULT 0 COMMENT '1 = Email is an Apple private-relay alias (host privaterelay.appleid.com)',
    RefreshToken    TEXT            NULL COMMENT 'Provider refresh token (Apple: minted by /auth/token code exchange at first sign-in) — required by account_delete to call Apple /auth/revoke. Useless without the owner-provisioned .p8 client secret; NEVER logged, NEVER echoed by any API',
    IdentityJson    JSON            NULL COMMENT 'Forward-looking provider extras captured at link time: fullName snapshot (Apple sends it ONLY on first authorization), real_user_status, email_verified — additive without a second migration (rule #20)',
    LastLoginAt     TIMESTAMP       NULL DEFAULT NULL COMMENT 'Last successful sign-in through this provider link',
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_provider_subject (Provider, Subject),
    UNIQUE KEY uq_user_provider    (UserId, Provider),
    INDEX      idx_User            (UserId),

    CONSTRAINT fk_AuthProviders_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- tblAuthNonces (#1402)
-- Single-use nonce consumption ledger (anti-replay) for provider sign-in
-- assertions. A nonce is CONSUMED by inserting its hash; the UNIQUE key makes
-- a second consumption a duplicate-key error = replay = reject. Purpose is a
-- VARCHAR discriminator (rule #20) so future flows (siwa_reauth, handoff, …)
-- share the table without a second migration. Rows are prunable after
-- ExpiresAt (DATETIME not TIMESTAMP — the #1066 TTL convention).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblAuthNonces (
    Id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    Purpose         VARCHAR(30)     NOT NULL COMMENT 'siwa_login | siwa_reauth | … — app-validated vocabulary, VARCHAR not ENUM (rule #20)',
    NonceHash       CHAR(64)        NOT NULL COMMENT 'sha256 hex of the RAW client nonce (raw nonce never stored)',
    UsedAt          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ExpiresAt       DATETIME        NOT NULL COMMENT 'Prune horizon (UsedAt + 15 min — longer than any Apple identity-token exp window)',

    UNIQUE KEY uq_purpose_nonce (Purpose, NonceHash),
    INDEX      idx_Expires      (ExpiresAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- ADMIN-CONFIGURABLE FEATURE GATING (#1481) — extends the #1352/#1353 TIER_CAPS
-- registry so a Global Admin can define + gate new capabilities from the UI
-- without dev code. Mirrors appWeb/.sql/migrate-add-gating-registry.php (rule
-- #19 — DDL here must stay byte-identical, COMMENT text included). Both
-- tables created in ONE pass (rule #20) even though only tblGatingCapabilities
-- is enforced this release — tblGatingRules is P2 schema, DORMANT until its
-- enforcement loop ships. Additive: with tblGatingCapabilities empty,
-- tierCapsEffective() === TIER_CAPS byte-for-byte (see
-- tests/php/test-gating-registry.php).
-- ============================================================================

-- ----------------------------------------------------------------------------
-- tblGatingCapabilities (#1481 P1) — admin-defined capability DEFINITIONS.
-- Each Enabled=1 row is unioned into TIER_CAPS by tierCapsEffective() in
-- includes/access_tier_validation.php. Per-tier VALUES still ride the
-- existing tblAccessTiers.Capabilities JSON column (#1352) — this table only
-- holds the definition (key/label/description/default/emit/enabled).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblGatingCapabilities (
    Id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    CapKey        VARCHAR(40)  NOT NULL COMMENT 'PascalCase capability key, grammar ^Can[A-Z][A-Za-z0-9]{1,29}$ (app-validated) -- unioned into TIER_CAPS by tierCapsEffective(); a code-key collision (case-insensitive, incl. camelCase) is ignored, code wins',
    Label         VARCHAR(30)  NOT NULL COMMENT 'Short column-header label shown on /manage/tiers and /manage/feature-gating',
    Description   VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Checkbox tooltip / hint text',
    DefaultValue  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Assumed 0/1 value when a tier has not set this cap (mirrors the TIER_CAPS 4-tuple default slot)',
    EmitInApi     TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1 = surfaced as a camelCase key on the public access_tiers API emit (rule #28 contract stays additive-only -- the 7 built-ins are unaffected)',
    EnforceJson   JSON         NULL DEFAULT NULL COMMENT 'Enforcement mapping this admin-defined cap CARRIES (#1769 P1): object keyed by behaviour kind from the includes/gating_rules.php catalog (strip_payload_keys | drop_media_kinds | requiresCoverage | ...), value = kind-specific params. Mirrors the additive 5th enforce element TIER_CAPS gains in P2 so a cap definition and its enforcement live in ONE place and tblGatingRules can retire (P5). NULL = definition-only cap (identical to pre-#1769 behaviour -- what keeps this dormant). App-validated growable JSON, never ENUM (rule #20)',
    Enabled       TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0 = soft-disabled -- excluded from the tierCapsEffective() union but kept for audit/history; hard-delete is separate and blocked while tblGatingRules references the CapKey',
    SortOrder     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Display order on the /manage/tiers matrix and the /manage/feature-gating list',
    Source        VARCHAR(20)  NOT NULL DEFAULT 'admin' COMMENT 'provenance vocabulary -- VARCHAR not ENUM (rule #20); "admin" today, room to grow (e.g. a future bulk-import source) with no ALTER',
    CreatedBy     INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK tblUsers.Id -- who defined this capability',
    UpdatedBy     INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK tblUsers.Id -- who last edited it',
    CreatedAt     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_GatingCap_CapKey (CapKey),
    INDEX      idx_GatingCap_Enabled (Enabled, SortOrder),

    CONSTRAINT fk_GatingCap_CreatedBy FOREIGN KEY (CreatedBy) REFERENCES tblUsers(Id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_GatingCap_UpdatedBy FOREIGN KEY (UpdatedBy) REFERENCES tblUsers(Id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Admin-defined gating capabilities (#1481 P1) -- unioned into TIER_CAPS by tierCapsEffective(); a code-key collision (case-insensitive, incl. camelCase) is ignored (code wins).';

-- ----------------------------------------------------------------------------
-- tblGatingRules (#1481 P2). Maps a DB-defined capability to a behaviour-kind
-- + kind-specific JSON params. Rows are born Enabled=0; entirely DORMANT until
-- BOTH content_gating_enabled and feature_gating_rules_enabled are '1'.
--
-- (This header used to end "the enforcement loop that reads this table is a
-- SEPARATE, not-yet-built change". P2 HAS since shipped: the loop is
-- gatingRulesApply() in includes/gating_rules.php, invoked from
-- contentGatingApply() in includes/content_gating.php, with the rules CRUD UI
-- at /manage/feature-gating. Dormant-by-flag and not-yet-written are very
-- different states and this comment was asserting the wrong one.)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblGatingRules (
    Id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    CapKey        VARCHAR(40)  NOT NULL COMMENT 'FK tblGatingCapabilities.CapKey -- P2 v1 rules key on DB-defined caps ONLY, never the 7 built-ins (preserves the Service-Mode presence grant + the CCLI gate)',
    BehaviorKind  VARCHAR(30)  NOT NULL COMMENT 'strip_payload_keys | drop_media_kinds -- VARCHAR not ENUM (rule #20); catalog lives in includes/gating_rules.php (P2, not yet built)',
    ParamsJson    JSON         NULL DEFAULT NULL COMMENT 'Kind-specific parameters, e.g. {"keys":[...]} or {"kinds":[...]} -- NULL is treated as empty by the P2 enforcement loop',
    Scope         VARCHAR(40)  NOT NULL DEFAULT '' COMMENT 'Reserved scoping discriminator (e.g. a future per-surface/per-songbook scope) -- NOT NULL so it can sit inside a UNIQUE key without NULL-permits-duplicates (rule #20 lesson from tblApiKeyUsage)',
    Enabled       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Rules are born DISABLED -- the P2 enforcement loop only applies Enabled=1 rows, and only when feature_gating_rules_enabled=1 AND content_gating_enabled=1 (two-flag nested topology)',
    SortOrder     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Apply order when multiple rules target the same capability',
    Source        VARCHAR(20)  NOT NULL DEFAULT 'admin' COMMENT 'provenance vocabulary -- VARCHAR not ENUM (rule #20)',
    CreatedBy     INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK tblUsers.Id -- who defined this rule',
    UpdatedBy     INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK tblUsers.Id -- who last edited it',
    CreatedAt     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_GatingRule (CapKey, BehaviorKind, Scope),
    INDEX      idx_GatingRule_Enabled (Enabled, SortOrder),

    CONSTRAINT fk_GatingRule_CapKey    FOREIGN KEY (CapKey)    REFERENCES tblGatingCapabilities(CapKey) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_GatingRule_CreatedBy FOREIGN KEY (CreatedBy) REFERENCES tblUsers(Id)              ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_GatingRule_UpdatedBy FOREIGN KEY (UpdatedBy) REFERENCES tblUsers(Id)              ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Admin-defined enforcement rules (#1481 P2, schema created in P1 one-pass per rule #20) -- cap-to-behaviour-kind mapping; DORMANT until P2 ships the enforcement loop and both feature flags are on.';

-- ----------------------------------------------------------------------------
-- tblIntAppsSync (#1725/#1727). MWBM-IntAppsAPI gateway local snapshot +
-- refresh bookkeeping. Dormant until tblAppSettings.intappsapi_enabled_channels
-- names the current channel AND includes/intapps_client.php is live; fail-open
-- contract (a failed/malformed fetch never overwrites PayloadJson/FetchedAt)
-- lives entirely in that module, not here. One-pass DDL (rule #20): the UNIQUE
-- key (Scope, Channel, AppSlug) reserves multiplicity for future scopes
-- ('updates'/'notifications'/'status'), per-docroot channels, and a second
-- registered gateway app/slug, so none of those become a second migration.
-- DDL is byte-identical to appWeb/.sql/migrate-add-intapps-sync.php (rule #19).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblIntAppsSync (
    Id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Surrogate PK',
    Scope               VARCHAR(30)  NOT NULL COMMENT 'Gateway data family this row caches: features|updates|notifications|status. App-validated vocabulary (rule #20: VARCHAR, never ENUM); only "features" is read today',
    Channel             VARCHAR(20)  NOT NULL DEFAULT '' COMMENT 'Docroot discriminator (serviceMode_channel/ihymns_environment value); empty string = shared across all three docroots. Reserved multiplicity per rule #20/#26; default operation uses only the empty-string row',
    AppSlug             VARCHAR(50)  NOT NULL DEFAULT '' COMMENT 'Gateway app slug this row caches; empty string = the primary ihymns app. Reserved multiplicity for a second registered gateway app, per-platform or per-env (rule #20); default operation uses only the empty-string value',
    PayloadJson         JSON         NULL COMMENT 'Last-known-GOOD decoded gateway envelope data. Never overwritten by a failed or malformed fetch',
    FetchedAt           DATETIME     NULL COMMENT 'UTC time of last SUCCESSFUL fetch. NULL = cold. DATETIME not TIMESTAMP (house rule, #1066)',
    AttemptedAt         DATETIME     NULL COMMENT 'UTC time of last attempt, success or failure -- drives exponential backoff',
    RefreshLockedUntil  DATETIME     NULL COMMENT 'Single-flight lock: a request that wins the conditional UPDATE owns the refresh until this UTC time; stale locks self-expire',
    LastHttpStatus      SMALLINT UNSIGNED NULL COMMENT 'HTTP status of the last attempt; NULL = transport-level failure (no answer at all)',
    LastErrorCode       VARCHAR(50)  NULL COMMENT 'Gateway envelope error.code of the last failure (ACCESS_DENIED, RATE_LIMITED, BAD_SHAPE, ...) for the admin status card; never the message prose',
    ConsecutiveFailures INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Failure streak since the last success; backoff = LEAST(300*2^n, 3600) seconds',

    PRIMARY KEY (Id),
    UNIQUE KEY uq_IntAppsSync_ScopeChannelApp (Scope, Channel, AppSlug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='IntAppsAPI gateway local snapshot + refresh bookkeeping (#1725/#1727). Dormant until tblAppSettings.intappsapi_enabled_channels names the current channel; fail-open contract lives in includes/intapps_client.php.';

-- ============================================================================
-- GATING FACTS + LICENCE-TYPE REGISTRY (#1769 P1, Model 2) — the one-pass
-- additive-dormant schema batch for the access-model consolidation. Creates
-- the #459 licence vocabulary table and the per-entity rights-requirement
-- FACT columns (mirrored above on tblSongs / tblSongbooks / tblSongArrangements
-- / tblGatingCapabilities). EVERYTHING here is DORMANT: no code reads any of
-- these objects until #1769 P2 wires the licence registry + resolver, and the
-- whole family only acts when tblAppSettings.content_gating_enabled = 1
-- (rule #28 A). Mirrors appWeb/.sql/migrate-add-gating-facts-and-licence-types.php
-- (rule #19 — DDL stays token-identical, COMMENT text included).
-- ============================================================================

-- ----------------------------------------------------------------------------
-- tblLicenceTypes (#459, built by #1769 P1) — THE licence vocabulary + per-type
-- metadata. Collapses the six hardcoded licence-vocab sites (organisations.php,
-- restrictions.php, my-organisations.php, the ccli_validator licence-to-tier
-- map, and the two divergent schema comments). A licence type declares WHAT IT
-- LEGALLY COVERS (CoversJson) and WHICH TIER IT CONFERS (ConfersTier — the
-- previously hidden bridge, now a visible attribute). The ABSENCE of a licence
-- is the absence of a row; there is deliberately NO "none" row (all three
-- licence stores filter LicenceType <> "none" before the vocabulary is read).
-- P2 keeps the legacy hardcoded maps as the un-migrated fallback.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblLicenceTypes (
    Id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    LicenceKey     VARCHAR(30)  NOT NULL COMMENT 'THE licence vocabulary token (#459/#1769) -- the value every licence store writes (tblOrganisations.LicenceType, tblOrganisationLicences.LicenceType, tblContentLicences.LicenceType) and every requirement fact names (tblSongs LyricsRightsLicenceKey/MusicRightsLicenceKey, require_licence restriction targets). Lowercase snake, grammar ^[a-z][a-z0-9_]{1,29}$ app-validated in includes/licence_registry.php (P2) -- VARCHAR never ENUM (rule #20). Absence of a licence is the ABSENCE of a row, not a none row',
    Label          VARCHAR(100) NOT NULL COMMENT 'Curator-facing display name, e.g. CCLI, iHymns Pro',
    Description    VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Curator-facing hint text shown in pickers and on the admin Licences tab',
    ConfersTier    VARCHAR(30)  NULL DEFAULT NULL COMMENT 'tblAccessTiers.Name this licence ALSO grants its holders -- the now-visible licence-to-tier bridge that was the hidden hardcoded map in ccli_validator.php (#1769 OV-6). NULL = confers no tier. NO FK, degrade-safe like tblUsers.AccessTier: an unknown or renamed tier degrades to no conferral, never RESTRICT-fails',
    CoversJson     JSON         NULL DEFAULT NULL COMMENT 'Coverage SET this licence legally unlocks: object keyed by coverage kind -- lyrics_display | lyrics_print | music_reproduction | audio_playback (growable app-validated vocabulary, LICENCE_COVERAGE_KINDS in includes/licence_registry.php, P2; VARCHAR-in-JSON never ENUM, rule #20) -- value = per-kind qualifier object, {} = unqualified, RESERVED for future regional/time-box qualifiers so richer coverage never needs an ALTER. NULL = no legal coverage (a plan-conferral licence acts through ConfersTier only)',
    RequiresNumber TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = recording this licence needs a licence number entered (CCLI/MRL); drives P4 form validation only, never enforcement',
    Authority      VARCHAR(30)  NULL DEFAULT NULL COMMENT 'RESERVED (#1769 P1): issuing-body vocabulary -- ccli | onelicense | ihymns | ... (app-validated, VARCHAR never ENUM). NULL = unspecified. Nothing reads it yet; exists so a per-issuer picker grouping or validation route never needs an ALTER',
    AuthorityRef   VARCHAR(50)  NULL DEFAULT NULL COMMENT 'RESERVED (#1769 P1): the issuing-authority reference for this licence product (e.g. a CCLI product/programme code) -- free text, NULL = none. Dormant sibling of tblSongTags.CcliThemeId',
    Enabled        TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0 = soft-disabled -- excluded from pickers and the P2 registry union but kept so historical org-licence rows keep resolving their label; hard-delete is refused while any store references the key (app-enforced -- no FK exists to enforce it)',
    SortOrder      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Display order in pickers and on the admin Licences tab',
    Source         VARCHAR(20)  NOT NULL DEFAULT 'curator' COMMENT 'Provenance vocabulary -- system (shipped seed) | curator (admin-created); VARCHAR never ENUM (rule #20)',
    CreatedBy      INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK tblUsers.Id -- who created this licence type (NULL for seeds)',
    UpdatedBy      INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK tblUsers.Id -- who last edited it',
    CreatedAt      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_LicenceTypes_Key      (LicenceKey),
    INDEX      idx_LicenceTypes_Enabled (Enabled, SortOrder),

    CONSTRAINT fk_LicenceTypes_CreatedBy FOREIGN KEY (CreatedBy) REFERENCES tblUsers(Id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_LicenceTypes_UpdatedBy FOREIGN KEY (UpdatedBy) REFERENCES tblUsers(Id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='THE licence-type vocabulary + per-type metadata (#459, built by #1769 P1) -- collapses the six hardcoded licence-vocab sites. DORMANT until includes/licence_registry.php (P2) reads it; the legacy hardcoded maps survive as the un-migrated fallback.';

-- Seed the licence-type vocabulary (#1769 P1). INSERT IGNORE keyed on the
-- uq_LicenceTypes_Key UNIQUE: re-running never clobbers curator edits to a
-- row. ConfersTier mirrors the legacy hardcoded licence-to-tier map in
-- ccli_validator.php (ccli->ccli, ihymns_basic->free, ihymns_pro->premium);
-- mrl and custom confer NO tier (#1769 sub-question 5). mrl ships Enabled=1
-- because my-organisations.php already records mrl licences today and the
-- whole family is dormant behind content_gating_enabled anyway (rule #28 A).
INSERT IGNORE INTO tblLicenceTypes
    (LicenceKey, Label, Description, ConfersTier, CoversJson, RequiresNumber, Authority, AuthorityRef, Enabled, SortOrder, Source) VALUES
    ('ccli',         'CCLI',         'Christian Copyright Licensing International -- Church Copyright Licence. Covers displaying and printing copyrighted LYRICS for congregational use. Licence number required.', 'ccli',    '{"lyrics_display": {}, "lyrics_print": {}}', 1, 'ccli',   NULL, 1, 10, 'system'),
    ('mrl',          'MRL',          'Music Reproduction Licence (CCLI) -- covers reproducing the MUSICAL WORK: sheet music, chord charts, MusicXML, MIDI (#1768). Licence number required.',                       NULL,      '{"music_reproduction": {}}',                 1, 'ccli',   NULL, 1, 20, 'system'),
    ('ihymns_basic', 'iHymns Basic', 'Free iHymns plan licence -- public-domain songs only. Confers the free tier; carries no legal coverage of its own.',                                                          'free',    NULL,                                         0, 'ihymns', NULL, 1, 30, 'system'),
    ('ihymns_pro',   'iHymns Pro',   'Paid iHymns plan licence -- full catalogue access. Confers the premium tier; carries no legal coverage of its own.',                                                          'premium', NULL,                                         0, 'ihymns', NULL, 1, 40, 'system'),
    ('custom',       'Custom',       'Free-form licence recorded by an organisation. Coverage and tier conferral are set per install by a curator.',                                                                NULL,      NULL,                                         0, NULL,     NULL, 1, 50, 'system');

-- feature_gating_rules_enabled was never seeded (#1769 P0 finding, deferred to
-- this batch). Reads already default to 0 when the row is absent, so this is a
-- pure make-the-switch-visible seed, not a behaviour change.
INSERT IGNORE INTO tblAppSettings (SettingKey, SettingValue, Description) VALUES
    ('feature_gating_rules_enabled', '0', 'Enable the admin-defined payload-rules engine (/manage/feature-gating). Nested under content_gating_enabled -- rules apply only when BOTH are 1. Seeded 0 by #1769 P1 (deferred from P0).');

-- =====================================================================
-- DEFERRED FOREIGN KEYS (#1708)
--
-- ELI5: these links point at tables that are created further down this
-- file, so they have to wait until everything exists.
--
-- InnoDB requires the referenced table to EXIST when an inline FOREIGN KEY
-- is declared. Declared inline, each of these referenced a table defined
-- LATER in this file, so a fresh install died with
--   ERROR 1005 (errno: 150 "Foreign key constraint is incorrectly formed")
-- and produced 16 of 136 tables. Nobody noticed because long-running
-- installs are built incrementally by migration cards and never execute
-- this file end to end.
--
-- Moving them here is the idiom this file already uses for the same reason
-- (see fk_Songs_Tune and fk_Songs_DeletedBy above). tests/php/
-- test-schema-installs.php now proves the whole file builds.
-- =====================================================================
ALTER TABLE tblLyrics ADD CONSTRAINT fk_Lyrics_SubmittedBy FOREIGN KEY (SubmittedBy) REFERENCES tblUsers(Id) ON DELETE SET NULL;
ALTER TABLE tblLyrics ADD CONSTRAINT fk_Lyrics_ApprovedBy FOREIGN KEY (ApprovedBy) REFERENCES tblUsers(Id) ON DELETE SET NULL;
ALTER TABLE tblApiKeys ADD CONSTRAINT fk_ApiKeys_CreatedBy FOREIGN KEY (CreatedBy) REFERENCES tblUsers(Id) ON DELETE SET NULL;
ALTER TABLE tblLiveFollowSessions ADD CONSTRAINT fk_LiveFollow_Venue FOREIGN KEY (VenueId) REFERENCES tblOrgVenues(Id) ON DELETE SET NULL ON UPDATE CASCADE;
ALTER TABLE tblLiveFollowSessions ADD CONSTRAINT fk_LiveFollow_Schedule FOREIGN KEY (ScheduleId) REFERENCES tblOrgServiceSchedules(Id) ON DELETE SET NULL ON UPDATE CASCADE;

