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
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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
    Language            VARCHAR(10)     NOT NULL DEFAULT 'en',
    Copyright           VARCHAR(500)    NOT NULL DEFAULT '',
    Ccli                VARCHAR(50)     NOT NULL DEFAULT '',
    Verified            TINYINT(1)      NOT NULL DEFAULT 0,
    LyricsPublicDomain  TINYINT(1)      NOT NULL DEFAULT 0,
    MusicPublicDomain   TINYINT(1)      NOT NULL DEFAULT 0,
    HasAudio            TINYINT(1)      NOT NULL DEFAULT 0,
    HasSheetMusic       TINYINT(1)      NOT NULL DEFAULT 0,
    LyricsText          MEDIUMTEXT      NOT NULL DEFAULT '' COMMENT 'Concatenated lyrics for full-text search',
    CreatedAt           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_Songbook          (SongbookAbbr),
    INDEX idx_SongbookNumber    (SongbookAbbr, Number),
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
    Description     TEXT            NOT NULL DEFAULT '',
    AccessAlpha     TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'Can access Alpha (dev) builds',
    AccessBeta      TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'Can access Beta builds',
    AccessRc        TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'Can access RC (Release Candidate) builds',
    AccessRtw       TINYINT(1)      NOT NULL DEFAULT 1 COMMENT 'Can access RTW (Release to Web / production)',
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
    Description     TEXT            NOT NULL DEFAULT '',
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
    Description     TEXT            NOT NULL DEFAULT '',
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
    SongsJson       MEDIUMTEXT      NOT NULL DEFAULT '[]' COMMENT 'JSON array of song objects',
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_UserSetlist (UserId, SetlistId),
    INDEX idx_User (UserId),

    CONSTRAINT fk_Setlists_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
        ON DELETE CASCADE ON UPDATE CASCADE
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
    Code            VARCHAR(10)     NOT NULL PRIMARY KEY COMMENT 'ISO 639-1 code (e.g. en, fr, es)',
    Name            VARCHAR(100)    NOT NULL COMMENT 'English name (e.g. French)',
    NativeName      VARCHAR(100)    NOT NULL DEFAULT '' COMMENT 'Native name (e.g. Français)',
    TextDirection   VARCHAR(3)      NOT NULL DEFAULT 'ltr' COMMENT 'ltr or rtl',
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
    TargetLanguage      VARCHAR(10)     NOT NULL COMMENT 'ISO 639-1 code of translation',
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
    Language        VARCHAR(10)     NOT NULL DEFAULT 'en' COMMENT 'Language of the requested song',
    Details         TEXT            NOT NULL DEFAULT '' COMMENT 'Additional info (first line of lyrics, etc.)',
    ContactEmail    VARCHAR(255)    NOT NULL DEFAULT '' COMMENT 'Optional email for follow-up',
    UserId          INT UNSIGNED    NULL DEFAULT NULL COMMENT 'FK to tblUsers (NULL for anonymous)',
    IpAddress       VARCHAR(45)     NOT NULL DEFAULT '' COMMENT 'Submitter IP for rate limiting',
    Status          VARCHAR(20)     NOT NULL DEFAULT 'pending' COMMENT 'pending, reviewed, added, declined',
    AdminNotes      TEXT            NOT NULL DEFAULT '' COMMENT 'Internal notes from reviewers',
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
-- Audit trail for significant actions.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblActivityLog (
    Id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    UserId          INT UNSIGNED    NULL COMMENT 'User who performed the action (NULL for system)',
    Action          VARCHAR(50)     NOT NULL COMMENT 'e.g. song.edit, user.create, login, import',
    EntityType      VARCHAR(50)     NOT NULL DEFAULT '' COMMENT 'e.g. song, user, songbook, setlist',
    EntityId        VARCHAR(50)     NOT NULL DEFAULT '' COMMENT 'ID of the affected entity',
    Details         JSON            NULL COMMENT 'Additional context (old/new values, etc.)',
    IpAddress       VARCHAR(45)     NOT NULL DEFAULT '',
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_User      (UserId),
    INDEX idx_Action    (Action),
    INDEX idx_Entity    (EntityType, EntityId),
    INDEX idx_Created   (CreatedAt),

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
    SettingValue    TEXT            NOT NULL DEFAULT '',
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
    Notes           TEXT            NOT NULL DEFAULT '',
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
    Description     TEXT            NOT NULL DEFAULT '',
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
    ReviewNote      TEXT            NOT NULL DEFAULT '',
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
-- tblNotifications
-- In-app notification system for users (new songs, request status changes,
-- system announcements, etc.).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblNotifications (
    Id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    UserId          INT UNSIGNED    NOT NULL,
    Type            VARCHAR(50)     NOT NULL COMMENT 'e.g., song_added, request_update, announcement',
    Title           VARCHAR(255)    NOT NULL,
    Body            TEXT            NOT NULL DEFAULT '',
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
