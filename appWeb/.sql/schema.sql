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
    Copyright       VARCHAR(500)    NULL DEFAULT NULL COMMENT 'Copyright notice for the collection as a whole (#502)',
    Affiliation     VARCHAR(120)    NULL DEFAULT NULL COMMENT 'Denominational / religious affiliation; backed by tblSongbookAffiliations registry (#670)',
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

    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_DisplayOrder (DisplayOrder)
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
    SongbookAbbr        VARCHAR(10)     NOT NULL COMMENT 'FK to tblSongbooks.Abbreviation',
    SongbookName        VARCHAR(255)    NOT NULL COMMENT 'Denormalised songbook name for convenience',
    Language            VARCHAR(35)     NOT NULL DEFAULT 'en' COMMENT 'IETF BCP 47 tag (language[-script][-region]); widened from VARCHAR(10) to fit script + region subtags (#681)',
    Copyright           VARCHAR(500)    NOT NULL DEFAULT '',
    TuneName            VARCHAR(120)    NULL DEFAULT NULL COMMENT 'Traditional tune name, e.g. HYFRYDOL, OLD HUNDREDTH (#497)',
    Ccli                VARCHAR(50)     NOT NULL DEFAULT '' COMMENT 'CCLI Song Number',
    Iswc                VARCHAR(15)     NULL DEFAULT NULL COMMENT 'International Standard Musical Work Code, e.g. T-034.524.680-C (#497)',
    Verified            TINYINT(1)      NOT NULL DEFAULT 0,
    LyricsPublicDomain  TINYINT(1)      NOT NULL DEFAULT 0,
    MusicPublicDomain   TINYINT(1)      NOT NULL DEFAULT 0,
    HasAudio            TINYINT(1)      NOT NULL DEFAULT 0,
    HasSheetMusic       TINYINT(1)      NOT NULL DEFAULT 0,
    LyricsText          MEDIUMTEXT      NOT NULL DEFAULT ('') COMMENT 'Concatenated lyrics for full-text search',
    CreatedAt           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_Songbook          (SongbookAbbr),
    INDEX idx_SongbookNumber    (SongbookAbbr, Number),
    INDEX idx_TuneName          (TuneName),
    FULLTEXT idx_TitleFt        (Title),
    FULLTEXT idx_LyricsFt       (LyricsText),
    FULLTEXT idx_TitleLyricsFt  (Title, LyricsText),

    CONSTRAINT fk_Songs_Songbook
        FOREIGN KEY (SongbookAbbr) REFERENCES tblSongbooks(Abbreviation)
        ON DELETE RESTRICT ON UPDATE CASCADE
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
    /* Special-case + Group flags (#584 / #585) — distinguish
       Anonymous / Traditional / Public Domain / Unknown ("special
       case") from real individuals, and Hillsong United / Bethel
       Music ("group / collective") from solo writers. Both flags
       feed UI rules in the Credit People editor (e.g. disable
       birth/death fields when special case; relabel dates as
       Founded/Disbanded when group). */
    IsSpecialCase   TINYINT(1)      NOT NULL DEFAULT 0,
    IsGroup         TINYINT(1)      NOT NULL DEFAULT 0,
    Notes           TEXT            NULL,
    BirthPlace      VARCHAR(255)    NULL,
    BirthDate       DATE            NULL,
    DeathPlace      VARCHAR(255)    NULL,
    DeathDate       DATE            NULL,
    CreatedAt       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_Name (Name),
    INDEX idx_Name (Name),
    INDEX idx_Slug (Slug)
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
-- tblSongComponents
-- Each component stores its lyrics lines as a JSON array.
-- `SortOrder` preserves the display sequence from the original data.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblSongComponents (
    Id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    SongId      VARCHAR(20)     NOT NULL,
    Type        VARCHAR(20)     NOT NULL COMMENT 'verse, chorus, refrain, bridge, etc.',
    Number      INT UNSIGNED    NOT NULL COMMENT 'Component number (e.g., verse 1, verse 2)',
    SortOrder   INT UNSIGNED    NOT NULL COMMENT 'Display order within the song',
    LinesJson   JSON            NOT NULL COMMENT 'Array of lyric lines',

    INDEX idx_SongId        (SongId),
    INDEX idx_SongOrder     (SongId, SortOrder),

    CONSTRAINT fk_Components_Song
        FOREIGN KEY (SongId) REFERENCES tblSongs(SongId)
        ON DELETE CASCADE ON UPDATE CASCADE
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
    Token           VARCHAR(48)     NOT NULL PRIMARY KEY,
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
    Token           VARCHAR(64)     NOT NULL UNIQUE COMMENT '48-char hex token for magic link',
    Code            VARCHAR(6)      NOT NULL COMMENT '6-digit numeric code for manual entry',
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
    LicenceType     VARCHAR(30)     NOT NULL DEFAULT 'none' COMMENT 'none, ihymns_basic, ihymns_pro, ccli',
    LicenceNumber   VARCHAR(100)    NOT NULL DEFAULT '' COMMENT 'CCLI licence number or iHymns key',
    LicenceExpiresAt TIMESTAMP      NULL DEFAULT NULL,
    IsActive        TINYINT(1)      NOT NULL DEFAULT 1,
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_Parent    (ParentOrgId),
    INDEX idx_Slug      (Slug),
    INDEX idx_Licence   (LicenceType),

    CONSTRAINT fk_Org_Parent
        FOREIGN KEY (ParentOrgId) REFERENCES tblOrganisations(Id)
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
    RestrictionType VARCHAR(30)     NOT NULL COMMENT 'require_licence, require_org, block_platform, block_user, block_org',
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
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (UserId, SongId),

    CONSTRAINT fk_Favorites_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_Favorites_Song
        FOREIGN KEY (SongId) REFERENCES tblSongs(SongId)
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
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_Status    (Status),
    INDEX idx_User      (UserId),
    INDEX idx_Created   (CreatedAt),

    CONSTRAINT fk_Requests_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
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
    IpAddress       VARCHAR(45)     NOT NULL DEFAULT '',
    UserAgent       VARCHAR(500)    NOT NULL DEFAULT '' COMMENT 'Truncated UA — useful for "mobile vs desktop" debugging (#535)',
    RequestId       CHAR(16)        NOT NULL DEFAULT '' COMMENT 'Per-HTTP-request correlation ID; groups every row from one request (#535)',
    Method          VARCHAR(10)     NOT NULL DEFAULT '' COMMENT 'HTTP method (GET/POST/etc) for HTTP-driven events; blank for cron/system (#535)',
    DurationMs      INT UNSIGNED    NULL COMMENT 'Wall-clock duration of the logged operation in milliseconds (#535)',
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_User      (UserId),
    INDEX idx_Action    (Action),
    INDEX idx_Entity    (EntityType, EntityId),
    INDEX idx_Created   (CreatedAt),
    INDEX idx_Result    (Result),
    INDEX idx_RequestId (RequestId),

    CONSTRAINT fk_Log_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
        ON DELETE SET NULL ON UPDATE CASCADE
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
        ON DELETE SET NULL ON UPDATE CASCADE
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
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_Slug  (Slug)
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
