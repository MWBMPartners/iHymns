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
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongbooks (
    Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    Abbreviation    VARCHAR(10)     NOT NULL UNIQUE,
    Name            VARCHAR(255)    NOT NULL,
    SongCount       INT UNSIGNED    NOT NULL DEFAULT 0,
    DisplayOrder    INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'Explicit sort order for listings / filter dropdowns',
    Colour          VARCHAR(7)      NOT NULL DEFAULT '' COMMENT 'Badge colour hex #RRGGBB (empty = theme default)',
    IsOfficial      TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = published hymnal; 0 = curated grouping / pseudo-songbook (#502)',
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
-- vs "SDA Church"). Same shape as tblCreditPeople below.
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
    Number              INT UNSIGNED    NULL DEFAULT NULL COMMENT 'Song number within its songbook; NULL for Misc (unstructured collection)',
    Title               VARCHAR(500)    NOT NULL,
    NormalizedTitle     VARCHAR(500)    NOT NULL DEFAULT '' COMMENT 'App-maintained fold of Title (iconv ASCII//TRANSLIT + mb_strtolower + unicode-property strip via ihymns_normalize_title()) for a fast indexed dedup/match pre-filter; the exact compare still runs in PHP. Plain column (not GENERATED) because MySQL 8 cannot reproduce the PHP normalizer. Backfilled on migrate; kept in sync on create/edit (#1066 Theme D)',
    SongbookAbbr        VARCHAR(10)     NOT NULL COMMENT 'FK to tblSongbooks.Abbreviation; the songbook NAME is read live via JOIN to tblSongbooks.Name (de-normalised SongbookName dropped in WS-E #1013 ph2)',
    Language            VARCHAR(35)     NOT NULL DEFAULT 'en' COMMENT 'IETF BCP 47 tag (language[-script][-region]); widened from VARCHAR(10) to fit script + region subtags (#681)',
    Copyright           VARCHAR(500)    NOT NULL DEFAULT '',
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

    INDEX idx_Songbook          (SongbookAbbr),
    INDEX idx_SongbookNumber    (SongbookAbbr, Number),
    INDEX idx_NormalizedTitle   (NormalizedTitle),
    INDEX idx_TuneName          (TuneName),
    INDEX idx_TuneId            (TuneId),
    INDEX idx_OriginCityId      (OriginCityId),
    INDEX idx_Genre             (Genre),
    INDEX idx_Isrc              (Isrc),
    FULLTEXT idx_TitleFt        (Title),
    FULLTEXT idx_LyricsFt       (LyricsText),
    FULLTEXT idx_TitleLyricsFt  (Title, LyricsText),
    FULLTEXT ft_LyricsTextFolded (LyricsTextFolded),

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
-- tblCreditPeople (#545)
-- Registry of people credited on songs. Holds the canonical Name plus
-- optional biographical metadata. The five song-credit tables above
-- (tblSongWriters / tblSongComposers / tblSongArrangers / tblSongAdaptors
-- / tblSongTranslators) continue to store free-text Name strings — this
-- registry is additive, not a foreign-key on those five. Rename / merge
-- operations bulk-UPDATE the Name column across all five tables (and the
-- registry row) inside a single transaction, leaving the existing schema
-- intact.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblCreditPeople (
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
    /* Structured-name parts (#934). Backfilled from Name on first run
       of migrate-credit-people-name-parts.php; new inserts populate
       these alongside the canonical Name string. Group / special-case
       rows leave these NULL — only real individuals get split. */
    FirstNames      VARCHAR(255)    NULL,
    Surname         VARCHAR(255)    NULL,
    Suffix          VARCHAR(64)     NULL,
    Notes           TEXT            NULL,
    BirthPlace      VARCHAR(255)    NULL,
    /* FK into tblPlaces; nullable for legacy / free-text rows where
       no canonical place was picked from the geocoder. BirthPlace
       stays alongside as a denormalised display string so reports
       and read paths don't need a JOIN. */
    BirthPlaceId    INT UNSIGNED    NULL,
    BirthDate       DATE            NULL,
    DeathPlace      VARCHAR(255)    NULL,
    DeathPlaceId    INT UNSIGNED    NULL,
    DeathDate       DATE            NULL,
    CreatedAt       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_Name (Name),
    UNIQUE KEY uq_MbArtist (MusicBrainzArtistMBID),
    INDEX idx_Name (Name),
    INDEX idx_Slug (Slug),
    INDEX idx_BirthPlaceId (BirthPlaceId),
    INDEX idx_DeathPlaceId (DeathPlaceId),

    CONSTRAINT fk_CreditPeople_BirthPlace
        FOREIGN KEY (BirthPlaceId) REFERENCES tblPlaces(Id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_CreditPeople_DeathPlace
        FOREIGN KEY (DeathPlaceId) REFERENCES tblPlaces(Id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblCreditPersonLinks (#545)
-- Multiple external reference links per person (Wikipedia, official
-- website, MusicBrainz, Discogs, IMSLP, Hymnary, other). LinkType is a
-- short string key the UI maps to a friendly label and icon; storing as
-- VARCHAR rather than ENUM keeps new categories cheap to add. SortOrder
-- preserves admin-controlled display order. ON DELETE CASCADE removes
-- the links automatically when the parent registry row is deleted.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblCreditPersonLinks (
    Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    CreditPersonId  INT UNSIGNED    NOT NULL,
    LinkType        VARCHAR(64)     NOT NULL,
    Url             VARCHAR(2048)   NOT NULL,
    Label           VARCHAR(255)    NULL,
    SortOrder       SMALLINT        NOT NULL DEFAULT 0,
    CreatedAt       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_CreditPersonId (CreditPersonId),

    CONSTRAINT fk_CreditPersonLinks_Person
        FOREIGN KEY (CreditPersonId) REFERENCES tblCreditPeople(Id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblCreditPersonIPI (#545)
-- IPI (Interested Parties Information) Name Numbers per person. A single
-- individual can be registered under more than one IPI Name Number when
-- they use multiple performing names — hence one-to-many on the registry
-- row. UNIQUE on (CreditPersonId, IPINumber) prevents duplicate IPIs per
-- person while still allowing the same number to legitimately attach to
-- two different registry rows if the data demands it. NameUsed is the
-- spelling that IPI is registered under (often differs from the canonical
-- registry Name). ON DELETE CASCADE matches the links table.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblCreditPersonIPI (
    Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    CreditPersonId  INT UNSIGNED    NOT NULL,
    IPINumber       VARCHAR(32)     NOT NULL,
    NameUsed        VARCHAR(255)    NULL,
    Notes           VARCHAR(255)    NULL,
    CreatedAt       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_PersonIPI (CreditPersonId, IPINumber),
    INDEX idx_IPINumber (IPINumber),

    CONSTRAINT fk_CreditPersonIPI_Person
        FOREIGN KEY (CreditPersonId) REFERENCES tblCreditPeople(Id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblCreditPersonIdentifiers
-- Unified MusicBrainz-style identifier table: holds IPI Name Numbers AND
-- ISNI (International Standard Name Identifier) rows side by side, with
-- IdentifierType discriminating. Replaces the per-kind tblCreditPersonIPI
-- table going forward; the legacy table stays as a one-release rollback
-- snapshot and is dropped in a follow-up migration.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblCreditPersonIdentifiers (
    Id              INT UNSIGNED       AUTO_INCREMENT PRIMARY KEY,
    CreditPersonId  INT UNSIGNED       NOT NULL,
    IdentifierType  VARCHAR(20)        NOT NULL COMMENT 'ipi | isni | cae | ipi-base | <pro-id> (app-validated; widened from ENUM #1090 P6 so new industry identifier types need no ALTER)',
    IdentifierValue VARCHAR(64)        NOT NULL,
    NameUsed        VARCHAR(255)       NULL,
    Notes           VARCHAR(255)       NULL,
    CreatedAt       DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP
                                       ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_PersonIdValue (CreditPersonId, IdentifierType, IdentifierValue),
    INDEX idx_TypeValue (IdentifierType, IdentifierValue),

    CONSTRAINT fk_CreditPersonId_Person
        FOREIGN KEY (CreditPersonId) REFERENCES tblCreditPeople(Id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


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
        FOREIGN KEY (SongId) REFERENCES tblSongs(SongId) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_Lyrics_SubmittedBy
        FOREIGN KEY (SubmittedBy) REFERENCES tblUsers(Id) ON DELETE SET NULL,
    CONSTRAINT fk_Lyrics_ApprovedBy
        FOREIGN KEY (ApprovedBy) REFERENCES tblUsers(Id) ON DELETE SET NULL
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
    INDEX idx_Active (Active),

    CONSTRAINT fk_ApiKeys_CreatedBy
        FOREIGN KEY (CreatedBy) REFERENCES tblUsers(Id) ON DELETE SET NULL
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

    INDEX idx_Role      (Role),
    INDEX idx_Email     (Email),
    INDEX idx_Group     (GroupId),

    CONSTRAINT fk_Users_Group
        FOREIGN KEY (GroupId) REFERENCES tblUserGroups(Id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblSessions
-- Server-side session records for the admin panel (/manage/).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSessions (
    Id              VARCHAR(128)    NOT NULL PRIMARY KEY,
    UserId          INT UNSIGNED    NOT NULL,
    IpAddress       VARCHAR(45)     NULL COMMENT 'IPv4 or IPv6',
    UserAgent       TEXT            NULL,
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ExpiresAt       TIMESTAMP       NOT NULL,

    INDEX idx_User      (UserId),
    INDEX idx_Expires   (ExpiresAt),

    CONSTRAINT fk_Sessions_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblApiTokens
-- Bearer tokens for public-facing user authentication.
-- 64-character hex string (32 random bytes), 30-day default expiry.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblApiTokens (
    Token           VARCHAR(64)     NOT NULL PRIMARY KEY,
    UserId          INT UNSIGNED    NOT NULL,
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ExpiresAt       TIMESTAMP       NOT NULL,

    INDEX idx_User      (UserId),
    INDEX idx_Expires   (ExpiresAt),

    CONSTRAINT fk_Tokens_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


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
-- ACCESS TIERS & PURCHASES
-- ============================================================================

-- ----------------------------------------------------------------------------
-- tblAccessTiers
-- Defines available content access tiers. Each tier unlocks specific
-- content types. Higher tiers include all lower tier access.
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
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblUserPurchases
-- Tracks one-off purchases or subscription activations per user.
-- Used for premium content unlocks and subscription management.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblUserPurchases (
    Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    UserId          INT UNSIGNED    NOT NULL,
    ProductType     VARCHAR(30)     NOT NULL COMMENT 'tier_upgrade, songbook_unlock, feature_unlock, subscription',
    ProductId       VARCHAR(50)     NOT NULL DEFAULT '' COMMENT 'Specific product (e.g., songbook abbreviation)',
    TierGranted     VARCHAR(20)     NOT NULL DEFAULT '' COMMENT 'Access tier granted by this purchase',
    TransactionId   VARCHAR(100)    NOT NULL DEFAULT '' COMMENT 'Payment processor transaction ID',
    Amount          DECIMAL(10,2)   NULL COMMENT 'Payment amount',
    Currency        VARCHAR(3)      NOT NULL DEFAULT 'GBP',
    Status          VARCHAR(20)     NOT NULL DEFAULT 'active' COMMENT 'active, expired, refunded, cancelled',
    ExpiresAt       TIMESTAMP       NULL DEFAULT NULL COMMENT 'NULL = never expires (one-off purchase)',
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_User      (UserId),
    INDEX idx_Status    (Status),
    INDEX idx_Expires   (ExpiresAt),

    CONSTRAINT fk_Purchases_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
        ON DELETE CASCADE ON UPDATE CASCADE
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
    LicenceType     VARCHAR(30)     NOT NULL DEFAULT 'none' COMMENT 'none, ihymns_basic, ihymns_pro, ccli',
    LicenceNumber   VARCHAR(100)    NOT NULL DEFAULT '' COMMENT 'CCLI licence number or iHymns key',
    LicenceExpiresAt TIMESTAMP      NULL DEFAULT NULL,
    IsActive        TINYINT(1)      NOT NULL DEFAULT 1,
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
    LicenceType     VARCHAR(30)     NOT NULL COMMENT 'ccli, mrl, ihymns_basic, ihymns_pro, custom',
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
    LicenceType     VARCHAR(30)     NOT NULL COMMENT 'ihymns_basic, ihymns_pro, ccli, custom',
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

    INDEX idx_Entity    (EntityType, EntityId),
    INDEX idx_Target    (TargetType, TargetId),
    INDEX idx_Priority  (Priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblUserGroupMembers
-- Many-to-many user-to-group membership.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblUserGroupMembers (
    UserId          INT UNSIGNED    NOT NULL,
    GroupId         INT UNSIGNED    NOT NULL,
    AssignedAt      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (UserId, GroupId),

    CONSTRAINT fk_Ugm_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_Ugm_Group
        FOREIGN KEY (GroupId) REFERENCES tblUserGroups(Id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblUserPermissions
-- Fine-grained permission flags per user. NULL = inherit from role.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblUserPermissions (
    Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    UserId          INT UNSIGNED    NOT NULL UNIQUE,
    CanEditSongs    TINYINT(1)      NULL DEFAULT NULL COMMENT 'NULL = inherit from role',
    CanManageUsers  TINYINT(1)      NULL DEFAULT NULL,
    CanViewAdmin    TINYINT(1)      NULL DEFAULT NULL,
    CanShareSetlists TINYINT(1)     NULL DEFAULT NULL,
    CanAccessApi    TINYINT(1)      NULL DEFAULT NULL,

    CONSTRAINT fk_Perms_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


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

    UNIQUE KEY uq_UserSetlist (UserId, SetlistId),
    INDEX idx_User (UserId),

    CONSTRAINT fk_Setlists_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblSharedSetlists
-- Public, link-shared setlists (anyone with the URL can view). Replaces the
-- legacy file-based store under APP_SETLIST_SHARE_DIR. ShareId stays the
-- 8-char hex (bin2hex(random_bytes(4))) so existing share URLs keep
-- working when historical JSON files are imported by the migration.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSharedSetlists (
    ShareId         VARCHAR(16)     NOT NULL PRIMARY KEY COMMENT '8 hex chars by default; column wider for forward-compat',
    Data            JSON            NOT NULL COMMENT 'Full setlist payload as written by the share API',
    CreatedBy       INT UNSIGNED    NULL DEFAULT NULL COMMENT 'FK to tblUsers (NULL for guest creates)',
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    ViewCount       INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'Incremented on retrieval for share-link analytics',

    INDEX idx_CreatedBy (CreatedBy),
    INDEX idx_CreatedAt (CreatedAt),

    CONSTRAINT fk_SharedSetlists_User FOREIGN KEY (CreatedBy) REFERENCES tblUsers(Id)
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
-- tblMigrations
-- Schema migration tracking.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblMigrations (
    Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    Name            VARCHAR(255)    NOT NULL UNIQUE,
    AppliedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- FEATURE TABLES (Song Keys, Chords, Scheduling, Templates, Collaboration, etc.)
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
-- tblSongChords (#299)
-- Chord notation per component.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongChords (
    Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    ComponentId     INT UNSIGNED    NOT NULL COMMENT 'FK to tblSongComponents.Id',
    ChordsJson      JSON            NOT NULL COMMENT 'Array of {position, chord} objects per line',
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_Chords_Component
        FOREIGN KEY (ComponentId) REFERENCES tblSongComponents(Id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblSetlistSchedule (#300)
-- Calendar scheduling for setlists.
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
-- tblUserPreferences (#310)
-- Server-side preference sync.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblUserPreferences (
    UserId          INT UNSIGNED    NOT NULL PRIMARY KEY,
    PreferencesJson JSON            NOT NULL COMMENT 'Theme, font size, default songbook, etc.',
    UpdatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_Prefs_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


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
    ('captcha_provider', 'none', 'Bot protection: none, recaptcha_v2, recaptcha_v3, turnstile, hcaptcha, friendly, altcha, mtcaptcha'),
    ('captcha_site_key', '', 'CAPTCHA provider public site key'),
    ('captcha_secret_key', '', 'CAPTCHA provider server-side secret key'),
    ('ads_enabled', '0', 'Enable advertisement display (0=off, 1=on)'),
    ('ads_provider', 'none', 'Ad provider: none, adsense, ezoic, mediavine, custom'),
    ('ads_publisher_id', '', 'Ad provider publisher/client ID'),
    ('content_gating_enabled', '0', 'Enable content tier gating (0=off, 1=on — all content open when off)'),
    ('ccli_validation_enabled', '0', 'Require valid CCLI licence for copyrighted songs (0=off, 1=on)');


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
-- User-defined tags/categories for songs (e.g., "Easter", "Communion",
-- "Wedding", "Funeral"). Tags are shared across all users. Songs can
-- have multiple tags, and tags can apply to multiple songs.
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
    CreatedBy    INT UNSIGNED  NULL DEFAULT NULL
                 COMMENT 'tblUsers.Id of the curator who linked this row, if signed in',
    CreatedAt    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_song (SongId),
    KEY idx_GroupId (GroupId),
    CONSTRAINT fk_SongLinks_Song
        FOREIGN KEY (SongId) REFERENCES tblSongs(SongId)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblSongLinkSuggestions (#808) — pre-computed pairwise similarity scores.
-- Populated by appWeb/public_html/includes/tools/build-song-link-suggestions.php; consumed by the
-- /manage/song-link-suggestions admin page. Pairs are stored canonically
-- with SongIdA < SongIdB so each unordered pair has at most one row.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongLinkSuggestions (
    Id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    SongIdA         VARCHAR(20)  NOT NULL COMMENT 'Always lexicographically <= SongIdB',
    SongIdB         VARCHAR(20)  NOT NULL,
    Score           DECIMAL(4,3) NOT NULL COMMENT 'Composite similarity, 0.000-1.000',
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
-- Tables added by migrations (sync target for the schema-audit page).
-- Each table below was originally introduced via an appWeb/.sql/migrate-*.php
-- script; the definitions are mirrored here so schema.sql remains the
-- canonical source of truth for what the live database is expected to hold.
-- Adding a new table that ships via a migration? Append the matching
-- CREATE TABLE block here so the schema-audit page (#518) stays clean.
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


-- ----------------------------------------------------------------------------
-- tblExternalLinkTypes (#833) — controlled vocabulary of link providers
-- (Hymnary, CCLI Songselect, IMSLP, YouTube, Spotify, Internet Archive,
-- Wikipedia, MusicBrainz, …). Seeded by migrate-external-links.php.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblExternalLinkTypes (
    Id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    Slug          VARCHAR(60)  NOT NULL,
    Name          VARCHAR(120) NOT NULL,
    Category      ENUM(
                      'information', 'listen', 'watch', 'read',
                      'sheet-music', 'purchase', 'authority',
                      'official', 'social', 'other'
                  ) NOT NULL DEFAULT 'other',
    UrlPattern    VARCHAR(255) NULL,
    IconClass     VARCHAR(60)  NULL,
    AppliesTo     SET('song','songbook','person','work') NOT NULL DEFAULT 'song,songbook,person',
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
-- tblCreditPersonExternalLinks (#833) — per-credit-person external links.
-- Replaces the legacy free-text tblCreditPersonLinks (kept as read-fallback).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblCreditPersonExternalLinks (
    Id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    CreditPersonId  INT UNSIGNED NOT NULL,
    LinkTypeId      INT UNSIGNED NOT NULL,
    Url             VARCHAR(2048) NOT NULL,
    Note            VARCHAR(255) NULL,
    SortOrder       INT UNSIGNED NOT NULL DEFAULT 0,
    Verified        TINYINT(1)   NOT NULL DEFAULT 0,
    CreatedAt       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_person (CreditPersonId),
    INDEX idx_type   (LinkTypeId),

    CONSTRAINT fk_link_person
        FOREIGN KEY (CreditPersonId) REFERENCES tblCreditPeople(Id)        ON DELETE CASCADE,
    CONSTRAINT fk_link_type_person
        FOREIGN KEY (LinkTypeId)     REFERENCES tblExternalLinkTypes(Id)   ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- tblCreditPersonAliases — AKA / alternative names for searchability.
-- MusicBrainz-style alias model: one row per (person, name) with a Type
-- classification, optional Locale tag for transliterations, and an
-- IsPrimary flag for the preferred display form within a locale.
-- Searched alongside Name in site search + admin filter + editor
-- typeahead. /people/<slug> renders aliases under the bio header and
-- emits JSON-LD alternateName.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblCreditPersonAliases (
    Id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    CreditPersonId  INT UNSIGNED NOT NULL,
    Name            VARCHAR(255) NOT NULL COMMENT 'Display form of the alias',
    SortName        VARCHAR(255) NULL COMMENT 'Surname-first sortable form; NULL = derive from Name',
    Type            ENUM('legal','artist','pseudonym','nickname','maiden','search-hint','misspelling','other')
                                 NOT NULL DEFAULT 'other'
                                 COMMENT 'MusicBrainz-style alias classification',
    Locale          VARCHAR(35)  NULL COMMENT 'Optional IETF BCP 47 tag for transliterations (ja, ru-Latn, zh-Hans, …)',
    IsPrimary       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = preferred display form in this Locale',
    SortOrder       INT UNSIGNED NOT NULL DEFAULT 0,
    Note            VARCHAR(255) NULL,
    CreatedAt       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_person_name (CreditPersonId, Name),
    INDEX idx_person (CreditPersonId),
    INDEX idx_name   (Name),
    INDEX idx_type   (Type),

    CONSTRAINT fk_alias_person
        FOREIGN KEY (CreditPersonId) REFERENCES tblCreditPeople(Id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


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
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongbookCompilers (
    Id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    SongbookId      INT UNSIGNED NOT NULL,
    CreditPersonId  INT UNSIGNED NOT NULL,
    SortOrder       INT UNSIGNED NOT NULL DEFAULT 0,
    Note            VARCHAR(255) NULL,
    CreatedAt       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_book_person (SongbookId, CreditPersonId),
    INDEX idx_book   (SongbookId),
    INDEX idx_person (CreditPersonId),

    CONSTRAINT fk_compiler_book
        FOREIGN KEY (SongbookId)     REFERENCES tblSongbooks(Id)    ON DELETE CASCADE,
    CONSTRAINT fk_compiler_person
        FOREIGN KEY (CreditPersonId) REFERENCES tblCreditPeople(Id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


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
    Notes         TEXT         NULL,
    /* Composition origin — VARCHAR mirror + FK into tblPlaces. */
    OriginCity    VARCHAR(255) NULL,
    OriginCityId  INT UNSIGNED NULL,
    CreatedAt     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_slug   (Slug),
    UNIQUE KEY uq_iswc   (Iswc),
    UNIQUE KEY uq_mbwork (MusicBrainzWorkMBID),
    INDEX      idx_title (Title),
    INDEX      idx_parent (ParentWorkId),
    INDEX      idx_OriginCityId (OriginCityId),

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
-- tblSongIdentityMap (#1066 Theme D) — cross-system recording identity:
-- iHymns SongId <-> MusicBrainz recording / Spotify track / Genius / ISRC.
-- SongId is a NON-unique index on purpose: one song legitimately maps to
-- several recordings (explicit vs clean ISRC, live vs studio Spotify) — the
-- same N-recordings-per-song world tblLyrics already models. Uniqueness lives
-- on the external-id columns (stress-A1). Composition identity (Work MBID)
-- lives on tblWorks, not here (stress-C2). Change history goes to the existing
-- tblActivityLog, not a dedicated table (stress-C1). The iLyricsDB link column
-- + bridge views are GATED on the DB-merge decision (see issue #1066-gated).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongIdentityMap (
    Id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    SongId                   VARCHAR(20)  NOT NULL COMMENT 'FK to tblSongs.SongId (the iHymns song); NON-unique — a song may map to several recordings',
    MusicBrainzRecordingMBID VARCHAR(50)  NULL DEFAULT NULL COMMENT 'MusicBrainz recording MBID',
    SpotifyTrackId           VARCHAR(50)  NULL DEFAULT NULL COMMENT 'Spotify track id/URI',
    GeniusTrackId            VARCHAR(50)  NULL DEFAULT NULL COMMENT 'Genius track id',
    IsrcCode                 VARCHAR(15)  NULL DEFAULT NULL COMMENT 'Denorm of tblSongs.Isrc for join-free lookups (app keeps both in sync)',
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
-- tblCreditPersonAliases; indexed rows, not JSON).
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

-- Back-reference FK from tblSongs.TuneId -> tblTunes (added here, after tblTunes
-- exists; the column + index are declared inline in the tblSongs block above).
ALTER TABLE tblSongs
    ADD CONSTRAINT fk_Songs_Tune
        FOREIGN KEY (TuneId) REFERENCES tblTunes(Id) ON DELETE SET NULL ON UPDATE CASCADE;

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
    Vector      MEDIUMBLOB      NOT NULL COMMENT 'Packed float32 vector',
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
    CreatedAt            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_Code   (SessionCode),
    INDEX      idx_Host  (HostUserId),
    INDEX      idx_Active (IsActive, LastHeartbeatAt),
    INDEX      idx_Service (VenueId, ScheduleId, OccurrenceDate, IsActive),
    INDEX      idx_OrgActive (OrgId, IsActive),

    CONSTRAINT fk_LiveFollow_Host
        FOREIGN KEY (HostUserId) REFERENCES tblUsers(Id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_LiveFollow_Org
        FOREIGN KEY (OrgId) REFERENCES tblOrganisations(Id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_LiveFollow_Song
        FOREIGN KEY (CurrentSongId) REFERENCES tblSongs(SongId) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_LiveFollow_Venue
        FOREIGN KEY (VenueId) REFERENCES tblOrgVenues(Id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_LiveFollow_Schedule
        FOREIGN KEY (ScheduleId) REFERENCES tblOrgServiceSchedules(Id) ON DELETE SET NULL ON UPDATE CASCADE
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
    Status      VARCHAR(20)   NOT NULL DEFAULT 'current' COMMENT 'current | previous | superseded — app-validated, VARCHAR not ENUM (rule #20)',
    IssuedAt    DATETIME      NOT NULL,
    ExpiresAt   DATETIME      NOT NULL COMMENT 'Rotation horizon + grace (UTC); join rejects past this',
    CreatedAt   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_Session_Code (SessionId, Code),
    INDEX idx_Session_Status (SessionId, Status),
    INDEX idx_Expiry (ExpiresAt),
    CONSTRAINT fk_JoinCode_Session FOREIGN KEY (SessionId) REFERENCES tblLiveFollowSessions(Id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Rotating venue join codes for Service Mode (#1335). Session-scoped uniqueness; current+previous window so a just-before-rotation scan still validates.';

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


-- ============================================================================
-- SCHEMA-COMPLETENESS BATCH (#1090 audit) — gaps the cross-repo audit found
-- iHymns missing that a converged iHymns+iLyricsDB backend needs. Additive +
-- dormant; anchored on shipped tables; VARCHAR-not-ENUM vocab throughout.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- VOCAL / SINGING PARTS (#1137) — first-class queryable projection of the
-- lossless TTML ttm:agent/ttm:role/background-vocal signal trapped in
-- tblLyricLines.MetaJson. Registry + MANY-to-MANY line/word assignment (true
-- duet/unison). Named singer reuses tblCreditPeople (no new tblArtists). Gender
-- is an orthogonal axis. Additive + dormant (MetaJson stays the source of truth).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblVocalParts (
    Id             INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    LyricsId       INT UNSIGNED    NOT NULL COMMENT 'FK to tblLyrics.Id — parts are per lyrics version',
    PartKind       VARCHAR(30)     NOT NULL DEFAULT 'lead' COMMENT 'lead|main|backing|soloist|male|female|duet|group|unison|choir|congregation|cantor|descant|narrator|spoken|named-singer (app-validated vs a central map -> badge). VARCHAR not ENUM',
    Label          VARCHAR(120)    NULL DEFAULT NULL COMMENT 'Editor display override (Soprano, Worship Leader, …)',
    CreditPersonId INT UNSIGNED    NULL DEFAULT NULL COMMENT 'FK to tblCreditPeople.Id — typed named-singer link (reuses the person registry, NOT a new tblArtists)',
    SingerName     VARCHAR(255)    NULL DEFAULT NULL COMMENT 'Free-text named singer when no registry row',
    Gender         VARCHAR(16)     NULL DEFAULT NULL COMMENT 'male|female|neutral — orthogonal axis (a named soloist may also be female)',
    TtmlAgentId    VARCHAR(64)     NULL DEFAULT NULL COMMENT 'Source <ttm:agent> handle (v1,v2) — loss-free re-export + idempotent back-fill key',
    Source         VARCHAR(100)    NOT NULL DEFAULT 'ihymns' COMMENT 'applemusic-ttml | manual | … (mirrors tblLyrics.Source)',
    SortOrder      INT UNSIGNED    NOT NULL DEFAULT 0,
    MetaJson       JSON            NULL DEFAULT NULL COMMENT 'Lossless TTML <head> agent def attrs (ttm:agent type, ttm:name)',
    CreatedAt      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_Lyrics_Agent (LyricsId, TtmlAgentId),
    INDEX idx_Lyrics (LyricsId),
    INDEX idx_Kind   (PartKind),
    INDEX idx_Person (CreditPersonId),

    CONSTRAINT fk_VocalParts_Lyrics
        FOREIGN KEY (LyricsId)       REFERENCES tblLyrics(Id)       ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT fk_VocalParts_Person
        FOREIGN KEY (CreditPersonId) REFERENCES tblCreditPeople(Id) ON DELETE SET NULL ON UPDATE CASCADE
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
